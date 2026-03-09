<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher') {
    header('Location: login.php');
    exit();
}

$teacher_id = (int)$_SESSION['user_id'];
$message = '';
$error = '';
$selected_section = trim($_GET['section'] ?? '');
$active_attendance_date = $_POST['attendance_date'] ?? ($_GET['attendance_date'] ?? date('Y-m-d'));
$blocked_sections = ['ICT 11-D', 'Grade 6-B'];

if (in_array($selected_section, $blocked_sections, true)) {
    $selected_section = '';
}

$date_obj = DateTime::createFromFormat('Y-m-d', $active_attendance_date);
if (!$date_obj || $date_obj->format('Y-m-d') !== $active_attendance_date) {
    $active_attendance_date = date('Y-m-d');
}

$default_sections = [
    'Grade 7-A', 'Grade 7-B', 'Grade 7-C', 'Grade 7-D',
    'Grade 8-A', 'Grade 8-B', 'Grade 8-C', 'Grade 8-D',
    'Grade 9-A', 'Grade 9-B', 'Grade 9-C', 'Grade 9-D',
    'Grade 10-A', 'Grade 10-B', 'Grade 10-C', 'Grade 10-D',
    'Grade 11-A', 'Grade 11-B', 'Grade 11-C', 'Grade 11-D',
    'Grade 12-A', 'Grade 12-B', 'Grade 12-C', 'Grade 12-D'
];

$section_options = $default_sections;
$sections_result = $conn->query('SELECT DISTINCT class_section FROM students WHERE class_section IS NOT NULL AND class_section <> "" ORDER BY class_section ASC');
if ($sections_result) {
    while ($section_row = $sections_result->fetch_assoc()) {
        $section_name = trim((string)$section_row['class_section']);
        if ($section_name !== '' && !in_array($section_name, $blocked_sections, true) && !in_array($section_name, $section_options, true)) {
            $section_options[] = $section_name;
        }
    }
}
usort($section_options, function ($a, $b) {
    $pattern = '/^Grade\s+(\d+)-([A-Za-z])$/';
    $match_a = [];
    $match_b = [];

    $has_a = preg_match($pattern, $a, $match_a) === 1;
    $has_b = preg_match($pattern, $b, $match_b) === 1;

    if ($has_a && $has_b) {
        $grade_compare = ((int)$match_a[1]) <=> ((int)$match_b[1]);
        if ($grade_compare !== 0) {
            return $grade_compare;
        }
        return strcmp(strtoupper($match_a[2]), strtoupper($match_b[2]));
    }

    if ($has_a) {
        return -1;
    }

    if ($has_b) {
        return 1;
    }

    return strcasecmp($a, $b);
});

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attendance'])) {
    $attendance_date = $active_attendance_date;
    $attendance_data = $_POST['status'] ?? [];
    $selected_section = trim($_POST['selected_section'] ?? '');

    $post_date_obj = DateTime::createFromFormat('Y-m-d', $attendance_date);
    $valid_date = $post_date_obj && $post_date_obj->format('Y-m-d') === $attendance_date;

    if (!$valid_date) {
        $error = 'Invalid attendance date.';
    } elseif (in_array($selected_section, $blocked_sections, true)) {
        $error = 'Selected class section is not available.';
    } elseif ($selected_section === '') {
        $error = 'Please select a class section first.';
    } elseif (!is_array($attendance_data) || count($attendance_data) === 0) {
        $error = 'Please mark attendance for at least one student.';
    } else {
        $check_stmt = $conn->prepare('SELECT id FROM attendance WHERE student_id = ? AND attendance_date = ? LIMIT 1');
        $insert_stmt = $conn->prepare('INSERT INTO attendance (student_id, attendance_date, status, marked_by) VALUES (?, ?, ?, ?)');

        $success_count = 0;
        $duplicate_count = 0;

        foreach ($attendance_data as $student_id => $status) {
            if (!ctype_digit((string)$student_id)) {
                continue;
            }

            $student_id = (int)$student_id;
            if ($student_id <= 0 || ($status !== 'present' && $status !== 'absent')) {
                continue;
            }

            $check_stmt->bind_param('is', $student_id, $attendance_date);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows > 0) {
                $duplicate_count++;
                continue;
            }

            $insert_stmt->bind_param('issi', $student_id, $attendance_date, $status, $teacher_id);
            if ($insert_stmt->execute()) {
                $success_count++;
            }
        }

        $message = "Saved attendance: $success_count new record(s).";
        if ($duplicate_count > 0) {
            $message .= " Skipped $duplicate_count duplicate record(s).";
        }
    }
}

$students = [];
$students_stmt = null;
$attendance_map = [];
if ($selected_section !== '') {
    $students_stmt = $conn->prepare('SELECT id, name, class_section FROM students WHERE class_section = ? ORDER BY name ASC');
    $students_stmt->bind_param('s', $selected_section);
    $students_stmt->execute();
    $students_result = $students_stmt->get_result();
    if ($students_result) {
        while ($row = $students_result->fetch_assoc()) {
            $students[] = $row;
        }
    }

    $existing_stmt = $conn->prepare('SELECT a.student_id, a.status
        FROM attendance a
        INNER JOIN students s ON s.id = a.student_id
        WHERE s.class_section = ? AND a.attendance_date = ?');
    $existing_stmt->bind_param('ss', $selected_section, $active_attendance_date);
    $existing_stmt->execute();
    $existing_result = $existing_stmt->get_result();
    if ($existing_result) {
        while ($existing = $existing_result->fetch_assoc()) {
            $attendance_map[(int)$existing['student_id']] = (string)$existing['status'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - Attendance</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include __DIR__ . '/atlas_brand.php'; ?>
    <div class="container">
        <h1>Teacher Dashboard</h1>
        <div class="top-links action-links">
            <span class="welcome-chip">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
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

        <h2>Class Section</h2>
        <div class="top-links action-links">
            <a class="btn btn-soft" href="isolated_scanner.php">Scanner Dashboard</a>
        </div>

        <form method="GET" action="dashboard.php">
            <div class="input-group">
                <label for="section">Select Section</label>
                <select id="section" name="section" required>
                    <option value="">-- Choose Section --</option>
                    <?php foreach ($section_options as $section_option): ?>
                        <option value="<?php echo htmlspecialchars($section_option); ?>" <?php echo ($selected_section === $section_option) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($section_option); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="input-group">
                <label for="attendance_date_filter">Attendance Date</label>
                <input type="date" id="attendance_date_filter" name="attendance_date" value="<?php echo htmlspecialchars($active_attendance_date); ?>" required>
            </div>
            <button type="submit">Load Students</button>
        </form>

        <div class="spacer-md"></div>
        <h2>Attendance Entry</h2>
        <form method="POST" action="dashboard.php">
            <input type="hidden" name="selected_section" value="<?php echo htmlspecialchars($selected_section); ?>">
            <label for="attendance_date"><b>Date:</b></label>
            <input type="date" id="attendance_date" name="attendance_date" value="<?php echo htmlspecialchars($active_attendance_date); ?>" required>
            <div class="spacer-sm"></div>

            <div class="table-wrap">
                <table border="1" cellpadding="10" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Class/Section</th>
                            <th>Recorded Status</th>
                            <th>Mark Attendance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($students) > 0): ?>
                            <?php foreach ($students as $student): ?>
                                <?php
                                $student_id = (int)$student['id'];
                                $saved_status = $attendance_map[$student_id] ?? '';
                                $is_saved = ($saved_status === 'present' || $saved_status === 'absent');
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['class_section']); ?></td>
                                    <td>
                                        <?php if ($is_saved): ?>
                                            <span class="badge <?php echo ($saved_status === 'present') ? 'badge-present' : 'badge-absent'; ?>">
                                                <?php echo htmlspecialchars(ucfirst($saved_status)); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="hint">Not marked yet</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_saved): ?>
                                            <span class="hint">Locked for this day</span>
                                        <?php else: ?>
                                            <label>
                                                <input type="radio" name="status[<?php echo $student_id; ?>]" value="present"> Present
                                            </label>
                                            <label>
                                                <input type="radio" name="status[<?php echo $student_id; ?>]" value="absent"> Absent
                                            </label>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4">Select a section to load students, or no students are enrolled in this section.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="spacer-sm"></div>
            <button type="submit" name="submit_attendance">Save Attendance</button>
        </form>
    </div>
</body>
</html>


