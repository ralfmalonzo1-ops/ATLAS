<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$class_section = trim($_GET['class_section'] ?? '');
$teacher = trim($_GET['teacher'] ?? '');

$conditions = [];

if ($start_date !== '') {
    $start_obj = DateTime::createFromFormat('Y-m-d', $start_date);
    if ($start_obj && $start_obj->format('Y-m-d') === $start_date) {
        $safe_start = $conn->real_escape_string($start_date);
        $conditions[] = "a.attendance_date >= '$safe_start'";
    }
}

if ($end_date !== '') {
    $end_obj = DateTime::createFromFormat('Y-m-d', $end_date);
    if ($end_obj && $end_obj->format('Y-m-d') === $end_date) {
        $safe_end = $conn->real_escape_string($end_date);
        $conditions[] = "a.attendance_date <= '$safe_end'";
    }
}

if ($class_section !== '') {
    $safe_class = $conn->real_escape_string($class_section);
    $conditions[] = "s.class_section = '$safe_class'";
}

if ($role === 'admin' && $teacher !== '') {
    $safe_teacher = $conn->real_escape_string($teacher);
    $conditions[] = "u.username = '$safe_teacher'";
}

if ($role !== 'admin') {
    $conditions[] = "a.marked_by = $user_id";
}

$where_sql = '';
if (count($conditions) > 0) {
    $where_sql = ' WHERE ' . implode(' AND ', $conditions);
}

$summary_sql = 'SELECT a.attendance_date,
                       s.class_section,
                       COALESCE(u.username, "N/A") AS marked_by,
                       SUM(CASE WHEN a.status = "present" THEN 1 ELSE 0 END) AS present_count,
                       SUM(CASE WHEN a.status = "absent" THEN 1 ELSE 0 END) AS absent_count,
                       COUNT(*) AS total_count
                FROM attendance a
                INNER JOIN students s ON s.id = a.student_id
                LEFT JOIN users u ON u.id = a.marked_by'
        . $where_sql .
       ' GROUP BY a.attendance_date, s.class_section, u.username
         ORDER BY a.attendance_date DESC, s.class_section ASC';

$detail_sql = 'SELECT a.attendance_date,
                      s.id AS student_id,
                      s.name AS student_name,
                      s.class_section,
                      a.status,
                      COALESCE(u.username, "N/A") AS marked_by
               FROM attendance a
               INNER JOIN students s ON s.id = a.student_id
               LEFT JOIN users u ON u.id = a.marked_by'
        . $where_sql .
       ' ORDER BY a.attendance_date DESC, s.class_section ASC, s.name ASC';

$summary_rows = [];
$summary_result = $conn->query($summary_sql);
if ($summary_result) {
    while ($row = $summary_result->fetch_assoc()) {
        $summary_rows[] = $row;
    }
}

$detail_rows = [];
$detail_result = $conn->query($detail_sql);
if ($detail_result) {
    while ($row = $detail_result->fetch_assoc()) {
        $detail_rows[] = $row;
    }
}

$total_records = count($detail_rows);
$present_total = 0;
$absent_total = 0;
$dates_seen = [];
$students_seen = [];

foreach ($detail_rows as $row) {
    if ($row['status'] === 'present') {
        $present_total++;
    } else {
        $absent_total++;
    }
    $dates_seen[$row['attendance_date']] = true;
    $students_seen[(int)$row['student_id']] = true;
}

$attendance_rate = $total_records > 0 ? round(($present_total / $total_records) * 100, 2) : 0;
$unique_days = count($dates_seen);
$unique_students = count($students_seen);

$class_options = [];
$class_result = $conn->query('SELECT DISTINCT class_section FROM students WHERE class_section IS NOT NULL AND class_section <> "" ORDER BY class_section ASC');
if ($class_result) {
    while ($class_row = $class_result->fetch_assoc()) {
        $class_options[] = (string)$class_row['class_section'];
    }
}

$teacher_options = [];
if ($role === 'admin') {
    $teacher_result = $conn->query("SELECT username FROM users WHERE role IN ('teacher', 'admin') ORDER BY username ASC");
    if ($teacher_result) {
        while ($teacher_row = $teacher_result->fetch_assoc()) {
            $teacher_options[] = (string)$teacher_row['username'];
        }
    }
}

$export_link = 'export_docs.php?start_date=' . urlencode($start_date)
    . '&end_date=' . urlencode($end_date)
    . '&class_section=' . urlencode($class_section)
    . '&teacher=' . urlencode($teacher);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Reports</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include __DIR__ . '/atlas_brand.php'; ?>
    <div class="container">
        <div class="page-hero">
            <h1>Attendance Report Sheet</h1>
            <p class="hero-subtitle">Detailed attendance analytics with full student-level logs.</p>
        </div>

        <div class="top-links action-links">
            <a class="btn btn-soft" href="<?php echo ($role === 'admin') ? 'admin_dashboard.php' : 'dashboard.php'; ?>">Back to Dashboard</a>
            <a class="btn btn-soft" href="<?php echo htmlspecialchars($export_link); ?>">Export DOC</a>
            <a class="btn btn-danger" href="logout.php">Logout</a>
        </div>

        <form method="GET" action="reports.php" class="report-filters">
            <div class="filter-grid">
                <div class="input-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                <div class="input-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                <div class="input-group">
                    <label for="class_section">Class/Section</label>
                    <select id="class_section" name="class_section">
                        <option value="">All Classes</option>
                        <?php foreach ($class_options as $option): ?>
                            <option value="<?php echo htmlspecialchars($option); ?>" <?php echo ($class_section === $option) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($role === 'admin'): ?>
                    <div class="input-group">
                        <label for="teacher">Marked By</label>
                        <select id="teacher" name="teacher">
                            <option value="">All Teachers</option>
                            <?php foreach ($teacher_options as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>" <?php echo ($teacher === $option) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>

            <div class="top-links">
                <button type="submit">Generate Report</button>
                <a class="btn btn-soft" href="reports.php">Reset Filters</a>
            </div>
        </form>

        <div class="stat-grid">
            <div class="stat-card">
                <span class="stat-label">Total Records</span>
                <strong class="stat-value"><?php echo $total_records; ?></strong>
            </div>
            <div class="stat-card">
                <span class="stat-label">Attendance Rate</span>
                <strong class="stat-value"><?php echo number_format($attendance_rate, 2); ?>%</strong>
            </div>
            <div class="stat-card">
                <span class="stat-label">Present</span>
                <strong class="stat-value"><?php echo $present_total; ?></strong>
            </div>
            <div class="stat-card">
                <span class="stat-label">Absent</span>
                <strong class="stat-value"><?php echo $absent_total; ?></strong>
            </div>
            <div class="stat-card">
                <span class="stat-label">Active Days</span>
                <strong class="stat-value"><?php echo $unique_days; ?></strong>
            </div>
            <div class="stat-card">
                <span class="stat-label">Students Tracked</span>
                <strong class="stat-value"><?php echo $unique_students; ?></strong>
            </div>
        </div>

        <h2>Grouped Summary</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Class/Section</th>
                        <th>Marked By</th>
                        <th>Present</th>
                        <th>Absent</th>
                        <th>Total</th>
                        <th>Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($summary_rows) > 0): ?>
                        <?php foreach ($summary_rows as $row): ?>
                            <?php
                            $row_total = (int)$row['total_count'];
                            $row_present = (int)$row['present_count'];
                            $row_rate = $row_total > 0 ? round(($row_present / $row_total) * 100, 2) : 0;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['attendance_date']); ?></td>
                                <td><?php echo htmlspecialchars($row['class_section']); ?></td>
                                <td><?php echo htmlspecialchars($row['marked_by']); ?></td>
                                <td><?php echo $row_present; ?></td>
                                <td><?php echo (int)$row['absent_count']; ?></td>
                                <td><?php echo $row_total; ?></td>
                                <td><?php echo number_format($row_rate, 2); ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7">No summary data available for the selected filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="top-links report-head">
            <h2>Detailed Attendance Log</h2>
            <input type="text" id="reportSearch" placeholder="Search student, class, status, or teacher...">
        </div>
        <p id="detailCount" class="hint"></p>

        <div class="table-wrap">
            <table id="detailReportTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Student</th>
                        <th>Class/Section</th>
                        <th>Status</th>
                        <th>Marked By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($detail_rows) > 0): ?>
                        <?php foreach ($detail_rows as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['attendance_date']); ?></td>
                                <td><?php echo htmlspecialchars($row['student_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['class_section']); ?></td>
                                <td>
                                    <span class="badge <?php echo ($row['status'] === 'present') ? 'badge-present' : 'badge-absent'; ?>">
                                        <?php echo htmlspecialchars(ucfirst($row['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($row['marked_by']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5">No detailed records found for the selected filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        (function () {
            const input = document.getElementById('reportSearch');
            const table = document.getElementById('detailReportTable');
            const countLabel = document.getElementById('detailCount');
            if (!input || !table || !countLabel) return;

            const rows = Array.from(table.querySelectorAll('tbody tr'));

            function updateCount(visible, total) {
                countLabel.textContent = 'Showing ' + visible + ' of ' + total + ' detailed record(s).';
            }

            function runFilter() {
                const query = input.value.trim().toLowerCase();
                let visible = 0;

                rows.forEach(function (row) {
                    if (row.children.length === 1) {
                        return;
                    }

                    const text = row.textContent.toLowerCase();
                    const match = query === '' || text.indexOf(query) !== -1;
                    row.style.display = match ? '' : 'none';
                    if (match) visible++;
                });

                updateCount(visible, rows.filter(function (r) { return r.children.length > 1; }).length);
            }

            input.addEventListener('input', runFilter);
            runFilter();
        })();
    </script>
</body>
</html>
