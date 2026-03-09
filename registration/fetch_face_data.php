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

$create_sql = 'CREATE TABLE IF NOT EXISTS captured_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL UNIQUE,
    captured_image LONGTEXT NULL,
    face_descriptor LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
$conn->query($create_sql);
$col_check = $conn->query("SHOW COLUMNS FROM students LIKE 'lrn'");
if ($col_check && $col_check->num_rows === 0) {
    if ($conn->query('ALTER TABLE students ADD COLUMN lrn VARCHAR(12) NULL')) {
        $conn->query('ALTER TABLE students ADD UNIQUE KEY uq_students_lrn (lrn)');
    }
}

$section = trim($_GET['section'] ?? '');
$where = '';
if ($section !== '') {
    $safe_section = $conn->real_escape_string($section);
    $where = " WHERE s.class_section = '$safe_section'";
}

$sql = 'SELECT s.id, s.name, s.class_section, COALESCE(s.lrn, "") AS lrn, f.face_descriptor
        FROM captured_images f
        INNER JOIN students s ON s.id = f.student_id'
        . $where .
       ' ORDER BY s.class_section ASC, s.name ASC';

$result = $conn->query($sql);
$rows = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $decoded = json_decode((string)$row['face_descriptor'], true);
        if (!is_array($decoded) || count($decoded) < 64) {
            continue;
        }

        $vector = [];
        foreach ($decoded as $value) {
            if (!is_numeric($value)) {
                continue;
            }
            $vector[] = (float)$value;
        }

        if (count($vector) < 64) {
            continue;
        }

        $rows[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'class_section' => (string)$row['class_section'],
            'lrn' => (string)$row['lrn'],
            'descriptor' => $vector
        ];
    }
}

echo json_encode([
    'success' => true,
    'count' => count($rows),
    'students' => $rows
]);
exit();
?>
