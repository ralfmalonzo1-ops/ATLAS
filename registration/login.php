
<?php
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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
    <title>Login - Attendance System</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-body">
    <?php include __DIR__ . '/atlas_brand.php'; ?>
    <div class="container login-card auth-card">
        <h2>Login</h2>

        <?php
        if (isset($_SESSION['message'])) {
            echo "<p class='auth-message error'>" . htmlspecialchars($_SESSION['message']) . "</p>";
            unset($_SESSION['message']);
        }
        ?>

        <form id="loginForm" action="login_process.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="input-group">
                <label for="username">Full Name</label>
                <input type="text" id="username" name="username" autocomplete="name" required>
            </div>
            <div class="input-group">
                <label for="password">Password</label>
                <div class="password-field">
                    <input type="password" id="password" name="password" autocomplete="current-password" required>
                    <button type="button" class="toggle-password" data-target="password" aria-label="Show password">Show</button>
                </div>
            </div>
            <button type="submit">Login</button>
        </form>

        <p><a href="forgot_password.php">Forgot password?</a></p>
        <p>Need an account? <a href="register.php">Register here</a>.</p>
    </div>

    <script>
        (function () {
            const toggleButtons = document.querySelectorAll('.toggle-password');
            toggleButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    const targetId = button.getAttribute('data-target');
                    const field = document.getElementById(targetId);
                    if (!field) return;

                    const reveal = field.type === 'password';
                    field.type = reveal ? 'text' : 'password';
                    button.textContent = reveal ? 'Hide' : 'Show';
                    button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
                });
            });
        })();
    </script>
</body>
</html>
