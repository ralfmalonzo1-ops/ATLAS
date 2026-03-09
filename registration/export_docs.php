<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$class_section = trim($_GET['class_section'] ?? '');
$teacher = trim($_GET['teacher'] ?? '');

$conditions = [];

if ($start_date !== '') {
    $start_obj = DateTime::createFromFormat('Y-m-d', $start_date);
    if ($start_obj && $start_obj->format('Y-m-d') === $start_date) {
        $safe_start = $conn->real_escape_string($start_date);
        $conditions[] = "a.attendance_date >= '$safe_start'";
    } else {
        $start_date = '';
    }
}

if ($end_date !== '') {
    $end_obj = DateTime::createFromFormat('Y-m-d', $end_date);
    if ($end_obj && $end_obj->format('Y-m-d') === $end_date) {
        $safe_end = $conn->real_escape_string($end_date);
        $conditions[] = "a.attendance_date <= '$safe_end'";
    } else {
        $end_date = '';
    }
}

if ($class_section !== '') {
    $safe_class = $conn->real_escape_string($class_section);
    $conditions[] = "s.class_section = '$safe_class'";
}

if ($role === 'admin' && $teacher !== '') {
    $safe_teacher = $conn->real_escape_string($teacher);
    $conditions[] = "u.username = '$safe_teacher'";
}

if ($role !== 'admin') {
    $conditions[] = "a.marked_by = $user_id";
}

$where_sql = '';
if (count($conditions) > 0) {
    $where_sql = ' WHERE ' . implode(' AND ', $conditions);
}

$sql = 'SELECT a.attendance_date, s.name AS student_name, s.class_section, a.status, COALESCE(u.username, "N/A") AS marked_by
        FROM attendance a
        INNER JOIN students s ON s.id = a.student_id
        LEFT JOIN users u ON u.id = a.marked_by'
        . $where_sql .
       ' ORDER BY a.attendance_date DESC, s.class_section ASC, s.name ASC';

$result = $conn->query($sql);

header('Content-Type: application/msword; charset=utf-8');
header('Content-Disposition: attachment; filename=attendance_report_' . date('Ymd_His') . '.doc');

$report_title = 'Attendance Report';
$filter_parts = [];
if ($start_date !== '') {
    $filter_parts[] = 'Start Date: ' . $start_date;
}
if ($end_date !== '') {
    $filter_parts[] = 'End Date: ' . $end_date;
}
if ($class_section !== '') {
    $filter_parts[] = 'Class/Section: ' . $class_section;
}
if ($role === 'admin' && $teacher !== '') {
    $filter_parts[] = 'Marked By: ' . $teacher;
}
if (count($filter_parts) === 0) {
    $filter_parts[] = 'Filters: None';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($report_title); ?></title>
    <style>
        body { font-family: Arial, sans-serif; color: #1f2933; }
        h1 { margin: 0 0 8px; color: #174e5c; }
        .meta { margin: 0 0 14px; font-size: 12px; }
        .filters { margin: 0 0 16px; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #c8d1d8; padding: 8px; font-size: 12px; text-align: left; }
        th { background: #edf6f8; color: #174e5c; }
        .table-wrap { width: 100%; overflow-x: auto; }
    </style>
</head>
<body>
    <h1><?php echo htmlspecialchars($report_title); ?></h1>
    <p class="meta">Generated on: <?php echo htmlspecialchars(date('Y-m-d H:i:s')); ?></p>
    <p class="filters"><?php echo htmlspecialchars(implode(' | ', $filter_parts)); ?></p>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Student Name</th>
                    <th>Class/Section</th>
                    <th>Status</th>
                    <th>Marked By</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)$row['attendance_date']); ?></td>
                            <td><?php echo htmlspecialchars((string)$row['student_name']); ?></td>
                            <td><?php echo htmlspecialchars((string)$row['class_section']); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst((string)$row['status'])); ?></td>
                            <td><?php echo htmlspecialchars((string)$row['marked_by']); ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">No records found for the selected filters.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
