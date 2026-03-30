<?php
// admin-login.php
session_start();
require "../API/db-connector.php";
require "../API/auth-check.php";


// CONFIG

define('MAX_ATTEMPTS_5', 5);
define('MAX_ATTEMPTS_10', 10);
define('MAX_ATTEMPTS_15', 15);

define('LOCK_5_MIN', 300);
define('LOCK_1_HOUR', 3600);
define('LOCK_1_DAY', 86400);


// REDIRECT IF LOGGED IN

if (isset($_SESSION['user_id'])) {
    header("Location: admin-dashboard.php");
    exit();
}

$time = time();


// HANDLE POST

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['user'] ?? '');
    $password = trim($_POST['pass'] ?? '');

    if (empty($email) || empty($password)) {
        $_SESSION['error'] = "Both fields are required!";
        header("Location: index.php");
        exit();
    }

    
   // CHECK LOGIN ATTEMPTS
    
    $stmt = $conn->prepare("SELECT attempts, lockout_until FROM login_attempts WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $attemptData = $stmt->get_result()->fetch_assoc();

    if ($attemptData && $time < $attemptData['lockout_until']) {

        $remaining = $attemptData['lockout_until'] - $time;
        $minutes = floor($remaining / 60);
        $seconds = $remaining % 60;

        $_SESSION['locked_email'] = $email;
        $_SESSION['error'] = "Too many failed attempts. Try again in {$minutes}m {$seconds}s.";

        header("Location: index.php");
        exit();
    }

    
    // CHECK USER CREDENTIALS
    
    $stmt = $conn->prepare("
        SELECT u.USER_ID, u.USER_FNAME, u.USER_LNAME, u.USER_PASS, ur.UR_ROLE
        FROM users u
        JOIN user_role ur ON u.UR_ID = ur.UR_ID
        WHERE u.USER_EMAIL = ? 
          AND ur.UR_ROLE = 'Admin' 
          AND u.USER_IS_ARCHIVED = 0
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    $fname = $user['USER_FNAME'] ?? 'Unknown';
    $lname = $user['USER_LNAME'] ?? 'Unknown';
    $role = $user['UR_ROLE'] ?? 'Unknown';
    $activity = "Login Attempt";

    
    // SUCCESS LOGIN
    
    if ($user && password_verify($password, $user['USER_PASS'])) {

        $_SESSION['user_id'] = $user['USER_ID'];
        $_SESSION['username'] = $user['USER_FNAME'] . " " . $user['USER_LNAME'];
        $_SESSION['role'] = $user['UR_ROLE'];
        $_SESSION['FNAME'] = $user['USER_FNAME'];
        $_SESSION['LNAME'] = $user['USER_LNAME'];

        $activity = "Login Success"; 

        $logStmt = $conn->prepare("INSERT INTO user_logs (LOGS_FNAME, LOGS_LNAME, LOGS_USER_ROLE, LOGS_ACTIVITY) VALUES (?, ?, ?, ?)");
        $logStmt->bind_param(
            "ssss",
            $user['USER_FNAME'],
            $user['USER_LNAME'],
            $user['UR_ROLE'],
            $activity
        );
        $logStmt->execute();
        $logStmt->close();

        // REMEMBER ME 
        if (!empty($_POST['remember'])) {

            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));

            $stmt = $conn->prepare(
                "UPDATE users SET remember_token = ?, remember_token_expiry = ? WHERE USER_ID = ?"
            );
            $stmt->bind_param("ssi", $token, $expiry, $user['USER_ID']);
            $stmt->execute();
            $stmt->close();

            $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            setcookie(
                "REMEMBER_TOKEN",
                $token,
                time() + (86400 * 30),
                "/",
                "",
                $isSecure,
                true
            );
        }

        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();

        unset($_SESSION['locked_email']);

        header("Location: admin-dashboard.php");
        exit();
    }
    
    // FAILED LOGIN

    $activity = "Login Failed";

    $failedName = trim($_POST['user'] ?? '');

    $logStmt = $conn->prepare("INSERT INTO user_logs (LOGS_FNAME, LOGS_USER_ROLE, LOGS_ACTIVITY) VALUES (?, ?, ?)");
    $logStmt->bind_param("sss", $failedName, $role, $activity);
    $logStmt->execute();
    $logStmt->close();

    $attempts = $attemptData ? $attemptData['attempts'] + 1 : 1;

    $lockoutUntil = 0;
    if ($attempts == MAX_ATTEMPTS_15) {
        $lockoutUntil = $time + LOCK_1_DAY;
    } elseif ($attempts == MAX_ATTEMPTS_10) {
        $lockoutUntil = $time + LOCK_1_HOUR;
    } elseif ($attempts >= MAX_ATTEMPTS_5) {
        $lockoutUntil = $time + LOCK_5_MIN;
    }

    if ($attemptData) {
        $stmt = $conn->prepare("
            UPDATE login_attempts
            SET attempts = ?, lockout_until = ?, last_attempt = ?
            WHERE email = ?
        ");
        $stmt->bind_param("iiis", $attempts, $lockoutUntil, $time, $email);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO login_attempts (email, attempts, lockout_until, last_attempt)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("siii", $email, $attempts, $lockoutUntil, $time);
    }
    $stmt->execute();

    $_SESSION['locked_email'] = $email;
    $_SESSION['error'] = "Invalid email or password!";
    header("Location: index.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DepEd Gapan City - Supply Unit</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="icon" type="image/png" href="../image/favicon.png">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(145deg, #f8fafc 0%, #e9eef3 100%);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 1.5rem;
        }

        .card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.1);
            padding: 2rem 1.5rem;
        }

        .logo-wrapper {
            display: flex;
            justify-content: center;
            margin-bottom: 1.5rem;
        }

        .logo {
            width: 80px;
            height: 80px;
            object-fit: contain;
        }

        .title {
            font-size: 1.35rem;
            font-weight: 600;
            color: #1e293b;
            text-align: center;
            line-height: 1.4;
            margin-bottom: 0.5rem;
        }

        .subtitle {
            font-size: 0.85rem;
            color: #64748b;
            text-align: center;
            margin-bottom: 2rem;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 1.25rem;
        }

        .input-group {
            margin-bottom: 1.25rem;
        }

        .input-label {
            display: block;
            font-size: 0.85rem;
            font-weight: 500;
            color: #334155;
            margin-bottom: 0.3rem;
        }

        .input-field {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            font-size: 0.95rem;
            transition: all 0.15s;
            background: #f8fafc;
        }

        .input-field:focus {
            outline: none;
            border-color: #2563eb;
            background: white;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .password-wrapper {
            position: relative;
        }

        .eye-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #64748b;
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .eye-btn:hover {
            color: #2563eb;
        }

        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 1rem 0 1.5rem;
        }

        .checkbox-wrapper input[type="checkbox"] {
            width: 18px;
            height: 18px;
            border-radius: 5px;
            border: 1.5px solid #cbd5e1;
            cursor: pointer;
        }

        .checkbox-wrapper label {
            font-size: 0.9rem;
            color: #475569;
            cursor: pointer;
        }

        .login-btn {
            width: 100%;
            background: #2563eb;
            color: white;
            border: none;
            padding: 0.9rem;
            border-radius: 14px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.15s;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }

        .login-btn:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.3);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .footer-text {
            font-size: 0.7rem;
            color: #94a3b8;
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="card">
            
            <!-- Logo -->
            <div class="logo-wrapper">
                <img class="logo" src="../image/logo.png" alt="DepEd Gapan City">
            </div>

            <!-- Title -->
            <div class="title">
                SDO Gapan City<br>Supply/Property Unit
            </div>
            <div class="subtitle">
                Administrative Access
            </div>

            <!-- Form -->
            <form method="post">
                
                <!-- Email -->
                <div class="input-group">
                    <label class="input-label" for="user">Email</label>
                    <input 
                        type="email" 
                        id="user" 
                        name="user" 
                        class="input-field"
                        placeholder="Enter your email"
                        autocomplete="off"
                        required
                    >
                </div>

                <!-- Password -->
                <div class="input-group">
                    <label class="input-label" for="pass">Password</label>
                    <div class="password-wrapper">
                        <input 
                            type="password" 
                            id="pass" 
                            name="pass" 
                            class="input-field"
                            placeholder="••••••••"
                            required
                        >
                        <button type="button" class="eye-btn" onclick="togglePassword()">
                            <i data-lucide="eye" class="show-password" width="18" height="18"></i>
                        </button>
                    </div>
                </div>

                <!-- Remember me -->
                <div class="checkbox-wrapper">
                    <input type="checkbox" id="remember" name="remember">
                    <label for="remember">Keep me signed in</label>
                </div>

                <!-- Submit -->
                <button type="submit" class="login-btn">
                    Sign in
                </button>

            </form>

            <!-- Footer -->
            <div class="footer-text">
                DepEd Gapan City • Authorized Personnel Only
            </div>
        </div>
    </div>

    <script>
        // Initialize icons
        lucide.createIcons();

        // Toggle password visibility
        function togglePassword() {
            let passwordField = document.getElementById('pass');
            let eyeIcon = document.querySelector('.show-password');

            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                eyeIcon.setAttribute("data-lucide", "eye-off");
            } else {
                passwordField.type = 'password';
                eyeIcon.setAttribute("data-lucide", "eye");
            }
            lucide.createIcons();
        }

        // Auto-focus email
        document.getElementById('user').focus();

        // Error messages
        <?php if (isset($_SESSION['error'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Unable to login',
                text: '<?php echo $_SESSION['error']; ?>',
                confirmButtonColor: '#2563eb',
                confirmButtonText: 'OK',
                timer: 3000,
                showConfirmButton: true
            });
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
    </script>
</body>

</html>