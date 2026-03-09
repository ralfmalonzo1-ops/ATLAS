<?php
// login_process.php
session_start();
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit();
}

$csrf_token = trim((string)($_POST['csrf_token'] ?? ''));
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    $_SESSION['message'] = 'Invalid request token. Please try again.';
    header('Location: login.php');
    exit();
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    $_SESSION['message'] = 'Name and password are required.';
    header('Location: login.php');
    exit();
}

$stmt = $conn->prepare('SELECT id, username, password, role, status FROM users WHERE username = ? LIMIT 1');
$stmt->bind_param('s', $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows === 1) {
    $row = $result->fetch_assoc();

    if (!password_verify($password, $row['password'])) {
        $_SESSION['message'] = 'Invalid name or password.';
        header('Location: login.php');
        exit();
    }

    if ($row['status'] === 'pending') {
        $_SESSION['message'] = 'Your account is still pending admin approval.';
        header('Location: login.php');
        exit();
    }

    if ($row['status'] === 'denied') {
        $_SESSION['message'] = 'Your account registration was denied.';
        header('Location: login.php');
        exit();
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$row['id'];
    $_SESSION['username'] = $row['username'];
    $_SESSION['role'] = $row['role'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    if ($row['role'] === 'admin') {
        header('Location: admin_dashboard.php');
    } else {
        header('Location: dashboard.php');
    }
    exit();
}

$_SESSION['message'] = 'Invalid name or password.';
header('Location: login.php');
exit();
?>
