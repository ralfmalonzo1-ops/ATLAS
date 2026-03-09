<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit();
}

$attendance_date = $_GET['attendance_date'] ?? '';
$class_section = trim($_GET['class_section'] ?? '');
$teacher = trim($_GET['teacher'] ?? '');

$conditions = [];

if ($attendance_date !== '') {
    $date_obj = DateTime::createFromFormat('Y-m-d', $attendance_date);
    if ($date_obj && $date_obj->format('Y-m-d') === $attendance_date) {
        $safe_date = $conn->real_escape_string($attendance_date);
        $conditions[] = "a.attendance_date = '$safe_date'";
    }
}

if ($class_section !== '') {
    $safe_class = $conn->real_escape_string($class_section);
    $conditions[] = "s.class_section = '$safe_class'";
}

if ($teacher !== '') {
    $safe_teacher = $conn->real_escape_string($teacher);
    $conditions[] = "u.username = '$safe_teacher'";
}

$where_sql = '';
if (count($conditions) > 0) {
    $where_sql = ' WHERE ' . implode(' AND ', $conditions);
}

$sql = 'SELECT a.attendance_date, s.name AS student_name, s.class_section, a.status, u.username AS marked_by
        FROM attendance a
        INNER JOIN students s ON s.id = a.student_id
        LEFT JOIN users u ON u.id = a.marked_by'
        . $where_sql .
       ' ORDER BY a.attendance_date DESC, s.name ASC';

$result = $conn->query($sql);
$rows = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - View Attendance</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include __DIR__ . '/atlas_brand.php'; ?>
    <div class="container">
        <h1>Attendance Records</h1>
        <div class="top-links action-links">
            <a class="btn btn-soft" href="admin_dashboard.php">Back to Admin Dashboard</a>
            <a class="btn btn-danger" href="logout.php">Logout</a>
        </div>

        <form method="GET" action="admin_view_attendance.php">
            <div class="input-group">
                <label for="attendance_date">Date</label>
                <input type="date" id="attendance_date" name="attendance_date" value="<?php echo htmlspecialchars($attendance_date); ?>">
            </div>
            <div class="input-group">
                <label for="class_section">Class/Section</label>
                <input type="text" id="class_section" name="class_section" value="<?php echo htmlspecialchars($class_section); ?>" placeholder="Example: Grade 7-A">
            </div>
            <div class="input-group">
                <label for="teacher">Teacher Name</label>
                <input type="text" id="teacher" name="teacher" value="<?php echo htmlspecialchars($teacher); ?>" placeholder="Teacher display name">
            </div>
            <button type="submit">Filter</button>
        </form>

        <div class="table-wrap">
            <table border="1" cellpadding="10" cellspacing="0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Student</th>
                        <th>Class/Section</th>
                        <th>Status</th>
                        <th>Marked By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($rows) > 0): ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['attendance_date']); ?></td>
                                <td><?php echo htmlspecialchars($row['student_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['class_section']); ?></td>
                                <td><?php echo htmlspecialchars($row['status']); ?></td>
                                <td><?php echo htmlspecialchars((string)$row['marked_by']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5">No attendance records found for the selected filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>

