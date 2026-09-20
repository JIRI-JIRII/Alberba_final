<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../func/functions.php';

checkLogin();
checkRole(['Dentist']);
/** @var mysqli $conn */

$dentist_username = $_SESSION['username'];
$dentist_id = $_SESSION['employee_id'] ?? 0;
if (!$dentist_id) {
    header('Location: ../login.php');
    exit;
}

// ---- Dentist's own name, for the welcome header ----
$stmt = mysqli_prepare($conn, "SELECT first_name, last_name, specialization FROM employees WHERE employee_id = ?");
mysqli_stmt_bind_param($stmt, "i", $dentist_id);
mysqli_stmt_execute($stmt);
$dentist = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

$today = date('Y-m-d');

// ---- Stat cards ----
// "Pending Appointments" here means confirmed-by-reception but not yet performed
// (status = pending_operation) — matches the scope doc's own definition: "the appointments
// the dentist has that are already checked by the receptionists... how many patients will
// be coming for the day", which reads as scoped to today specifically.
$pending_today = 0;
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM appointments WHERE dentist_id = ? AND appointment_date = ? AND status = 'pending_operation'");
mysqli_stmt_bind_param($stmt, "is", $dentist_id, $today);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$pending_today = (int) $row['total'];
mysqli_stmt_close($stmt);

$completed_today = 0;
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM appointments WHERE dentist_id = ? AND appointment_date = ? AND status = 'completed'");
mysqli_stmt_bind_param($stmt, "is", $dentist_id, $today);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$completed_today = (int) $row['total'];
mysqli_stmt_close($stmt);

// "Total Patients" has no date qualifier in the doc — cumulative, all-time distinct patients
$total_patients = 0;
$stmt = mysqli_prepare($conn, "SELECT COUNT(DISTINCT patient_id) AS total FROM appointments WHERE dentist_id = ?");
mysqli_stmt_bind_param($stmt, "i", $dentist_id);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$total_patients = (int) $row['total'];
mysqli_stmt_close($stmt);

// ---- Today's schedule (everything actually on the books today, not cancelled) ----
$stmt = mysqli_prepare($conn, "SELECT a.appointment_id, a.start_time, a.status,
                                       CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                                       p.contact_number AS patient_phone, s.service_name
                                FROM appointments a
                                JOIN patients p ON a.patient_id = p.patient_id
                                JOIN services s ON a.service_id = s.service_id
                                WHERE a.dentist_id = ? AND a.appointment_date = ? AND a.status != 'cancelled'
                                ORDER BY a.start_time ASC");
mysqli_stmt_bind_param($stmt, "is", $dentist_id, $today);
mysqli_stmt_execute($stmt);
$todays_schedule = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dentist Dashboard - Alberba Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/dentist.css">
</head>
<body>

<aside class="sidebar">
    <div class="brand">Alberba <span>Dental</span></div>

    <a href="dashboard.php" class="nav-link active">
        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
        Dashboard
    </a>
    <a href="appointments.php" class="nav-link">
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
            <h1>Dentist Dashboard</h1>
            <p class="today"><?php echo date('l, F j, Y'); ?></p>
        </div>
        <span class="welcome-pill">Welcome, Dr. <?php echo htmlspecialchars($dentist['first_name'] . ' ' . $dentist['last_name']); ?></span>
    </div>

    <div class="stats-grid">
        <div class="stat-card pending"><div class="label">Pending Today</div><div class="value"><?php echo $pending_today; ?></div></div>
        <div class="stat-card completed"><div class="label">Completed Today</div><div class="value"><?php echo $completed_today; ?></div></div>
        <div class="stat-card"><div class="label">Total Patients</div><div class="value"><?php echo $total_patients; ?></div></div>
    </div>

    <div class="card">
        <div class="card-head">
            <div><h2>Today's Schedule</h2><p><?php echo date('F j, Y'); ?><?php echo $dentist['specialization'] ? ' — ' . htmlspecialchars($dentist['specialization']) : ''; ?></p></div>
        </div>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Time</th><th>Patient</th><th>Phone</th><th>Service</th><th>Status</th></tr></thead>
                <tbody>
                    <?php if (empty($todays_schedule)): ?>
                        <tr class="empty-row"><td colspan="5">Nothing on your schedule for today.</td></tr>
                    <?php else: ?>
                        <?php foreach ($todays_schedule as $a): ?>
                            <tr>
                                <td><?php echo date('g:i A', strtotime($a['start_time'])); ?></td>
                                <td><?php echo htmlspecialchars($a['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($a['patient_phone'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($a['service_name']); ?></td>
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