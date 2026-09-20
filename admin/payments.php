<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../func/functions.php';
/** @var mysqli $conn */

checkLogin();
checkRole(['Administrator']);
require_csrf();

$admin_username = $_SESSION['username'];
$message = '';
$error = '';

// ---- Handle: Generate Bill (billing is initiated once a service/appointment is completed) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_bill'])) {
    $appointment_id  = intval($_POST['appointment_id']);
    $reference_number = sanitizeInput($_POST['reference_number'] ?? '');
    $amount          = $_POST['amount'] ?? '';
    $billing_date    = $_POST['billing_date'] ?? date('Y-m-d');
    $payment_status  = ($_POST['payment_status'] ?? 'unpaid') === 'paid' ? 'paid' : 'unpaid';

    if (empty($reference_number) || $amount === '') {
        $error = "Reference number and amount are required.";
    } elseif (!is_numeric($amount) || (float) $amount <= 0) {
        $error = "Amount must be a positive number.";
    } else {
        // re-derive patient/dentist/service straight from the appointment rather than trusting
        // client-submitted hidden fields, and confirm it's actually eligible for billing
        $stmt = mysqli_prepare($conn, "SELECT a.patient_id, a.dentist_id, a.service_id
                                        FROM appointments a
                                        LEFT JOIN billing b ON b.appointment_id = a.appointment_id
                                        WHERE a.appointment_id = ? AND a.status = 'completed' AND b.billing_id IS NULL");
        mysqli_stmt_bind_param($stmt, "i", $appointment_id);
        mysqli_stmt_execute($stmt);
        $appt = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$appt) {
            $error = "This appointment isn't eligible for billing — it may already have a bill, or isn't marked completed.";
        } else {
            $amount_dec = (float) $amount;
            $paid_at = $payment_status === 'paid' ? date('Y-m-d H:i:s') : null;

            $stmt2 = mysqli_prepare($conn, "INSERT INTO billing
                (appointment_id, patient_id, dentist_id, service_id, amount, reference_number, payment_status, billing_date, processed_by, paid_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt2, "iiiidsssis",
                $appointment_id, $appt['patient_id'], $appt['dentist_id'], $appt['service_id'],
                $amount_dec, $reference_number, $payment_status, $billing_date, $_SESSION['user_id'], $paid_at);

            if (mysqli_stmt_execute($stmt2)) {
                mysqli_stmt_close($stmt2);
                header('Location: payments.php?billed=1');
                exit;
            } else {
                $error = (mysqli_errno($conn) === 1062) ? "That reference number is already in use." : "Failed to generate the bill.";
            }
            mysqli_stmt_close($stmt2);
        }
    }
}

// ---- Handle: Update Payment Status ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment_status'])) {
    $billing_id = intval($_POST['billing_id']);
    $new_status = $_POST['new_status'] === 'paid' ? 'paid' : 'unpaid';
    $paid_at = $new_status === 'paid' ? date('Y-m-d H:i:s') : null;

    $stmt = mysqli_prepare($conn, "UPDATE billing SET payment_status = ?, paid_at = ? WHERE billing_id = ?");
    mysqli_stmt_bind_param($stmt, "ssi", $new_status, $paid_at, $billing_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    header('Location: payments.php?status_updated=1');
    exit;
}

if (isset($_GET['billed']))         { $message = "Bill generated successfully."; }
if (isset($_GET['status_updated'])) { $message = "Payment status updated."; }

$tab = ($_GET['tab'] ?? 'records') === 'unbilled' ? 'unbilled' : 'records';

//var
$search        = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$date_from     = $_GET['date_from'] ?? '';
$date_to       = $_GET['date_to'] ?? '';
$sort          = $_GET['sort'] ?? 'date_desc';

// ---- Count of completed appointments still awaiting a bill (for the tab badge) ----
$unbilled_count = 0;
$res = mysqli_query($conn, "SELECT COUNT(*) AS total FROM appointments a
                             LEFT JOIN billing b ON b.appointment_id = a.appointment_id
                             WHERE a.status = 'completed' AND b.billing_id IS NULL");
if ($res && $row = mysqli_fetch_assoc($res)) { $unbilled_count = (int) $row['total']; }

$unbilled_appointments = [];
$billing_rows = [];
$billing_totals = ['count' => 0, 'total' => 0.0, 'paid' => 0.0, 'unpaid' => 0.0];

if ($tab === 'unbilled') {
    $result = mysqli_query($conn, "SELECT a.appointment_id, a.appointment_date, a.start_time,
                                           CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                                           CONCAT(e.first_name, ' ', e.last_name) AS dentist_name,
                                           s.service_name, s.price
                                    FROM appointments a
                                    JOIN patients p  ON a.patient_id = p.patient_id
                                    JOIN employees e ON a.dentist_id = e.employee_id
                                    JOIN services s  ON a.service_id = s.service_id
                                    LEFT JOIN billing b ON b.appointment_id = a.appointment_id
                                    WHERE a.status = 'completed' AND b.billing_id IS NULL
                                    ORDER BY a.appointment_date DESC, a.start_time DESC");
    $unbilled_appointments = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
} else {
    // Billing Records / Billing History — unrestricted by default (the doc only calls out
    // date-range filtering as a *capability*, not a default 30-day limit like the reports page)
    $search        = trim($_GET['search'] ?? '');
    $status_filter = $_GET['status'] ?? 'all';
    $date_from     = $_GET['date_from'] ?? '';
    $date_to       = $_GET['date_to'] ?? '';
    $sort          = $_GET['sort'] ?? 'date_desc';

    $conditions = ["1=1"];
    if ($search !== '') {
        $s = mysqli_real_escape_string($conn, $search);
        $conditions[] = "(p.first_name LIKE '%$s%' OR p.last_name LIKE '%$s%' OR b.reference_number LIKE '%$s%')";
    }
    if ($status_filter !== 'all') {
        $conditions[] = "b.payment_status = '" . mysqli_real_escape_string($conn, $status_filter) . "'";
    }
    if ($date_from !== '') { $conditions[] = "b.billing_date >= '" . mysqli_real_escape_string($conn, $date_from) . "'"; }
    if ($date_to !== '')   { $conditions[] = "b.billing_date <= '" . mysqli_real_escape_string($conn, $date_to) . "'"; }
    $where_clause = implode(' AND ', $conditions);

    $order_by = "b.billing_date DESC";
    if ($sort === 'date_asc')     { $order_by = "b.billing_date ASC"; }
    if ($sort === 'amount_desc')  { $order_by = "b.amount DESC"; }
    if ($sort === 'amount_asc')   { $order_by = "b.amount ASC"; }

    $query = "SELECT b.billing_id, b.amount, b.reference_number, b.payment_status, b.billing_date, b.paid_at,
                     CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                     CONCAT(e.first_name, ' ', e.last_name) AS dentist_name,
                     s.service_name
              FROM billing b
              JOIN patients p  ON b.patient_id = p.patient_id
              JOIN employees e ON b.dentist_id = e.employee_id
              JOIN services s  ON b.service_id = s.service_id
              WHERE $where_clause
              ORDER BY $order_by";
    $result = mysqli_query($conn, $query);
    $billing_rows = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];

    foreach ($billing_rows as $b) {
        $billing_totals['count']++;
        $billing_totals['total'] += (float) $b['amount'];
        if ($b['payment_status'] === 'paid') { $billing_totals['paid'] += (float) $b['amount']; }
        else { $billing_totals['unpaid'] += (float) $b['amount']; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payments - Alberba Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/admin.css">
<style>
    .tab-bar{display:flex;gap:.6rem;margin-bottom:1.4rem;}
    .tab-link{padding:.6rem 1.2rem;border-radius:100px;font-size:.86rem;font-weight:600;color:var(--text-gray);border:1.5px solid var(--border-color);background:var(--white);}
    .tab-link.active{background:var(--primary-pink);border-color:var(--primary-pink);color:var(--white);}
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
    <a href="reports.php" class="nav-link">
        <svg viewBox="0 0 24 24"><path d="M4 20V11M10 20V5M16 20v-8M22 20H2"/></svg>
        Reports &amp; Analytics
    </a>
    <a href="payments.php" class="nav-link active">
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
            <h1>Payments</h1>
            <p class="subtitle">Billing is manual — no online payment gateway is connected. Reference numbers are recorded for every transaction.</p>
        </div>
        <span class="welcome-pill">Welcome, <?php echo htmlspecialchars($admin_username); ?></span>
    </div>

    <?php if (!empty($message)): ?><div class="alert success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="tab-bar">
        <a href="payments.php?tab=records"  class="tab-link <?php echo $tab === 'records'  ? 'active' : ''; ?>">Billing Records</a>
        <a href="payments.php?tab=unbilled" class="tab-link <?php echo $tab === 'unbilled' ? 'active' : ''; ?>">Ready to Bill (<?php echo $unbilled_count; ?>)</a>
    </div>

    <?php if ($tab === 'unbilled'): ?>

        <div class="card">
            <div class="card-head">
                <div><h2>Completed Appointments Awaiting a Bill</h2><p>Billing is initiated once a service has been completed</p></div>
            </div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Patient</th><th>Dentist</th><th>Service</th><th>Date</th><th>Service Price</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($unbilled_appointments)): ?>
                            <tr class="empty-row"><td colspan="6">Nothing waiting to be billed right now.</td></tr>
                        <?php else: ?>
                            <?php foreach ($unbilled_appointments as $a): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($a['patient_name']); ?></td>
                                    <td><?php echo htmlspecialchars($a['dentist_name']); ?></td>
                                    <td><?php echo htmlspecialchars($a['service_name']); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($a['appointment_date'])); ?></td>
                                    <td><?php echo format_currency($a['price']); ?></td>
                                    <td><button type="button" class="btn-sm btn-confirm" onclick='openBill(<?php echo json_encode($a, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>)'>Generate Bill</button></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php else: ?>

        <div class="card">
            <form class="filter-bar" method="get" action="payments.php">
                <input type="hidden" name="tab" value="records">
                <div class="filter-field" style="flex:1;min-width:200px;">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Patient name or reference #">
                </div>
                <div class="filter-field">
                    <label for="status">Payment Status</label>
                    <select id="status" name="status">
                        <option value="all"    <?php echo $status_filter === 'all'    ? 'selected' : ''; ?>>All</option>
                        <option value="paid"   <?php echo $status_filter === 'paid'   ? 'selected' : ''; ?>>Paid</option>
                        <option value="unpaid" <?php echo $status_filter === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
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
                    <label for="sort">Sort By</label>
                    <select id="sort" name="sort">
                        <option value="date_desc"   <?php echo $sort === 'date_desc'   ? 'selected' : ''; ?>>Date (Newest)</option>
                        <option value="date_asc"    <?php echo $sort === 'date_asc'    ? 'selected' : ''; ?>>Date (Oldest)</option>
                        <option value="amount_desc" <?php echo $sort === 'amount_desc' ? 'selected' : ''; ?>>Amount (High–Low)</option>
                        <option value="amount_asc"  <?php echo $sort === 'amount_asc'  ? 'selected' : ''; ?>>Amount (Low–High)</option>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-filter">Search</button>
                    <a href="payments.php?tab=records" class="btn-reset">Reset</a>
                </div>
            </form>
        </div>

        <div class="stats-grid">
            <div class="stat-card total"><div class="label">Total Billed</div><div class="value"><small>₱</small><?php echo number_format($billing_totals['total'], 2); ?></div></div>
            <div class="stat-card completed"><div class="label">Paid</div><div class="value"><small>₱</small><?php echo number_format($billing_totals['paid'], 2); ?></div></div>
            <div class="stat-card pending"><div class="label">Outstanding</div><div class="value"><small>₱</small><?php echo number_format($billing_totals['unpaid'], 2); ?></div></div>
            <div class="stat-card total"><div class="label">Records</div><div class="value"><?php echo $billing_totals['count']; ?></div></div>
        </div>

        <div class="card">
            <div class="card-head">
                <div><h2>Billing Records</h2><p>Complete log of past bills — filter by patient or date range</p></div>
            </div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Patient</th><th>Dentist</th><th>Service</th><th>Billed On</th><th>Reference #</th><th>Amount</th><th>Payment</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($billing_rows)): ?>
                            <tr class="empty-row"><td colspan="8">No billing records match these filters.</td></tr>
                        <?php else: ?>
                            <?php foreach ($billing_rows as $b): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($b['patient_name']); ?></td>
                                    <td><?php echo htmlspecialchars($b['dentist_name']); ?></td>
                                    <td><?php echo htmlspecialchars($b['service_name']); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($b['billing_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($b['reference_number']); ?></td>
                                    <td><?php echo format_currency($b['amount']); ?></td>
                                    <td><span class="badge <?php echo $b['payment_status'] === 'paid' ? 'completed' : 'pending'; ?>"><?php echo ucfirst($b['payment_status']); ?></span></td>
                                    <td>
                                        <?php if ($b['payment_status'] === 'unpaid'): ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Mark this bill as paid?');"><?php echo csrf_field(); ?>
                                                <input type="hidden" name="billing_id" value="<?php echo $b['billing_id']; ?>">
                                                <input type="hidden" name="new_status" value="paid">
                                                <button type="submit" name="update_payment_status" class="btn-sm btn-confirm">Mark Paid</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Mark this bill as unpaid?');"><?php echo csrf_field(); ?>
                                                <input type="hidden" name="billing_id" value="<?php echo $b['billing_id']; ?>">
                                                <input type="hidden" name="new_status" value="unpaid">
                                                <button type="submit" name="update_payment_status" class="btn-sm btn-cancel">Mark Unpaid</button>
                                            </form>
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

<!-- Generate Bill Modal -->
<div class="modal-overlay" id="billModal">
    <div class="modal">
        <div class="modal-head">
            <h3>Generate Bill</h3>
            <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" novalidate><?php echo csrf_field(); ?>
            <input type="hidden" name="appointment_id" id="bill_appointment_id">
            <div class="modal-body">
                <div class="detail-row"><span>Patient</span><strong id="bill_patient_name"></strong></div>
                <div class="detail-row"><span>Service</span><strong id="bill_service_name"></strong></div>
                <div class="detail-row"><span>Dentist</span><strong id="bill_dentist_name"></strong></div>
                <div class="form-group" style="margin-top:1.1rem;">
                    <label for="reference_number">Reference Number *</label>
                    <input type="text" id="reference_number" name="reference_number" placeholder="Manual payment reference" required>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="amount">Amount (₱) *</label>
                        <input type="number" id="amount" name="amount" min="0" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label for="billing_date">Billing Date *</label>
                        <input type="date" id="billing_date" name="billing_date" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="payment_status">Payment Status</label>
                    <select id="payment_status" name="payment_status">
                        <option value="unpaid">Unpaid</option>
                        <option value="paid">Paid</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-ghost-sm" onclick="closeModal()">Cancel</button>
                <button type="submit" name="generate_bill" class="btn-primary-sm">Generate Bill</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openBill(appt) {
        document.getElementById('bill_appointment_id').value = appt.appointment_id;
        document.getElementById('bill_patient_name').textContent = appt.patient_name;
        document.getElementById('bill_service_name').textContent = appt.service_name;
        document.getElementById('bill_dentist_name').textContent = appt.dentist_name;
        document.getElementById('amount').value = appt.price;
        document.getElementById('billing_date').value = new Date().toISOString().slice(0, 10);
        document.getElementById('reference_number').value = '';
        document.getElementById('payment_status').value = 'unpaid';
        document.getElementById('billModal').classList.add('open');
    }

    function closeModal() {
        document.getElementById('billModal').classList.remove('open');
    }

    document.getElementById('billModal').addEventListener('click', function (e) {
        if (e.target === this) closeModal();
    });

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