<?php
// register_process.php
session_start();
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: register.php');
    exit();
}

$csrf_token = trim((string)($_POST['csrf_token'] ?? ''));
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    $_SESSION['message'] = 'Invalid request token. Please try again.';
    header('Location: register.php');
    exit();
}

function users_column_exists(mysqli $conn, string $column): bool
{
    $safe = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM users LIKE '$safe'");
    return $result && $result->num_rows > 0;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';
$role = strtolower(trim($_POST['role'] ?? 'teacher'));
$allowed_roles = ['teacher', 'admin'];

if ($username === '' || $password === '' || $confirm_password === '' || $role === '') {
    $_SESSION['message'] = 'All fields are required.';
    header('Location: register.php');
    exit();
}

if (!in_array($role, $allowed_roles, true)) {
    $_SESSION['message'] = 'Invalid role selected.';
    header('Location: register.php');
    exit();
}

if ($password !== $confirm_password) {
    $_SESSION['message'] = 'Passwords do not match.';
    header('Location: register.php');
    exit();
}

if (strlen($password) < 6) {
    $_SESSION['message'] = 'Password must be at least 6 characters long.';
    header('Location: register.php');
    exit();
}

$check_stmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
$check_stmt->bind_param('s', $username);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result && $check_result->num_rows > 0) {
    $_SESSION['message'] = 'Name already exists. Please use another one.';
    header('Location: register.php');
    exit();
}
$hashed_password = password_hash($password, PASSWORD_DEFAULT);
$status = ($role === 'admin') ? 'pending' : 'approved';

if (users_column_exists($conn, 'email') && users_column_exists($conn, 'resume_cv')) {
    $system_email = strtolower(preg_replace('/[^a-z0-9]+/', '.', $username));
    if ($system_email === '' || $system_email === null) {
        $system_email = 'user';
    }
    $system_email .= '.' . time() . '.' . bin2hex(random_bytes(2)) . '@local.invalid';

    $empty_resume = '';
    $insert_stmt = $conn->prepare('INSERT INTO users (username, email, password, role, status, resume_cv) VALUES (?, ?, ?, ?, ?, ?)');
    $insert_stmt->bind_param('ssssss', $username, $system_email, $hashed_password, $role, $status, $empty_resume);
} elseif (users_column_exists($conn, 'email')) {
    $system_email = strtolower(preg_replace('/[^a-z0-9]+/', '.', $username));
    if ($system_email === '' || $system_email === null) {
        $system_email = 'user';
    }
    $system_email .= '.' . time() . '.' . bin2hex(random_bytes(2)) . '@local.invalid';

    $insert_stmt = $conn->prepare('INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, ?, ?)');
    $insert_stmt->bind_param('sssss', $username, $system_email, $hashed_password, $role, $status);
} elseif (users_column_exists($conn, 'resume_cv')) {
    $empty_resume = '';
    $insert_stmt = $conn->prepare('INSERT INTO users (username, password, role, status, resume_cv) VALUES (?, ?, ?, ?, ?)');
    $insert_stmt->bind_param('sssss', $username, $hashed_password, $role, $status, $empty_resume);
} else {
    $insert_stmt = $conn->prepare('INSERT INTO users (username, password, role, status) VALUES (?, ?, ?, ?)');
    $insert_stmt->bind_param('ssss', $username, $hashed_password, $role, $status);
}

if ($insert_stmt->execute()) {
    if ($role === 'admin') {
        $_SESSION['message'] = 'Registration submitted. Admin approval is required before login.';
    } else {
        $_SESSION['message'] = 'Registration successful! You can now log in.';
    }
    header('Location: login.php');
    exit();
}
$_SESSION['message'] = 'Registration failed. Please try again.';
header('Location: register.php');
exit();
?>
