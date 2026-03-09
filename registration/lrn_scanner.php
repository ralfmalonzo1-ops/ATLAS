<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$role = $_SESSION['role'] ?? '';
if ($role !== 'teacher' && $role !== 'admin') {
    header('Location: login.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LRN Attendance Scanner</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include __DIR__ . '/atlas_brand.php'; ?>
    <div class="container">
        <h1>LRN Attendance Scanner</h1>
        <div class="top-links action-links">
            <a class="btn btn-soft" href="isolated_scanner.php">Back to Scanner Dashboard</a>
            <a class="btn btn-soft" href="face_scanner.php">Open Face + LRN Scanner</a>
            <a class="btn btn-danger" href="logout.php">Logout</a>
        </div>

        <div class="report-filters">
            <div class="filter-grid">
                <div class="input-group">
                    <label for="attendanceDate">Attendance Date</label>
                    <input type="date" id="attendanceDate" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>">
                </div>
                <div class="input-group">
                    <label for="attendanceStatus">Attendance Status</label>
                    <select id="attendanceStatus">
                        <option value="present" selected>Present</option>
                        <option value="absent">Absent</option>
                    </select>
                </div>
                <div class="input-group">
                    <label for="lrnInput">LRN (12 digits)</label>
                    <input type="text" id="lrnInput" maxlength="12" inputmode="numeric" pattern="\d{12}" placeholder="123456789012">
                </div>
            </div>
            <div class="top-links">
                <button type="button" id="verifyLrnBtn" class="btn btn-soft">Verify Typed LRN</button>
                <button type="button" id="startCameraBtn">Start Camera</button>
                <button type="button" id="readCameraLrnBtn" class="btn btn-soft">Read LRN From Camera (AI OCR)</button>
                <button type="button" id="markAttendanceBtn" class="btn btn-success">Mark Attendance</button>
                <button type="button" id="clearBtn" class="btn btn-soft">Clear</button>
            </div>
            <p id="scanStatus" class="hint">Ready.</p>
        </div>

        <div class="scanner-grid">
            <div class="scanner-panel">
                <h3>Camera Preview</h3>
                <video id="scanVideo" class="scanner-video" autoplay muted playsinline></video>
                <canvas id="scanCanvas" class="scanner-canvas"></canvas>
            </div>
            <div class="scanner-panel">
                <h3>Verification Result</h3>
                <p id="resultText" class="hint">No LRN verified yet.</p>
                <div id="scanLogs" class="scan-log"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    <script>
        (function () {
            const csrfToken = <?php echo json_encode($_SESSION['csrf_token']); ?>;
            const lrnInput = document.getElementById('lrnInput');
            const attendanceDate = document.getElementById('attendanceDate');
            const attendanceStatus = document.getElementById('attendanceStatus');
            const verifyBtn = document.getElementById('verifyLrnBtn');
            const startCameraBtn = document.getElementById('startCameraBtn');
            const readCameraLrnBtn = document.getElementById('readCameraLrnBtn');
            const markAttendanceBtn = document.getElementById('markAttendanceBtn');
            const clearBtn = document.getElementById('clearBtn');
            const statusLabel = document.getElementById('scanStatus');
            const resultText = document.getElementById('resultText');
            const logs = document.getElementById('scanLogs');
            const video = document.getElementById('scanVideo');
            const canvas = document.getElementById('scanCanvas');

            let cameraReady = false;
            let ocrRunning = false;
            let verifiedStudentId = '';
            let lrnConfirmationToken = '';
            let verifiedStudentName = '';
            let verifiedSection = '';

            function addLog(text, isError) {
                const item = document.createElement('div');
                item.className = isError ? 'hint error' : 'hint success';
                item.textContent = '[' + new Date().toLocaleTimeString() + '] ' + text;
                logs.prepend(item);
            }

            function normalizeLrn(value) {
                const digitsOnly = String(value || '').replace(/\D+/g, '');
                return /^\d{12}$/.test(digitsOnly) ? digitsOnly : '';
            }

            function extractLrnFromText(text) {
                const source = String(text || '');
                const match = source.match(/(^|\D)(\d{12})(\D|$)/);
                return match ? match[2] : '';
            }

            function clearVerification() {
                verifiedStudentId = '';
                lrnConfirmationToken = '';
                verifiedStudentName = '';
                verifiedSection = '';
                resultText.textContent = 'No LRN verified yet.';
                resultText.className = 'hint';
            }

            async function verifyLrn(lrnValue, sourceLabel) {
                const cleanLrn = normalizeLrn(lrnValue);
                if (!cleanLrn) {
                    addLog('Invalid LRN from ' + sourceLabel + '. Enter exactly 12 digits.', true);
                    return;
                }

                statusLabel.textContent = 'Verifying LRN...';
                try {
                    const response = await fetch('api_verify_lrn.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            csrf_token: csrfToken,
                            lrn_value: cleanLrn
                        })
                    });
                    const json = await response.json();

                    if (!json.success) {
                        clearVerification();
                        addLog(json.message || 'LRN verification failed.', true);
                        statusLabel.textContent = 'Ready.';
                        return;
                    }

                    verifiedStudentId = String(json.student.id || '');
                    lrnConfirmationToken = String(json.lrn_confirmation_token || '');
                    verifiedStudentName = String(json.student.name || '');
                    verifiedSection = String(json.student.class_section || '');

                    resultText.textContent = 'Verified: ' + verifiedStudentName + ' (' + verifiedSection + ') | LRN: ' + cleanLrn;
                    resultText.className = 'hint success';
                    addLog('LRN verified from ' + sourceLabel + ': ' + verifiedStudentName, false);
                    statusLabel.textContent = 'Ready.';
                } catch (error) {
                    clearVerification();
                    addLog('Verification API error: ' + error.message, true);
                    statusLabel.textContent = 'Ready.';
                }
            }

            async function markAttendance() {
                if (!verifiedStudentId || !lrnConfirmationToken) {
                    addLog('Verify an LRN first before marking attendance.', true);
                    return;
                }

                statusLabel.textContent = 'Saving attendance...';
                try {
                    const response = await fetch('api_mark_attendance.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            csrf_token: csrfToken,
                            student_id: verifiedStudentId,
                            attendance_date: attendanceDate.value,
                            status: attendanceStatus.value,
                            lrn_confirmation_token: lrnConfirmationToken
                        })
                    });
                    const json = await response.json();

                    if (!json.success) {
                        addLog(json.message || 'Attendance not saved.', true);
                        statusLabel.textContent = 'Ready.';
                        return;
                    }

                    addLog('Marked ' + json.status + ': ' + json.student.name + ' (' + json.student.class_section + ')', false);
                    statusLabel.textContent = 'Attendance saved.';
                    clearVerification();
                } catch (error) {
                    addLog('Attendance API error: ' + error.message, true);
                    statusLabel.textContent = 'Ready.';
                }
            }

            async function startCamera() {
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
                    video.srcObject = stream;
                    await new Promise(function (resolve) {
                        if (video.readyState >= 2) {
                            resolve();
                            return;
                        }
                        video.onloadedmetadata = function () { resolve(); };
                    });
                    if (typeof video.play === 'function') {
                        await video.play();
                    }
                    cameraReady = true;
                    canvas.width = video.videoWidth || 640;
                    canvas.height = video.videoHeight || 480;
                    statusLabel.textContent = 'Camera active. Ready for LRN OCR.';
                } catch (error) {
                    cameraReady = false;
                    addLog('Camera start failed: ' + error.message, true);
                }
            }

            async function readLrnFromCamera() {
                if (ocrRunning) return;
                if (!cameraReady || !video.srcObject || video.videoWidth === 0 || video.videoHeight === 0) {
                    addLog('Start camera first.', true);
                    return;
                }
                if (!window.Tesseract) {
                    addLog('OCR library failed to load.', true);
                    return;
                }

                ocrRunning = true;
                statusLabel.textContent = 'Reading LRN from camera frame...';
                try {
                    const ocrCanvas = document.createElement('canvas');
                    ocrCanvas.width = video.videoWidth || 640;
                    ocrCanvas.height = video.videoHeight || 480;
                    const ctx = ocrCanvas.getContext('2d');
                    ctx.drawImage(video, 0, 0, ocrCanvas.width, ocrCanvas.height);

                    const result = await Tesseract.recognize(ocrCanvas, 'eng', {
                        tessedit_char_whitelist: '0123456789',
                        preserve_interword_spaces: '0'
                    });

                    const rawText = (result && result.data && result.data.text) ? result.data.text : '';
                    const foundLrn = extractLrnFromText(rawText) || normalizeLrn(rawText);
                    if (!foundLrn) {
                        addLog('No valid 12-digit LRN found in camera frame.', true);
                        statusLabel.textContent = 'Ready.';
                        return;
                    }

                    lrnInput.value = foundLrn;
                    addLog('OCR read LRN: ' + foundLrn + '. Verifying...', false);
                    await verifyLrn(foundLrn, 'camera OCR');
                } catch (error) {
                    addLog('OCR failed: ' + error.message, true);
                    statusLabel.textContent = 'Ready.';
                } finally {
                    ocrRunning = false;
                }
            }

            lrnInput.addEventListener('input', function () {
                lrnInput.value = String(lrnInput.value || '').replace(/\D+/g, '').slice(0, 12);
            });

            lrnInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    verifyLrn(lrnInput.value, 'manual input');
                }
            });

            verifyBtn.addEventListener('click', function () {
                verifyLrn(lrnInput.value, 'manual input');
            });
            startCameraBtn.addEventListener('click', startCamera);
            readCameraLrnBtn.addEventListener('click', function () {
                readLrnFromCamera().catch(function (error) {
                    addLog('Unable to read camera LRN: ' + error.message, true);
                });
            });
            markAttendanceBtn.addEventListener('click', function () {
                markAttendance();
            });
            clearBtn.addEventListener('click', function () {
                lrnInput.value = '';
                clearVerification();
                addLog('Cleared current LRN verification.', false);
            });
        })();
    </script>
</body>
</html>
