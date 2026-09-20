<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../func/functions.php';
/** @var mysqli $conn */
checkLogin();
checkRole(['Dentist']);

$dentist_username = $_SESSION['username'];
$dentist_id = $_SESSION['employee_id'] ?? 0;
if (!$dentist_id) {
    header('Location: ../login.php');
    exit;
}

// Defaults to today on first load (no ?date= param at all). Submitting the filter form with
// the date field cleared sends date= as an empty string, which is treated as "all dates" —
// distinct from the param being absent entirely.
$date_filter   = $_GET['date'] ?? date('Y-m-d');
$status_filter = $_GET['status'] ?? 'all';
$search        = trim($_GET['search'] ?? '');
$sort          = $_GET['sort'] ?? 'time_asc';

$conditions = ["a.dentist_id = ?"];
$types = "i";
$params = [$dentist_id];

if ($date_filter !== '') {
    $conditions[] = "a.appointment_date = ?";
    $types .= "s";
    $params[] = $date_filter;
}
if ($status_filter !== 'all') {
    $conditions[] = "a.status = ?";
    $types .= "s";
    $params[] = $status_filter;
}
if ($search !== '') {
    $conditions[] = "(p.first_name LIKE ? OR p.last_name LIKE ?)";
    $types .= "ss";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
}
$where_clause = implode(' AND ', $conditions);

$order_by = "a.appointment_date ASC, a.start_time ASC";
if ($sort === 'time_desc') { $order_by = "a.appointment_date DESC, a.start_time DESC"; }

$sql = "SELECT a.appointment_id, a.appointment_date, a.start_time, a.status,
               CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
               p.contact_number AS patient_phone, s.service_name
        FROM appointments a
        JOIN patients p ON a.patient_id = p.patient_id
        JOIN services s ON a.service_id = s.service_id
        WHERE $where_clause
        ORDER BY $order_by";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$appointments = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// ---- Quick counts for the currently selected date filter (or all-time if cleared) ----
$count_conditions = ["dentist_id = ?"];
$count_types = "i";
$count_params = [$dentist_id];
if ($date_filter !== '') {
    $count_conditions[] = "appointment_date = ?";
    $count_types .= "s";
    $count_params[] = $date_filter;
}
$count_where = implode(' AND ', $count_conditions);
$stmt = mysqli_prepare($conn, "SELECT status, COUNT(*) AS total FROM appointments WHERE $count_where GROUP BY status");
mysqli_stmt_bind_param($stmt, $count_types, ...$count_params);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$counts = ['pending_operation' => 0, 'completed' => 0, 'cancelled' => 0, 'pending' => 0];
while ($row = mysqli_fetch_assoc($res)) { $counts[$row['status']] = (int) $row['total']; }
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Appointments - Alberba Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/dentist.css">
</head>
<body>

<aside class="sidebar">
    <div class="brand">Alberba <span>Dental</span></div>

    <a href="dashboard.php" class="nav-link">
        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
        Dashboard
    </a>
    <a href="appointments.php" class="nav-link active">
        <svg viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>
        My Appointments
    </a>
    <a href="patients.php" class="nav-link">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M4.5 20.5v-1a7.5 7.5 0 0 1 15 0v1"/></svg>
        My Patients
    </a>
    <a href="schedule.php" class="nav-link">
        <svg viewBox="0 0 24 24"><rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M16 2.5v4M8 2.5v4M3 10h18"/></svg>
        Calendar
    </a>

    <div class="sidebar-footer">
        <a href="../logout.php" class="nav-link">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
            Log Out
        </a>
    </div>
</aside>

<main class="main">
    <div class="topbar">
        <div>
            <h1>My Appointments</h1>
            <p class="subtitle">Appointments confirmed by reception, ready for you to track</p>
        </div>
        <span class="welcome-pill">Welcome, <?php echo htmlspecialchars($dentist_username); ?></span>
    </div>

    <div class="stats-grid">
        <div class="stat-card pending"><div class="label">Pending<?php echo $date_filter !== '' ? ' (' . date('M j', strtotime($date_filter)) . ')' : ' (All Dates)'; ?></div><div class="value"><?php echo $counts['pending_operation']; ?></div></div>
        <div class="stat-card completed"><div class="label">Completed<?php echo $date_filter !== '' ? ' (' . date('M j', strtotime($date_filter)) . ')' : ' (All Dates)'; ?></div><div class="value"><?php echo $counts['completed']; ?></div></div>
        <div class="stat-card"><div class="label">Total Shown</div><div class="value"><?php echo count($appointments); ?></div></div>
    </div>

    <div class="card">
        <form class="filter-bar" method="get" action="appointments.php">
            <div class="filter-field" style="flex:1;min-width:200px;">
                <label for="search">Search Patient</label>
                <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Patient name">
            </div>
            <div class="filter-field">
                <label for="date">Date</label>
                <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($date_filter); ?>">
            </div>
            <div class="filter-field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="pending_operation" <?php echo $status_filter === 'pending_operation' ? 'selected' : ''; ?>>Pending</option>
                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <div class="filter-field">
                <label for="sort">Sort</label>
                <select id="sort" name="sort">
                    <option value="time_asc"  <?php echo $sort === 'time_asc'  ? 'selected' : ''; ?>>Earliest First</option>
                    <option value="time_desc" <?php echo $sort === 'time_desc' ? 'selected' : ''; ?>>Latest First</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-filter">Filter</button>
                <a href="appointments.php" class="btn-reset">Today</a>
                <a href="appointments.php?date=&status=all" class="btn-reset">All Dates</a>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-head">
            <div><h2>Appointment List</h2><p><?php echo count($appointments); ?> result<?php echo count($appointments) === 1 ? '' : 's'; ?></p></div>
        </div>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Patient Name</th><th>Phone Number</th><th>Service</th><th>Date</th><th>Time</th><th>Status</th></tr></thead>
                <tbody>
                    <?php if (empty($appointments)): ?>
                        <tr class="empty-row"><td colspan="6">No appointments match these filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($appointments as $a): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($a['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($a['patient_phone'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($a['service_name']); ?></td>
                                <td><?php echo date('M j, Y', strtotime($a['appointment_date'])); ?></td>
                                <td><?php echo date('g:i A', strtotime($a['start_time'])); ?></td>
                                <td><span class="badge <?php echo htmlspecialchars($a['status']); ?>"><?php echo status_label($a['status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

</body>
</html>