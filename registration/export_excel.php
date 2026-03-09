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
    }
}

if ($end_date !== '') {
    $end_obj = DateTime::createFromFormat('Y-m-d', $end_date);
    if ($end_obj && $end_obj->format('Y-m-d') === $end_date) {
        $safe_end = $conn->real_escape_string($end_date);
        $conditions[] = "a.attendance_date <= '$safe_end'";
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

$sql = 'SELECT a.attendance_date, s.name AS student_name, s.class_section, a.status, u.username AS marked_by
        FROM attendance a
        INNER JOIN students s ON s.id = a.student_id
        LEFT JOIN users u ON u.id = a.marked_by'
        . $where_sql .
       ' ORDER BY a.attendance_date DESC, s.name ASC';

$result = $conn->query($sql);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=attendance_export_' . date('Ymd_His') . '.csv');

$output = fopen('php://output', 'w');

fputcsv($output, ['Date', 'Student Name', 'Class/Section', 'Status', 'Marked By']);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['attendance_date'],
            $row['student_name'],
            $row['class_section'],
            $row['status'],
            $row['marked_by']
        ]);
    }
}

fclose($output);
exit();
?>
