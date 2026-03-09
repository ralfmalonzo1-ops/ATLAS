<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$role = $_SESSION['role'] ?? '';
if ($role !== 'teacher' && $role !== 'admin') {
    header('Location: login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Isolated Scanner Page</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include __DIR__ . '/atlas_brand.php'; ?>
    <div class="container isolated-dashboard">
        <div class="page-hero">
            <h1>Isolated Scanner Page</h1>
            <p class="hero-subtitle">Dedicated area for face capture/compare and LRN-based attendance scanning only.</p>
        </div>

        <div class="top-links action-links">
            <?php if ($role === 'teacher'): ?>
                <a class="btn btn-soft" href="dashboard.php">Back to Teacher Dashboard</a>
            <?php endif; ?>
            <a class="btn btn-danger" href="logout.php">Logout</a>
        </div>

        <div class="stat-grid spacer-md">
            <div class="stat-card">
                <span class="stat-label">Face + LRN Profiles</span>
                <strong class="stat-value">Register</strong>
                <p class="hint">Capture face descriptors and review registered 12-digit LRNs in one page.</p>
                <a class="btn btn-soft" href="face_register.php">Open Face + LRN Registration</a>
            </div>
            <div class="stat-card">
                <span class="stat-label">Merged Scanner</span>
                <strong class="stat-value">Face + LRN</strong>
                <p class="hint">Capture face for biometric compare and verify student LRN from camera OCR.</p>
                <a class="btn" href="face_scanner.php">Open Face + LRN Scanner</a>
            </div>
            <div class="stat-card">
                <span class="stat-label">LRN Only</span>
                <strong class="stat-value">Quick Scan</strong>
                <p class="hint">Verify 12-digit LRN (typed or camera OCR) and mark attendance without face capture.</p>
                <a class="btn btn-soft" href="lrn_scanner.php">Open LRN Scanner</a>
            </div>
        </div>
    </div>
</body>
</html>
