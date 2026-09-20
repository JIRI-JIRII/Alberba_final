<?php
require_once 'config/session.php';
require_once 'config/database.php'; // connection file created earlier — update the path if you move it into a /config folder

$error = '';
$success = '';

require_csrf();
    
// show success after redirect to clear form fields (PRG pattern)
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success = "Registration successful! You can now login.";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username         = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_SPECIAL_CHARS);
    $email            = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    // Passwords are hashed, never rendered as HTML, so they must NOT be sanitised.
    // Running them through FILTER_SANITIZE_SPECIAL_CHARS would rewrite & < > " ' and
    // store a hash of something other than what the user actually typed.
    $password         = (string) ($_POST['password'] ?? '');
    $confirm_password = (string) ($_POST['confirm_password'] ?? '');
    $first_name       = filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_SPECIAL_CHARS);
    $last_name        = filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_SPECIAL_CHARS);
    $phone            = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_NUMBER_INT);

    $error = null;
    $success = null;

    // required fields
    if (empty($username) || empty($email) || empty($first_name) || empty($last_name) || empty($phone) || empty($password) || empty($confirm_password)) {
        $error = "Please fill in all required fields";
    // username length (matches the hint shown under the field)
    } elseif (strlen($username) < 4) {
        $error = "Username must be at least 4 characters";
    // real email format check — FILTER_SANITIZE_EMAIL only strips illegal characters, it doesn't validate
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address";
    // PH mobile format: 11 digits starting with 09 (matches the hint shown under the field)
    } elseif (!preg_match('/^09\d{9}$/', $phone)) {
        $error = "Contact number must be 11 digits starting with 09";
    // relaxed password rule: minimum 8 chars and at least one digit (no uppercase/special required)
    } elseif (strlen($password) < 8 || !preg_match('/\d/', $password)) {
        $error = "Password must be at least 8 characters and include at least one digit";
    } elseif ($password != $confirm_password) {
        $error = "Passwords do not match";
    } else {
        // Use prepared statements for security
        mysqli_begin_transaction($conn);
        $transaction_ok = true;

        try {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // users.role_id is looked up from the roles table instead of storing the role as text,
            // and the password column is named password_hash in this schema
            $stmt = mysqli_prepare($conn, "INSERT INTO users (username, password_hash, email, role_id)
                                            VALUES (?, ?, ?, (SELECT role_id FROM roles WHERE role_name = 'Patient' LIMIT 1))");
            mysqli_stmt_bind_param($stmt, "sss", $username, $hashed_password, $email);

            if (!mysqli_stmt_execute($stmt)) {
                $transaction_ok = false;
            } else {
                $user_id = mysqli_insert_id($conn);

                // patients.contact_number replaces "phone", and registered_via records that this
                // account was created through the public registration page (not a receptionist walk-in)
                $stmt2 = mysqli_prepare($conn, "INSERT INTO patients (user_id, first_name, last_name, contact_number, registered_via)
                                                 VALUES (?, ?, ?, ?, 'online')");
                mysqli_stmt_bind_param($stmt2, "isss", $user_id, $first_name, $last_name, $phone);

                if (!mysqli_stmt_execute($stmt2)) {
                    $transaction_ok = false;
                }
                mysqli_stmt_close($stmt2);
            }
            mysqli_stmt_close($stmt);

            if ($transaction_ok) {
                mysqli_commit($conn);
                // redirect to clear POST and avoid duplicate submissions
                header('Location: register.php?success=1');
                exit;
            } else {
                mysqli_rollback($conn);

                if (mysqli_errno($conn) == 1062) {
                    $error = "Username or email already exists";
                } else {
                    $error = "Registration failed. Please try again.";
                }
            }
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Registration failed due to an unexpected error.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Alberba Dental Clinic</title>
    <link rel="stylesheet" href="register.css">

</head>
<body>
    <header class="header">
        <div class="header-title">
            <span class="alberba">ALBERBA</span>
            <span class="dental-clinic">DENTAL CLINIC</span>
        </div>
        <a href="index.php" class="back-btn">Back to Home</a>
    </header>

    <div class="container">
        <div class="register-card">
            <div class="register-title">
                <h1>
                    <span class="title-alberba">ALBERBA</span>
                    <span class="title-clinic">DENTAL CLINIC</span>
                </h1>
                <p>Create Your Account</p>
            </div>

            <?php
            // show server-side message when set
            if (!empty($success)) {
                echo '<div class="alert success">' . htmlspecialchars($success) . '</div>';
            }
            if (!empty($error)) {
                echo '<div class="alert error">' . htmlspecialchars($error) . '</div>';
            }
            ?>

            <form id="registerForm" novalidate method="POST" action="register.php"><?php echo csrf_field(); ?>
                <div class="form-layout">
                    <!-- Left Column -->
                    <div class="form-column">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="last_name">Last Name <span class="required">*</span></label>
                                <input
                                    type="text"
                                    id="last_name"
                                    name="last_name"
                                    class="form-control"
                                    value="<?php echo isset($last_name) ? htmlspecialchars($last_name) : ''; ?>"
                                    required
                                >
                                <div class="error-message" id="lastNameError">Last name is required</div>
                            </div>

                            <div class="form-group">
                                <label for="first_name">First Name <span class="required">*</span></label>
                                <input
                                    type="text"
                                    id="first_name"
                                    name="first_name"
                                    class="form-control"
                                    value="<?php echo isset($first_name) ? htmlspecialchars($first_name) : ''; ?>"
                                    required
                                >
                                <div class="error-message" id="firstNameError">First name is required</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address <span class="required">*</span></label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                class="form-control"
                                value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>"
                                required
                            >
                            <div class="error-message" id="emailError">Please enter a valid email address</div>
                        </div>

                        <div class="form-group">
                            <label for="phone">Contact Number <span class="required">*</span></label>
                            <input
                                type="tel"
                                id="phone"
                                name="phone"
                                class="form-control"
                                value="<?php echo isset($phone) ? htmlspecialchars($phone) : ''; ?>"
                                placeholder="09XXXXXXXXX"
                                maxlength="11"
                                required
                            >
                            <div class="error-message" id="contactNumberError">Contact number must be 11 digits starting with 09</div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="form-column">
                        <div class="form-group">
                            <label for="username">Username <span class="required">*</span></label>
                            <input
                                type="text"
                                id="username"
                                name="username"
                                class="form-control"
                                value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>"
                                placeholder="Enter your username"
                                required
                            >
                            <div class="error-message" id="usernameError">Username must be at least 4 characters</div>
                        </div>

                        <div class="form-group">
                            <label for="password">Password <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    class="form-control"
                                    placeholder="Enter your password"
                                    required
                                >
                                <button type="button" class="password-toggle" data-target="password">
                                    <svg class="eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                    <svg class="eye-slash-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: none;">
                                        <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                        <line x1="1" y1="1" x2="23" y2="23"></line>
                                    </svg>
                                </button>
                            </div>
                            <div class="error-message" id="passwordError">Password must be at least 8 characters with at least one digit</div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <input
                                    type="password"
                                    id="confirm_password"
                                    name="confirm_password"
                                    class="form-control"
                                    placeholder="Confirm your password"
                                    required
                                >
                                <button type="button" class="password-toggle" data-target="confirm_password">
                                    <svg class="eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                    <svg class="eye-slash-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: none;">
                                        <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                        <line x1="1" y1="1" x2="23" y2="23"></line>
                                    </svg>
                                </button>
                            </div>
                            <div class="error-message" id="confirmPasswordError">Passwords do not match</div>
                        </div>
                    </div>

                    <button type="submit" class="register-button">Register</button>

                    <div class="login-link">
                        Already have an account? <a href="login.php">Log In</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Password toggle functionality
        document.querySelectorAll('.password-toggle').forEach(button => {
            button.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const input = document.getElementById(targetId);
                const eyeIcon = this.querySelector('.eye-icon');
                const eyeSlashIcon = this.querySelector('.eye-slash-icon');

                if (input.type === 'password') {
                    input.type = 'text';
                    eyeIcon.style.display = 'none';
                    eyeSlashIcon.style.display = 'block';
                } else {
                    input.type = 'password';
                    eyeIcon.style.display = 'block';
                    eyeSlashIcon.style.display = 'none';
                }
            });
        });

        // Form validation
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            let isValid = true;

            // Clear previous errors
            document.querySelectorAll('.error-message').forEach(msg => msg.classList.remove('show'));
            document.querySelectorAll('.form-control').forEach(input => input.classList.remove('error'));

            // Validate required fields
            const requiredFields = ['last_name', 'first_name', 'email', 'phone', 'username', 'password', 'confirm_password'];
            requiredFields.forEach(field => {
                const input = document.getElementById(field);
                if (!input.value.trim()) {
                    isValid = false;
                    input.classList.add('error');
                    const errorMsg = document.getElementById(field.replace(/_/g, '') + 'Error');
                    if (errorMsg) errorMsg.classList.add('show');
                }
            });

            // Validate contact number format (11 digits starting with 09)
            const phone = document.getElementById('phone');
            if (phone.value.trim() && !/^09\d{9}$/.test(phone.value.trim())) {
                isValid = false;
                phone.classList.add('error');
                document.getElementById('contactNumberError').classList.add('show');
            }

            // Validate password match
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            if (password !== confirmPassword) {
                isValid = false;
                document.getElementById('confirm_password').classList.add('error');
                document.getElementById('confirmPasswordError').classList.add('show');
            }

            if (!isValid) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>