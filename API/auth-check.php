<?php
// api/auth-check.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/db-connector.php";

if (!isset($_SESSION['user_id'], $_SESSION['FNAME'], $_SESSION['LNAME'], $_SESSION['role'], $_SESSION['username']) && isset($_COOKIE['REMEMBER_TOKEN'])) {

    $stmt = $conn->prepare("
        SELECT u.USER_ID, u.USER_FNAME, u.USER_LNAME, ur.UR_ROLE
        FROM users u
        JOIN user_role ur ON u.UR_ID = ur.UR_ID
        WHERE u.remember_token = ?
        AND u.remember_token_expiry > NOW()
        LIMIT 1
    ");

    $stmt->bind_param("s", $_COOKIE['REMEMBER_TOKEN']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user) {
        $_SESSION['user_id'] = $user['USER_ID'];
        $_SESSION['username'] = $user['USER_FNAME'] . ' ' . $user['USER_LNAME'];
        $_SESSION['role'] = $user['UR_ROLE'];
        $_SESSION['FNAME'] = $user['USER_FNAME'];
        $_SESSION['LNAME'] = $user['USER_LNAME'];

    } else {
        setcookie("REMEMBER_TOKEN", "", time() - 3600, "/");
    }
}
