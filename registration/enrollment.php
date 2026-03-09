<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit();
}

$message = '';
$error = '';
$blocked_sections = ['ICT 11-D', 'Grade 6-B'];

function ensure_students_lrn_schema(mysqli $conn): bool
{
    $col_check = $conn->query("SHOW COLUMNS FROM students LIKE 'lrn'");
    $has_lrn_column = $col_check && $col_check->num_rows > 0;
    if (!$has_lrn_column) {
        if (!$conn->query('ALTER TABLE students ADD COLUMN lrn VARCHAR(12) NULL')) {
            return false;
        }
    }

    $index_check = $conn->query("SHOW INDEX FROM students WHERE Key_name = 'uq_students_lrn'");
    $has_unique_index = $index_check && $index_check->num_rows > 0;
    if ($has_unique_index) {
        return true;
    }

    if (!$conn->query('ALTER TABLE students ADD UNIQUE KEY uq_students_lrn (lrn)')) {
        return false;
    }

    return true;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$lrn_ready = ensure_students_lrn_schema($conn);
if (!$lrn_ready) {
    $error = 'Failed to initialize student LRN column.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_students'], $_POST['csrf_token'])) {
    $csrf_token = (string)$_POST['csrf_token'];
    if (!hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $error = 'Invalid request token.';
    } elseif (!$lrn_ready) {
        $error = 'Cannot import students until the LRN column is available.';
    } elseif (!isset($_FILES['students_file']) || !is_array($_FILES['students_file'])) {
        $error = 'Please choose a file to import.';
    } else {
        $upload = $_FILES['students_file'];

        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'File upload failed. Please try again.';
        } else {
            $original_name = (string)($upload['name'] ?? '');
            $tmp_path = (string)($upload['tmp_name'] ?? '');
            $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

            if ($tmp_path === '' || !is_uploaded_file($tmp_path)) {
                $error = 'Invalid uploaded file.';
            } elseif ($ext !== 'txt' && $ext !== 'csv') {
                $error = 'Only .txt or .csv files are allowed.';
            } else {
                $lines = file($tmp_path, FILE_IGNORE_NEW_LINES);
                if ($lines === false || count($lines) === 0) {
                    $error = 'The selected file is empty or unreadable.';
                } else {
                    $check_stmt = $conn->prepare('SELECT id FROM students WHERE name = ? AND class_section = ? LIMIT 1');
                    $check_lrn_stmt = $conn->prepare('SELECT id FROM students WHERE lrn = ? LIMIT 1');
                    $insert_stmt = $conn->prepare('INSERT INTO students (name, class_section, lrn) VALUES (?, ?, ?)');

                    if (!$check_stmt || !$check_lrn_stmt || !$insert_stmt) {
                        $error = 'Failed to prepare enrollment queries.';
                    } else {
                        $inserted = 0;
                        $duplicates = 0;
                        $invalid = 0;

                        foreach ($lines as $line) {
                            $line = trim((string)$line);
                            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
                            if ($line === '' || strpos($line, '#') === 0) {
                                continue;
                            }

                            $parts = preg_split('/\s*[,|\t]\s*/', $line);
                            if (!is_array($parts) || count($parts) < 2) {
                                $invalid++;
                                continue;
                            }

                            $student_name = trim((string)$parts[0]);
                            $class_section = trim((string)$parts[1]);
                            $lrn = isset($parts[2]) ? preg_replace('/\D+/', '', (string)$parts[2]) : '';
                            if ($lrn === null) {
                                $lrn = '';
                            }

                            $is_header_row = strcasecmp($student_name, 'student name') === 0
                                && (strcasecmp($class_section, 'class section') === 0 || strcasecmp($class_section, 'section') === 0);
                            if ($is_header_row) {
                                continue;
                            }

                            if ($student_name === '' || $class_section === '' || in_array($class_section, $blocked_sections, true)) {
                                $invalid++;
                                continue;
                            }
                            if ($lrn !== '' && preg_match('/^\d{12}$/', $lrn) !== 1) {
                                $invalid++;
                                continue;
                            }

                            $check_stmt->bind_param('ss', $student_name, $class_section);
                            $check_stmt->execute();
                            $check_stmt->store_result();

                            if ($check_stmt->num_rows > 0) {
                                $duplicates++;
                                continue;
                            }

                            if ($lrn !== '') {
                                $check_lrn_stmt->bind_param('s', $lrn);
                                $check_lrn_stmt->execute();
                                $check_lrn_stmt->store_result();
                                if ($check_lrn_stmt->num_rows > 0) {
                                    $duplicates++;
                                    continue;
                                }
                            }

                            $insert_stmt->bind_param('sss', $student_name, $class_section, $lrn);
                            if ($insert_stmt->execute()) {
                                $inserted++;
                            }
                        }

                        if ($inserted === 0 && $duplicates === 0 && $invalid > 0) {
                            $error = 'No students were imported. Check file format.';
                        } else {
                            $message = "Import complete: $inserted added, $duplicates skipped duplicate(s), $invalid invalid row(s).";
                        }
                    }
                }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_students'])) {
    $error = 'Invalid request token.';
}

$students = [];
$students_result = $conn->query("SELECT id, name, class_section, COALESCE(lrn, '') AS lrn FROM students WHERE class_section NOT IN ('ICT 11-D', 'Grade 6-B') ORDER BY class_section ASC, name ASC");
if ($students_result) {
    while ($row = $students_result->fetch_assoc()) {
        $students[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enrollment - Attendance System</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include __DIR__ . '/atlas_brand.php'; ?>
    <div class="container">
        <h1>Student Enrollment</h1>
        <div class="top-links action-links">
            <span class="welcome-chip">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <a class="btn btn-soft" href="admin_dashboard.php">Admin Dashboard</a>
            <a class="btn btn-soft" href="admin_view_attendance.php">View Attendance</a>
            <a class="btn btn-soft" href="reports.php">Reports</a>
            <a class="btn btn-soft" href="export_docs.php">Export DOC</a>
            <a class="btn btn-danger" href="logout.php">Logout</a>
        </div>

        <?php if ($message !== ''): ?>
            <p class="message info"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <p class="message error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <h2>Import Students From File</h2>
        <p>Upload a <b>.txt</b> or <b>.csv</b> file. Each line must be: <b>Student Name,Class Section,LRN</b></p>
        <p>Example: <b>Juan Dela Cruz,Grade 7-A,123456789012</b></p>
        <p>LRN is optional in import, but when provided it must be exactly 12 digits.</p>

        <form method="POST" action="enrollment.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="input-group">
                <label for="students_file">Choose File</label>
                <input type="file" id="students_file" name="students_file" accept=".txt,.csv" required>
            </div>
            <button type="submit" name="import_students">Import Students</button>
        </form>

        <h2>Enrolled Students</h2>
        <div class="table-wrap">
            <table border="1" cellpadding="10" cellspacing="0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Student Name</th>
                        <th>Class Section</th>
                        <th>LRN</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($students) > 0): ?>
                        <?php foreach ($students as $student): ?>
                            <tr>
                                <td><?php echo (int)$student['id']; ?></td>
                                <td><?php echo htmlspecialchars($student['name']); ?></td>
                                <td><?php echo htmlspecialchars($student['class_section']); ?></td>
                                <td><?php echo htmlspecialchars($student['lrn']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4">No students enrolled yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>

