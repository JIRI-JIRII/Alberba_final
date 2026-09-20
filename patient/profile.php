<?php
require_once '../config/session.php';
require_once '../config/database.php';
require_once '../func/functions.php';

checkLogin();
checkRole(['Patient']);
require_csrf();
/** @var mysqli $conn */

$patient_id = $_SESSION['patient_id'] ?? 0;
$user_id = $_SESSION['user_id'];
if (!$patient_id) {
    header('Location: ../login.php');
    exit;
}

$message = '';
$error = '';
$error_modal = ''; // which modal to re-open if validation fails, so the patient doesn't lose their place

// ---- Handle: Change Username ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_username'])) {
    $new_username = sanitizeInput($_POST['new_username'] ?? '');

    if (strlen($new_username) < 4) {
        $error = "Username must be at least 4 characters.";
        $error_modal = 'username';
    } else {
        $stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE username = ? AND user_id != ?");
        mysqli_stmt_bind_param($stmt, "si", $new_username, $user_id);
        mysqli_stmt_execute($stmt);
        $taken = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) !== null;
        mysqli_stmt_close($stmt);

        if ($taken) {
            $error = "That username is already in use. Please choose another.";
            $error_modal = 'username';
        } else {
            $stmt2 = mysqli_prepare($conn, "UPDATE users SET username = ? WHERE user_id = ?");
            mysqli_stmt_bind_param($stmt2, "si", $new_username, $user_id);
            if (mysqli_stmt_execute($stmt2)) {
                mysqli_stmt_close($stmt2);
                $_SESSION['username'] = $new_username; // keep the session in sync with the new value
                header('Location: profile.php?username_updated=1');
                exit;
            } else {
                $error = (mysqli_errno($conn) === 1062) ? "That username is already in use." : "Failed to update username.";
                $error_modal = 'username';
            }
            mysqli_stmt_close($stmt2);
        }
    }
}

// ---- Handle: Change Password ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_new_password'] ?? '';

    $stmt = mysqli_prepare($conn, "SELECT password_hash FROM users WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row || !password_verify($current_password, $row['password_hash'])) {
        $error = "Your current password is incorrect.";
        $error_modal = 'password';
    } elseif (strlen($new_password) < 8 || !preg_match('/\d/', $new_password)) {
        $error = "New password must be at least 8 characters and include at least one digit.";
        $error_modal = 'password';
    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match.";
        $error_modal = 'password';
    } else {
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt2 = mysqli_prepare($conn, "UPDATE users SET password_hash = ? WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt2, "si", $new_hash, $user_id);
        if (mysqli_stmt_execute($stmt2)) {
            mysqli_stmt_close($stmt2);
            session_regenerate_id(true); // good practice after a credential change
            header('Location: profile.php?password_updated=1');
            exit;
        } else {
            $error = "Failed to update password.";
            $error_modal = 'password';
        }
        mysqli_stmt_close($stmt2);
    }
}

// ---- Handle: Change Email ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_email'])) {
    $new_email = filter_var(trim($_POST['new_email'] ?? ''), FILTER_SANITIZE_EMAIL);

    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
        $error_modal = 'email';
    } else {
        $stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        mysqli_stmt_bind_param($stmt, "si", $new_email, $user_id);
        mysqli_stmt_execute($stmt);
        $taken = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) !== null;
        mysqli_stmt_close($stmt);

        if ($taken) {
            $error = "That email is already linked to another account.";
            $error_modal = 'email';
        } else {
            $stmt2 = mysqli_prepare($conn, "UPDATE users SET email = ? WHERE user_id = ?");
            mysqli_stmt_bind_param($stmt2, "si", $new_email, $user_id);
            if (mysqli_stmt_execute($stmt2)) {
                mysqli_stmt_close($stmt2);
                header('Location: profile.php?email_updated=1');
                exit;
            } else {
                $error = (mysqli_errno($conn) === 1062) ? "That email is already in use." : "Failed to update email.";
                $error_modal = 'email';
            }
            mysqli_stmt_close($stmt2);
        }
    }
}

if (isset($_GET['username_updated'])) { $message = "Username updated. Use your new username next time you log in."; }
if (isset($_GET['password_updated'])) { $message = "Password updated."; }
if (isset($_GET['email_updated']))    { $message = "Email address updated."; }

// ---- Fetch current profile ----
$stmt = mysqli_prepare($conn, "SELECT p.first_name, p.last_name, p.contact_number, p.address, p.birthdate,
                                       p.registered_via, p.created_at, u.username, u.email
                                FROM patients p
                                JOIN users u ON p.user_id = u.user_id
                                WHERE p.patient_id = ?");
mysqli_stmt_bind_param($stmt, "i", $patient_id);
mysqli_stmt_execute($stmt);
$patient = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$patient) {
    header('Location: ../login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile - Alberba Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/patient.css">
<style>
    .cred-row{display:flex;justify-content:space-between;align-items:center;padding:1rem 0;border-bottom:1px solid var(--border-color);gap:1rem;flex-wrap:wrap;}
    .cred-row:last-child{border-bottom:none;}
    .cred-row .cred-label{font-size:.78rem;color:var(--text-gray);font-weight:600;margin-bottom:.25rem;}
    .cred-row .cred-value{font-size:.98rem;color:var(--text-dark);font-weight:500;}
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
        My Appointments
    </a>
    <a href="profile.php" class="nav-link active">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M4.5 20.5v-1a7.5 7.5 0 0 1 15 0v1"/></svg>
        Profile
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
            <h1>My Profile</h1>
            <p class="subtitle">Your account details and login credentials</p>
        </div>
    </div>

    <?php if (!empty($message)): ?><div class="alert success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card">
        <div class="card-head">
            <div><h2>Personal Information</h2><p>For reference only — contact the clinic to update these details</p></div>
        </div>
        <div class="info-grid">
            <div class="info-item"><div class="info-label">Full Name</div><div class="info-value"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></div></div>
            <div class="info-item"><div class="info-label">Contact Number</div><div class="info-value"><?php echo htmlspecialchars($patient['contact_number'] ?? '—'); ?></div></div>
            <div class="info-item"><div class="info-label">Birthdate</div><div class="info-value"><?php echo $patient['birthdate'] ? date('M j, Y', strtotime($patient['birthdate'])) : '—'; ?></div></div>
            <div class="info-item"><div class="info-label">Address</div><div class="info-value"><?php echo htmlspecialchars($patient['address'] ?? '—'); ?></div></div>
            <div class="info-item"><div class="info-label">Patient Since</div><div class="info-value"><?php echo date('F Y', strtotime($patient['created_at'])); ?></div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div><h2>Account Credentials</h2><p>These are yours to control — change them anytime below</p></div>
        </div>

        <div class="cred-row">
            <div>
                <div class="cred-label">Username</div>
                <div class="cred-value"><?php echo htmlspecialchars($patient['username']); ?></div>
            </div>
            <button type="button" class="btn-ghost-sm" onclick="openModal('usernameModal')">Change Username</button>
        </div>

        <div class="cred-row">
            <div>
                <div class="cred-label">Password</div>
                <div class="cred-value">••••••••</div>
            </div>
            <button type="button" class="btn-ghost-sm" onclick="openModal('passwordModal')">Change Password</button>
        </div>

        <div class="cred-row">
            <div>
                <div class="cred-label">Email Address</div>
                <div class="cred-value"><?php echo htmlspecialchars($patient['email']); ?></div>
            </div>
            <button type="button" class="btn-ghost-sm" onclick="openModal('emailModal')">Change Email</button>
        </div>
    </div>
</main>

<!-- Change Username Modal -->
<div class="modal-overlay" id="usernameModal">
    <div class="modal">
        <div class="modal-head">
            <h3>Change Username</h3>
            <button type="button" class="modal-close" onclick="closeModal('usernameModal')">&times;</button>
        </div>
        <form method="POST" onsubmit="return confirm('Change your username to the one you entered? You\'ll need to use it next time you log in.');"><?php echo csrf_field(); ?>
            <div class="modal-body">
                <div class="form-group">
                    <label for="new_username">New Username</label>
                    <input type="text" id="new_username" name="new_username" value="<?php echo htmlspecialchars($patient['username']); ?>" required>
                    <p style="font-size:.78rem;color:var(--text-gray);margin-top:.4rem;">Must be at least 4 characters and not already taken.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-ghost-sm" onclick="closeModal('usernameModal')">Cancel</button>
                <button type="submit" name="change_username" class="btn-primary-sm">Save Username</button>
            </div>
        </form>
    </div>
</div>

<!-- Change Password Modal -->
<div class="modal-overlay" id="passwordModal">
    <div class="modal">
        <div class="modal-head">
            <h3>Change Password</h3>
            <button type="button" class="modal-close" onclick="closeModal('passwordModal')">&times;</button>
        </div>
        <form method="POST"><?php echo csrf_field(); ?>
            <div class="modal-body">
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required>
                    <p style="font-size:.78rem;color:var(--text-gray);margin-top:.4rem;">At least 8 characters, including a digit.</p>
                </div>
                <div class="form-group">
                    <label for="confirm_new_password">Confirm New Password</label>
                    <input type="password" id="confirm_new_password" name="confirm_new_password" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-ghost-sm" onclick="closeModal('passwordModal')">Cancel</button>
                <button type="submit" name="change_password" class="btn-primary-sm">Save Password</button>
            </div>
        </form>
    </div>
</div>

<!-- Change Email Modal -->
<div class="modal-overlay" id="emailModal">
    <div class="modal">
        <div class="modal-head">
            <h3>Change Email</h3>
            <button type="button" class="modal-close" onclick="closeModal('emailModal')">&times;</button>
        </div>
        <form method="POST" onsubmit="return confirm('Update your email address? Future appointment confirmations and account recovery will go to this new address.');"><?php echo csrf_field(); ?>
            <div class="modal-body">
                <div class="form-group">
                    <label for="new_email">New Email Address</label>
                    <input type="email" id="new_email" name="new_email" value="<?php echo htmlspecialchars($patient['email']); ?>" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-ghost-sm" onclick="closeModal('emailModal')">Cancel</button>
                <button type="submit" name="change_email" class="btn-primary-sm">Save Email</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(id) { document.getElementById(id).classList.add('open'); }
    function closeModal(id) { document.getElementById(id).classList.remove('open'); }

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

    <?php if ($error_modal): ?>
        openModal('<?php echo $error_modal; ?>Modal');
    <?php endif; ?>
</script>
</body>
</html>