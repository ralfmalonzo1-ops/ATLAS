<?php
session_start();
require 'config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$role = $_SESSION['role'] ?? '';
if ($role !== 'teacher' && $role !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Session token missing']);
    exit();
}

$raw = file_get_contents('php://input');
$json = json_decode((string)$raw, true);
if (!is_array($json)) {
    $json = $_POST;
}

$csrf_token = trim((string)($json['csrf_token'] ?? ''));
if (!hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request token']);
    exit();
}

$student_id_value = trim((string)($json['student_id'] ?? ''));
$attendance_date = trim((string)($json['attendance_date'] ?? date('Y-m-d')));
$status = strtolower(trim((string)($json['status'] ?? 'present')));
$lrn_confirmation_token = trim((string)($json['lrn_confirmation_token'] ?? ''));

if ($status !== 'present' && $status !== 'absent') {
    $status = 'present';
}

$date_obj = DateTime::createFromFormat('Y-m-d', $attendance_date);
if (!$date_obj || $date_obj->format('Y-m-d') !== $attendance_date) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid attendance date']);
    exit();
}

$student_id = 0;
if ($student_id_value !== '' && ctype_digit($student_id_value)) {
    $student_id = (int)$student_id_value;
}

if ($student_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unable to read a valid student ID from face match']);
    exit();
}

$lrn_confirmation = $_SESSION['lrn_confirmation'] ?? null;
if (!is_array($lrn_confirmation)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'LRN confirmation required before marking attendance']);
    exit();
}

$confirmed_student_id = (int)($lrn_confirmation['student_id'] ?? 0);
$confirmed_token = (string)($lrn_confirmation['token'] ?? '');
$confirmed_at = (int)($lrn_confirmation['confirmed_at'] ?? 0);
$confirmation_is_fresh = $confirmed_at > 0 && (time() - $confirmed_at) <= 180;

if (!$confirmation_is_fresh) {
    unset($_SESSION['lrn_confirmation']);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'LRN confirmation expired. Show LRN again.']);
    exit();
}

if ($confirmed_student_id !== $student_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'LRN confirmation does not match face/student ID']);
    exit();
}

if ($lrn_confirmation_token === '' || !hash_equals($confirmed_token, $lrn_confirmation_token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid LRN confirmation token']);
    exit();
}

$student_stmt = $conn->prepare('SELECT id, name, class_section FROM students WHERE id = ? LIMIT 1');
$student_stmt->bind_param('i', $student_id);
$student_stmt->execute();
$student_result = $student_stmt->get_result();

if (!$student_result || $student_result->num_rows !== 1) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Student not found']);
    exit();
}

$student = $student_result->fetch_assoc();

$check_stmt = $conn->prepare('SELECT id FROM attendance WHERE student_id = ? AND attendance_date = ? LIMIT 1');
$check_stmt->bind_param('is', $student_id, $attendance_date);
$check_stmt->execute();
$check_stmt->store_result();
if ($check_stmt->num_rows > 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Attendance already marked for this student on this date',
        'student' => [
            'id' => (int)$student['id'],
            'name' => (string)$student['name'],
            'class_section' => (string)$student['class_section']
        ]
    ]);
    exit();
}

$marked_by = (int)$_SESSION['user_id'];
$insert_stmt = $conn->prepare('INSERT INTO attendance (student_id, attendance_date, status, marked_by) VALUES (?, ?, ?, ?)');
$insert_stmt->bind_param('issi', $student_id, $attendance_date, $status, $marked_by);

if (!$insert_stmt->execute()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save attendance']);
    exit();
}

unset($_SESSION['lrn_confirmation']);

echo json_encode([
    'success' => true,
    'message' => 'Attendance saved',
    'student' => [
        'id' => (int)$student['id'],
        'name' => (string)$student['name'],
        'class_section' => (string)$student['class_section']
    ],
    'attendance_date' => $attendance_date,
    'status' => $status
]);
exit();
?>
