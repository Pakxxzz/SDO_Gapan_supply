<?php
// admin/logout.php
session_start();
include "../API/db-connector.php";

$userId = $_SESSION['user_id'] ?? null;
$token = $_COOKIE['REMEMBER_TOKEN'] ?? null;

// 1. Identify User if Session is gone but Cookie remains
if ($token && isset($token)) {
    $stmt = $conn->prepare("
        SELECT u.USER_ID, u.USER_FNAME, u.USER_LNAME, ur.UR_ROLE 
        FROM users u 
        JOIN user_role ur ON u.UR_ID = ur.UR_ID 
        WHERE u.remember_token = ? AND u.remember_token_expiry > NOW()
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user) {
        $userId = $user['USER_ID'];
        $_SESSION['FNAME'] = $user['USER_FNAME'];
        $_SESSION['LNAME'] = $user['USER_LNAME'];
        $_SESSION['role'] = $user['UR_ROLE'];
    }
}

// 2. Log Activity if User is identified
if ($userId) {
    $activity = "Logout";
    $fname = $_SESSION['FNAME'];
    $Lname = $_SESSION['LNAME'];
    $role = $_SESSION['role'];

    $logStmt = $conn->prepare("
        INSERT INTO user_logs (LOGS_FNAME, LOGS_LNAME, LOGS_USER_ROLE, LOGS_ACTIVITY)
        VALUES (?, ?, ?, ?)
    ");
    $logStmt->bind_param("ssss", $fname, $Lname, $role, $activity);
    $logStmt->execute();
    $logStmt->close();

    // 3. Invalidate Token in Database
    $clearStmt = $conn->prepare("UPDATE users SET remember_token = NULL, remember_token_expiry = NULL WHERE USER_ID = ?");
    $clearStmt->bind_param("i", $userId);
    $clearStmt->execute();
    $clearStmt->close();
}

// 4. Destroy Session and Clear Cookies
session_unset();
session_destroy();

$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
setcookie("REMEMBER_TOKEN", "", time() - 3600, "/", "", $isSecure, true);

header("Location: ./index.php");
exit();

?>