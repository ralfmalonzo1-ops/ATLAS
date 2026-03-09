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

function ensure_students_lrn_schema(mysqli $conn): bool
{
    $col_check = $conn->query("SHOW COLUMNS FROM students LIKE 'lrn'");
    if ($col_check && $col_check->num_rows > 0) {
        return true;
    }

    if (!$conn->query('ALTER TABLE students ADD COLUMN lrn VARCHAR(12) NULL')) {
        return false;
    }

    $conn->query('ALTER TABLE students ADD UNIQUE KEY uq_students_lrn (lrn)');
    return true;
}

$lrn_ready = ensure_students_lrn_schema($conn);
$message = '';
$error = '';

$section_options = [];
$sections_result = $conn->query('SELECT DISTINCT class_section FROM students WHERE class_section IS NOT NULL AND class_section <> "" ORDER BY class_section ASC');
if ($sections_result) {
    while ($row = $sections_result->fetch_assoc()) {
        $section_options[] = (string)$row['class_section'];
    }
}

$selected_section = trim((string)($_POST['selected_section'] ?? $_GET['section'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_lrn'])) {
    $csrf_token = trim((string)($_POST['csrf_token'] ?? ''));
    $student_id_raw = trim((string)($_POST['student_id'] ?? ''));
    $lrn_value = preg_replace('/\D+/', '', (string)($_POST['lrn'] ?? ''));
    if (!is_string($lrn_value)) {
        $lrn_value = '';
    }

    if (!$lrn_ready) {
        $error = 'LRN column is unavailable.';
    } elseif (!hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $error = 'Invalid request token.';
    } elseif ($student_id_raw === '' || !ctype_digit($student_id_raw)) {
        $error = 'Select a valid student.';
    } elseif (preg_match('/^\\d{12}$/', $lrn_value) !== 1) {
        $error = 'LRN must be exactly 12 digits.';
    } else {
        $student_id = (int)$student_id_raw;
        $dupe_stmt = $conn->prepare('SELECT id FROM students WHERE lrn = ? AND id <> ? LIMIT 1');
        if ($dupe_stmt) {
            $dupe_stmt->bind_param('si', $lrn_value, $student_id);
            $dupe_stmt->execute();
            $dupe_stmt->store_result();
            if ($dupe_stmt->num_rows > 0) {
                $error = 'This LRN is already assigned to another student.';
            }
        }

        if ($error === '') {
            $update_stmt = $conn->prepare('UPDATE students SET lrn = ? WHERE id = ? LIMIT 1');
            if ($update_stmt) {
                $update_stmt->bind_param('si', $lrn_value, $student_id);
                if ($update_stmt->execute() && $update_stmt->affected_rows >= 0) {
                    $message = 'LRN saved successfully.';
                } else {
                    $error = 'Failed to save LRN.';
                }
            } else {
                $error = 'Failed to prepare LRN update.';
            }
        }
    }
}

$students = [];
if ($selected_section !== '') {
    $stmt = $conn->prepare('SELECT id, name, class_section, COALESCE(lrn, "") AS lrn FROM students WHERE class_section = ? ORDER BY name ASC');
    if ($stmt) {
        $stmt->bind_param('s', $selected_section);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $students[] = $row;
            }
        }
    }
}

$registered_faces = [];
$faces_result = false;
$table_check = $conn->query("SHOW TABLES LIKE 'captured_images'");
if ($table_check && $table_check->num_rows > 0) {
    $updated_col_check = $conn->query("SHOW COLUMNS FROM captured_images LIKE 'updated_at'");
    $has_updated_col = $updated_col_check && $updated_col_check->num_rows > 0;

    $faces_sql = 'SELECT s.id, s.name, s.class_section, COALESCE(s.lrn, "") AS lrn, ';
    if ($has_updated_col) {
        $faces_sql .= 'ci.updated_at';
    } else {
        $faces_sql .= "'' AS updated_at";
    }
    $faces_sql .= ' FROM captured_images ci INNER JOIN students s ON s.id = ci.student_id';

    if ($selected_section !== '') {
        $faces_sql .= ' WHERE s.class_section = ?';
        $faces_sql .= ' ORDER BY s.name ASC';
        $faces_stmt = $conn->prepare($faces_sql);
        if ($faces_stmt) {
            $faces_stmt->bind_param('s', $selected_section);
            $faces_stmt->execute();
            $faces_result = $faces_stmt->get_result();
        }
    } else {
        $faces_sql .= ' ORDER BY s.class_section ASC, s.name ASC';
        $faces_result = $conn->query($faces_sql);
    }
}
if ($faces_result) {
    while ($row = $faces_result->fetch_assoc()) {
        $registered_faces[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face + LRN Registration</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include __DIR__ . '/atlas_brand.php'; ?>
    <div class="container">
        <h1>Face + LRN Registration</h1>
        <div class="top-links action-links">
            <a class="btn btn-soft" href="isolated_scanner.php">Back to Scanner Dashboard</a>
            <a class="btn btn-soft" href="face_scanner.php">Face Compare</a>
            <a class="btn btn-danger" href="logout.php">Logout</a>
        </div>

        <?php if (!$lrn_ready): ?>
            <p class="message error">Failed to initialize LRN database column.</p>
        <?php endif; ?>
        <?php if ($message !== ''): ?>
            <p class="message success"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <p class="message error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <form method="GET" action="face_register.php" class="report-filters">
            <div class="filter-grid">
                <div class="input-group">
                    <label for="section">Class/Section</label>
                    <select id="section" name="section" required>
                        <option value="">Select Section</option>
                        <?php foreach ($section_options as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>" <?php echo ($selected_section === $option) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit">Load Students</button>
        </form>

        <div class="scanner-grid">
            <div class="scanner-panel">
                <h3>Camera Preview</h3>
                <video id="faceVideo" class="scanner-video" autoplay muted playsinline></video>
                <canvas id="faceCanvas" class="scanner-canvas"></canvas>
                <p id="cameraStatus" class="hint">Camera not started.</p>
                <div class="top-links">
                    <button type="button" id="startCameraBtn">Start Camera</button>
                    <button type="button" id="captureFaceBtn" class="btn btn-success">Capture & Save Face</button>
                </div>
            </div>

            <div class="scanner-panel">
                <h3>Student Assignment</h3>
                <div class="input-group">
                    <label for="studentId">Student</label>
                    <select id="studentId" <?php echo (count($students) === 0) ? 'disabled' : ''; ?>>
                        <option value="">Select Student</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?php echo (int)$student['id']; ?>">
                                <?php echo htmlspecialchars($student['name'] . ' (' . $student['class_section'] . ') - LRN: ' . (($student['lrn'] !== '') ? $student['lrn'] : 'Not set')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <p class="hint">Tip: face should be centered and clearly visible before capture.</p>
                <p id="faceRegisterResult" class="hint"></p>
            </div>

            <div class="scanner-panel">
                <h3>LRN Registration</h3>
                <form method="POST" action="face_register.php<?php echo ($selected_section !== '') ? '?section=' . urlencode($selected_section) : ''; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="selected_section" value="<?php echo htmlspecialchars($selected_section); ?>">
                    <div class="input-group">
                        <label for="student_id">Student</label>
                        <select id="student_id" name="student_id" <?php echo (count($students) === 0) ? 'disabled' : ''; ?> required>
                            <option value="">Select Student</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?php echo (int)$student['id']; ?>">
                                    <?php echo htmlspecialchars($student['name'] . ' (' . $student['class_section'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group">
                        <label for="lrn">LRN (12 digits)</label>
                        <input type="text" id="lrn" name="lrn" maxlength="12" inputmode="numeric" pattern="\d{12}" placeholder="123456789012" required>
                    </div>
                    <button type="submit" name="save_lrn" class="btn btn-soft">Save LRN</button>
                </form>
                <p class="hint">Set one unique 12-digit LRN per student.</p>
            </div>
        </div>

        <h2>Registered Face Profiles</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Class/Section</th>
                        <th>LRN</th>
                        <th>Last Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($registered_faces) > 0): ?>
                        <?php foreach ($registered_faces as $face_row): ?>
                            <tr>
                                <td><?php echo (int)$face_row['id']; ?></td>
                                <td><?php echo htmlspecialchars((string)$face_row['name']); ?></td>
                                <td><?php echo htmlspecialchars((string)$face_row['class_section']); ?></td>
                                <td><?php echo htmlspecialchars((string)$face_row['lrn']); ?></td>
                                <td><?php echo htmlspecialchars((string)$face_row['updated_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5">No registered face profiles found<?php echo ($selected_section !== '') ? ' for this section' : ''; ?>.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($selected_section !== ''): ?>
            <div class="spacer-md"></div>
            <h2>LRN Registry (<?php echo htmlspecialchars($selected_section); ?>)</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Class/Section</th>
                            <th>LRN</th>
                            <th>LRN Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($students) > 0): ?>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td><?php echo (int)$student['id']; ?></td>
                                    <td><?php echo htmlspecialchars((string)$student['name']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$student['class_section']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$student['lrn']); ?></td>
                                    <td><?php echo ((string)$student['lrn'] !== '') ? 'Registered' : 'Missing'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5">No students found for this section.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="hint spacer-sm">Set missing LRNs in Student Enrollment (admin page). LRN must be exactly 12 digits.</p>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <script>
        (function () {
            const MODEL_URL = 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights';
            const csrfToken = <?php echo json_encode($_SESSION['csrf_token']); ?>;
            const video = document.getElementById('faceVideo');
            const canvas = document.getElementById('faceCanvas');
            const startCameraBtn = document.getElementById('startCameraBtn');
            const captureFaceBtn = document.getElementById('captureFaceBtn');
            const studentId = document.getElementById('studentId');
            const result = document.getElementById('faceRegisterResult');
            const cameraStatus = document.getElementById('cameraStatus');
            let modelsLoaded = false;
            let cameraReady = false;
            let trackerRunning = false;

            async function loadModels() {
                if (modelsLoaded) return;
                cameraStatus.textContent = 'Loading face models...';
                await Promise.all([
                    faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
                    faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
                    faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL)
                ]);
                modelsLoaded = true;
                cameraStatus.textContent = 'Face models loaded.';
            }

            async function startCamera() {
                try {
                    await loadModels();
                    const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
                    video.srcObject = stream;
                    await new Promise(function (resolve) {
                        if (video.readyState >= 2) {
                            resolve();
                            return;
                        }
                        video.onloadedmetadata = function () {
                            resolve();
                        };
                    });

                    if (typeof video.play === 'function') {
                        await video.play();
                    }

                    cameraReady = true;
                    const vw = video.videoWidth || 640;
                    const vh = video.videoHeight || 480;
                    canvas.width = vw;
                    canvas.height = vh;
                    cameraStatus.textContent = 'Camera is active.';
                    startTracker();
                } catch (error) {
                    cameraReady = false;
                    cameraStatus.textContent = 'Failed to start camera: ' + error.message;
                }
            }

            async function startTracker() {
                if (trackerRunning) return;
                trackerRunning = true;
                while (trackerRunning && cameraReady && video.srcObject) {
                    const detection = await faceapi
                        .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
                        .withFaceLandmarks();

                    const ctx = canvas.getContext('2d');
                    const displaySize = { width: video.videoWidth || 640, height: video.videoHeight || 480 };
                    faceapi.matchDimensions(canvas, displaySize);
                    canvas.width = displaySize.width;
                    canvas.height = displaySize.height;
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

                    if (detection) {
                        const resized = faceapi.resizeResults(detection, displaySize);
                        faceapi.draw.drawDetections(canvas, resized);
                        cameraStatus.textContent = 'AI tracker: face detected and aligned.';
                        cameraStatus.className = 'hint success';
                    } else {
                        cameraStatus.textContent = 'AI tracker: no face detected. Center your face in frame.';
                        cameraStatus.className = 'hint error';
                    }

                    await new Promise(function (resolve) { setTimeout(resolve, 220); });
                }
            }

            async function captureAndSaveFace() {
                if (!modelsLoaded) {
                    result.textContent = 'Please start camera first.';
                    result.className = 'hint error';
                    return;
                }

                if (!cameraReady || !video.srcObject || video.videoWidth === 0 || video.videoHeight === 0) {
                    result.textContent = 'Camera is not ready yet. Click Start Camera and try again.';
                    result.className = 'hint error';
                    return;
                }

                if (!studentId.value) {
                    result.textContent = 'Please select a student.';
                    result.className = 'hint error';
                    return;
                }

                const detection = await faceapi
                    .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
                    .withFaceLandmarks()
                    .withFaceDescriptor();

                if (!detection) {
                    result.textContent = 'No face detected. Please try again.';
                    result.className = 'hint error';
                    return;
                }

                const displaySize = { width: video.videoWidth, height: video.videoHeight };
                faceapi.matchDimensions(canvas, displaySize);
                const resized = faceapi.resizeResults(detection, displaySize);
                const ctx = canvas.getContext('2d');
                canvas.width = displaySize.width;
                canvas.height = displaySize.height;
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                faceapi.draw.drawDetections(canvas, resized);

                const payload = {
                    csrf_token: csrfToken,
                    student_id: studentId.value,
                    descriptor: Array.from(detection.descriptor),
                    captured_image: canvas.toDataURL('image/jpeg', 0.85)
                };

                try {
                    const response = await fetch('save_face_data.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const json = await response.json();
                    if (json.success) {
                        const studentText = json.student
                            ? (json.student.name + ' (' + json.student.class_section + ')')
                            : 'selected student';
                        result.textContent = 'Face linked and saved for ' + studentText + '.';
                        result.className = 'hint success';
                    } else {
                        result.textContent = json.message || 'Failed to save face data.';
                        result.className = 'hint error';
                    }
                } catch (error) {
                    result.textContent = 'Request failed: ' + error.message;
                    result.className = 'hint error';
                }
            }

            startCameraBtn.addEventListener('click', startCamera);
            captureFaceBtn.addEventListener('click', captureAndSaveFace);
            window.addEventListener('beforeunload', function () {
                trackerRunning = false;
            });
        })();
    </script>
</body>
</html>
