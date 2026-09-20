<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../func/functions.php';

checkLogin();
checkRole(['Administrator']);
require_csrf();
/** @var mysqli $conn */
$admin_username = $_SESSION['username'];
$message = '';
$error = '';

// ---- Handle appointment actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_appointment'])) {
    $appointment_id = intval($_POST['appointment_id']);
    $stmt = mysqli_prepare($conn, "UPDATE appointments SET status = 'pending_operation', confirmed_by = ? WHERE appointment_id = ? AND status = 'pending'");
    mysqli_stmt_bind_param($stmt, "ii", $_SESSION['user_id'], $appointment_id);
    if (mysqli_stmt_execute($stmt)) {
        $message = "Appointment confirmed successfully!";
    } else {
        $error = "Failed to confirm appointment.";
    }
    mysqli_stmt_close($stmt);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_appointment'])) {
    $appointment_id = intval($_POST['appointment_id']);
    $stmt = mysqli_prepare($conn, "UPDATE appointments SET status = 'completed' WHERE appointment_id = ? AND status = 'pending_operation'");
    mysqli_stmt_bind_param($stmt, "i", $appointment_id);
    if (mysqli_stmt_execute($stmt)) {
        $message = "Appointment marked as completed!";
    } else {
        $error = "Failed to update appointment.";
    }
    mysqli_stmt_close($stmt);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_appointment'])) {
    $appointment_id = intval($_POST['appointment_id']);
    $cancel_reason = sanitizeInput($_POST['cancel_reason'] ?? '');
    $stmt = mysqli_prepare($conn, "UPDATE appointments SET status = 'cancelled', cancel_reason = ? WHERE appointment_id = ?");
    mysqli_stmt_bind_param($stmt, "si", $cancel_reason, $appointment_id);
    if (mysqli_stmt_execute($stmt)) {
        $message = "Appointment cancelled.";
    } else {
        $error = "Failed to cancel appointment.";
    }
    mysqli_stmt_close($stmt);
}

// ---- Filters: status, service, date range, patient search ----
// (matches the scope doc: "Admins can sort the appointment list by range of dates,
// service type, and appointment status to find records faster")
$status_filter  = $_GET['status'] ?? 'all';
$service_filter = $_GET['service_id'] ?? 'all';
$date_from      = $_GET['date_from'] ?? '';
$date_to        = $_GET['date_to'] ?? '';
$search         = trim($_GET['search'] ?? '');

$conditions = ["1=1"];

if ($status_filter !== 'all' && $status_filter !== '') {
    $conditions[] = "a.status = '" . mysqli_real_escape_string($conn, $status_filter) . "'";
}
if ($service_filter !== 'all' && $service_filter !== '') {
    $conditions[] = "a.service_id = " . (int) $service_filter;
}
if ($date_from !== '') {
    $conditions[] = "a.appointment_date >= '" . mysqli_real_escape_string($conn, $date_from) . "'";
}
if ($date_to !== '') {
    $conditions[] = "a.appointment_date <= '" . mysqli_real_escape_string($conn, $date_to) . "'";
}
if ($search !== '') {
    $search_esc = mysqli_real_escape_string($conn, $search);
    $conditions[] = "(p.first_name LIKE '%$search_esc%' OR p.last_name LIKE '%$search_esc%')";
}

$where_clause = implode(' AND ', $conditions);

// ---- Get appointments with patient, dentist, and service details ----
$query = "SELECT
            a.appointment_id, a.appointment_date, a.start_time, a.end_time,
            a.appointment_type, a.status, a.cancel_reason, a.created_at,
            CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
            p.contact_number AS patient_phone,
            pu.email AS patient_email,
            CONCAT(e.first_name, ' ', e.last_name) AS dentist_name,
            s.service_name, s.price, s.duration_minutes
          FROM appointments a
          JOIN patients p   ON a.patient_id = p.patient_id
          JOIN users pu     ON p.user_id = pu.user_id
          JOIN employees e  ON a.dentist_id = e.employee_id
          JOIN services s   ON a.service_id = s.service_id
          WHERE $where_clause
          ORDER BY
            CASE
              WHEN a.status = 'pending' THEN 1
              WHEN a.status = 'confirmed' THEN 2
              WHEN a.status = 'pending_operation' THEN 2
              WHEN a.status = 'completed' THEN 3
              WHEN a.status = 'cancelled' THEN 4
              ELSE 5
            END,
            a.appointment_date DESC,
            a.start_time DESC";
$result = mysqli_query($conn, $query);
$appointments = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];

// services list for the filter dropdown
$services_result = mysqli_query($conn, "SELECT service_id, service_name FROM services WHERE deleted_at IS NULL ORDER BY service_name");
$services_list = $services_result ? mysqli_fetch_all($services_result, MYSQLI_ASSOC) : [];

// ---- Stats: always reflect the WHOLE clinic, not just the current filter ----
$stats_result = mysqli_query($conn, "SELECT status, COUNT(*) AS total FROM appointments GROUP BY status");
$stats = ['pending' => 0, 'confirmed' => 0, 'pending_operation' => 0, 'completed' => 0, 'cancelled' => 0];
if ($stats_result) {
    while ($row = mysqli_fetch_assoc($stats_result)) {
        $stats[$row['status']] = (int) $row['total'];
    }
}
$stats_confirmed_total = $stats['confirmed'] + $stats['pending_operation'];
$stats_total = array_sum($stats);
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
<link rel="stylesheet" href="../assets/admin.css">
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

    <div class="nav-section-label">Maintenance</div>
    <a href="services.php" class="nav-link">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/></svg>
        Manage Services
    </a>
    <a href="users.php" class="nav-link">
        <svg viewBox="0 0 24 24"><circle cx="9" cy="8.5" r="3.2"/><circle cx="17" cy="9.5" r="2.4"/><path d="M3 20v-.8a5.8 5.8 0 0 1 11.6 0V20"/><path d="M15.3 14.2c2.2.5 3.9 2 3.9 4v1.8"/></svg>
        User Management
    </a>
    <a href="archives.php" class="nav-link">
        <svg viewBox="0 0 24 24"><path d="M3 7.5 12 3l9 4.5-9 4.5-9-4.5Z"/><path d="M3 7.5v9L12 21l9-4.5v-9"/><path d="M12 12v9"/></svg>
        Archives
    </a>

    <div class="nav-section-label">Reports</div>
    <a href="reports.php" class="nav-link">
        <svg viewBox="0 0 24 24"><path d="M4 20V11M10 20V5M16 20v-8M22 20H2"/></svg>
        Reports &amp; Analytics
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
            <p class="subtitle">View, filter, and manage every appointment the clinic has on record</p>
        </div>
        <span class="welcome-pill">Welcome, <?php echo htmlspecialchars($admin_username); ?></span>
    </div>

    <?php if (!empty($message)): ?><div class="alert success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card pending"><div class="label">Pending</div><div class="value"><?php echo $stats['pending']; ?></div></div>
        <div class="stat-card confirmed"><div class="label">Confirmed</div><div class="value"><?php echo $stats_confirmed_total; ?></div></div>
        <div class="stat-card completed"><div class="label">Completed</div><div class="value"><?php echo $stats['completed']; ?></div></div>
        <div class="stat-card cancelled"><div class="label">Cancelled</div><div class="value"><?php echo $stats['cancelled']; ?></div></div>
        <div class="stat-card total"><div class="label">Total</div><div class="value"><?php echo $stats_total; ?></div></div>
    </div>

    <div class="card">
        <form class="filter-bar" method="get" action="appointments.php">
            <div class="filter-field">
                <label for="search">Search Patient</label>
                <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Patient name">
            </div>
            <div class="filter-field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                    <?php foreach (['pending','confirmed','pending_operation','completed','cancelled'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo $status_filter === $s ? 'selected' : ''; ?>><?php echo status_label($s); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-field">
                <label for="service_id">Service</label>
                <select id="service_id" name="service_id">
                    <option value="all">All Services</option>
                    <?php foreach ($services_list as $svc): ?>
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
            <div class="filter-actions">
                <button type="submit" class="btn-filter">Filter</button>
                <a href="appointments.php" class="btn-reset">Reset</a>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h2>Appointment List</h2>
                <p><?php echo count($appointments); ?> result<?php echo count($appointments) === 1 ? '' : 's'; ?> matching these filters</p>
            </div>
        </div>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Patient</th><th>Dentist</th><th>Service</th><th>Date</th><th>Time</th><th>Type</th><th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($appointments)): ?>
                        <tr class="empty-row"><td colspan="8">No appointments match these filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($appointments as $appt): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($appt['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($appt['dentist_name']); ?></td>
                                <td><?php echo htmlspecialchars($appt['service_name']); ?></td>
                                <td><?php echo date('M j, Y', strtotime($appt['appointment_date'])); ?></td>
                                <td><?php echo date('g:i A', strtotime($appt['start_time'])); ?></td>
                                <td><span class="badge <?php echo htmlspecialchars($appt['appointment_type']); ?>"><?php echo ucfirst($appt['appointment_type']); ?></span></td>
                                <td><span class="badge <?php echo htmlspecialchars($appt['status']); ?>"><?php echo status_label($appt['status']); ?></span></td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="btn-sm btn-view" onclick='viewDetails(<?php echo json_encode($appt, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>)'>View</button>

                                        <?php if ($appt['status'] === 'pending'): ?>
                                            <form method="POST" style="display:inline;"><?php echo csrf_field(); ?>
                                                <input type="hidden" name="appointment_id" value="<?php echo $appt['appointment_id']; ?>">
                                                <button type="submit" name="confirm_appointment" class="btn-sm btn-confirm" onclick="return confirm('Confirm this appointment and assign it to the dentist\'s schedule?');">Confirm</button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($appt['status'] === 'pending_operation'): ?>
                                            <form method="POST" style="display:inline;"><?php echo csrf_field(); ?>
                                                <input type="hidden" name="appointment_id" value="<?php echo $appt['appointment_id']; ?>">
                                                <button type="submit" name="complete_appointment" class="btn-sm btn-complete" onclick="return confirm('Mark this appointment as completed?');">Complete</button>
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
</main>

<!-- Appointment Details Modal -->
<div class="modal-overlay" id="detailsModal">
    <div class="modal">
        <div class="modal-head">
            <h3>Appointment Details</h3>
            <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody"></div>
    </div>
</div>

<script>
    function escapeHtml(str) {
        if (!str) return '—';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function formatDate(dateStr) {
        const d = new Date(dateStr + 'T00:00:00');
        return d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
    }

    function formatTime(timeStr) {
        const [h, m] = timeStr.split(':');
        const hour = parseInt(h, 10);
        const ampm = hour >= 12 ? 'PM' : 'AM';
        const displayHour = hour % 12 === 0 ? 12 : hour % 12;
        return `${displayHour}:${m} ${ampm}`;
    }

    function viewDetails(appt) {
        const rows = [
            ['Patient', escapeHtml(appt.patient_name)],
            ['Contact Number', escapeHtml(appt.patient_phone)],
            ['Email', escapeHtml(appt.patient_email)],
            ['Dentist', escapeHtml(appt.dentist_name)],
            ['Service', `${escapeHtml(appt.service_name)} (${appt.duration_minutes} min)`],
            ['Price', '₱' + parseFloat(appt.price).toFixed(2)],
            ['Date', formatDate(appt.appointment_date)],
            ['Time', `${formatTime(appt.start_time)} – ${formatTime(appt.end_time)}`],
            ['Type', appt.appointment_type === 'online' ? 'Online Booking' : 'Walk-in'],
            ['Status', appt.status.replace('_', ' ').replace(/\b\w/g, c => c.toUpperCase())],
        ];
        if (appt.cancel_reason) rows.push(['Cancel Reason', escapeHtml(appt.cancel_reason)]);
        rows.push(['Booked On', new Date(appt.created_at).toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })]);

        document.getElementById('modalBody').innerHTML = rows.map(([label, value]) =>
            `<div class="detail-row"><span>${label}</span><strong>${value}</strong></div>`
        ).join('');

        document.getElementById('detailsModal').classList.add('open');
    }

    function closeModal() {
        document.getElementById('detailsModal').classList.remove('open');
    }

    document.getElementById('detailsModal').addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });

    // capture a cancellation reason via a native prompt, then let the normal confirm() gate the submit
    function prepareCancel(form) {
        const reason = prompt('Reason for cancelling this appointment (optional):', '');
        if (reason === null) return false; // user dismissed the prompt
        form.querySelector('.cancel-reason-field').value = reason;
        return confirm('Cancel this appointment?');
    }

    // auto-hide success/error banners after 5 seconds
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