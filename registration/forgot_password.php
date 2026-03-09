<?php
session_start();

if (isset($_SESSION['user_id'])) {
    if (($_SESSION['role'] ?? '') === 'admin') {
        header('Location: admin_dashboard.php');
    } else {
        header('Location: dashboard.php');
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Attendance System</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-body">
    <?php include __DIR__ . '/atlas_brand.php'; ?>
    <div class="container login-card">
        <h2>Reset Password</h2>

        <?php
        if (isset($_SESSION['message'])) {
            echo "<p class='message error'>" . htmlspecialchars($_SESSION['message']) . "</p>";
            unset($_SESSION['message']);
        }
        ?>

        <form action="forgot_password_process.php" method="POST">
            <div class="input-group">
                <label>Full Name</label>
                <input type="text" name="username" autocomplete="name" required>
            </div>
            <div class="input-group">
                <label>New Password</label>
                <input type="password" name="new_password" required>
            </div>
            <div class="input-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" required>
            </div>
            <div class="spacer-sm"></div>
            <button type="submit">Update Password</button>
        </form>

        <p>Back to <a href="login.php">Login</a>.</p>
    </div>
</body>
</html>
