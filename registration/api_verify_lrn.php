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

$lrn_value = preg_replace('/\D+/', '', (string)($json['lrn_value'] ?? ''));
if (!is_string($lrn_value) || preg_match('/^\d{12}$/', $lrn_value) !== 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Valid 12-digit LRN is required']);
    exit();
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

if (!ensure_students_lrn_schema($conn)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to initialize LRN column']);
    exit();
}

$student_stmt = $conn->prepare('SELECT id, name, class_section, lrn FROM students WHERE lrn = ? LIMIT 1');
if (!$student_stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare LRN verification query']);
    exit();
}

$student_stmt->bind_param('s', $lrn_value);
$student_stmt->execute();
$student_result = $student_stmt->get_result();

if (!$student_result || $student_result->num_rows !== 1) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'LRN is not registered for any student']);
    exit();
}

$student = $student_result->fetch_assoc();
$student_id = (int)$student['id'];
$confirmation_token = bin2hex(random_bytes(16));

$_SESSION['lrn_confirmation'] = [
    'student_id' => $student_id,
    'token' => $confirmation_token,
    'confirmed_at' => time()
];

echo json_encode([
    'success' => true,
    'message' => 'LRN confirmed',
    'student' => [
        'id' => (int)$student['id'],
        'name' => (string)$student['name'],
        'class_section' => (string)$student['class_section'],
        'lrn' => (string)$student['lrn']
    ],
    'lrn_confirmation_token' => $confirmation_token,
    'expires_in_seconds' => 180
]);
exit();
?>
