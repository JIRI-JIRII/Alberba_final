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

// ---- Handle: Confirm Appointment (same status transition as the dashboard's queue) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_appointment'])) {
    $appointment_id = intval($_POST['appointment_id']);
    $stmt = mysqli_prepare($conn, "UPDATE appointments SET status = 'pending_operation', confirmed_by = ? WHERE appointment_id = ? AND status = 'pending'");
    mysqli_stmt_bind_param($stmt, "ii", $_SESSION['user_id'], $appointment_id);
    $message = mysqli_stmt_execute($stmt) ? "Appointment confirmed and assigned to the dentist's schedule." : '';
    if (!$message) { $error = "Failed to confirm appointment."; }
    mysqli_stmt_close($stmt);
}

// ---- Handle: Cancel Appointment ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_appointment'])) {
    $appointment_id = intval($_POST['appointment_id']);
    $cancel_reason = sanitizeInput($_POST['cancel_reason'] ?? '');
    $stmt = mysqli_prepare($conn, "UPDATE appointments SET status = 'cancelled', cancel_reason = ? WHERE appointment_id = ?");
    mysqli_stmt_bind_param($stmt, "si", $cancel_reason, $appointment_id);
    $message = mysqli_stmt_execute($stmt) ? "Appointment cancelled." : '';
    if (!$message) { $error = "Failed to cancel appointment."; }
    mysqli_stmt_close($stmt);
}

// ---- Handle: Complete Appointment ----
// Receptionist marks a visit complete at checkout — this is also the trigger point for
// billing (see Payments), so it makes sense for the front desk to control this transition.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_appointment'])) {
    $appointment_id = intval($_POST['appointment_id']);
    $stmt = mysqli_prepare($conn, "UPDATE appointments SET status = 'completed' WHERE appointment_id = ? AND status = 'pending_operation'");
    mysqli_stmt_bind_param($stmt, "i", $appointment_id);
    $message = mysqli_stmt_execute($stmt) ? "Appointment marked as completed." : '';
    if (!$message) { $error = "Failed to update appointment."; }
    mysqli_stmt_close($stmt);
}

// ---- Handle: Confirm Walk-in Booking ----
// A receptionist booking a walk-in in person has already effectively "reviewed" it —
// there's no need to route it through the online pending-approval queue the way a
// patient's self-service booking does. It goes straight to pending_operation, confirmed
// by whoever's at the front desk right now.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_walkin'])) {
    $walkin_patient_id = intval($_POST['patient_id']);
    $service_id = intval($_POST['service_id']);
    $dentist_id = intval($_POST['dentist_id']);
    $date       = $_POST['date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';

    $svc_stmt = mysqli_prepare($conn, "SELECT duration_minutes FROM services WHERE service_id = ? AND deleted_at IS NULL AND is_active = 1");
    mysqli_stmt_bind_param($svc_stmt, "i", $service_id);
    mysqli_stmt_execute($svc_stmt);
    $svc = mysqli_fetch_assoc(mysqli_stmt_get_result($svc_stmt));
    mysqli_stmt_close($svc_stmt);

    $patient_stmt = mysqli_prepare($conn, "SELECT patient_id FROM patients WHERE patient_id = ?");
    mysqli_stmt_bind_param($patient_stmt, "i", $walkin_patient_id);
    mysqli_stmt_execute($patient_stmt);
    $patient_exists = mysqli_fetch_assoc(mysqli_stmt_get_result($patient_stmt)) !== null;
    mysqli_stmt_close($patient_stmt);

    if (!$svc) {
        $error = "That service is no longer available.";
    } elseif (!$patient_exists) {
        $error = "That patient record couldn't be found. If they're new, register them in Patient Records first.";
    } else {
        $fresh_slots = get_available_slots($conn, $dentist_id, $date, (int) $svc['duration_minutes']);
        $match = null;
        foreach ($fresh_slots as $s) { if ($s['start'] === $start_time) { $match = $s; break; } }

        if (!$match) {
            $error = "That time slot is no longer available. Please pick another.";
        } else {
            // Books inside a transaction that locks this dentist's day, so a walk-in and
            // an online booking racing for the same slot can't both succeed.
            $new_id = book_appointment_safely(
                $conn, (int) $walkin_patient_id, $dentist_id, $service_id, $date,
                $match['start'], $match['end'], 'walk-in', 'pending_operation',
                (int) $_SESSION['user_id'], (int) $_SESSION['user_id']
            );

            if ($new_id !== null) {
                header('Location: appointments.php?booked=1');
                exit;
            }

            $error = "That time slot was just booked by someone else. Please pick another.";
        }
    }
}

if (isset($_GET['booked'])) { $message = "Walk-in appointment booked and confirmed."; }

$view = $_GET['view'] ?? 'list';

// ==================================================================
// WALK-IN BOOKING FLOW
// ==================================================================
$patient_search_results = [];
$selected_patient = null;
$services_list = [];
$dentists_list = [];
$available_slots = [];
$selected_service = null;

if ($view === 'book') {
    $patient_search = trim($_GET['patient_search'] ?? '');
    $walkin_patient_id = $_GET['patient_id'] ?? '';

    if ($walkin_patient_id) {
        $stmt = mysqli_prepare($conn, "SELECT p.patient_id, p.first_name, p.last_name, p.contact_number, u.email
                                        FROM patients p JOIN users u ON p.user_id = u.user_id
                                        WHERE p.patient_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $walkin_patient_id);
        mysqli_stmt_execute($stmt);
        $selected_patient = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
    } elseif ($patient_search !== '') {
        $s = mysqli_real_escape_string($conn, $patient_search);
        $res = mysqli_query($conn, "SELECT p.patient_id, p.first_name, p.last_name, p.contact_number, u.email
                                     FROM patients p JOIN users u ON p.user_id = u.user_id
                                     WHERE p.first_name LIKE '%$s%' OR p.last_name LIKE '%$s%' OR p.contact_number LIKE '%$s%' OR u.email LIKE '%$s%'
                                     ORDER BY p.first_name LIMIT 15");
        $patient_search_results = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    }

    if ($selected_patient) {
        $res = mysqli_query($conn, "SELECT service_id, service_name, duration_minutes, price FROM services WHERE deleted_at IS NULL AND is_active = 1 ORDER BY service_name");
        if ($res) { while ($row = mysqli_fetch_assoc($res)) { $services_list[] = $row; } }

        $res = mysqli_query($conn, "SELECT e.employee_id, CONCAT(e.first_name, ' ', e.last_name) AS dentist_name, e.specialization
                                     FROM employees e JOIN users u ON e.user_id = u.user_id JOIN roles r ON u.role_id = r.role_id
                                     WHERE r.role_name = 'Dentist' AND u.account_status = 'active' ORDER BY e.first_name");
        if ($res) { while ($row = mysqli_fetch_assoc($res)) { $dentists_list[] = $row; } }

        $selected_service_id = $_GET['service_id'] ?? '';
        $selected_dentist_id  = $_GET['dentist_id'] ?? '';
        $selected_date        = $_GET['date'] ?? '';

        if ($selected_service_id && $selected_dentist_id && $selected_date) {
            foreach ($services_list as $s) { if ((string) $s['service_id'] === (string) $selected_service_id) { $selected_service = $s; break; } }
            if ($selected_service && $selected_date >= date('Y-m-d')) {
                $available_slots = get_available_slots($conn, (int) $selected_dentist_id, $selected_date, (int) $selected_service['duration_minutes']);
            }
        }
    }
}

// ==================================================================
// FULL APPOINTMENT LIST
// ==================================================================
$appointments = [];
$services_filter_list = [];
$search = '';

if ($view === 'list') {
    $search        = trim($_GET['search'] ?? '');
    $status_filter = $_GET['status'] ?? 'all';
    $service_filter = $_GET['service_id'] ?? 'all';
    $date_from     = $_GET['date_from'] ?? '';
    $date_to       = $_GET['date_to'] ?? '';
    $sort          = $_GET['sort'] ?? 'date_desc';

    $conditions = ["1=1"];
    if ($search !== '') {
        $s = mysqli_real_escape_string($conn, $search);
        $conditions[] = "(p.first_name LIKE '%$s%' OR p.last_name LIKE '%$s%')";
    }
    if ($status_filter !== 'all') { $conditions[] = "a.status = '" . mysqli_real_escape_string($conn, $status_filter) . "'"; }
    if ($service_filter !== 'all' && $service_filter !== '') { $conditions[] = "a.service_id = " . (int) $service_filter; }
    if ($date_from !== '') { $conditions[] = "a.appointment_date >= '" . mysqli_real_escape_string($conn, $date_from) . "'"; }
    if ($date_to !== '')   { $conditions[] = "a.appointment_date <= '" . mysqli_real_escape_string($conn, $date_to) . "'"; }
    $where_clause = implode(' AND ', $conditions);

    $order_by = "a.appointment_date DESC, a.start_time DESC";
    if ($sort === 'date_asc') { $order_by = "a.appointment_date ASC, a.start_time ASC"; }

    $query = "SELECT a.appointment_id, a.appointment_date, a.start_time, a.end_time, a.appointment_type, a.status,
                     CONCAT(p.first_name, ' ', p.last_name) AS patient_name, p.contact_number AS patient_phone,
                     CONCAT(e.first_name, ' ', e.last_name) AS dentist_name, s.service_name
              FROM appointments a
              JOIN patients p  ON a.patient_id = p.patient_id
              JOIN employees e ON a.dentist_id = e.employee_id
              JOIN services s  ON a.service_id = s.service_id
              WHERE $where_clause
              ORDER BY $order_by";
    $result = mysqli_query($conn, $query);
    $appointments = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];

    $res = mysqli_query($conn, "SELECT service_id, service_name FROM services WHERE deleted_at IS NULL ORDER BY service_name");
    if ($res) { while ($row = mysqli_fetch_assoc($res)) { $services_filter_list[] = $row; } }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Appointments - Alberba Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/receptionist.css">
<style>
    .patient-result{display:flex;justify-content:space-between;align-items:center;padding:.9rem 1rem;border:1.5px solid var(--border-color);border-radius:10px;margin-bottom:.6rem;}
    .patient-result:hover{border-color:var(--primary-pink);}
    .patient-result .pname{font-weight:600;color:var(--text-dark);}
    .patient-result .pmeta{font-size:.82rem;color:var(--text-gray);}
    .slot-grid{display:grid;grid-template-columns:repeat(auto-fill, minmax(110px, 1fr));gap:.7rem;margin:1rem 0;}
    .slot-option{position:relative;}
    .slot-option input{position:absolute;opacity:0;}
    .slot-option label{display:block;text-align:center;padding:.7rem .5rem;border:1.5px solid var(--border-color);border-radius:10px;font-size:.85rem;font-weight:600;color:var(--text-dark);cursor:pointer;background:var(--ivory);}
    .slot-option input:checked + label{background:var(--primary-pink);border-color:var(--primary-pink);color:var(--white);}
    .selected-patient-banner{background:var(--light-pink);border-radius:14px;padding:1rem 1.3rem;margin-bottom:1.4rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.8rem;}
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
            <h1>Appointments</h1>
            <p class="subtitle">Search the full appointment book, or book a walk-in on the spot</p>
        </div>
        <?php if ($view !== 'book'): ?>
            <a href="appointments.php?view=book" class="btn-primary-sm">+ Book Walk-in</a>
        <?php else: ?>
            <a href="appointments.php" class="btn-ghost-sm">&larr; Back to Appointments</a>
        <?php endif; ?>
    </div>

    <?php if (!empty($message)): ?><div class="alert success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <?php if ($view === 'book'): ?>

        <?php if (!$selected_patient): ?>
            <div class="card">
                <div class="card-head"><div><h2>1. Find the Patient</h2></div></div>
                <form class="filter-bar" method="get" action="appointments.php">
                    <input type="hidden" name="view" value="book">
                    <div class="filter-field" style="flex:1;min-width:240px;">
                        <label for="patient_search">Search by name, contact number, or email</label>
                        <input type="text" id="patient_search" name="patient_search" value="<?php echo htmlspecialchars($_GET['patient_search'] ?? ''); ?>" placeholder="e.g. Maria Santos">
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn-filter">Search</button>
                    </div>
                </form>

                <?php if (!empty($_GET['patient_search'])): ?>
                    <?php if (empty($patient_search_results)): ?>
                        <p style="color:var(--text-gray);margin-top:1rem;">No matching patient found. If they're new, register them first in <a href="patients.php" style="color:var(--accent-pink);font-weight:600;">Patient Records</a>, then come back here to book.</p>
                    <?php else: ?>
                        <div style="margin-top:1.2rem;">
                            <?php foreach ($patient_search_results as $p): ?>
                                <a href="appointments.php?view=book&patient_id=<?php echo $p['patient_id']; ?>" class="patient-result" style="display:flex;text-decoration:none;">
                                    <div>
                                        <div class="pname"><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></div>
                                        <div class="pmeta"><?php echo htmlspecialchars($p['contact_number'] ?? '—'); ?> · <?php echo htmlspecialchars($p['email']); ?></div>
                                    </div>
                                    <span class="btn-sm btn-view">Select</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        <?php else: ?>

            <div class="selected-patient-banner">
                <div>
                    <div class="pname"><?php echo htmlspecialchars($selected_patient['first_name'] . ' ' . $selected_patient['last_name']); ?></div>
                    <div class="pmeta"><?php echo htmlspecialchars($selected_patient['contact_number'] ?? '—'); ?> · <?php echo htmlspecialchars($selected_patient['email']); ?></div>
                </div>
                <a href="appointments.php?view=book" class="btn-ghost-sm">Change Patient</a>
            </div>

            <div class="card">
                <div class="card-head"><div><h2>2. Choose a Service, Dentist &amp; Date</h2></div></div>
                <form class="filter-bar" method="get" action="appointments.php">
                    <input type="hidden" name="view" value="book">
                    <input type="hidden" name="patient_id" value="<?php echo $selected_patient['patient_id']; ?>">
                    <div class="filter-field" style="min-width:220px;">
                        <label for="service_id">Service</label>
                        <select id="service_id" name="service_id" required>
                            <option value="">Select a service…</option>
                            <?php foreach ($services_list as $svc): ?>
                                <option value="<?php echo $svc['service_id']; ?>" <?php echo (string) ($_GET['service_id'] ?? '') === (string) $svc['service_id'] ? 'selected' : ''; ?>>
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
                                <option value="<?php echo $d['employee_id']; ?>" <?php echo (string) ($_GET['dentist_id'] ?? '') === (string) $d['employee_id'] ? 'selected' : ''; ?>>
                                    Dr. <?php echo htmlspecialchars($d['dentist_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-field">
                        <label for="date">Date</label>
                        <input type="date" id="date" name="date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($_GET['date'] ?? ''); ?>" required>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn-filter">Find Available Times</button>
                    </div>
                </form>
            </div>

            <?php if (!empty($_GET['service_id']) && !empty($_GET['dentist_id']) && !empty($_GET['date'])): ?>
                <div class="card">
                    <div class="card-head"><div><h2>3. Pick a Time</h2></div></div>
                    <?php if (!$selected_service): ?>
                        <p style="color:var(--text-gray);">That service isn't available anymore. Please choose another above.</p>
                    <?php elseif (empty($available_slots)): ?>
                        <p style="color:var(--text-gray);">No open times for this dentist on this date. Try another date or dentist above.</p>
                    <?php else: ?>
                        <form method="POST"><?php echo csrf_field(); ?>
                            <input type="hidden" name="patient_id" value="<?php echo $selected_patient['patient_id']; ?>">
                            <input type="hidden" name="service_id" value="<?php echo htmlspecialchars($_GET['service_id']); ?>">
                            <input type="hidden" name="dentist_id" value="<?php echo htmlspecialchars($_GET['dentist_id']); ?>">
                            <input type="hidden" name="date" value="<?php echo htmlspecialchars($_GET['date']); ?>">
                            <div class="slot-grid">
                                <?php foreach ($available_slots as $i => $slot): ?>
                                    <div class="slot-option">
                                        <input type="radio" name="start_time" id="slot<?php echo $i; ?>" value="<?php echo $slot['start']; ?>" <?php echo $i === 0 ? 'checked' : ''; ?> required>
                                        <label for="slot<?php echo $i; ?>"><?php echo $slot['display']; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="submit" name="confirm_walkin" class="btn-primary-sm">Book &amp; Confirm Walk-in</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    <?php else: ?>

        <div class="card">
            <form class="filter-bar" method="get" action="appointments.php">
                <div class="filter-field" style="flex:1;min-width:200px;">
                    <label for="search">Search Patient</label>
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Patient name">
                </div>
                <div class="filter-field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <?php foreach (['pending','pending_operation','completed','cancelled'] as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo $status_filter === $s ? 'selected' : ''; ?>><?php echo status_label($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="service_id">Service</label>
                    <select id="service_id" name="service_id">
                        <option value="all">All Services</option>
                        <?php foreach ($services_filter_list as $svc): ?>
                            <option value="<?php echo $svc['service_id']; ?>" <?php echo (string) $service_filter === (string) $svc['service_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($svc['service_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="date_from">From</label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="filter-field">
                    <label for="date_to">To</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="filter-field">
                    <label for="sort">Sort</label>
                    <select id="sort" name="sort">
                        <option value="date_desc" <?php echo $sort === 'date_desc' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="date_asc"  <?php echo $sort === 'date_asc'  ? 'selected' : ''; ?>>Oldest First</option>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-filter">Filter</button>
                    <a href="appointments.php" class="btn-reset">Reset</a>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-head">
                <div><h2>Appointment List</h2><p><?php echo count($appointments); ?> result<?php echo count($appointments) === 1 ? '' : 's'; ?></p></div>
            </div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Patient</th><th>Contact</th><th>Dentist</th><th>Service</th><th>Date</th><th>Time</th><th>Type</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($appointments)): ?>
                            <tr class="empty-row"><td colspan="9">No appointments match these filters.</td></tr>
                        <?php else: ?>
                            <?php foreach ($appointments as $appt): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($appt['patient_name']); ?></td>
                                    <td><?php echo htmlspecialchars($appt['patient_phone'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($appt['dentist_name']); ?></td>
                                    <td><?php echo htmlspecialchars($appt['service_name']); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($appt['appointment_date'])); ?></td>
                                    <td><?php echo date('g:i A', strtotime($appt['start_time'])); ?></td>
                                    <td><span class="badge <?php echo htmlspecialchars($appt['appointment_type']); ?>"><?php echo ucfirst($appt['appointment_type']); ?></span></td>
                                    <td><span class="badge <?php echo htmlspecialchars($appt['status']); ?>"><?php echo status_label($appt['status']); ?></span></td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($appt['status'] === 'pending'): ?>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Confirm this appointment and assign it to the dentist\'s schedule?');"><?php echo csrf_field(); ?>
                                                    <input type="hidden" name="appointment_id" value="<?php echo $appt['appointment_id']; ?>">
                                                    <button type="submit" name="confirm_appointment" class="btn-sm btn-confirm">Confirm</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if ($appt['status'] === 'pending_operation'): ?>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Mark this appointment as completed?');"><?php echo csrf_field(); ?>
                                                    <input type="hidden" name="appointment_id" value="<?php echo $appt['appointment_id']; ?>">
                                                    <button type="submit" name="complete_appointment" class="btn-sm btn-confirm">Complete</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (in_array($appt['status'], ['pending', 'pending_operation'], true)): ?>
                                                <form method="POST" style="display:inline;" onsubmit="return prepareCancel(this);"><?php echo csrf_field(); ?>
                                                    <input type="hidden" name="appointment_id" value="<?php echo $appt['appointment_id']; ?>">
                                                    <input type="hidden" name="cancel_reason" class="cancel-reason-field" value="">
                                                    <button type="submit" name="cancel_appointment" class="btn-sm btn-cancel">Cancel</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
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
    function prepareCancel(form) {
        const reason = prompt('Reason for cancelling this appointment (optional):', '');
        if (reason === null) return false;
        form.querySelector('.cancel-reason-field').value = reason;
        return confirm('Cancel this appointment?');
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