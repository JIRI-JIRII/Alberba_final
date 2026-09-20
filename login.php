<?php
require_once 'config/session.php';
require_once 'config/database.php';
require_once 'func/login_throttle.php';

$error = '';

require_csrf();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));

    // The password is NEVER sanitised. It is compared against a hash, not rendered as
    // HTML, so running it through a sanitiser would silently rewrite characters like
    // & < > " ' and lock users out of passwords they typed correctly.
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = "Please enter your username and password";
    } elseif (login_attempts_exceeded($conn, $username)) {
        $error = "Too many failed login attempts. Please wait " . LOGIN_LOCKOUT_MINUTES . " minutes and try again.";
    } else {
        $stmt = mysqli_prepare($conn, "SELECT u.user_id, u.username, u.password_hash, u.account_status, r.role_name
                                        FROM users u
                                        JOIN roles r ON u.role_id = r.role_id
                                        WHERE u.username = ?");
        mysqli_stmt_bind_param($stmt, "s", $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            record_login_failure($conn, $username);
            $error = "Invalid username or password";
        } elseif ($user['account_status'] !== 'active') {
            // covers archived employee accounts (see User Management > Archive Account in the scope doc)
            $error = "This account has been archived. Please contact the clinic.";
        } else {
            clear_login_failures($conn, $username);

            // Upgrade the stored hash if PHP's default algorithm/cost has moved on.
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                $new_hash = password_hash($password, PASSWORD_DEFAULT);
                $rehash = mysqli_prepare($conn, "UPDATE users SET password_hash = ? WHERE user_id = ?");
                mysqli_stmt_bind_param($rehash, "si", $new_hash, $user['user_id']);
                mysqli_stmt_execute($rehash);
                mysqli_stmt_close($rehash);
            }

            session_regenerate_id(true);

            $_SESSION['user_id']  = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role_name'];

            // pull the role-specific profile id now so other pages don't need to re-join later
            if ($user['role_name'] === 'Patient') {
                $stmt2 = mysqli_prepare($conn, "SELECT patient_id FROM patients WHERE user_id = ?");
                mysqli_stmt_bind_param($stmt2, "i", $user['user_id']);
                mysqli_stmt_execute($stmt2);
                $profile = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt2));
                mysqli_stmt_close($stmt2);
                $_SESSION['patient_id'] = $profile['patient_id'] ?? null;
            } elseif (in_array($user['role_name'], ['Dentist', 'Receptionist'], true)) {
                $stmt2 = mysqli_prepare($conn, "SELECT employee_id FROM employees WHERE user_id = ?");
                mysqli_stmt_bind_param($stmt2, "i", $user['user_id']);
                mysqli_stmt_execute($stmt2);
                $profile = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt2));
                mysqli_stmt_close($stmt2);
                $_SESSION['employee_id'] = $profile['employee_id'] ?? null;
            }

            // send each role to its own dashboard — update these paths to match your actual folder structure
            switch ($user['role_name']) {
                case 'Administrator':
                    header('Location: admin/dashboard.php');
                    break;
                case 'Dentist':
                    header('Location: dentist/dashboard.php');
                    break;
                case 'Receptionist':
                    header('Location: receptionist/dashboard.php');
                    break;
                case 'Patient':
                default:
                    header('Location: patient/dashboard.php');
                    break;
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In - Alberba Dental Clinic</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,600;0,9..144,700;1,9..144,500&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root{
            --primary-pink:#d4739b;
            --light-pink:#f8e8f0;
            --accent-pink:#c85a87;
            --deep-rose:#7a3552;
            --ink:#241a1e;
            --ivory:#fffbf9;
            --white:#ffffff;
            --text-dark:#34242b;
            --text-gray:#7a6670;
            --border-color:#f0dbe4;
            --display:'Fraunces', serif;
            --body:'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        *{margin:0;padding:0;box-sizing:border-box;}

        body{
            font-family:var(--body);
            color:var(--text-dark);
            background:linear-gradient(180deg, var(--light-pink) 0%, var(--ivory) 55%);
            min-height:100vh;
            display:flex;
            flex-direction:column;
        }

        a{color:inherit;}

        :focus-visible{outline:3px solid var(--accent-pink);outline-offset:2px;}

        /* Header */
        .header{
            background:var(--white);
            padding:1.1rem 2rem;
            display:flex;
            justify-content:space-between;
            align-items:center;
            border-bottom:1px solid var(--border-color);
        }
        .header-title{font-family:var(--display);font-size:1.15rem;font-weight:700;letter-spacing:.02em;}
        .header-title .alberba{color:var(--text-dark);}
        .header-title .dental-clinic{color:var(--primary-pink);font-style:italic;font-weight:500;margin-left:.3rem;}
        .back-btn{
            text-decoration:none;
            font-weight:600;
            font-size:.9rem;
            color:var(--accent-pink);
            border:2px solid var(--primary-pink);
            padding:.55rem 1.3rem;
            border-radius:100px;
            transition:background-color .25s ease, color .25s ease, transform .25s ease;
        }
        .back-btn:hover{background:var(--primary-pink);color:var(--white);transform:translateY(-2px);}

        /* Layout */
        .container{
            flex:1;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:3rem 1.5rem;
        }

        .login-card{
            background:var(--white);
            width:100%;
            max-width:420px;
            border-radius:28px 28px 20px 20px;
            padding:2.6rem 2.4rem 2.4rem;
            box-shadow:0 20px 50px rgba(122,53,82,.16);
            border-top:6px solid var(--primary-pink);
        }

        .login-title{text-align:center;margin-bottom:1.8rem;}
        .login-title h1{font-family:var(--display);font-size:1.15rem;line-height:1.3;margin-bottom:.6rem;}
        .title-alberba{color:var(--text-dark);font-weight:700;}
        .title-clinic{color:var(--primary-pink);font-weight:500;font-style:italic;}
        .login-title p{color:var(--text-gray);font-size:.92rem;}

        /* Alerts */
        .alert{
            padding:.85rem 1.1rem;
            border-radius:10px;
            font-size:.9rem;
            margin-bottom:1.4rem;
            font-weight:500;
        }
        .alert.error{background:#fbe6ec;color:#96234a;border:1px solid #f3c1d3;}
        .alert.success{background:#e9f5ec;color:#2d6a3e;border:1px solid #bfe3c8;}

        /* Form */
        .form-group{margin-bottom:1.3rem;}
        .form-group label{
            display:block;
            font-size:.85rem;
            font-weight:600;
            color:var(--text-dark);
            margin-bottom:.45rem;
        }
        .required{color:var(--accent-pink);}
        .form-control{
            width:100%;
            padding:.75rem .95rem;
            font-family:var(--body);
            font-size:.95rem;
            color:var(--text-dark);
            background:var(--ivory);
            border:1.5px solid var(--border-color);
            border-radius:10px;
            transition:border-color .2s ease, box-shadow .2s ease;
        }
        .form-control:focus{
            outline:none;
            border-color:var(--primary-pink);
            box-shadow:0 0 0 3px rgba(212,115,155,.18);
        }
        .form-control.error{border-color:#c94a6d;}

        .input-wrapper{position:relative;}
        .input-wrapper .form-control{padding-right:2.8rem;}
        .password-toggle{
            position:absolute;
            top:50%;
            right:.7rem;
            transform:translateY(-50%);
            background:none;
            border:none;
            cursor:pointer;
            color:var(--text-gray);
            padding:.3rem;
            display:flex;
            align-items:center;
        }
        .password-toggle:hover{color:var(--accent-pink);}

        .error-message{
            display:none;
            font-size:.8rem;
            color:#c94a6d;
            margin-top:.4rem;
        }
        .error-message.show{display:block;}

        .login-button{
            width:100%;
            padding:.9rem;
            background:var(--primary-pink);
            color:var(--white);
            border:none;
            border-radius:100px;
            font-family:var(--body);
            font-weight:600;
            font-size:.98rem;
            cursor:pointer;
            margin-top:.4rem;
            transition:background-color .25s ease, transform .25s ease, box-shadow .25s ease;
        }
        .login-button:hover{
            background:var(--accent-pink);
            transform:translateY(-2px);
            box-shadow:0 10px 24px rgba(122,53,82,.28);
        }

        .register-link{
            text-align:center;
            margin-top:1.5rem;
            font-size:.88rem;
            color:var(--text-gray);
        }
        .register-link a{color:var(--accent-pink);font-weight:600;text-decoration:none;}
        .register-link a:hover{text-decoration:underline;}

        @media (max-width:480px){
            .login-card{padding:2.2rem 1.6rem 2rem;}
            .header{padding:1rem 1.2rem;}
        }

        @media (prefers-reduced-motion:reduce){
            *{transition:none !important;}
        }
    </style>
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
        <div class="login-card">
            <div class="login-title">
                <h1>
                    <span class="title-alberba">ALBERBA</span>
                    <span class="title-clinic">DENTAL CLINIC</span>
                </h1>
                <p>Log In to Your Account</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form id="loginForm" novalidate method="POST" action="login.php"><?php echo csrf_field(); ?>
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
                    <div class="error-message" id="usernameError">Username is required</div>
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
                    <div class="error-message" id="passwordError">Password is required</div>
                </div>

                <button type="submit" class="login-button">Log In</button>

                <div class="register-link">
                    Don't have an account? <a href="register.php">Register</a>
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

        // Basic client-side validation (server-side check in login.php is what actually matters)
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            let isValid = true;

            document.querySelectorAll('.error-message').forEach(msg => msg.classList.remove('show'));
            document.querySelectorAll('.form-control').forEach(input => input.classList.remove('error'));

            ['username', 'password'].forEach(field => {
                const input = document.getElementById(field);
                if (!input.value.trim()) {
                    isValid = false;
                    input.classList.add('error');
                    document.getElementById(field + 'Error').classList.add('show');
                }
            });

            if (!isValid) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>