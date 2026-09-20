<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../func/functions.php';

checkLogin();
checkRole(['Patient']);
require_csrf();
/** @var mysqli $conn */
$patient_id = $_SESSION['patient_id'] ?? 0;
if (!$patient_id) {
    header('Location: ../login.php');
    exit;
}

// Patients can cancel online up until this many hours before the appointment start.
// The scope doc says "before a specified cutoff time" without naming a number — 24 hours
// is a common clinic default; adjust this single constant if the clinic wants something else.
const CANCEL_CUTOFF_HOURS = 24;

$message = '';
$error = '';

// ---- Handle: Confirm Booking ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_booking'])) {
    $service_id = intval($_POST['service_id']);
    $dentist_id = intval($_POST['dentist_id']);
    $date       = $_POST['date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';

    $svc_stmt = mysqli_prepare($conn, "SELECT duration_minutes FROM services WHERE service_id = ? AND deleted_at IS NULL AND is_active = 1");
    mysqli_stmt_bind_param($svc_stmt, "i", $service_id);
    mysqli_stmt_execute($svc_stmt);
    $svc = mysqli_fetch_assoc(mysqli_stmt_get_result($svc_stmt));
    mysqli_stmt_close($svc_stmt);

    if (!$svc) {
        $error = "That service is no longer available. Please choose another.";
    } else {
        // re-check availability server-side — never trust the posted time slot on its own,
        // it could be stale (someone else booked it) or tampered with
        $fresh_slots = get_available_slots($conn, $dentist_id, $date, (int) $svc['duration_minutes']);
        $match = null;
        foreach ($fresh_slots as $s) { if ($s['start'] === $start_time) { $match = $s; break; } }

        if (!$match) {
            $error = "That time slot is no longer available. Please pick another.";
        } else {
            // Books inside a transaction that locks this dentist's day, so two patients
            // racing for the same slot can't both succeed.
            $new_id = book_appointment_safely(
                $conn, $patient_id, $dentist_id, $service_id, $date,
                $match['start'], $match['end'], 'online', 'pending', (int) $_SESSION['user_id']
            );

            if ($new_id !== null) {
                header('Location: appointments.php?booked=1');
                exit;
            }

            $error = "That time slot was just booked by someone else. Please pick another.";
        }
    }
}

// ---- Handle: Cancel Appointment ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_appointment'])) {
    $appointment_id = intval($_POST['appointment_id']);

    // ownership check is essential here — never cancel an appointment without confirming
    // it actually belongs to the logged-in patient
    $stmt = mysqli_prepare($conn, "SELECT appointment_date, start_time, status FROM appointments WHERE appointment_id = ? AND patient_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $appointment_id, $patient_id);
    mysqli_stmt_execute($stmt);
    $appt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$appt) {
        $error = "Appointment not found.";
    } elseif (!in_array($appt['status'], ['pending', 'confirmed', 'pending_operation'], true)) {
        $error = "This appointment can no longer be cancelled.";
    } else {
        $appt_time = new DateTime($appt['appointment_date'] . ' ' . $appt['start_time']);
        $cutoff = (new DateTime())->modify('+' . CANCEL_CUTOFF_HOURS . ' hours');
        if ($appt_time < $cutoff) {
            $error = "This appointment is within " . CANCEL_CUTOFF_HOURS . " hours and can no longer be cancelled online. Please call the clinic directly.";
        } else {
            $stmt2 = mysqli_prepare($conn, "UPDATE appointments SET status = 'cancelled', cancel_reason = 'Cancelled by patient' WHERE appointment_id = ? AND patient_id = ?");
            mysqli_stmt_bind_param($stmt2, "ii", $appointment_id, $patient_id);
            mysqli_stmt_execute($stmt2);
            mysqli_stmt_close($stmt2);
            header('Location: appointments.php?cancelled=1');
            exit;
        }
    }
}

if (isset($_GET['booked']))    { $message = "Appointment requested! We'll confirm it shortly."; }
if (isset($_GET['cancelled'])) { $message = "Appointment cancelled."; }

$view = $_GET['view'] ?? 'list';
$tab  = $_GET['tab'] ?? 'upcoming';

// ---- Booking form data ----
$services_list = [];
$res = mysqli_query($conn, "SELECT service_id, service_name, category, duration_minutes, price FROM services WHERE deleted_at IS NULL AND is_active = 1 ORDER BY service_name");
if ($res) { while ($row = mysqli_fetch_assoc($res)) { $services_list[] = $row; } }

$dentists_list = [];
$res = mysqli_query($conn, "SELECT e.employee_id, CONCAT(e.first_name, ' ', e.last_name) AS dentist_name, e.specialization
                             FROM employees e JOIN users u ON e.user_id = u.user_id JOIN roles r ON u.role_id = r.role_id
                             WHERE r.role_name = 'Dentist' AND u.account_status = 'active' ORDER BY e.first_name");
if ($res) { while ($row = mysqli_fetch_assoc($res)) { $dentists_list[] = $row; } }

$selected_service_id = $_GET['service_id'] ?? '';
$selected_dentist_id  = $_GET['dentist_id'] ?? '';
$selected_date        = $_GET['date'] ?? '';
$available_slots      = [];
$selected_service     = null;

if ($view === 'book' && $selected_service_id && $selected_dentist_id && $selected_date) {
    foreach ($services_list as $s) { if ((string) $s['service_id'] === (string) $selected_service_id) { $selected_service = $s; break; } }
    if ($selected_service && $selected_date >= date('Y-m-d')) {
        $available_slots = get_available_slots($conn, (int) $selected_dentist_id, $selected_date, (int) $selected_service['duration_minutes']);
    }
}

// ---- Appointment list (tab-filtered) ----
$conditions = ["a.patient_id = ?"];
$types = "i"; $params = [$patient_id];

if ($tab === 'upcoming') {
    $conditions[] = "a.appointment_date >= CURDATE() AND a.status IN ('pending','confirmed','pending_operation')";
} elseif ($tab === 'completed') {
    $conditions[] = "a.status = 'completed'";
} elseif ($tab === 'cancelled') {
    $conditions[] = "a.status = 'cancelled'";
}
$where_clause = implode(' AND ', $conditions);

$sql = "SELECT a.appointment_id, a.appointment_date, a.start_time, a.status, a.appointment_type,
               CONCAT(e.first_name, ' ', e.last_name) AS dentist_name, s.service_name
        FROM appointments a
        JOIN employees e ON a.dentist_id = e.employee_id
        JOIN services s  ON a.service_id = s.service_id
        WHERE $where_clause
        ORDER BY a.appointment_date DESC, a.start_time DESC";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$appointments = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$cutoff_now = new DateTime();
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
<link rel="stylesheet" href="../assets/patient.css">
<style>
    .tab-bar{display:flex;gap:.6rem;margin-bottom:1.4rem;}
    .tab-link{padding:.6rem 1.2rem;border-radius:100px;font-size:.86rem;font-weight:600;color:var(--text-gray);border:1.5px solid var(--border-color);background:var(--white);}
    .tab-link.active{background:var(--primary-pink);border-color:var(--primary-pink);color:var(--white);}
    .slot-grid{display:grid;grid-template-columns:repeat(auto-fill, minmax(110px, 1fr));gap:.7rem;margin:1rem 0;}
    .slot-option{position:relative;}
    .slot-option input{position:absolute;opacity:0;}
    .slot-option label{display:block;text-align:center;padding:.7rem .5rem;border:1.5px solid var(--border-color);border-radius:10px;font-size:.85rem;font-weight:600;color:var(--text-dark);cursor:pointer;background:var(--ivory);}
    .slot-option input:checked + label{background:var(--primary-pink);border-color:var(--primary-pink);color:var(--white);}
    .slot-option label:hover{border-color:var(--primary-pink);}
    .cutoff-note{font-size:.78rem;color:var(--text-gray);}
</style>
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
    <div class="topbar">
        <div>
            <h1>My Appointments</h1>
            <p class="subtitle">Book a new visit, or manage what's already on the calendar</p>
        </div>
        <?php if ($view !== 'book'): ?>
            <a href="appointments.php?view=book" class="btn-primary-sm">+ Book an Appointment</a>
        <?php else: ?>
            <a href="appointments.php" class="btn-ghost-sm">&larr; Back to My Appointments</a>
        <?php endif; ?>
    </div>

    <?php if (!empty($message)): ?><div class="alert success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <?php if ($view === 'book'): ?>

        <div class="card">
            <div class="card-head"><div><h2>1. Choose a Service, Dentist &amp; Date</h2></div></div>
            <form class="filter-bar" method="get" action="appointments.php">
                <input type="hidden" name="view" value="book">
                <div class="filter-field" style="min-width:220px;">
                    <label for="service_id">Service</label>
                    <select id="service_id" name="service_id" required>
                        <option value="">Select a service…</option>
                        <?php foreach ($services_list as $svc): ?>
                            <option value="<?php echo $svc['service_id']; ?>" <?php echo (string) $selected_service_id === (string) $svc['service_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($svc['service_name']); ?> (<?php echo $svc['duration_minutes']; ?> min, <?php echo format_currency($svc['price']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field" style="min-width:200px;">
                    <label for="dentist_id">Dentist</label>
                    <select id="dentist_id" name="dentist_id" required>
                        <option value="">Select a dentist…</option>
                        <?php foreach ($dentists_list as $d): ?>
                            <option value="<?php echo $d['employee_id']; ?>" <?php echo (string) $selected_dentist_id === (string) $d['employee_id'] ? 'selected' : ''; ?>>
                                Dr. <?php echo htmlspecialchars($d['dentist_name']); ?><?php echo $d['specialization'] ? ' — ' . htmlspecialchars($d['specialization']) : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="date">Date</label>
                    <input type="date" id="date" name="date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($selected_date); ?>" required>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-filter">Find Available Times</button>
                </div>
            </form>
        </div>

        <?php if ($selected_service_id && $selected_dentist_id && $selected_date): ?>
            <div class="card">
                <div class="card-head"><div><h2>2. Pick a Time</h2><p><?php echo $selected_service ? htmlspecialchars($selected_service['service_name']) . ' — ' : ''; ?><?php echo date('l, F j, Y', strtotime($selected_date)); ?></p></div></div>

                <?php if (empty($dentists_list)): ?>
                    <p style="color:var(--text-gray);">No dentists are available to book right now — please check back later or call the clinic.</p>
                <?php elseif (!$selected_service): ?>
                    <p style="color:var(--text-gray);">That service isn't available anymore. Please choose another above.</p>
                <?php elseif (empty($available_slots)): ?>
                    <p style="color:var(--text-gray);">No open times for this dentist on this date — the clinic may be closed, fully booked, or the date has already passed. Try another date or dentist above.</p>
                <?php else: ?>
                    <form method="POST"><?php echo csrf_field(); ?>
                        <input type="hidden" name="service_id" value="<?php echo htmlspecialchars($selected_service_id); ?>">
                        <input type="hidden" name="dentist_id" value="<?php echo htmlspecialchars($selected_dentist_id); ?>">
                        <input type="hidden" name="date" value="<?php echo htmlspecialchars($selected_date); ?>">
                        <div class="slot-grid">
                            <?php foreach ($available_slots as $i => $slot): ?>
                                <div class="slot-option">
                                    <input type="radio" name="start_time" id="slot<?php echo $i; ?>" value="<?php echo $slot['start']; ?>" <?php echo $i === 0 ? 'checked' : ''; ?> required>
                                    <label for="slot<?php echo $i; ?>"><?php echo $slot['display']; ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="submit" name="confirm_booking" class="btn-primary-sm">Confirm Booking</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>

        <div class="tab-bar">
            <a href="appointments.php?tab=upcoming"  class="tab-link <?php echo $tab === 'upcoming'  ? 'active' : ''; ?>">Upcoming</a>
            <a href="appointments.php?tab=completed" class="tab-link <?php echo $tab === 'completed' ? 'active' : ''; ?>">Completed</a>
            <a href="appointments.php?tab=cancelled" class="tab-link <?php echo $tab === 'cancelled' ? 'active' : ''; ?>">Cancelled</a>
            <a href="appointments.php?tab=all"       class="tab-link <?php echo $tab === 'all'       ? 'active' : ''; ?>">All</a>
        </div>

        <div class="card">
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Date</th><th>Time</th><th>Service</th><th>Dentist</th><th>Type</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($appointments)): ?>
                            <tr class="empty-row"><td colspan="7">No appointments here yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($appointments as $a):
                                $appt_time = new DateTime($a['appointment_date'] . ' ' . $a['start_time']);
                                $cutoff = (clone $cutoff_now)->modify('+' . CANCEL_CUTOFF_HOURS . ' hours');
                                $can_cancel = in_array($a['status'], ['pending','confirmed','pending_operation'], true) && $appt_time >= $cutoff;
                                $within_cutoff = in_array($a['status'], ['pending','confirmed','pending_operation'], true) && $appt_time < $cutoff && $appt_time >= $cutoff_now;
                            ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($a['appointment_date'])); ?></td>
                                    <td><?php echo date('g:i A', strtotime($a['start_time'])); ?></td>
                                    <td><?php echo htmlspecialchars($a['service_name']); ?></td>
                                    <td><?php echo htmlspecialchars($a['dentist_name']); ?></td>
                                    <td><span class="badge <?php echo htmlspecialchars($a['appointment_type']); ?>"><?php echo ucfirst($a['appointment_type']); ?></span></td>
                                    <td><span class="badge <?php echo htmlspecialchars($a['status']); ?>"><?php echo status_label($a['status']); ?></span></td>
                                    <td>
                                        <?php if ($can_cancel): ?>
                                            <form method="POST" onsubmit="return confirm('Cancel this appointment?');"><?php echo csrf_field(); ?>
                                                <input type="hidden" name="appointment_id" value="<?php echo $a['appointment_id']; ?>">
                                                <button type="submit" name="cancel_appointment" class="btn-sm btn-cancel">Cancel</button>
                                            </form>
                                        <?php elseif ($within_cutoff): ?>
                                            <span class="cutoff-note">Call clinic to cancel</span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>
</main>

<script>
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