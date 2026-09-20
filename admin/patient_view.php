<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../func/functions.php';

checkLogin();
checkRole(['Administrator']);

/** @var mysqli $conn */

$admin_username = $_SESSION['username'];
$patient_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// ---- Fetch patient profile ----
$patient = null;
$stmt = mysqli_prepare($conn, "SELECT p.patient_id, p.first_name, p.last_name, p.birthdate, p.contact_number,
                                       p.address, p.registered_via, p.created_at,
                                       u.username, u.email, u.account_status
                                FROM patients p
                                JOIN users u ON p.user_id = u.user_id
                                WHERE p.patient_id = ?");
mysqli_stmt_bind_param($stmt, "i", $patient_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$patient = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

$appointments = [];
$billing_records = [];

if ($patient) {
    // ---- Appointment history ----
    $stmt = mysqli_prepare($conn, "SELECT a.appointment_id, a.appointment_date, a.start_time, a.appointment_type, a.status,
                                           CONCAT(e.first_name, ' ', e.last_name) AS dentist_name,
                                           s.service_name
                                    FROM appointments a
                                    JOIN employees e ON a.dentist_id = e.employee_id
                                    JOIN services s  ON a.service_id = s.service_id
                                    WHERE a.patient_id = ?
                                    ORDER BY a.appointment_date DESC, a.start_time DESC");
    mysqli_stmt_bind_param($stmt, "i", $patient_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) { $appointments[] = $row; }
    mysqli_stmt_close($stmt);

    // ---- Treatment / billing records ----
    $stmt = mysqli_prepare($conn, "SELECT b.billing_id, b.amount, b.payment_status, b.reference_number, b.billing_date,
                                           s.service_name, CONCAT(e.first_name, ' ', e.last_name) AS dentist_name
                                    FROM billing b
                                    JOIN appointments a ON b.appointment_id = a.appointment_id
                                    JOIN services s     ON b.service_id = s.service_id
                                    JOIN employees e    ON b.dentist_id = e.employee_id
                                    WHERE b.patient_id = ?
                                    ORDER BY b.billing_date DESC");
    mysqli_stmt_bind_param($stmt, "i", $patient_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) { $billing_records[] = $row; }
    mysqli_stmt_close($stmt);
}

$total_visits = count(array_filter($appointments, fn($a) => $a['status'] === 'completed'));
$total_spent  = array_sum(array_map(fn($b) => $b['payment_status'] === 'paid' ? (float) $b['amount'] : 0, $billing_records));

$message = '';
if (isset($_GET['archived'])) { $message = "Patient account archived."; }
if (isset($_GET['restored'])) { $message = "Patient account restored."; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Patient Profile - Alberba Dental Clinic</title>
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
    <a href="appointments.php" class="nav-link">
        <svg viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>
        Appointments
    </a>
    <a href="patients.php" class="nav-link active">
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
            <h1><?php echo $patient ? htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) : 'Patient Not Found'; ?></h1>
            <p class="subtitle"><a href="patients.php" style="color:var(--accent-pink);font-weight:600;">&larr; Back to Patient Records</a></p>
        </div>
        <?php if ($patient): ?>
        <div class="topbar-right">
            <?php if ($patient['account_status'] === 'active'): ?>
                <form method="POST" action="patients.php" style="display:inline;" onsubmit="return confirm('Archive this patient account? They will no longer be able to log in, but their records stay intact.');"><?php echo csrf_field(); ?>
                    <input type="hidden" name="patient_id" value="<?php echo $patient['patient_id']; ?>">
                    <input type="hidden" name="redirect" value="profile">
                    <button type="submit" name="archive_patient" class="btn-sm btn-cancel" style="padding:.6rem 1.2rem;font-size:.86rem;">Archive Patient</button>
                </form>
            <?php else: ?>
                <form method="POST" action="patients.php" style="display:inline;" onsubmit="return confirm('Restore this patient account?');"><?php echo csrf_field(); ?>
                    <input type="hidden" name="patient_id" value="<?php echo $patient['patient_id']; ?>">
                    <input type="hidden" name="redirect" value="profile">
                    <button type="submit" name="restore_patient" class="btn-sm btn-confirm" style="padding:.6rem 1.2rem;font-size:.86rem;">Restore Patient</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($message)): ?><div class="alert success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

    <?php if (!$patient): ?>
        <div class="card">
            <p style="color:var(--text-gray);">No patient found with that ID. It may have been removed, or the link may be incorrect.</p>
        </div>
    <?php else: ?>

        <div class="stats-grid">
            <div class="stat-card total"><div class="label">Completed Visits</div><div class="value"><?php echo $total_visits; ?></div></div>
            <div class="stat-card total"><div class="label">Total Appointments</div><div class="value"><?php echo count($appointments); ?></div></div>
            <div class="stat-card total"><div class="label">Total Paid</div><div class="value"><small>₱</small><?php echo number_format($total_spent, 2); ?></div></div>
            <div class="stat-card total"><div class="label">Patient Since</div><div class="value" style="font-size:1.1rem;"><?php echo date('M Y', strtotime($patient['created_at'])); ?></div></div>
        </div>

        <div class="card">
            <div class="card-head"><div><h2>Personal Information</h2></div></div>
            <div class="info-grid">
                <div class="info-item"><div class="info-label">Full Name</div><div class="info-value"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></div></div>
                <div class="info-item"><div class="info-label">Contact Number</div><div class="info-value"><?php echo htmlspecialchars($patient['contact_number'] ?? '—'); ?></div></div>
                <div class="info-item"><div class="info-label">Email</div><div class="info-value"><?php echo htmlspecialchars($patient['email']); ?></div></div>
                <div class="info-item"><div class="info-label">Username</div><div class="info-value"><?php echo htmlspecialchars($patient['username']); ?></div></div>
                <div class="info-item"><div class="info-label">Birthdate</div><div class="info-value"><?php echo $patient['birthdate'] ? date('M j, Y', strtotime($patient['birthdate'])) : '—'; ?></div></div>
                <div class="info-item"><div class="info-label">Address</div><div class="info-value"><?php echo htmlspecialchars($patient['address'] ?? '—'); ?></div></div>
                <div class="info-item"><div class="info-label">Registered Via</div><div class="info-value"><span class="badge <?php echo htmlspecialchars($patient['registered_via']); ?>"><?php echo ucfirst($patient['registered_via']); ?></span></div></div>
                <div class="info-item"><div class="info-label">Account Status</div><div class="info-value"><span class="badge <?php echo $patient['account_status'] === 'active' ? 'confirmed' : 'cancelled'; ?>"><?php echo ucfirst($patient['account_status']); ?></span></div></div>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div><h2>Appointment History</h2><p><?php echo count($appointments); ?> appointment<?php echo count($appointments) === 1 ? '' : 's'; ?> on record</p></div>
            </div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Date</th><th>Time</th><th>Dentist</th><th>Service</th><th>Type</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php if (empty($appointments)): ?>
                            <tr class="empty-row"><td colspan="6">No appointments yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($appointments as $a): ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($a['appointment_date'])); ?></td>
                                    <td><?php echo date('g:i A', strtotime($a['start_time'])); ?></td>
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

        <div class="card">
            <div class="card-head">
                <div><h2>Treatment &amp; Billing Records</h2><p><?php echo count($billing_records); ?> billed treatment<?php echo count($billing_records) === 1 ? '' : 's'; ?></p></div>
            </div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Date</th><th>Service</th><th>Dentist</th><th>Reference #</th><th>Amount</th><th>Payment</th></tr></thead>
                    <tbody>
                        <?php if (empty($billing_records)): ?>
                            <tr class="empty-row"><td colspan="6">No billed treatments yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($billing_records as $b): ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($b['billing_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($b['service_name']); ?></td>
                                    <td><?php echo htmlspecialchars($b['dentist_name']); ?></td>
                                    <td><?php echo htmlspecialchars($b['reference_number']); ?></td>
                                    <td><?php echo format_currency($b['amount']); ?></td>
                                    <td><span class="badge <?php echo $b['payment_status'] === 'paid' ? 'completed' : 'pending'; ?>"><?php echo ucfirst($b['payment_status']); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>
</main>
</body>
</html>