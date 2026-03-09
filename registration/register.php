<?php
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Attendance System</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-body">
    <?php include __DIR__ . '/atlas_brand.php'; ?>
    <div class="container login-card auth-card">
        <h2>Create Account</h2>

        <?php
        if (isset($_SESSION['message'])) {
            $message = (string)$_SESSION['message'];
            $message_class = stripos($message, 'successful') !== false ? 'auth-message success' : 'auth-message error';
            echo "<p class='" . htmlspecialchars($message_class) . "'>" . htmlspecialchars($message) . "</p>";
            unset($_SESSION['message']);
        }
        ?>

        <form id="registerForm" action="register_process.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="input-group">
                <label for="username">Full Name</label>
                <input type="text" id="username" name="username" autocomplete="name" required>
                <small class="hint">This name is shown on dashboards and attendance reports.</small>
            </div>

            <div class="input-group">
                <label for="password">Password</label>
                <div class="password-field">
                    <input type="password" id="password" name="password" minlength="6" autocomplete="new-password" required>
                    <button type="button" class="toggle-password" data-target="password" aria-label="Show password">Show</button>
                </div>
                <small id="passwordHint" class="hint">Use at least 6 characters.</small>
            </div>

            <div class="input-group">
                <label for="confirm_password">Confirm Password</label>
                <div class="password-field">
                    <input type="password" id="confirm_password" name="confirm_password" minlength="6" autocomplete="new-password" required>
                    <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Show confirm password">Show</button>
                </div>
                <small id="passwordMatchHint" class="hint"></small>
            </div>

            <div class="input-group">
                <label>Register As</label>
                <div class="role-options" id="roleOptions">
                    <label class="role-option" for="role_teacher">
                        <input type="radio" id="role_teacher" name="role" value="teacher" checked>
                        <span>
                            <strong>Teacher</strong>
                            <small>Access teaching dashboard and attendance tools.</small>
                        </span>
                    </label>

                    <label class="role-option" for="role_admin">
                        <input type="radio" id="role_admin" name="role" value="admin">
                        <span>
                            <strong>Admin</strong>
                            <small>Request admin account. Approval is required.</small>
                        </span>
                    </label>
                </div>
                <small id="roleHint" class="hint">Selected role: Teacher</small>
            </div>

            <button id="registerBtn" type="submit">Register</button>
        </form>

        <p>Already have an account? <a href="login.php">Login here</a>.</p>
    </div>

    <script>
        (function () {
            const form = document.getElementById('registerForm');
            const password = document.getElementById('password');
            const confirmPassword = document.getElementById('confirm_password');
            const passwordHint = document.getElementById('passwordHint');
            const matchHint = document.getElementById('passwordMatchHint');
            const roleHint = document.getElementById('roleHint');
            const registerBtn = document.getElementById('registerBtn');
            const roleInputs = document.querySelectorAll('input[name="role"]');
            const toggleButtons = document.querySelectorAll('.toggle-password');

            function updatePasswordState() {
                if (password.value.length === 0) {
                    passwordHint.textContent = 'Use at least 6 characters.';
                    passwordHint.className = 'hint';
                } else if (password.value.length < 6) {
                    passwordHint.textContent = 'Password is too short.';
                    passwordHint.className = 'hint error';
                } else {
                    passwordHint.textContent = 'Password length looks good.';
                    passwordHint.className = 'hint success';
                }
            }

            function updateMatchState() {
                if (confirmPassword.value.length === 0) {
                    matchHint.textContent = '';
                    matchHint.className = 'hint';
                    confirmPassword.setCustomValidity('');
                    return;
                }

                if (password.value === confirmPassword.value) {
                    matchHint.textContent = 'Passwords match.';
                    matchHint.className = 'hint success';
                    confirmPassword.setCustomValidity('');
                } else {
                    matchHint.textContent = 'Passwords do not match.';
                    matchHint.className = 'hint error';
                    confirmPassword.setCustomValidity('Passwords do not match.');
                }
            }

            function updateRoleState() {
                const selectedRole = document.querySelector('input[name="role"]:checked');
                if (!selectedRole) {
                    roleHint.textContent = 'Please choose a role.';
                    return;
                }

                roleHint.textContent = 'Selected role: ' + (selectedRole.value === 'admin' ? 'Admin' : 'Teacher');
            }

            function updateSubmitState() {
                const valid =
                    password.value.length >= 6 &&
                    confirmPassword.value.length >= 6 &&
                    password.value === confirmPassword.value;

                registerBtn.disabled = !valid;
                registerBtn.classList.toggle('btn-disabled', !valid);
            }

            password.addEventListener('input', function () {
                updatePasswordState();
                updateMatchState();
                updateSubmitState();
            });

            confirmPassword.addEventListener('input', function () {
                updateMatchState();
                updateSubmitState();
            });

            roleInputs.forEach(function (input) {
                input.addEventListener('change', updateRoleState);
            });

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

            form.addEventListener('submit', function (event) {
                updatePasswordState();
                updateMatchState();
                updateRoleState();
                updateSubmitState();

                if (!form.checkValidity()) {
                    event.preventDefault();
                    form.reportValidity();
                }
            });

            updatePasswordState();
            updateMatchState();
            updateRoleState();
            updateSubmitState();
        })();
    </script>
</body>
</html>
