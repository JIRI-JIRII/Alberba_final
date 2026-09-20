<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../func/functions.php';

checkLogin();
checkRole(['Administrator']);

$admin_username = $_SESSION['username'];

/** @var mysqli $conn */
// ---- Stat cards ----
$today = date('Y-m-d');

$todays_appointments = 0;
$res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM appointments WHERE appointment_date = '$today'");
if ($res && $row = mysqli_fetch_assoc($res)) { $todays_appointments = (int) $row['total']; }

$pending_appointments = 0;
$res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM appointments WHERE status = 'pending'");
if ($res && $row = mysqli_fetch_assoc($res)) { $pending_appointments = (int) $row['total']; }

$total_patients = 0;
$res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM patients");
if ($res && $row = mysqli_fetch_assoc($res)) { $total_patients = (int) $row['total']; }

$month_revenue = 0.00;
$res = mysqli_query($conn, "SELECT COALESCE(SUM(b.amount),0) AS total
                             FROM billing b
                             JOIN appointments a ON b.appointment_id = a.appointment_id
                             WHERE a.status = 'completed' AND b.payment_status = 'paid'
                             AND DATE_FORMAT(b.billing_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')");
if ($res && $row = mysqli_fetch_assoc($res)) { $month_revenue = (float) $row['total']; }

// ---- Appointments Insight: last 14 days, filled so days with zero appointments still show on the line ----
$counts_by_date = [];
$start_date = date('Y-m-d', strtotime('-13 days'));
$stmt = mysqli_prepare($conn, "SELECT appointment_date, COUNT(*) AS total
                                FROM appointments
                                WHERE appointment_date BETWEEN ? AND ?
                                GROUP BY appointment_date");
mysqli_stmt_bind_param($stmt, "ss", $start_date, $today);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    $counts_by_date[$row['appointment_date']] = (int) $row['total'];
}
mysqli_stmt_close($stmt);

$chart_labels = [];
$chart_values = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('M j', strtotime($d));
    $chart_values[] = $counts_by_date[$d] ?? 0;
}

// ---- Recent Appointments Table ----
$recent_appointments = [];
$res = mysqli_query($conn, "SELECT a.appointment_id,
                                    CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                                    CONCAT(e.first_name, ' ', e.last_name) AS dentist_name,
                                    s.service_name, a.appointment_date, a.start_time, a.status
                             FROM appointments a
                             JOIN patients p  ON a.patient_id = p.patient_id
                             JOIN employees e ON a.dentist_id = e.employee_id
                             JOIN services s  ON a.service_id = s.service_id
                             ORDER BY a.created_at DESC
                             LIMIT 10");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $recent_appointments[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard - Alberba Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<link rel="stylesheet" href="../assets/admin.css">
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
            <h1>Admin Dashboard</h1>
            <p class="today"><?php echo date('l, F j, Y'); ?></p>
        </div>
        <div class="topbar-right">
            <span class="welcome-pill">Welcome, <?php echo htmlspecialchars($admin_username); ?></span>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Today's Appointments</div>
            <div class="value"><?php echo $todays_appointments; ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Pending Approval</div>
            <div class="value"><?php echo $pending_appointments; ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Total Patients</div>
            <div class="value"><?php echo $total_patients; ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Revenue This Month</div>
            <div class="value"><small>₱</small><?php echo number_format($month_revenue, 2); ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h2>Appointments Insight</h2>
                <p>Daily appointment volume, last 14 days</p>
            </div>
        </div>
        <div class="chart-wrap">
            <canvas id="insightChart"></canvas>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h2>Recent Appointments</h2>
                <p>Latest 10 appointments accepted by the clinic</p>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Patient</th>
                    <th>Dentist</th>
                    <th>Service</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_appointments)): ?>
                    <tr class="empty-row"><td colspan="6">No appointments yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($recent_appointments as $appt): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($appt['patient_name']); ?></td>
                            <td><?php echo htmlspecialchars($appt['dentist_name']); ?></td>
                            <td><?php echo htmlspecialchars($appt['service_name']); ?></td>
                            <td><?php echo date('M j, Y', strtotime($appt['appointment_date'])); ?></td>
                            <td><?php echo date('g:i A', strtotime($appt['start_time'])); ?></td>
                            <td><span class="badge <?php echo htmlspecialchars($appt['status']); ?>"><?php echo status_label($appt['status']); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<script>
    const ctx = document.getElementById('insightChart');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [{
                label: 'Appointments',
                data: <?php echo json_encode($chart_values); ?>,
                borderColor: '#d4739b',
                backgroundColor: 'rgba(212,115,155,0.12)',
                borderWidth: 2.5,
                tension: 0.35,
                fill: true,
                pointRadius: 3,
                pointBackgroundColor: '#c85a87'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f0dbe4' } },
                x: { grid: { display: false } }
            }
        }
    });
</script>
</body>
</html>