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

// ---- Handle: Add Patient (walk-in registration by staff) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_patient'])) {
    $first_name = sanitizeInput($_POST['first_name'] ?? '');
    $last_name  = sanitizeInput($_POST['last_name'] ?? '');
    $email      = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $phone      = preg_replace('/\D/', '', $_POST['phone'] ?? '');
    $username   = sanitizeInput($_POST['username'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';
    $birthdate  = $_POST['birthdate'] ?? '';
    $address    = sanitizeInput($_POST['address'] ?? '');

    if (empty($first_name) || empty($last_name) || empty($email) || empty($phone) || empty($username) || empty($password) || empty($confirm)) {
        $error = "Please fill in all required fields.";
    } elseif (strlen($username) < 4) {
        $error = "Username must be at least 4 characters.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (!preg_match('/^09\d{9}$/', $phone)) {
        $error = "Contact number must be 11 digits starting with 09.";
    } elseif (strlen($password) < 8 || !preg_match('/\d/', $password)) {
        $error = "Password must be at least 8 characters and include at least one digit.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        mysqli_begin_transaction($conn);
        $ok = true;
        try {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = mysqli_prepare($conn, "INSERT INTO users (username, password_hash, email, role_id)
                                            VALUES (?, ?, ?, (SELECT role_id FROM roles WHERE role_name = 'Patient' LIMIT 1))");
            mysqli_stmt_bind_param($stmt, "sss", $username, $hashed, $email);
            if (!mysqli_stmt_execute($stmt)) { $ok = false; }
            $new_user_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            if ($ok) {
                $birthdate_val = $birthdate !== '' ? $birthdate : null;
                $address_val   = $address !== '' ? $address : null;
                $stmt2 = mysqli_prepare($conn, "INSERT INTO patients (user_id, first_name, last_name, contact_number, birthdate, address, registered_via, created_by)
                                                 VALUES (?, ?, ?, ?, ?, ?, 'walk-in', ?)");
                mysqli_stmt_bind_param($stmt2, "isssssi", $new_user_id, $first_name, $last_name, $phone, $birthdate_val, $address_val, $_SESSION['user_id']);
                if (!mysqli_stmt_execute($stmt2)) { $ok = false; }
                mysqli_stmt_close($stmt2);
            }

            if ($ok) {
                mysqli_commit($conn);
                header('Location: patients.php?added=1');
                exit;
            } else {
                mysqli_rollback($conn);
                $error = (mysqli_errno($conn) === 1062) ? "Username or email already exists." : "Failed to add patient. Please try again.";
            }
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Failed to add patient due to an unexpected error.";
        }
    }
}

if (isset($_GET['added']))   { $message = "Patient registered successfully."; }
if (isset($_GET['archived'])) { $message = "Patient account archived."; }
if (isset($_GET['restored'])) { $message = "Patient account restored."; }

// ---- Handle: Archive / Restore patient account ----
// Patients don't have their own status column — this flips the linked users.account_status,
// the same field used for employee archiving and already checked by login.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['archive_patient']) || isset($_POST['restore_patient']))) {
    $patient_id = intval($_POST['patient_id']);
    $new_status = isset($_POST['archive_patient']) ? 'archived' : 'active';

    $stmt = mysqli_prepare($conn, "SELECT user_id FROM patients WHERE patient_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $patient_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($row) {
        $stmt2 = mysqli_prepare($conn, "UPDATE users SET account_status = ? WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt2, "si", $new_status, $row['user_id']);
        mysqli_stmt_execute($stmt2);
        mysqli_stmt_close($stmt2);
    }

    $flag = $new_status === 'archived' ? 'archived' : 'restored';
    $redirect_target = $_POST['redirect'] ?? '';
    if ($redirect_target === 'profile') {
        $redirect = "patient_view.php?id=$patient_id&$flag=1";
    } elseif ($redirect_target === 'archives') {
        $redirect = "archives.php?$flag=1&tab=patients";
    } else {
        $redirect = "patients.php?$flag=1";
    }
    header("Location: $redirect");
    exit;
}

// ---- Search + Sort + Status filter ----
$search  = trim($_GET['search'] ?? '');
$sort    = $_GET['sort'] ?? 'name_asc';
$status_filter = $_GET['status'] ?? 'active';

$conditions = ["1=1"];
if ($search !== '') {
    $s = mysqli_real_escape_string($conn, $search);
    $conditions[] = "(p.first_name LIKE '%$s%' OR p.last_name LIKE '%$s%' OR p.contact_number LIKE '%$s%' OR u.email LIKE '%$s%')";
}
if ($status_filter !== 'all') {
    $conditions[] = "u.account_status = '" . mysqli_real_escape_string($conn, $status_filter) . "'";
}
$where_clause = implode(' AND ', $conditions);

$order_by = "p.last_name ASC, p.first_name ASC";
if ($sort === 'name_desc') { $order_by = "p.last_name DESC, p.first_name DESC"; }
if ($sort === 'date_desc') { $order_by = "p.created_at DESC"; }
if ($sort === 'date_asc')  { $order_by = "p.created_at ASC"; }

$query = "SELECT p.patient_id, p.first_name, p.last_name, p.contact_number, p.birthdate, p.address,
                 p.registered_via, p.created_at, u.email, u.username, u.account_status,
                 (SELECT COUNT(*) FROM appointments a WHERE a.patient_id = p.patient_id) AS total_appointments
          FROM patients p
          JOIN users u ON p.user_id = u.user_id
          WHERE $where_clause
          ORDER BY $order_by";
$result = mysqli_query($conn, $query);
$patients = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Patient Records - Alberba Dental Clinic</title>
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
            <h1>Patient Records</h1>
            <p class="subtitle">Search, review, and manage every patient on file</p>
        </div>
        <div class="topbar-right">
            <button type="button" class="btn-primary-sm" onclick="openAdd()">+ Add Patient</button>
            <span class="welcome-pill">Welcome, <?php echo htmlspecialchars($admin_username); ?></span>
        </div>
    </div>

    <?php if (!empty($message)): ?><div class="alert success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card">
        <form class="filter-bar" method="get" action="patients.php">
            <div class="filter-field" style="flex:1;min-width:220px;">
                <label for="search">Search</label>
                <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, contact number, or email">
            </div>
            <div class="filter-field">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="active"   <?php echo $status_filter === 'active'   ? 'selected' : ''; ?>>Active</option>
                    <option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
                    <option value="all"      <?php echo $status_filter === 'all'      ? 'selected' : ''; ?>>All</option>
                </select>
            </div>
            <div class="filter-field">
                <label for="sort">Sort By</label>
                <select id="sort" name="sort">
                    <option value="name_asc"  <?php echo $sort === 'name_asc'  ? 'selected' : ''; ?>>Name (A–Z)</option>
                    <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name (Z–A)</option>
                    <option value="date_desc" <?php echo $sort === 'date_desc' ? 'selected' : ''; ?>>Newest Registered</option>
                    <option value="date_asc"  <?php echo $sort === 'date_asc'  ? 'selected' : ''; ?>>Oldest Registered</option>
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
            <div>
                <h2>Patient List</h2>
                <p><?php echo count($patients); ?> patient<?php echo count($patients) === 1 ? '' : 's'; ?> on record</p>
            </div>
        </div>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Patient</th><th>Contact</th><th>Email</th><th>Registered</th><th>Since</th><th>Appointments</th><th>Account</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($patients)): ?>
                        <tr class="empty-row"><td colspan="8">No patients match this search.</td></tr>
                    <?php else: ?>
                        <?php foreach ($patients as $p): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($p['contact_number'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($p['email']); ?></td>
                                <td><span class="badge <?php echo htmlspecialchars($p['registered_via']); ?>"><?php echo ucfirst($p['registered_via']); ?></span></td>
                                <td><?php echo date('M j, Y', strtotime($p['created_at'])); ?></td>
                                <td><?php echo (int) $p['total_appointments']; ?></td>
                                <td><span class="badge <?php echo $p['account_status'] === 'active' ? 'confirmed' : 'cancelled'; ?>"><?php echo ucfirst($p['account_status']); ?></span></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="patient_view.php?id=<?php echo $p['patient_id']; ?>" class="btn-sm btn-view">View</a>
                                        <?php if ($p['account_status'] === 'active'): ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this patient account? They will no longer be able to log in, but their records stay intact.');"><?php echo csrf_field(); ?>
                                                <input type="hidden" name="patient_id" value="<?php echo $p['patient_id']; ?>">
                                                <button type="submit" name="archive_patient" class="btn-sm btn-cancel">Archive</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Restore this patient account?');"><?php echo csrf_field(); ?>
                                                <input type="hidden" name="patient_id" value="<?php echo $p['patient_id']; ?>">
                                                <button type="submit" name="restore_patient" class="btn-sm btn-confirm">Restore</button>
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

<!-- Add Patient Modal -->
<div class="modal-overlay" id="addModal">
    <div class="modal modal-wide">
        <div class="modal-head">
            <h3>Register New Patient</h3>
            <button type="button" class="modal-close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST" novalidate><?php echo csrf_field(); ?>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="add_first_name">First Name *</label>
                        <input type="text" id="add_first_name" name="first_name" required>
                    </div>
                    <div class="form-group">
                        <label for="add_last_name">Last Name *</label>
                        <input type="text" id="add_last_name" name="last_name" required>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="add_email">Email *</label>
                        <input type="email" id="add_email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label for="add_phone">Contact Number *</label>
                        <input type="tel" id="add_phone" name="phone" placeholder="09XXXXXXXXX" maxlength="11" required>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="add_birthdate">Birthdate</label>
                        <input type="date" id="add_birthdate" name="birthdate">
                    </div>
                    <div class="form-group">
                        <label for="add_address">Address</label>
                        <input type="text" id="add_address" name="address">
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="add_username">Username *</label>
                        <input type="text" id="add_username" name="username" required>
                    </div>
                    <div></div>
                    <div class="form-group">
                        <label for="add_password">Password *</label>
                        <input type="password" id="add_password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label for="add_confirm_password">Confirm Password *</label>
                        <input type="password" id="add_confirm_password" name="confirm_password" required>
                    </div>
                </div>
                <p style="font-size:.78rem;color:var(--text-gray);">Password must be at least 8 characters and include a digit. The patient can change this later from their own profile.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-ghost-sm" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" name="add_patient" class="btn-primary-sm">Register Patient</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openAdd() {
        document.getElementById('addModal').classList.add('open');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
    }

    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) overlay.classList.remove('open');
        });
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