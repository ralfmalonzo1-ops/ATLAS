<?php
session_start();
require 'config.php';

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

$section_options = [];
$sections_result = $conn->query('SELECT DISTINCT class_section FROM students WHERE class_section IS NOT NULL AND class_section <> "" ORDER BY class_section ASC');
if ($sections_result) {
    while ($row = $sections_result->fetch_assoc()) {
        $section_options[] = (string)$row['class_section'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face + LRN Scanner</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include __DIR__ . '/atlas_brand.php'; ?>
    <div class="container">
        <h1>Face + LRN Scanner</h1>
        <div class="top-links action-links">
            <a class="btn btn-soft" href="isolated_scanner.php">Back to Scanner Dashboard</a>
            <a class="btn btn-soft" href="face_register.php">Face + LRN Registration</a>
            <a class="btn btn-soft" href="lrn_scanner.php">LRN Scanner</a>
            <a class="btn btn-danger" href="logout.php">Logout</a>
        </div>

        <div class="report-filters">
            <div class="filter-grid">
                <div class="input-group">
                    <label for="scanSection">Class/Section</label>
                    <select id="scanSection">
                        <option value="">All Sections</option>
                        <?php foreach ($section_options as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
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
                    <label for="manualLrnInput">Manual LRN (12 digits)</label>
                    <input type="text" id="manualLrnInput" maxlength="12" inputmode="numeric" pattern="\d{12}" placeholder="123456789012">
                </div>
            </div>
            <div class="top-links">
                <button type="button" id="startCameraBtn">Start Camera</button>
                <button type="button" id="captureCompareBtn" class="btn btn-success">Capture & Compare Face</button>
                <button type="button" id="captureCompareLrnBtn" class="btn btn-soft">Read LRN From Camera (AI OCR)</button>
                <button type="button" id="verifyManualLrnBtn" class="btn btn-soft">Verify Typed LRN</button>
                <button type="button" id="markAttendanceBtn" class="btn btn-soft">Mark Attendance</button>
                <button type="button" id="reloadFacesBtn" class="btn btn-soft">Reload Saved Faces</button>
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
                <h3>Result</h3>
                <p id="matchResult" class="hint">No capture yet.</p>
                <div id="scanLogs" class="scan-log"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    <script>
        (function () {
            const MODEL_URL = 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights';
            const csrfToken = <?php echo json_encode($_SESSION['csrf_token']); ?>;
            const video = document.getElementById('scanVideo');
            const canvas = document.getElementById('scanCanvas');
            const statusLabel = document.getElementById('scanStatus');
            const matchResult = document.getElementById('matchResult');
            const logs = document.getElementById('scanLogs');
            const sectionSelect = document.getElementById('scanSection');
            const attendanceDate = document.getElementById('attendanceDate');
            const attendanceStatus = document.getElementById('attendanceStatus');
            const manualLrnInput = document.getElementById('manualLrnInput');
            const startCameraBtn = document.getElementById('startCameraBtn');
            const captureCompareBtn = document.getElementById('captureCompareBtn');
            const captureCompareLrnBtn = document.getElementById('captureCompareLrnBtn');
            const verifyManualLrnBtn = document.getElementById('verifyManualLrnBtn');
            const markAttendanceBtn = document.getElementById('markAttendanceBtn');
            const reloadFacesBtn = document.getElementById('reloadFacesBtn');

            let modelsLoaded = false;
            let cameraReady = false;
            let matcher = null;
            let knownById = {};
            let lastMatchedStudentId = '';
            const FACE_COMPARE_THRESHOLD = 0.56;
            const FACE_CONFIRM_TTL_MS = 30000;
            let lastMatchedDistance = null;
            let lastFaceMatchedAt = 0;
            let lrnConfirmedStudentId = '';
            let lrnConfirmationToken = '';
            let attendanceSubmitting = false;
            let ocrRunning = false;

            function addLog(text, isError) {
                const item = document.createElement('div');
                item.className = isError ? 'hint error' : 'hint success';
                item.textContent = '[' + new Date().toLocaleTimeString() + '] ' + text;
                logs.prepend(item);
            }

            function clearLrnConfirmation() {
                lrnConfirmedStudentId = '';
                lrnConfirmationToken = '';
            }

            function hasRecentAiFaceConfirmation(studentId) {
                if (!studentId) return false;
                if (lastMatchedStudentId !== String(studentId)) return false;
                if (lastFaceMatchedAt <= 0 || (Date.now() - lastFaceMatchedAt) > FACE_CONFIRM_TTL_MS) return false;
                return typeof lastMatchedDistance === 'number' && lastMatchedDistance <= FACE_COMPARE_THRESHOLD;
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

            async function loadModels() {
                if (modelsLoaded) return;
                statusLabel.textContent = 'Loading face models...';
                await Promise.all([
                    faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
                    faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
                    faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL)
                ]);
                modelsLoaded = true;
                statusLabel.textContent = 'Face models loaded.';
            }

            async function loadKnownFaces() {
                const section = sectionSelect.value ? '?section=' + encodeURIComponent(sectionSelect.value) : '';
                const response = await fetch('fetch_face_data.php' + section, { cache: 'no-store' });
                const json = await response.json();
                if (!json.success) {
                    throw new Error(json.message || 'Failed to load saved faces');
                }

                if (!json.students || json.students.length === 0) {
                    matcher = null;
                    knownById = {};
                    statusLabel.textContent = 'No saved faces found for selected section.';
                    return;
                }

                knownById = {};
                const labeled = json.students.map(function (student) {
                    knownById[String(student.id)] = student;
                    return new faceapi.LabeledFaceDescriptors(String(student.id), [new Float32Array(student.descriptor)]);
                });
                matcher = new faceapi.FaceMatcher(labeled, FACE_COMPARE_THRESHOLD);
                statusLabel.textContent = 'Loaded ' + json.students.length + ' saved face(s).';
            }

            async function startCamera() {
                try {
                    await loadModels();
                    if (!matcher) {
                        await loadKnownFaces();
                    }
                    const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
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
                    statusLabel.textContent = 'Camera active. Ready to capture.';
                } catch (error) {
                    cameraReady = false;
                    statusLabel.textContent = 'Camera start failed: ' + error.message;
                }
            }

            async function captureAndCompare() {
                lastMatchedStudentId = '';
                lastMatchedDistance = null;
                lastFaceMatchedAt = 0;
                if (!modelsLoaded) {
                    matchResult.textContent = 'Start camera first.';
                    matchResult.className = 'hint error';
                    return;
                }
                if (!cameraReady || !video.srcObject || video.videoWidth === 0 || video.videoHeight === 0) {
                    matchResult.textContent = 'Camera is not ready yet.';
                    matchResult.className = 'hint error';
                    return;
                }
                if (!matcher) {
                    await loadKnownFaces();
                    if (!matcher) {
                        matchResult.textContent = 'No saved faces to compare.';
                        matchResult.className = 'hint error';
                        return;
                    }
                }

                const detection = await faceapi
                    .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
                    .withFaceLandmarks()
                    .withFaceDescriptor();

                const ctx = canvas.getContext('2d');
                canvas.width = video.videoWidth || 640;
                canvas.height = video.videoHeight || 480;
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

                if (!detection) {
                    matchResult.textContent = 'No face detected. Try again.';
                    matchResult.className = 'hint error';
                    addLog('FACE: No face detected in capture.', true);
                    return;
                }

                const displaySize = { width: video.videoWidth, height: video.videoHeight };
                faceapi.matchDimensions(canvas, displaySize);
                const resized = faceapi.resizeResults(detection, displaySize);
                faceapi.draw.drawDetections(canvas, resized);

                const best = matcher.findBestMatch(detection.descriptor);
                if (best.label === 'unknown' || best.distance > FACE_COMPARE_THRESHOLD) {
                    matchResult.textContent = 'No biometric match found. Distance: ' + best.distance.toFixed(4);
                    matchResult.className = 'hint error';
                    addLog('FACE: Compare failed (no match).', true);
                    return;
                }

                const matched = knownById[String(best.label)];
                lastMatchedStudentId = String(best.label);
                lastMatchedDistance = Number(best.distance);
                lastFaceMatchedAt = Date.now();
                matchResult.textContent = 'Matched: ' + matched.name + ' (' + matched.class_section + ') | LRN: ' + (matched.lrn || 'Not set') + ' | Distance: ' + best.distance.toFixed(4);
                matchResult.className = 'hint success';
                addLog('FACE: Matched ' + matched.name + '. Now show LRN (12 digits) to the camera.', false);

                if (lrnConfirmedStudentId && lrnConfirmedStudentId !== lastMatchedStudentId) {
                    addLog('LRN confirmation belongs to a different student. Verify LRN again.', true);
                    clearLrnConfirmation();
                }
            }

            async function verifyLrnValue(lrnValue, sourceLabel) {
                const cleanLrn = normalizeLrn(lrnValue);
                if (!cleanLrn) {
                    addLog('Invalid LRN from ' + sourceLabel + '. Expected exactly 12 digits.', true);
                    return;
                }

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
                        clearLrnConfirmation();
                        addLog('LRN verify failed: ' + (json.message || 'No matching student for this LRN.'), true);
                        return;
                    }

                    const scannedStudentId = String(json.student.id);
                    if (!hasRecentAiFaceConfirmation(scannedStudentId)) {
                        clearLrnConfirmation();
                        addLog('LRN matched saved record, but no recent AI face match for this student. Capture & compare face first.', true);
                        return;
                    }

                    lrnConfirmedStudentId = scannedStudentId;
                    lrnConfirmationToken = String(json.lrn_confirmation_token || '');
                    addLog('LRN confirmed with AI face match: ' + json.student.name + ' (' + json.student.class_section + '). Marking attendance now...', false);
                    await markAttendanceNow();
                } catch (error) {
                    clearLrnConfirmation();
                    addLog('LRN API error: ' + error.message, true);
                }
            }

            async function readLrnFromCamera() {
                if (ocrRunning) return;
                if (!cameraReady || !video.srcObject || video.videoWidth === 0 || video.videoHeight === 0) {
                    addLog('Start camera before reading LRN.', true);
                    return;
                }
                if (!window.Tesseract) {
                    addLog('OCR library failed to load.', true);
                    return;
                }

                ocrRunning = true;
                statusLabel.textContent = 'Reading LRN from camera frame (AI OCR)...';
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
                        addLog('No valid 12-digit LRN detected in camera frame. Try better lighting/angle.', true);
                        return;
                    }

                    manualLrnInput.value = foundLrn;
                    addLog('OCR read LRN: ' + foundLrn + '. Verifying...', false);
                    await verifyLrnValue(foundLrn, 'camera OCR');
                } catch (error) {
                    addLog('OCR failed: ' + error.message, true);
                } finally {
                    ocrRunning = false;
                    statusLabel.textContent = 'Ready.';
                }
            }

            async function markAttendanceNow() {
                if (attendanceSubmitting) {
                    return;
                }
                if (!lastMatchedStudentId) {
                    addLog('No confirmed face match yet. Capture & compare first.', true);
                    return;
                }
                if (!lrnConfirmedStudentId || !lrnConfirmationToken) {
                    addLog('Verify LRN first (camera OCR or typed LRN) before marking attendance.', true);
                    return;
                }
                if (lrnConfirmedStudentId !== lastMatchedStudentId) {
                    addLog('Face match and LRN verification are for different students.', true);
                    return;
                }
                if (!hasRecentAiFaceConfirmation(lastMatchedStudentId)) {
                    addLog('Face AI confirmation expired. Capture & compare face again, then verify LRN again.', true);
                    clearLrnConfirmation();
                    return;
                }
                attendanceSubmitting = true;
                try {
                    const response = await fetch('api_mark_attendance.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            csrf_token: csrfToken,
                            student_id: lastMatchedStudentId,
                            attendance_date: attendanceDate.value,
                            status: attendanceStatus.value,
                            lrn_confirmation_token: lrnConfirmationToken
                        })
                    });
                    const json = await response.json();
                    if (json.success) {
                        addLog('Marked ' + json.status + ': ' + json.student.name + ' (' + json.student.class_section + ')', false);
                        clearLrnConfirmation();
                    } else {
                        addLog(json.message || 'Attendance not saved.', true);
                    }
                } catch (error) {
                    addLog('Attendance API error: ' + error.message, true);
                } finally {
                    attendanceSubmitting = false;
                }
            }

            startCameraBtn.addEventListener('click', startCamera);
            captureCompareBtn.addEventListener('click', function () {
                captureAndCompare().catch(function (error) {
                    matchResult.textContent = 'Capture failed: ' + error.message;
                    matchResult.className = 'hint error';
                });
            });
            captureCompareLrnBtn.addEventListener('click', function () {
                readLrnFromCamera().catch(function (error) {
                    addLog('Unable to read LRN from camera: ' + error.message, true);
                });
            });
            verifyManualLrnBtn.addEventListener('click', function () {
                verifyLrnValue(manualLrnInput.value, 'manual input');
            });
            markAttendanceBtn.addEventListener('click', function () {
                markAttendanceNow();
            });
            reloadFacesBtn.addEventListener('click', function () {
                loadKnownFaces().catch(function (error) {
                    statusLabel.textContent = 'Reload failed: ' + error.message;
                });
            });
            sectionSelect.addEventListener('change', function () {
                matcher = null;
                knownById = {};
                lastMatchedStudentId = '';
                lastMatchedDistance = null;
                lastFaceMatchedAt = 0;
                clearLrnConfirmation();
                matchResult.textContent = 'Section changed. Capture again to compare with updated saved faces.';
                matchResult.className = 'hint';
                loadKnownFaces().catch(function (error) {
                    statusLabel.textContent = 'Reload failed: ' + error.message;
                });
            });
            manualLrnInput.addEventListener('input', function () {
                manualLrnInput.value = String(manualLrnInput.value || '').replace(/\D+/g, '').slice(0, 12);
            });
        })();
    </script>
</body>
</html>
