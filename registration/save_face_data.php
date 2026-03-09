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
$descriptor = $json['descriptor'] ?? null;
$captured_image = trim((string)($json['captured_image'] ?? ''));

if ($student_id_value === '' || !ctype_digit($student_id_value)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
    exit();
}

$student_id = (int)$student_id_value;
if ($student_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid student ID']);
    exit();
}

if (!is_array($descriptor) || count($descriptor) === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Face descriptor is required']);
    exit();
}

$normalized = [];
foreach ($descriptor as $value) {
    if (is_numeric($value)) {
        $normalized[] = (float)$value;
    }
}
if (count($normalized) < 64) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid face descriptor length']);
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

function euclidean_distance(array $a, array $b): float
{
    $count = min(count($a), count($b));
    if ($count === 0) {
        return 999.0;
    }
    $sum = 0.0;
    for ($i = 0; $i < $count; $i++) {
        $diff = ((float)$a[$i]) - ((float)$b[$i]);
        $sum += $diff * $diff;
    }
    return sqrt($sum);
}

// Compare against latest saved profile for this student if available.
$existing_stmt = $conn->prepare('SELECT face_descriptor FROM captured_images WHERE student_id = ? ORDER BY image_id DESC LIMIT 1');
$student_id_text = (string)$student_id;
$existing_stmt->bind_param('s', $student_id_text);
$existing_stmt->execute();
$existing_result = $existing_stmt->get_result();
if ($existing_result && $existing_result->num_rows === 1) {
    $existing_row = $existing_result->fetch_assoc();
    $existing_desc = json_decode((string)$existing_row['face_descriptor'], true);
    if (is_array($existing_desc)) {
        $distance = euclidean_distance($normalized, $existing_desc);
        if ($distance > 0.58) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Biometric mismatch: captured face is not similar to this student\'s saved profile.',
                'distance' => round($distance, 4)
            ]);
            exit();
        }
    }
}

// Compare against other students to avoid collisions.
$other_stmt = $conn->prepare('SELECT student_id, face_descriptor FROM captured_images WHERE student_id <> ? AND face_descriptor IS NOT NULL');
$other_stmt->bind_param('s', $student_id_text);
$other_stmt->execute();
$other_result = $other_stmt->get_result();
if ($other_result) {
    while ($other = $other_result->fetch_assoc()) {
        $other_desc = json_decode((string)$other['face_descriptor'], true);
        if (!is_array($other_desc)) {
            continue;
        }
        $distance = euclidean_distance($normalized, $other_desc);
        if ($distance < 0.46) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Biometric collision: this face is too similar to another registered student.',
                'distance' => round($distance, 4)
            ]);
            exit();
        }
    }
}

$descriptor_json = json_encode($normalized);
if ($descriptor_json === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to encode descriptor']);
    exit();
}

$image_blob = null;
$file_type = null;
if ($captured_image !== '' && preg_match('/^data:image\/([a-zA-Z0-9+]+);base64,(.+)$/', $captured_image, $m) === 1) {
    $file_type = strtolower($m[1]);
    $decoded = base64_decode($m[2], true);
    if ($decoded !== false) {
        $image_blob = $decoded;
    }
}

// 1) Save/update captured_images table
$check_cap_stmt = $conn->prepare('SELECT image_id FROM captured_images WHERE student_id = ? ORDER BY image_id DESC LIMIT 1');
$check_cap_stmt->bind_param('s', $student_id_text);
$check_cap_stmt->execute();
$cap_result = $check_cap_stmt->get_result();
if ($cap_result && $cap_result->num_rows === 1) {
    $row = $cap_result->fetch_assoc();
    $image_id = (int)$row['image_id'];
    $update_cap = $conn->prepare('UPDATE captured_images
        SET student_name = ?, grade_section = ?, face_descriptor = ?, image_data = ?, file_type = ?
        WHERE image_id = ?');
    $update_cap->bind_param(
        'sssssi',
        $student['name'],
        $student['class_section'],
        $descriptor_json,
        $image_blob,
        $file_type,
        $image_id
    );
    if (!$update_cap->execute()) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update captured_images']);
        exit();
    }
} else {
    $insert_cap = $conn->prepare('INSERT INTO captured_images
        (student_id, student_name, grade_section, image_data, file_type, face_descriptor)
        VALUES (?, ?, ?, ?, ?, ?)');
    $insert_cap->bind_param(
        'ssssss',
        $student_id_text,
        $student['name'],
        $student['class_section'],
        $image_blob,
        $file_type,
        $descriptor_json
    );
    if (!$insert_cap->execute()) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to insert into captured_images']);
        exit();
    }
}

// 2) Sync student_face_data table
$upsert_sfd = $conn->prepare('INSERT INTO student_face_data (student_id, face_descriptor) VALUES (?, ?)
    ON DUPLICATE KEY UPDATE face_descriptor = VALUES(face_descriptor), updated_at = CURRENT_TIMESTAMP');
$upsert_sfd->bind_param('is', $student_id, $descriptor_json);
$upsert_sfd->execute();

// 3) Sync student_faces table
$upsert_sf = $conn->prepare('INSERT INTO student_faces (student_id, student_name, grade_section, face_descriptor)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE student_name = VALUES(student_name), grade_section = VALUES(grade_section), face_descriptor = VALUES(face_descriptor)');
$upsert_sf->bind_param('ssss', $student_id_text, $student['name'], $student['class_section'], $descriptor_json);
$upsert_sf->execute();

echo json_encode([
    'success' => true,
    'message' => 'Face data saved and synced across captured_images, student_face_data, and student_faces.',
    'student' => [
        'id' => (int)$student['id'],
        'name' => (string)$student['name'],
        'class_section' => (string)$student['class_section']
    ]
]);
exit();
?>
