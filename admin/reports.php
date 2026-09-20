<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../func/functions.php';

checkLogin();
checkRole(['Administrator']);

$admin_username = $_SESSION['username'];

$report = $_GET['report'] ?? 'sales';
if (!in_array($report, ['sales', 'services', 'patients'], true)) { $report = 'sales'; }

// All three reports default to a trailing 30-day window, matching the scope doc
// ("Defaults to monthly view" / "Covers the past 30 days by default"), with the
// filters below letting an admin widen or narrow that range.
$default_to   = date('Y-m-d');
$default_from = date('Y-m-d', strtotime('-29 days'));
$date_from = $_GET['date_from'] ?? $default_from;
$date_to   = $_GET['date_to'] ?? $default_to;

/** @var mysqli $conn */

$sales_rows = [];
$sales_totals = ['transactions' => 0, 'revenue' => 0.0];
$sales_chart_labels = [];
$sales_chart_values = [];

if ($report === 'sales') {
    $period = $_GET['period'] ?? 'monthly';
    if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) { $period = 'monthly'; }

    switch ($period) {
        case 'daily':   $date_format = '%Y-%m-%d'; break;
        case 'weekly':  $date_format = '%x-W%v';   break;
        default:        $date_format = '%Y-%m';    break;
    }

    $stmt = mysqli_prepare($conn, "SELECT DATE_FORMAT(b.billing_date, '$date_format') AS period_label,
                                           MIN(b.billing_date) AS period_start,
                                           COUNT(*) AS total_transactions,
                                           SUM(b.amount) AS total_revenue
                                    FROM billing b
                                    JOIN appointments a ON b.appointment_id = a.appointment_id
                                    WHERE a.status = 'completed' AND b.payment_status = 'paid'
                                      AND b.billing_date BETWEEN ? AND ?
                                    GROUP BY period_label
                                    ORDER BY period_start ASC");
    mysqli_stmt_bind_param($stmt, "ss", $date_from, $date_to);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $sales_rows[] = $row;
        $sales_totals['transactions'] += (int) $row['total_transactions'];
        $sales_totals['revenue']      += (float) $row['total_revenue'];
        $sales_chart_labels[] = $row['period_label'];
        $sales_chart_values[] = (float) $row['total_revenue'];
    }
    mysqli_stmt_close($stmt);
}

// ==================================================================
// SERVICES REPORT
// ==================================================================
$service_rows = [];

if ($report === 'services') {
    $stmt = mysqli_prepare($conn, "SELECT s.service_id, s.service_name, s.category,
                                           COUNT(a.appointment_id) AS times_booked,
                                           SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS times_completed
                                    FROM services s
                                    LEFT JOIN appointments a ON a.service_id = s.service_id
                                           AND a.appointment_date BETWEEN ? AND ?
                                    WHERE s.deleted_at IS NULL
                                    GROUP BY s.service_id, s.service_name, s.category
                                    ORDER BY times_booked DESC, s.service_name ASC");
    mysqli_stmt_bind_param($stmt, "ss", $date_from, $date_to);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) { $service_rows[] = $row; }
    mysqli_stmt_close($stmt);
}

// ==================================================================
// PATIENT REPORT
// ==================================================================
$patient_rows = [];
$patient_counts = ['total' => 0, 'completed' => 0, 'pending' => 0, 'cancelled' => 0];
$services_list = [];

if ($report === 'patients') {
    $status_filter  = $_GET['status'] ?? 'all';
    $service_filter = $_GET['service_id'] ?? 'all';
    $sort           = $_GET['sort'] ?? 'date_desc';

    $conditions = ["a.appointment_date BETWEEN ? AND ?"];
    $types  = "ss";
    $params = [$date_from, $date_to];

    if ($status_filter === 'completed') {
        $conditions[] = "a.status = 'completed'";
    } elseif ($status_filter === 'cancelled') {
        $conditions[] = "a.status = 'cancelled'";
    } elseif ($status_filter === 'pending') {
        // "currently in progress or scheduled but not yet concluded" per the scope doc —
        // broader than just the literal 'pending' status
        $conditions[] = "a.status IN ('pending','confirmed','pending_operation')";
    }
    if ($service_filter !== 'all' && $service_filter !== '') {
        $conditions[] = "a.service_id = ?";
        $types .= "i";
        $params[] = (int) $service_filter;
    }

    $where_clause = implode(' AND ', $conditions);
    $order_by = "a.appointment_date DESC, a.start_time DESC";
    if ($sort === 'date_asc')    { $order_by = "a.appointment_date ASC, a.start_time ASC"; }
    if ($sort === 'service_asc') { $order_by = "s.service_name ASC"; }
    if ($sort === 'status_asc')  { $order_by = "a.status ASC"; }

    $sql = "SELECT a.appointment_id, a.appointment_date, a.start_time, a.status, a.appointment_type,
                   CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                   CONCAT(e.first_name, ' ', e.last_name) AS dentist_name,
                   s.service_name
            FROM appointments a
            JOIN patients p  ON a.patient_id = p.patient_id
            JOIN employees e ON a.dentist_id = e.employee_id
            JOIN services s  ON a.service_id = s.service_id
            WHERE $where_clause
            ORDER BY $order_by";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) { $patient_rows[] = $row; }
    mysqli_stmt_close($stmt);

    // counts always reflect the full date range (ignoring the status filter), so the
    // stat cards stay stable reference points no matter which quick-filter is active
    $stmt2 = mysqli_prepare($conn, "SELECT status, COUNT(*) AS total FROM appointments WHERE appointment_date BETWEEN ? AND ? GROUP BY status");
    mysqli_stmt_bind_param($stmt2, "ss", $date_from, $date_to);
    mysqli_stmt_execute($stmt2);
    $res2 = mysqli_stmt_get_result($stmt2);
    while ($row = mysqli_fetch_assoc($res2)) {
        $patient_counts['total'] += (int) $row['total'];
        if ($row['status'] === 'completed') { $patient_counts['completed'] += (int) $row['total']; }
        elseif ($row['status'] === 'cancelled') { $patient_counts['cancelled'] += (int) $row['total']; }
        else { $patient_counts['pending'] += (int) $row['total']; }
    }
    mysqli_stmt_close($stmt2);

    $cat_result = mysqli_query($conn, "SELECT service_id, service_name FROM services WHERE deleted_at IS NULL ORDER BY service_name");
    if ($cat_result) { while ($row = mysqli_fetch_assoc($cat_result)) { $services_list[] = $row; } }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports &amp; Analytics - Alberba Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<link rel="stylesheet" href="../assets/admin.css">
<style>
    .tab-bar{display:flex;gap:.6rem;margin-bottom:1.4rem;}
    .tab-link{padding:.6rem 1.2rem;border-radius:100px;font-size:.86rem;font-weight:600;color:var(--text-gray);border:1.5px solid var(--border-color);background:var(--white);}
    .tab-link.active{background:var(--primary-pink);border-color:var(--primary-pink);color:var(--white);}
    .stat-card.clickable{transition:transform .15s ease;}
    .stat-card.clickable:hover{transform:translateY(-2px);}
    a.stat-card{text-decoration:none;display:block;}
</style>
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
    <a href="reports.php" class="nav-link active">
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
            <h1>Reports &amp; Analytics</h1>
            <p class="subtitle">Clinic performance summaries to support decision-making</p>
        </div>
        <span class="welcome-pill">Welcome, <?php echo htmlspecialchars($admin_username); ?></span>
    </div>

    <div class="tab-bar">
        <a href="reports.php?report=sales"    class="tab-link <?php echo $report === 'sales'    ? 'active' : ''; ?>">Sales Report</a>
        <a href="reports.php?report=services" class="tab-link <?php echo $report === 'services' ? 'active' : ''; ?>">Services Report</a>
        <a href="reports.php?report=patients" class="tab-link <?php echo $report === 'patients' ? 'active' : ''; ?>">Patient Report</a>
    </div>

    <?php if ($report === 'sales'): ?>

        <div class="card">
            <form class="filter-bar" method="get" action="reports.php">
                <input type="hidden" name="report" value="sales">
                <div class="filter-field">
                    <label for="date_from">From</label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="filter-field">
                    <label for="date_to">To</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="filter-field">
                    <label for="period">Group By</label>
                    <select id="period" name="period">
                        <option value="daily"   <?php echo $period === 'daily'   ? 'selected' : ''; ?>>Daily</option>
                        <option value="weekly"  <?php echo $period === 'weekly'  ? 'selected' : ''; ?>>Weekly</option>
                        <option value="monthly" <?php echo $period === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-filter">Apply</button>
                    <a href="reports.php?report=sales" class="btn-reset">Reset</a>
                </div>
            </form>
        </div>

        <div class="stats-grid">
            <div class="stat-card total"><div class="label">Total Revenue</div><div class="value"><small>₱</small><?php echo number_format($sales_totals['revenue'], 2); ?></div></div>
            <div class="stat-card total"><div class="label">Paid Transactions</div><div class="value"><?php echo $sales_totals['transactions']; ?></div></div>
            <div class="stat-card total"><div class="label">Average per Transaction</div><div class="value"><small>₱</small><?php echo number_format($sales_totals['transactions'] > 0 ? $sales_totals['revenue'] / $sales_totals['transactions'] : 0, 2); ?></div></div>
        </div>

        <div class="card">
            <div class="card-head"><div><h2>Revenue Over Time</h2><p>Completed services, paid only</p></div></div>
            <div class="chart-wrap"><canvas id="salesChart"></canvas></div>
        </div>

        <div class="card">
            <div class="card-head"><div><h2>Breakdown</h2></div></div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Period</th><th>Transactions</th><th>Revenue</th></tr></thead>
                    <tbody>
                        <?php if (empty($sales_rows)): ?>
                            <tr class="empty-row"><td colspan="3">No paid, completed transactions in this range.</td></tr>
                        <?php else: ?>
                            <?php foreach ($sales_rows as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['period_label']); ?></td>
                                    <td><?php echo (int) $row['total_transactions']; ?></td>
                                    <td><?php echo format_currency($row['total_revenue']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
            new Chart(document.getElementById('salesChart'), {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($sales_chart_labels); ?>,
                    datasets: [{
                        label: 'Revenue',
                        data: <?php echo json_encode($sales_chart_values); ?>,
                        backgroundColor: '#d4739b',
                        borderRadius: 6,
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, grid: { color: '#f0dbe4' } }, x: { grid: { display: false } } }
                }
            });
        </script>

    <?php elseif ($report === 'services'): ?>

        <div class="card">
            <form class="filter-bar" method="get" action="reports.php">
                <input type="hidden" name="report" value="services">
                <div class="filter-field">
                    <label for="date_from">From</label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="filter-field">
                    <label for="date_to">To</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-filter">Apply</button>
                    <a href="reports.php?report=services" class="btn-reset">Reset</a>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-head"><div><h2>Most &amp; Least Booked Services</h2><p><?php echo htmlspecialchars($date_from); ?> to <?php echo htmlspecialchars($date_to); ?></p></div></div>
            <div class="chart-wrap"><canvas id="servicesChart"></canvas></div>
        </div>

        <div class="card">
            <div class="card-head"><div><h2>Ranked Breakdown</h2></div></div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Rank</th><th>Service</th><th>Category</th><th>Times Booked</th><th>Times Completed</th></tr></thead>
                    <tbody>
                        <?php if (empty($service_rows)): ?>
                            <tr class="empty-row"><td colspan="5">No services on record.</td></tr>
                        <?php else: ?>
                            <?php foreach ($service_rows as $i => $row): ?>
                                <tr>
                                    <td>#<?php echo $i + 1; ?></td>
                                    <td><?php echo htmlspecialchars($row['service_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['category'] ?: '—'); ?></td>
                                    <td><?php echo (int) $row['times_booked']; ?></td>
                                    <td><?php echo (int) $row['times_completed']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
            new Chart(document.getElementById('servicesChart'), {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($service_rows, 'service_name')); ?>,
                    datasets: [{
                        label: 'Times Booked',
                        data: <?php echo json_encode(array_map('intval', array_column($service_rows, 'times_booked'))); ?>,
                        backgroundColor: '#c85a87',
                        borderRadius: 6,
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { x: { beginAtZero: true, grid: { color: '#f0dbe4' } }, y: { grid: { display: false } } }
                }
            });
        </script>

    <?php else: // patients ?>

        <div class="card">
            <form class="filter-bar" method="get" action="reports.php">
                <input type="hidden" name="report" value="patients">
                <div class="filter-field">
                    <label for="date_from">From</label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="filter-field">
                    <label for="date_to">To</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
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
                    <label for="sort">Sort By</label>
                    <select id="sort" name="sort">
                        <option value="date_desc"    <?php echo $sort === 'date_desc'    ? 'selected' : ''; ?>>Date (Newest)</option>
                        <option value="date_asc"     <?php echo $sort === 'date_asc'     ? 'selected' : ''; ?>>Date (Oldest)</option>
                        <option value="service_asc"  <?php echo $sort === 'service_asc'  ? 'selected' : ''; ?>>Service (A–Z)</option>
                        <option value="status_asc"   <?php echo $sort === 'status_asc'   ? 'selected' : ''; ?>>Status</option>
                    </select>
                </div>
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                <div class="filter-actions">
                    <button type="submit" class="btn-filter">Apply</button>
                    <a href="reports.php?report=patients" class="btn-reset">Reset</a>
                </div>
            </form>
        </div>

        <div class="stats-grid">
            <?php
                $qs_base = "report=patients&date_from=" . urlencode($date_from) . "&date_to=" . urlencode($date_to);
            ?>
            <a href="reports.php?<?php echo $qs_base; ?>&status=all" class="stat-card total clickable"><div class="label">Total Appointments</div><div class="value"><?php echo $patient_counts['total']; ?></div></a>
            <a href="reports.php?<?php echo $qs_base; ?>&status=completed" class="stat-card completed clickable"><div class="label">Completed</div><div class="value"><?php echo $patient_counts['completed']; ?></div></a>
            <a href="reports.php?<?php echo $qs_base; ?>&status=pending" class="stat-card pending clickable"><div class="label">Pending</div><div class="value"><?php echo $patient_counts['pending']; ?></div></a>
            <a href="reports.php?<?php echo $qs_base; ?>&status=cancelled" class="stat-card cancelled clickable"><div class="label">Cancelled</div><div class="value"><?php echo $patient_counts['cancelled']; ?></div></a>
        </div>

        <div class="card">
            <div class="card-head">
                <div><h2>Appointment List</h2><p><?php echo count($patient_rows); ?> visit<?php echo count($patient_rows) === 1 ? '' : 's'; ?> — filter: <?php echo ucfirst($status_filter); ?></p></div>
            </div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Patient</th><th>Dentist</th><th>Service</th><th>Date</th><th>Time</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <?php if (empty($patient_rows)): ?>
                            <tr class="empty-row"><td colspan="7">No visits match these filters.</td></tr>
                        <?php else: ?>
                            <?php foreach ($patient_rows as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['dentist_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['service_name']); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($row['appointment_date'])); ?></td>
                                    <td><?php echo date('g:i A', strtotime($row['start_time'])); ?></td>
                                    <td><span class="badge <?php echo htmlspecialchars($row['status']); ?>"><?php echo status_label($row['status']); ?></span></td>
                                    <td><button type="button" class="btn-sm btn-view" onclick='viewVisit(<?php echo json_encode($row, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>)'>View</button></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="modal-overlay" id="visitModal">
            <div class="modal">
                <div class="modal-head"><h3>Visit Details</h3><button type="button" class="modal-close" onclick="document.getElementById('visitModal').classList.remove('open')">&times;</button></div>
                <div class="modal-body" id="visitModalBody"></div>
            </div>
        </div>

        <script>
            function viewVisit(v) {
                const rows = [
                    ['Patient', v.patient_name],
                    ['Dentist', v.dentist_name],
                    ['Service', v.service_name],
                    ['Date', new Date(v.appointment_date + 'T00:00:00').toLocaleDateString('en-US', {month:'long', day:'numeric', year:'numeric'})],
                    ['Time', new Date('1970-01-01T' + v.start_time).toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit'})],
                    ['Type', v.appointment_type === 'online' ? 'Online Booking' : 'Walk-in'],
                    ['Status', v.status.replace('_',' ').replace(/\b\w/g, c => c.toUpperCase())],
                ];
                document.getElementById('visitModalBody').innerHTML = rows.map(([label, value]) =>
                    `<div class="detail-row"><span>${label}</span><strong>${value}</strong></div>`
                ).join('');
                document.getElementById('visitModal').classList.add('open');
            }
            document.getElementById('visitModal').addEventListener('click', function (e) {
                if (e.target === this) this.classList.remove('open');
            });
        </script>

    <?php endif; ?>
</main>
</body>
</html>