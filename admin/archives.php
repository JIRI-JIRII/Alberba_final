<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../func/functions.php';

checkLogin();
checkRole(['Administrator']);

/** @var mysqli $conn */

$admin_username = $_SESSION['username'];
$message = '';

if (isset($_GET['restored'])) { $message = "Record restored."; }

$tab = ($_GET['tab'] ?? 'patients') === 'services' ? 'services' : 'patients';
$search = trim($_GET['search'] ?? '');

$patient_archives = [];
$service_archives = [];

if ($tab === 'patients') {
    // Patient Record Archives: patients whose linked account has been archived.
    // (Patients don't have their own status column — this mirrors the same users.account_status
    // flag that patients.php's Archive action sets and login.php checks.)
    $conditions = ["u.account_status = 'archived'"];
    if ($search !== '') {
        $s = mysqli_real_escape_string($conn, $search);
        $conditions[] = "(p.first_name LIKE '%$s%' OR p.last_name LIKE '%$s%' OR u.email LIKE '%$s%')";
    }
    $where_clause = implode(' AND ', $conditions);

    $query = "SELECT p.patient_id, p.first_name, p.last_name, p.contact_number, u.email, u.updated_at
              FROM patients p
              JOIN users u ON p.user_id = u.user_id
              WHERE $where_clause
              ORDER BY u.updated_at DESC";
    $result = mysqli_query($conn, $query);
    $patient_archives = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
} else {
    // Services Archives: permanent historical log, populated when a service is deleted
    // permanently from services.php's Recently Deleted tab. These rows can't be restored —
    // the live services row is gone — this is the paper trail that's left behind.
    $conditions = ["1=1"];
    if ($search !== '') {
        $s = mysqli_real_escape_string($conn, $search);
        $conditions[] = "(service_name LIKE '%$s%' OR category LIKE '%$s%')";
    }
    $where_clause = implode(' AND ', $conditions);

    $query = "SELECT archive_id, original_service_id, service_name, category, duration_minutes, price, deleted_at, archived_at
              FROM services_archive
              WHERE $where_clause
              ORDER BY archived_at DESC";
    $result = mysqli_query($conn, $query);
    $service_archives = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Archives - Alberba Dental Clinic</title>
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
    <a href="archives.php" class="nav-link active">
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
            <h1>Archives</h1>
            <p class="subtitle">Historical records the clinic keeps for reference</p>
        </div>
        <span class="welcome-pill">Welcome, <?php echo htmlspecialchars($admin_username); ?></span>
    </div>

    <?php if (!empty($message)): ?><div class="alert success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

    <div class="tab-bar">
        <a href="archives.php?tab=patients" class="tab-link <?php echo $tab === 'patients' ? 'active' : ''; ?>">Patient Record Archives</a>
        <a href="archives.php?tab=services" class="tab-link <?php echo $tab === 'services' ? 'active' : ''; ?>">Services Archives</a>
    </div>

    <div class="card">
        <form class="filter-bar" method="get" action="archives.php">
            <input type="hidden" name="tab" value="<?php echo $tab; ?>">
            <div class="filter-field" style="flex:1;min-width:220px;">
                <label for="search">Search</label>
                <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="<?php echo $tab === 'patients' ? 'Patient name or email' : 'Service name or category'; ?>">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-filter">Search</button>
                <a href="archives.php?tab=<?php echo $tab; ?>" class="btn-reset">Reset</a>
            </div>
        </form>
    </div>

    <?php if ($tab === 'patients'): ?>

        <div class="card">
            <div class="card-head">
                <div>
                    <h2>Patient Record Archives</h2>
                    <p><?php echo count($patient_archives); ?> archived patient<?php echo count($patient_archives) === 1 ? '' : 's'; ?></p>
                </div>
            </div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Patient</th><th>Contact</th><th>Email</th><th>Archived On</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php if (empty($patient_archives)): ?>
                            <tr class="empty-row"><td colspan="5">No archived patient records.</td></tr>
                        <?php else: ?>
                            <?php foreach ($patient_archives as $p): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($p['contact_number'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($p['email']); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($p['updated_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="patient_view.php?id=<?php echo $p['patient_id']; ?>" class="btn-sm btn-view">View</a>
                                            <form method="POST" action="patients.php" style="display:inline;" onsubmit="return confirm('Restore this patient account?');"><?php echo csrf_field(); ?>
                                                <input type="hidden" name="patient_id" value="<?php echo $p['patient_id']; ?>">
                                                <input type="hidden" name="redirect" value="archives">
                                                <button type="submit" name="restore_patient" class="btn-sm btn-confirm">Restore</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p style="font-size:.78rem;color:var(--text-gray);margin-top:1rem;">"Archived On" reflects when the account's status was last changed — it's a close approximation, not a dedicated audit log entry.</p>
        </div>

    <?php else: ?>

        <div class="card">
            <div class="card-head">
                <div>
                    <h2>Services Archives</h2>
                    <p><?php echo count($service_archives); ?> permanently removed service<?php echo count($service_archives) === 1 ? '' : 's'; ?></p>
                </div>
            </div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Service</th><th>Category</th><th>Duration</th><th>Price</th><th>Deleted On</th><th>Archived On</th></tr></thead>
                    <tbody>
                        <?php if (empty($service_archives)): ?>
                            <tr class="empty-row"><td colspan="6">No permanently removed services.</td></tr>
                        <?php else: ?>
                            <?php foreach ($service_archives as $s): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($s['service_name']); ?></td>
                                    <td><?php echo htmlspecialchars($s['category'] ?: '—'); ?></td>
                                    <td><?php echo $s['duration_minutes'] ? (int) $s['duration_minutes'] . ' min' : '—'; ?></td>
                                    <td><?php echo $s['price'] !== null ? format_currency($s['price']) : '—'; ?></td>
                                    <td><?php echo $s['deleted_at'] ? date('M j, Y', strtotime($s['deleted_at'])) : '—'; ?></td>
                                    <td><?php echo date('M j, Y', strtotime($s['archived_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p style="font-size:.78rem;color:var(--text-gray);margin-top:1rem;">These are permanently removed — there's no live service row left to restore. This table is the paper trail left behind.</p>
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