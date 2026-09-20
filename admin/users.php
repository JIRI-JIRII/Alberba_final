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

// ---- Handle: Create Employee Account (Dentist or Receptionist) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_employee'])) {
    $role       = $_POST['role'] ?? '';
    $first_name = sanitizeInput($_POST['first_name'] ?? '');
    $last_name  = sanitizeInput($_POST['last_name'] ?? '');
    $email      = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $phone      = preg_replace('/\D/', '', $_POST['phone'] ?? '');
    $username   = sanitizeInput($_POST['username'] ?? '');
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';
    $specialization = sanitizeInput($_POST['specialization'] ?? '');
    $hire_date  = $_POST['hire_date'] ?? '';

    // only these two roles are created through this screen — Administrator accounts aren't self-service here
    if (!in_array($role, ['Dentist', 'Receptionist'], true)) {
        $error = "Please select a valid role.";
    } elseif (empty($first_name) || empty($last_name) || empty($email) || empty($phone) || empty($username) || empty($password) || empty($confirm)) {
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
                                            VALUES (?, ?, ?, (SELECT role_id FROM roles WHERE role_name = ? LIMIT 1))");
            mysqli_stmt_bind_param($stmt, "ssss", $username, $hashed, $email, $role);
            if (!mysqli_stmt_execute($stmt)) { $ok = false; }
            $new_user_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            if ($ok) {
                $spec_val = ($role === 'Dentist' && $specialization !== '') ? $specialization : null;
                $hire_val = $hire_date !== '' ? $hire_date : date('Y-m-d');
                $stmt2 = mysqli_prepare($conn, "INSERT INTO employees (user_id, first_name, last_name, contact_number, specialization, hire_date, created_by)
                                                 VALUES (?, ?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt2, "isssssi", $new_user_id, $first_name, $last_name, $phone, $spec_val, $hire_val, $_SESSION['user_id']);
                // note: 6 string/int placeholders above but 7 values — corrected type string below
                mysqli_stmt_close($stmt2);

                $stmt2 = mysqli_prepare($conn, "INSERT INTO employees (user_id, first_name, last_name, contact_number, specialization, hire_date, created_by)
                                                 VALUES (?, ?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt2, "issssss", $new_user_id, $first_name, $last_name, $phone, $spec_val, $hire_val, $_SESSION['user_id']);
                if (!mysqli_stmt_execute($stmt2)) { $ok = false; }
                mysqli_stmt_close($stmt2);
            }

            if ($ok) {
                mysqli_commit($conn);
                header('Location: users.php?added=1');
                exit;
            } else {
                mysqli_rollback($conn);
                $error = (mysqli_errno($conn) === 1062) ? "Username or email already exists." : "Failed to create employee account.";
            }
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Failed to create employee account due to an unexpected error.";
        }
    }
}

// ---- Handle: Edit Employee Account (non-credential fields only) + Request Change notification ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_employee'])) {
    $employee_id    = intval($_POST['employee_id']);
    $first_name     = sanitizeInput($_POST['first_name'] ?? '');
    $last_name      = sanitizeInput($_POST['last_name'] ?? '');
    $phone          = preg_replace('/\D/', '', $_POST['phone'] ?? '');
    $address        = sanitizeInput($_POST['address'] ?? '');
    $specialization = sanitizeInput($_POST['specialization'] ?? '');
    $hire_date      = $_POST['hire_date'] ?? '';

    if (empty($first_name) || empty($last_name) || empty($phone)) {
        $error = "First name, last name, and contact number are required.";
    } elseif (!preg_match('/^09\d{9}$/', $phone)) {
        $error = "Contact number must be 11 digits starting with 09.";
    } else {
        $address_val = $address !== '' ? $address : null;
        $spec_val    = $specialization !== '' ? $specialization : null;
        $hire_val    = $hire_date !== '' ? $hire_date : null;

        $stmt = mysqli_prepare($conn, "UPDATE employees SET first_name = ?, last_name = ?, contact_number = ?, address = ?, specialization = ?, hire_date = ? WHERE employee_id = ?");
        mysqli_stmt_bind_param($stmt, "ssssssi", $first_name, $last_name, $phone, $address_val, $spec_val, $hire_val, $employee_id);

        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);

            // Request Change: notify the employee that an admin edited their account
            $lookup = mysqli_prepare($conn, "SELECT user_id FROM employees WHERE employee_id = ?");
            mysqli_stmt_bind_param($lookup, "i", $employee_id);
            mysqli_stmt_execute($lookup);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($lookup));
            mysqli_stmt_close($lookup);

            if ($row) {
                $notif_msg = "An administrator updated your account details on " . date('M j, Y \a\t g:i A') . ". If you didn't expect this change, please contact the clinic.";
                $notif = mysqli_prepare($conn, "INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                mysqli_stmt_bind_param($notif, "is", $row['user_id'], $notif_msg);
                mysqli_stmt_execute($notif);
                mysqli_stmt_close($notif);
            }

            header('Location: users.php?updated=1');
            exit;
        } else {
            $error = "Failed to update employee record.";
        }
        mysqli_stmt_close($stmt);
    }
}

// ---- Handle: Archive / Restore employee account ----
// Keeps users.account_status (what login.php actually checks) and employees.account_status in sync.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['archive_employee']) || isset($_POST['restore_employee']))) {
    $employee_id = intval($_POST['employee_id']);
    $new_status  = isset($_POST['archive_employee']) ? 'archived' : 'active';

    $stmt = mysqli_prepare($conn, "SELECT user_id FROM employees WHERE employee_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $employee_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($row) {
        $stmt2 = mysqli_prepare($conn, "UPDATE users SET account_status = ? WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt2, "si", $new_status, $row['user_id']);
        mysqli_stmt_execute($stmt2);
        mysqli_stmt_close($stmt2);

        $stmt3 = mysqli_prepare($conn, "UPDATE employees SET account_status = ? WHERE employee_id = ?");
        mysqli_stmt_bind_param($stmt3, "si", $new_status, $employee_id);
        mysqli_stmt_execute($stmt3);
        mysqli_stmt_close($stmt3);
    }

    $flag = $new_status === 'archived' ? 'archived' : 'restored';
    header("Location: users.php?$flag=1");
    exit;
}

if (isset($_GET['added']))    { $message = "Employee account created successfully."; }
if (isset($_GET['updated']))  { $message = "Employee record updated and the employee has been notified."; }
if (isset($_GET['archived'])) { $message = "Employee account archived."; }
if (isset($_GET['restored'])) { $message = "Employee account restored."; }

// ---- Search + Role filter + Status filter + Sort ----
$search        = trim($_GET['search'] ?? '');
$role_filter   = $_GET['role'] ?? 'all';
$status_filter = $_GET['status'] ?? 'active';
$sort          = $_GET['sort'] ?? 'name_asc';

$conditions = ["1=1"];
if ($search !== '') {
    $s = mysqli_real_escape_string($conn, $search);
    $conditions[] = "(e.first_name LIKE '%$s%' OR e.last_name LIKE '%$s%' OR e.contact_number LIKE '%$s%' OR u.email LIKE '%$s%')";
}
if ($role_filter !== 'all') {
    $conditions[] = "r.role_name = '" . mysqli_real_escape_string($conn, $role_filter) . "'";
}
if ($status_filter !== 'all') {
    $conditions[] = "u.account_status = '" . mysqli_real_escape_string($conn, $status_filter) . "'";
}
$where_clause = implode(' AND ', $conditions);

$order_by = "e.last_name ASC, e.first_name ASC";
if ($sort === 'name_desc') { $order_by = "e.last_name DESC, e.first_name DESC"; }
if ($sort === 'hire_desc') { $order_by = "e.hire_date DESC"; }
if ($sort === 'hire_asc')  { $order_by = "e.hire_date ASC"; }

$query = "SELECT e.employee_id, e.first_name, e.last_name, e.contact_number, e.address,
                 e.specialization, e.hire_date, e.created_at,
                 u.user_id, u.username, u.email, u.account_status, r.role_name
          FROM employees e
          JOIN users u ON e.user_id = u.user_id
          JOIN roles r ON u.role_id = r.role_id
          WHERE $where_clause
          ORDER BY $order_by";
$result = mysqli_query($conn, $query);
$employees = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Management - Alberba Dental Clinic</title>
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
    <a href="users.php" class="nav-link active">
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
            <h1>User Management</h1>
            <p class="subtitle">Create and manage Dentist and Receptionist accounts</p>
        </div>
        <div class="topbar-right">
            <button type="button" class="btn-primary-sm" onclick="openAdd()">+ Add Employee</button>
            <span class="welcome-pill">Welcome, <?php echo htmlspecialchars($admin_username); ?></span>
        </div>
    </div>

    <?php if (!empty($message)): ?><div class="alert success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card">
        <form class="filter-bar" method="get" action="users.php">
            <div class="filter-field" style="flex:1;min-width:200px;">
                <label for="search">Search</label>
                <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, contact number, or email">
            </div>
            <div class="filter-field">
                <label for="role">Role</label>
                <select id="role" name="role">
                    <option value="all"          <?php echo $role_filter === 'all' ? 'selected' : ''; ?>>All Roles</option>
                    <option value="Dentist"      <?php echo $role_filter === 'Dentist' ? 'selected' : ''; ?>>Dentist</option>
                    <option value="Receptionist" <?php echo $role_filter === 'Receptionist' ? 'selected' : ''; ?>>Receptionist</option>
                </select>
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
                    <option value="hire_desc" <?php echo $sort === 'hire_desc' ? 'selected' : ''; ?>>Newest Hired</option>
                    <option value="hire_asc"  <?php echo $sort === 'hire_asc'  ? 'selected' : ''; ?>>Oldest Hired</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-filter">Search</button>
                <a href="users.php" class="btn-reset">Reset</a>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h2>Account Overview</h2>
                <p><?php echo count($employees); ?> employee<?php echo count($employees) === 1 ? '' : 's'; ?> on record</p>
            </div>
        </div>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>Employee</th><th>Role</th><th>Contact</th><th>Specialization</th><th>Hired</th><th>Status</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employees)): ?>
                        <tr class="empty-row"><td colspan="7">No employee accounts match these filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($employees as $emp): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($emp['role_name']); ?></td>
                                <td><?php echo htmlspecialchars($emp['contact_number'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($emp['specialization'] ?? '—'); ?></td>
                                <td><?php echo $emp['hire_date'] ? date('M j, Y', strtotime($emp['hire_date'])) : '—'; ?></td>
                                <td><span class="badge <?php echo $emp['account_status'] === 'active' ? 'confirmed' : 'cancelled'; ?>"><?php echo ucfirst($emp['account_status']); ?></span></td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="btn-sm btn-view" onclick='viewEmployee(<?php echo json_encode($emp, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>)'>View</button>
                                        <button type="button" class="btn-sm btn-complete" onclick='openEdit(<?php echo json_encode($emp, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG); ?>)'>Edit</button>
                                        <?php if ($emp['account_status'] === 'active'): ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this employee account? They will no longer be able to log in.');"><?php echo csrf_field(); ?>
                                                <input type="hidden" name="employee_id" value="<?php echo $emp['employee_id']; ?>">
                                                <button type="submit" name="archive_employee" class="btn-sm btn-cancel">Archive</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Restore this employee account?');"><?php echo csrf_field(); ?>
                                                <input type="hidden" name="employee_id" value="<?php echo $emp['employee_id']; ?>">
                                                <button type="submit" name="restore_employee" class="btn-sm btn-confirm">Restore</button>
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

<!-- Add Employee Modal -->
<div class="modal-overlay" id="addModal">
    <div class="modal modal-wide">
        <div class="modal-head">
            <h3>Create Employee Account</h3>
            <button type="button" class="modal-close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST" novalidate><?php echo csrf_field(); ?>
            <div class="modal-body">
                <div class="form-group">
                    <label for="add_role">Role *</label>
                    <select id="add_role" name="role" required onchange="toggleSpecialization(this.value)">
                        <option value="">Select a role…</option>
                        <option value="Dentist">Dentist</option>
                        <option value="Receptionist">Receptionist</option>
                    </select>
                </div>
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
                <div class="form-group" id="add_specialization_group" style="display:none;">
                    <label for="add_specialization">Specialization</label>
                    <input type="text" id="add_specialization" name="specialization" placeholder="e.g. Orthodontics">
                </div>
                <div class="form-group">
                    <label for="add_hire_date">Hire Date</label>
                    <input type="date" id="add_hire_date" name="hire_date">
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
                <p style="font-size:.78rem;color:var(--text-gray);">Password must be at least 8 characters and include a digit. Share it with the employee directly — it won't be shown again here.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-ghost-sm" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" name="add_employee" class="btn-primary-sm">Create Account</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Employee Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal modal-wide">
        <div class="modal-head">
            <h3>Edit Employee Record</h3>
            <button type="button" class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST" novalidate><?php echo csrf_field(); ?>
            <input type="hidden" name="employee_id" id="edit_employee_id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="edit_first_name">First Name *</label>
                        <input type="text" id="edit_first_name" name="first_name" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_last_name">Last Name *</label>
                        <input type="text" id="edit_last_name" name="last_name" required>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="edit_phone">Contact Number *</label>
                        <input type="tel" id="edit_phone" name="phone" placeholder="09XXXXXXXXX" maxlength="11" required>
                    </div>
                    <div class="form-group" id="edit_specialization_group">
                        <label for="edit_specialization">Specialization</label>
                        <input type="text" id="edit_specialization" name="specialization">
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="edit_address">Address</label>
                        <input type="text" id="edit_address" name="address">
                    </div>
                    <div class="form-group">
                        <label for="edit_hire_date">Hire Date</label>
                        <input type="date" id="edit_hire_date" name="hire_date">
                    </div>
                </div>
                <p style="font-size:.78rem;color:var(--text-gray);">Username, email, and password belong to the employee's own account and aren't editable here. Saving will notify the employee that their record was changed.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-ghost-sm" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" name="edit_employee" class="btn-primary-sm">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- View Employee Modal -->
<div class="modal-overlay" id="viewModal">
    <div class="modal">
        <div class="modal-head">
            <h3>Employee Details</h3>
            <button type="button" class="modal-close" onclick="closeModal('viewModal')">&times;</button>
        </div>
        <div class="modal-body" id="viewModalBody"></div>
    </div>
</div>

<script>
    function openAdd() {
        document.getElementById('addModal').classList.add('open');
    }

    function toggleSpecialization(role) {
        document.getElementById('add_specialization_group').style.display = role === 'Dentist' ? 'block' : 'none';
    }

    function openEdit(emp) {
        document.getElementById('edit_employee_id').value = emp.employee_id;
        document.getElementById('edit_first_name').value = emp.first_name;
        document.getElementById('edit_last_name').value = emp.last_name;
        document.getElementById('edit_phone').value = emp.contact_number || '';
        document.getElementById('edit_address').value = emp.address || '';
        document.getElementById('edit_specialization').value = emp.specialization || '';
        document.getElementById('edit_hire_date').value = emp.hire_date || '';
        document.getElementById('edit_specialization_group').style.display = emp.role_name === 'Dentist' ? 'block' : 'none';
        document.getElementById('editModal').classList.add('open');
    }

    function escapeHtml(str) {
        if (!str) return '—';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function viewEmployee(emp) {
        const rows = [
            ['Name', escapeHtml(emp.first_name + ' ' + emp.last_name)],
            ['Role', escapeHtml(emp.role_name)],
            ['Username', escapeHtml(emp.username)],
            ['Email', escapeHtml(emp.email)],
            ['Contact Number', escapeHtml(emp.contact_number)],
            ['Address', escapeHtml(emp.address)],
        ];
        if (emp.role_name === 'Dentist') rows.push(['Specialization', escapeHtml(emp.specialization)]);
        rows.push(['Hire Date', emp.hire_date ? new Date(emp.hire_date + 'T00:00:00').toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) : '—']);
        rows.push(['Account Status', emp.account_status.charAt(0).toUpperCase() + emp.account_status.slice(1)]);

        document.getElementById('viewModalBody').innerHTML = rows.map(([label, value]) =>
            `<div class="detail-row"><span>${label}</span><strong>${value}</strong></div>`
        ).join('');
        document.getElementById('viewModal').classList.add('open');
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