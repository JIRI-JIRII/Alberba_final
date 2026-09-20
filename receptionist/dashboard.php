<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../func/functions.php';

checkLogin();
checkRole(['Receptionist']);
require_csrf();
/** @var mysqli $conn */

$receptionist_username = $_SESSION['username'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_appointment'])) {
    $appointment_id = intval($_POST['appointment_id']);
    $stmt = mysqli_prepare($conn, "UPDATE appointments SET status = 'pending_operation', confirmed_by = ? WHERE appointment_id = ? AND status = 'pending'");
    mysqli_stmt_bind_param($stmt, "ii", $_SESSION['user_id'], $appointment_id);
    if (mysqli_stmt_execute($stmt)) {
        $message = "Appointment confirmed and assigned to the dentist's schedule.";
    } else {
        $error = "Failed to confirm appointment.";
    }
    mysqli_stmt_close($stmt);
}

// ---- Handle: Cancel Appointment ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_appointment'])) {
    $appointment_id = intval($_POST['appointment_id']);
    $cancel_reason = sanitizeInput($_POST['cancel_reason'] ?? '');
    $stmt = mysqli_prepare($conn, "UPDATE appointments SET status = 'cancelled', cancel_reason = ? WHERE appointment_id = ? AND status = 'pending'");
    mysqli_stmt_bind_param($stmt, "si", $cancel_reason, $appointment_id);
    if (mysqli_stmt_execute($stmt)) {
        $message = "Appointment removed from the pending list.";
    } else {
        $error = "Failed to cancel appointment.";
    }
    mysqli_stmt_close($stmt);
}

$today = date('Y-m-d');

// ---- Stat cards ----
$todays_appointments = 0;
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM appointments WHERE appointment_date = ?");
mysqli_stmt_bind_param($stmt, "s", $today);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$todays_appointments = (int) $row['total'];
mysqli_stmt_close($stmt);

// "Pending Approval" has no date qualifier in the scope doc (unlike the other two stats,
// which both explicitly say "for the current day") — this is every pending request, any date
$pending_count = 0;
$res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM appointments WHERE status = 'pending'");
if ($res && $row = mysqli_fetch_assoc($res)) { $pending_count = (int) $row['total']; }

$confirmed_today = 0;
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM appointments WHERE appointment_date = ? AND status IN ('confirmed','pending_operation')");
mysqli_stmt_bind_param($stmt, "s", $today);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$confirmed_today = (int) $row['total'];
mysqli_stmt_close($stmt);

// ---- Pending Approval queue (actionable — Confirm / Cancel right here) ----
$res = mysqli_query($conn, "SELECT a.appointment_id, a.appointment_date, a.start_time, a.appointment_type,
                                    CONCAT(p.first_name, ' ', p.last_name) AS patient_name, p.contact_number AS patient_phone,
                                    CONCAT(e.first_name, ' ', e.last_name) AS dentist_name, s.service_name
                             FROM appointments a
                             JOIN patients p  ON a.patient_id = p.patient_id
                             JOIN employees e ON a.dentist_id = e.employee_id
                             JOIN services s  ON a.service_id = s.service_id
                             WHERE a.status = 'pending'
                             ORDER BY a.appointment_date ASC, a.start_time ASC");
$pending_appointments = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];

// ---- Today's schedule ----
$stmt = mysqli_prepare($conn, "SELECT a.appointment_id, a.start_time, a.status, a.appointment_type,
                                       CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                                       CONCAT(e.first_name, ' ', e.last_name) AS dentist_name, s.service_name
                                FROM appointments a
                                JOIN patients p  ON a.patient_id = p.patient_id
                                JOIN employees e ON a.dentist_id = e.employee_id
                                JOIN services s  ON a.service_id = s.service_id
                                WHERE a.appointment_date = ?
                                ORDER BY a.start_time ASC");
mysqli_stmt_bind_param($stmt, "s", $today);
mysqli_stmt_execute($stmt);
$todays_schedule = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receptionist Dashboard - Alberba Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/receptionist.css">
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
        Appointments
    </a>
    <a href="patients.php" class="nav-link">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M4.5 20.5v-1a7.5 7.5 0 0 1 15 0v1"/></svg>
        Patient Records
    </a>
    <a href="schedule.php" class="nav-link">
        <svg viewBox="0 0 24 24"><rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M16 2.5v4M8 2.5v4M3 10h18"/></svg>
        Calendar
    </a>
    <a href="payments.php" class="nav-link">
        <svg viewBox="0 0 24 24"><rect x="2.5" y="5.5" width="19" height="13" rx="2"/><path d="M2.5 10h19"/></svg>
        Payments
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
            <h1>Receptionist Dashboard</h1>
            <p class="today"><?php echo date('l, F j, Y'); ?></p>
        </div>
        <span class="welcome-pill">Welcome, <?php echo htmlspecialchars($receptionist_username); ?></span>
    </div>

    <?php if (!empty($message)): ?><div class="alert success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card"><div class="label">Today's Appointments</div><div class="value"><?php echo $todays_appointments; ?></div></div>
        <div class="stat-card pending"><div class="label">Pending Approval</div><div class="value"><?php echo $pending_count; ?></div></div>
        <div class="stat-card confirmed"><div class="label">Confirmed Today</div><div class="value"><?php echo $confirmed_today; ?></div></div>
    </div>

    <div class="card">
        <div class="card-head">
            <div><h2>Pending Approval</h2><p>Every appointment request awaiting a decision — confirm or cancel below</p></div>
        </div>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Patient</th><th>Contact</th><th>Dentist</th><th>Service</th><th>Date</th><th>Time</th><th>Type</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($pending_appointments)): ?>
                        <tr class="empty-row"><td colspan="8">Nothing waiting for approval right now.</td></tr>
                    <?php else: ?>
                        <?php foreach ($pending_appointments as $a): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($a['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($a['patient_phone'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($a['dentist_name']); ?></td>
                                <td><?php echo htmlspecialchars($a['service_name']); ?></td>
                                <td><?php echo date('M j, Y', strtotime($a['appointment_date'])); ?></td>
                                <td><?php echo date('g:i A', strtotime($a['start_time'])); ?></td>
                                <td><span class="badge <?php echo htmlspecialchars($a['appointment_type']); ?>"><?php echo ucfirst($a['appointment_type']); ?></span></td>
                                <td>
                                    <div class="action-buttons">
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Confirm this appointment and assign it to the dentist\'s schedule?');"><?php echo csrf_field(); ?>
                                            <input type="hidden" name="appointment_id" value="<?php echo $a['appointment_id']; ?>">
                                            <button type="submit" name="confirm_appointment" class="btn-sm btn-confirm">Confirm</button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return prepareCancel(this);"><?php echo csrf_field(); ?>
                                            <input type="hidden" name="appointment_id" value="<?php echo $a['appointment_id']; ?>">
                                            <input type="hidden" name="cancel_reason" class="cancel-reason-field" value="">
                                            <button type="submit" name="cancel_appointment" class="btn-sm btn-cancel">Cancel</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div><h2>Today's Schedule</h2><p>Every appointment on the books for <?php echo date('F j, Y'); ?></p></div>
        </div>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Time</th><th>Patient</th><th>Dentist</th><th>Service</th><th>Type</th><th>Status</th></tr></thead>
                <tbody>
                    <?php if (empty($todays_schedule)): ?>
                        <tr class="empty-row"><td colspan="6">No appointments scheduled for today.</td></tr>
                    <?php else: ?>
                        <?php foreach ($todays_schedule as $a): ?>
                            <tr>
                                <td><?php echo date('g:i A', strtotime($a['start_time'])); ?></td>
                                <td><?php echo htmlspecialchars($a['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($a['dentist_name']); ?></td>
                                <td><?php echo htmlspecialchars($a['service_name']); ?></td>
                                <td><span class="badge <?php echo htmlspecialchars($a['appointment_type']); ?>"><?php echo ucfirst($a['appointment_type']); ?></span></td>
                                <td><span class="badge <?php echo htmlspecialchars($a['status']); ?>"><?php echo status_label($a['status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script>
    // capture a cancellation reason via a native prompt, then let confirm() gate the submit
    function prepareCancel(form) {
        const reason = prompt('Reason for cancelling this appointment (optional):', '');
        if (reason === null) return false;
        form.querySelector('.cancel-reason-field').value = reason;
        return confirm('Remove this appointment from the pending list?');
    }

    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(alert => {
            alert.style.transition = 'opacity .5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        });
    }, 5000);
</script>
</body>
</html>