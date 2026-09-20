<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../func/functions.php';

checkLogin();
checkRole(['Patient']);
/** @var mysqli $conn */

$patient_id = $_SESSION['patient_id'] ?? 0;
if (!$patient_id) {
    // session was created before patient_id existed, or the linked patient row is gone
    header('Location: ../login.php');
    exit;
}

// ---- Patient profile summary ----
$stmt = mysqli_prepare($conn, "SELECT p.first_name, p.last_name, p.contact_number, p.created_at, u.email, u.username
                                FROM patients p
                                JOIN users u ON p.user_id = u.user_id
                                WHERE p.patient_id = ?");
mysqli_stmt_bind_param($stmt, "i", $patient_id);
mysqli_stmt_execute($stmt);
$patient = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$patient) {
    header('Location: ../login.php');
    exit;
}

// ---- Stats: Upcoming / Completed / Total Visits ----
// "Total Visits" is read as overall engagement (every appointment ever booked, any status),
// distinct from "Completed Appointments" (only the ones actually finished) — otherwise the
// two stat cards would just show the same number.
$stats = ['upcoming' => 0, 'completed' => 0, 'total' => 0];

$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM appointments
                                WHERE patient_id = ? AND appointment_date >= CURDATE() AND status != 'cancelled'");
mysqli_stmt_bind_param($stmt, "i", $patient_id);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$stats['upcoming'] = (int) $row['total'];
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM appointments WHERE patient_id = ? AND status = 'completed'");
mysqli_stmt_bind_param($stmt, "i", $patient_id);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$stats['completed'] = (int) $row['total'];
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM appointments WHERE patient_id = ?");
mysqli_stmt_bind_param($stmt, "i", $patient_id);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$stats['total'] = (int) $row['total'];
mysqli_stmt_close($stmt);

// ---- Upcoming appointments list ----
$stmt = mysqli_prepare($conn, "SELECT a.appointment_id, a.appointment_date, a.start_time, a.status, a.appointment_type,
                                       CONCAT(e.first_name, ' ', e.last_name) AS dentist_name, s.service_name
                                FROM appointments a
                                JOIN employees e ON a.dentist_id = e.employee_id
                                JOIN services s  ON a.service_id = s.service_id
                                WHERE a.patient_id = ? AND a.appointment_date >= CURDATE() AND a.status != 'cancelled'
                                ORDER BY a.appointment_date ASC, a.start_time ASC");
mysqli_stmt_bind_param($stmt, "i", $patient_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$upcoming_appointments = mysqli_fetch_all($res, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$next_appointment = $upcoming_appointments[0] ?? null;

// friendly greeting time-of-day
$hour = (int) date('G');
if ($hour < 12) { $greeting = 'Good morning'; }
elseif ($hour < 18) { $greeting = 'Good afternoon'; }
else { $greeting = 'Good evening'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Dashboard - Alberba Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/patient.css">
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
    <a href="profile.php" class="nav-link">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M4.5 20.5v-1a7.5 7.5 0 0 1 15 0v1"/></svg>
        Profile
    </a>

    <div class="sidebar-footer">
        <a href="../logout.php" class="nav-link">
            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
            Log Out
        </a>
    </div>
</aside>

<main class="main">
    <div class="greeting-hero">
        <div>
            <h2><?php echo $greeting; ?>, <?php echo htmlspecialchars($patient['first_name']); ?> :)</h2>
            <p><?php echo date('l, F j, Y'); ?> — here's what's coming up for your smile.</p>
        </div>
        <a href="appointments.php?action=book" class="btn-primary-sm">+ Book an Appointment</a>
    </div>

    <div class="stats-grid">
        <div class="stat-card confirmed"><div class="label">Upcoming Appointments</div><div class="value"><?php echo $stats['upcoming']; ?></div></div>
        <div class="stat-card completed"><div class="label">Completed Appointments</div><div class="value"><?php echo $stats['completed']; ?></div></div>
        <div class="stat-card"><div class="label">Total Visits</div><div class="value"><?php echo $stats['total']; ?></div></div>
    </div>

    <?php if ($next_appointment): ?>
        <div class="next-appt">
            <div>
                <div class="eyebrow">Your Next Visit</div>
                <h3><?php echo htmlspecialchars($next_appointment['service_name']); ?> with <?php echo htmlspecialchars($next_appointment['dentist_name']); ?></h3>
                <p><?php echo date('l, F j, Y', strtotime($next_appointment['appointment_date'])); ?> at <?php echo date('g:i A', strtotime($next_appointment['start_time'])); ?></p>
            </div>
            <span class="badge <?php echo htmlspecialchars($next_appointment['status']); ?>"><?php echo status_label($next_appointment['status']); ?></span>
        </div>
    <?php else: ?>
        <div class="next-appt-empty">
            <p>You don't have any upcoming appointments yet.</p>
            <p style="margin-top:.6rem;"><a href="appointments.php?action=book" class="btn-ghost-sm" style="display:inline-block;">Book your next visit</a></p>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-head">
            <div><h2>Upcoming Appointments</h2><p>Everything you have scheduled from today onward</p></div>
        </div>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Date</th><th>Time</th><th>Service</th><th>Dentist</th><th>Type</th><th>Status</th></tr></thead>
                <tbody>
                    <?php if (empty($upcoming_appointments)): ?>
                        <tr class="empty-row"><td colspan="6">Nothing scheduled yet — book your next visit whenever you're ready.</td></tr>
                    <?php else: ?>
                        <?php foreach ($upcoming_appointments as $a): ?>
                            <tr>
                                <td><?php echo date('M j, Y', strtotime($a['appointment_date'])); ?></td>
                                <td><?php echo date('g:i A', strtotime($a['start_time'])); ?></td>
                                <td><?php echo htmlspecialchars($a['service_name']); ?></td>
                                <td><?php echo htmlspecialchars($a['dentist_name']); ?></td>
                                <td><span class="badge <?php echo htmlspecialchars($a['appointment_type']); ?>"><?php echo ucfirst($a['appointment_type']); ?></span></td>
                                <td><span class="badge <?php echo htmlspecialchars($a['status']); ?>"><?php echo status_label($a['status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div><h2>Your Profile</h2><p>Reference info — visit <a href="profile.php" style="color:var(--accent-pink);font-weight:600;">Profile</a> to make changes</p></div>
        </div>
        <div class="info-grid">
            <div class="info-item"><div class="info-label">Full Name</div><div class="info-value"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></div></div>
            <div class="info-item"><div class="info-label">Username</div><div class="info-value"><?php echo htmlspecialchars($patient['username']); ?></div></div>
            <div class="info-item"><div class="info-label">Email</div><div class="info-value"><?php echo htmlspecialchars($patient['email']); ?></div></div>
            <div class="info-item"><div class="info-label">Contact Number</div><div class="info-value"><?php echo htmlspecialchars($patient['contact_number'] ?? '—'); ?></div></div>
            <div class="info-item"><div class="info-label">Patient Since</div><div class="info-value"><?php echo date('F Y', strtotime($patient['created_at'])); ?></div></div>
        </div>
    </div>
</main>

</body>
</html>