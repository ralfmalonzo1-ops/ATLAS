<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit();
}

$message = '';
$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function table_exists(mysqli $conn, string $table_name): bool
{
    $safe_table = $conn->real_escape_string($table_name);
    $result = $conn->query("SHOW TABLES LIKE '{$safe_table}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function column_exists(mysqli $conn, string $table_name, string $column_name): bool
{
    $safe_table = $conn->real_escape_string($table_name);
    $safe_column = $conn->real_escape_string($column_name);
    $result = $conn->query("SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_column}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'], $_POST['csrf_token'])) {
    $action = trim((string)$_POST['action']);
    $target_id = (int)$_POST['id'];
    $csrf_token = (string)$_POST['csrf_token'];

    if (!hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $error = 'Invalid request token.';
    } elseif (($action !== 'approve' && $action !== 'deny' && $action !== 'delete' && $action !== 'delete_student') || $target_id <= 0) {
        $error = 'Invalid action request.';
    } elseif ($action === 'delete_student') {
        try {
            $conn->begin_transaction();

            $student_name = '';
            $find_stmt = $conn->prepare('SELECT name FROM students WHERE id = ? LIMIT 1');
            if (!$find_stmt) {
                throw new RuntimeException('Failed to verify student record.');
            }
            $find_stmt->bind_param('i', $target_id);
            $find_stmt->execute();
            $find_result = $find_stmt->get_result();
            if (!$find_result || $find_result->num_rows === 0) {
                throw new RuntimeException('Student record was not found.');
            }
            $student_row = $find_result->fetch_assoc();
            $student_name = (string)($student_row['name'] ?? '');

            $cleanup_targets = [
                ['table' => 'attendance', 'column' => 'student_id'],
                ['table' => 'captured_images', 'column' => 'student_id'],
                ['table' => 'student_face_data', 'column' => 'student_id'],
                ['table' => 'student_faces', 'column' => 'student_id']
            ];

            foreach ($cleanup_targets as $cleanup_target) {
                $table_name = $cleanup_target['table'];
                $column_name = $cleanup_target['column'];

                if (!table_exists($conn, $table_name) || !column_exists($conn, $table_name, $column_name)) {
                    continue;
                }

                $delete_related_stmt = $conn->prepare("DELETE FROM `{$table_name}` WHERE `{$column_name}` = ?");
                if (!$delete_related_stmt) {
                    throw new RuntimeException('Failed to clean related records from ' . $table_name . '.');
                }
                $delete_related_stmt->bind_param('i', $target_id);
                if (!$delete_related_stmt->execute()) {
                    throw new RuntimeException('Failed to clean related records from ' . $table_name . '.');
                }
            }

            $delete_student_stmt = $conn->prepare('DELETE FROM students WHERE id = ?');
            if (!$delete_student_stmt) {
                throw new RuntimeException('Failed to prepare student delete query.');
            }
            $delete_student_stmt->bind_param('i', $target_id);
            if (!$delete_student_stmt->execute()) {
                throw new RuntimeException('Failed to delete student record.');
            }

            if ($delete_student_stmt->affected_rows <= 0) {
                throw new RuntimeException('Student record was not found.');
            }

            $conn->commit();
            $message = 'Student deleted successfully' . ($student_name !== '' ? ': ' . $student_name : '') . '.';
        } catch (Throwable $delete_error) {
            $conn->rollback();
            $error = $delete_error->getMessage();
        }
    } elseif ($action === 'delete') {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role IN ('teacher', 'admin')");
        $stmt->bind_param('i', $target_id);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $message = 'Account deleted successfully.';
            } else {
                $error = 'Account was not found.';
            }
        } else {
            $error = 'Failed to delete account.';
        }
    } else {
        $new_status = ($action === 'approve') ? 'approved' : 'denied';

        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ? AND role IN ('teacher', 'admin')");
        $stmt->bind_param('si', $new_status, $target_id);
        if ($stmt->execute()) {
            $message = 'Account successfully ' . $new_status . '.';
        } else {
            $error = 'Failed to update account status.';
        }
    }
}

$pending_users = [];
$sql = "SELECT id, username, role, created_at FROM users WHERE status = 'pending' AND role IN ('teacher', 'admin') ORDER BY created_at ASC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pending_users[] = $row;
    }
}

$students = [];
$students_result = $conn->query('SELECT id, name, class_section FROM students ORDER BY class_section ASC, name ASC');
if ($students_result) {
    while ($student_row = $students_result->fetch_assoc()) {
        $students[] = $student_row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include __DIR__ . '/atlas_brand.php'; ?>
    <div class="container">
        <h1>Admin Dashboard</h1>
        <div class="top-links action-links">
            <span class="welcome-chip">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <a class="btn btn-soft" href="admin_view_attendance.php">View Attendance</a>
            <a class="btn btn-soft" href="enrollment.php">Enrollment</a>
            <a class="btn btn-soft" href="reports.php">Reports</a>
            <a class="btn btn-soft" href="export_docs.php">Export DOC</a>
            <a class="btn btn-danger" href="logout.php">Logout</a>
        </div>

        <?php if ($message !== ''): ?>
            <p class="message success"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <p class="message error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <h2>Pending Account Registrations</h2>
        <div class="table-wrap">
            <table border="1" cellpadding="10" cellspacing="0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Registration Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($pending_users) > 0): ?>
                        <?php foreach ($pending_users as $row): ?>
                            <tr>
                                <td><?php echo (int)$row['id']; ?></td>
                                <td><?php echo htmlspecialchars($row['username']); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst((string)$row['role'])); ?></td>
                                <td><?php echo htmlspecialchars((string)$row['created_at']); ?></td>
                                <td>
                                    <form method="POST" action="admin_dashboard.php" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                        <button type="submit" class="btn btn-success btn-xs">Approve</button>
                                    </form>
                                    <form method="POST" action="admin_dashboard.php" class="inline-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="deny">
                                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-xs">Deny</button>
                                    </form>
                                    <form method="POST" action="admin_dashboard.php" class="inline-form" onsubmit="return confirm('Delete this teacher account permanently?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                        <button type="submit" class="btn btn-xs">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5">No pending registrations.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h2>Delete Student Records</h2>
        <div class="table-wrap">
            <table border="1" cellpadding="10" cellspacing="0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Student Name</th>
                        <th>Class Section</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($students) > 0): ?>
                        <?php foreach ($students as $student): ?>
                            <tr>
                                <td><?php echo (int)$student['id']; ?></td>
                                <td><?php echo htmlspecialchars($student['name']); ?></td>
                                <td><?php echo htmlspecialchars($student['class_section']); ?></td>
                                <td>
                                    <form method="POST" action="admin_dashboard.php" class="inline-form" onsubmit="return confirm('Delete this student record permanently?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="delete_student">
                                        <input type="hidden" name="id" value="<?php echo (int)$student['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-xs">Delete Student</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4">No student records found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>


