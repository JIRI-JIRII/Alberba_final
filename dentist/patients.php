<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../func/functions.php';
/** @var mysqli $conn */
checkLogin();
checkRole(['Dentist']);

$dentist_username = $_SESSION['username'];
$dentist_id = $_SESSION['employee_id'] ?? 0;
if (!$dentist_id) {
    header('Location: ../login.php');
    exit;
}

$search = trim($_GET['search'] ?? '');
$sort   = $_GET['sort'] ?? 'name_asc';

$conditions = ["EXISTS (SELECT 1 FROM appointments ex WHERE ex.patient_id = p.patient_id AND ex.dentist_id = ?)"];
$types = "i";
$params = [$dentist_id];

if ($search !== '') {
    $conditions[] = "(p.first_name LIKE ? OR p.last_name LIKE ?)";
    $types .= "ss";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
}
$where_clause = implode(' AND ', $conditions);

$order_by = "p.last_name ASC, p.first_name ASC";
if ($sort === 'name_desc') { $order_by = "p.last_name DESC, p.first_name DESC"; }
if ($sort === 'visits_desc') { $order_by = "past_visits DESC"; }

// Patients scoped strictly to this dentist — a dentist only ever sees people they've
// actually treated, never the clinic's full patient roster.
$sql = "SELECT p.patient_id,
               CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
               p.contact_number, p.address, u.email,
               (SELECT COUNT(*) FROM appointments pv WHERE pv.patient_id = p.patient_id AND pv.dentist_id = ? AND pv.status = 'completed') AS past_visits,
               (SELECT s.service_name FROM appointments la JOIN services s ON la.service_id = s.service_id
                WHERE la.patient_id = p.patient_id AND la.dentist_id = ?
                ORDER BY la.appointment_date DESC, la.start_time DESC LIMIT 1) AS last_service_booked,
               (SELECT s2.service_name FROM appointments op JOIN services s2 ON op.service_id = s2.service_id
                WHERE op.patient_id = p.patient_id AND op.dentist_id = ? AND op.status = 'completed'
                ORDER BY op.appointment_date DESC LIMIT 1) AS recent_operation,
               (SELECT op2.appointment_date FROM appointments op2
                WHERE op2.patient_id = p.patient_id AND op2.dentist_id = ? AND op2.status = 'completed'
                ORDER BY op2.appointment_date DESC LIMIT 1) AS recent_operation_date
        FROM patients p
        JOIN users u ON p.user_id = u.user_id
        WHERE $where_clause
        ORDER BY $order_by";

// the four subquery placeholders (all the same dentist_id) come first, then the WHERE clause's own params
$stmt = mysqli_prepare($conn, $sql);
$all_types = "iiii" . $types;
$all_params = [$dentist_id, $dentist_id, $dentist_id, $dentist_id, ...$params];
mysqli_stmt_bind_param($stmt, $all_types, ...$all_params);
mysqli_stmt_execute($stmt);
$patients = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

// ---- Total Patients ----
$stmt = mysqli_prepare($conn, "SELECT COUNT(DISTINCT patient_id) AS total FROM appointments WHERE dentist_id = ?");
mysqli_stmt_bind_param($stmt, "i", $dentist_id);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$total_patients = (int) $row['total'];
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Patients - Alberba Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/dentist.css">
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
        My Appointments
    </a>
    <a href="patients.php" class="nav-link active">
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
            <h1>My Patients</h1>
            <p class="subtitle">Everyone you've treated, with their booking and visit history</p>
        </div>
        <span class="welcome-pill">Welcome, <?php echo htmlspecialchars($dentist_username); ?></span>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="label">Total Patients</div><div class="value"><?php echo $total_patients; ?></div></div>
    </div>

    <div class="card">
        <form class="filter-bar" method="get" action="patients.php">
            <div class="filter-field" style="flex:1;min-width:220px;">
                <label for="search">Search</label>
                <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Patient name">
            </div>
            <div class="filter-field">
                <label for="sort">Sort By</label>
                <select id="sort" name="sort">
                    <option value="name_asc"    <?php echo $sort === 'name_asc'    ? 'selected' : ''; ?>>Name (A–Z)</option>
                    <option value="name_desc"   <?php echo $sort === 'name_desc'   ? 'selected' : ''; ?>>Name (Z–A)</option>
                    <option value="visits_desc" <?php echo $sort === 'visits_desc' ? 'selected' : ''; ?>>Most Visits</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-filter">Search</button>
                <a href="patients.php" class="btn-reset">Reset</a>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-head">
            <div><h2>Patient List</h2><p><?php echo count($patients); ?> patient<?php echo count($patients) === 1 ? '' : 's'; ?></p></div>
        </div>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Full Name</th><th>Service Booked</th><th>Email</th><th>Past Visits</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php if (empty($patients)): ?>
                        <tr class="empty-row"><td colspan="5">No patients found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($patients as $p): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($p['patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($p['last_service_booked'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($p['email']); ?></td>
                                <td><?php echo (int) $p['past_visits']; ?></td>
                                <td><button type="button" class="btn-sm btn-view" onclick='viewPatient(<?php echo json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>)'>View Details</button></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<div class="modal-overlay" id="patientModal">
    <div class="modal">
        <div class="modal-head">
            <h3>Patient Details</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('patientModal').classList.remove('open')">&times;</button>
        </div>
        <div class="modal-body" id="patientModalBody"></div>
    </div>
</div>

<script>
    function viewPatient(p) {
        const rows = [
            ['Full Name', p.patient_name],
            ['Address', p.address || '—'],
            ['Contact Number', p.contact_number || '—'],
            ['Email', p.email],
            ['Recent Operation', p.recent_operation ? p.recent_operation + ' (' + new Date(p.recent_operation_date + 'T00:00:00').toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) + ')' : 'No completed procedures yet'],
        ];
        document.getElementById('patientModalBody').innerHTML = rows.map(([label, value]) =>
            `<div class="detail-row"><span>${label}</span><strong>${value}</strong></div>`
        ).join('');
        document.getElementById('patientModal').classList.add('open');
    }

    document.getElementById('patientModal').addEventListener('click', function (e) {
        if (e.target === this) this.classList.remove('open');
    });
</script>
</body>
</html>