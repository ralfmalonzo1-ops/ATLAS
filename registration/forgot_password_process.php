<?php
session_start();
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: forgot_password.php');
    exit();
}

$username = trim($_POST['username'] ?? '');
$new_password = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

if ($username === '' || $new_password === '' || $confirm_password === '') {
    $_SESSION['message'] = 'All fields are required.';
    header('Location: forgot_password.php');
    exit();
}

if ($new_password !== $confirm_password) {
    $_SESSION['message'] = 'Passwords do not match.';
    header('Location: forgot_password.php');
    exit();
}

if (strlen($new_password) < 6) {
    $_SESSION['message'] = 'Password must be at least 6 characters long.';
    header('Location: forgot_password.php');
    exit();
}

$check_stmt = $conn->prepare('SELECT id, status FROM users WHERE username = ? LIMIT 1');
$check_stmt->bind_param('s', $username);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if (!$check_result || $check_result->num_rows !== 1) {
    $_SESSION['message'] = 'Account not found.';
    header('Location: forgot_password.php');
    exit();
}

$row = $check_result->fetch_assoc();
if (($row['status'] ?? '') === 'denied') {
    $_SESSION['message'] = 'This account is denied and cannot reset password.';
    header('Location: forgot_password.php');
    exit();
}

$hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
$user_id = (int)$row['id'];

$update_stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
$update_stmt->bind_param('si', $hashed_password, $user_id);

if ($update_stmt->execute()) {
    $_SESSION['message'] = 'Password updated successfully. Please log in.';
    header('Location: login.php');
    exit();
}

$_SESSION['message'] = 'Unable to update password. Please try again.';
header('Location: forgot_password.php');
exit();
?>
