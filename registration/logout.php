<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

session_unset();
session_destroy();

session_start();
$_SESSION['message'] = 'You have been logged out successfully.';
header('Location: login.php');
exit();
?>
