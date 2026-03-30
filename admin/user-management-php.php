<?php
// admin/user-management-php.php
session_start();
include "../API/db-connector.php";
// session_regenerate_id(true);

function logUserActivity($conn, $userId, $userName, $userRole, $ipaddress, $userAgent, $actionType, $module, $recordName, $details, $batchNo)
{
    $logQuery = "INSERT INTO user_activity_log (USER_ID, USER_NAME, USER_ROLE, IP_ADDRESS, USER_AGENT, ACTION_TYPE, MODULE, RECORD_NAME, DETAILS, BATCH_NO) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($logQuery);
    if (!$stmt)
        return false;
    $stmt->bind_param("isssssssss", $userId, $userName, $userRole, $ipaddress, $userAgent, $actionType, $module, $recordName, $details, $batchNo);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

function getUserIp()
{
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    }

    $ip = trim($ip);

    // Normalize IPv6 localhost to IPv4
    if ($ip === '::1') {
        return '127.0.0.1';
    }

    return $ip;
}


function getUserAgent()
{
    return $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
}



ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// Get the request method
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $json = file_get_contents("php://input");
    if (empty($json)) {
        echo json_encode(["status" => "error", "message" => "No data received"]);
        exit();
    }

    $data = json_decode($json, true);
    if (!$data) {
        echo json_encode(["status" => "error", "message" => "Invalid JSON format"]);
        exit();
    }

    // Check if action is set
    if (!isset($data['action'])) {
        echo json_encode(["status" => "error", "message" => "No action specified"]);
        exit();
    }

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(["status" => "error", "message" => "Session expired"]);
        exit();
    }

    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT u.USER_ID, u.USER_FNAME, u.USER_LNAME, ur.UR_ROLE, u.USER_PASS FROM users u JOIN user_role ur
     ON u.UR_ID = ur.UR_ID WHERE u.USER_ID = ?");
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Database error"]);
        exit();
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $userName = $user['USER_FNAME'] . ' ' . $user['USER_LNAME'];
    $userRole = $user['UR_ROLE'];

    $ipAddress = getUserIp();
    $userAgent = getUserAgent();

    // Handle Add User
    if ($data['action'] === "add") {

        if (!isset($data['fname'], $data['lname'], $data['email'], $data['pass'], $data['role'])) {
            echo json_encode(["status" => "error", "message" => "Missing required fields"]);
            exit();
        }

        $fname = trim($data['fname']);
        $lname = trim($data['lname']);
        $email = trim($data['email']);
        $password = $data['pass'];
        $role = trim($data['role']);

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $conn->begin_transaction();

        try {
            // Check email
            $checkEmail = $conn->prepare("SELECT USER_ID FROM users WHERE USER_EMAIL = ?");
            $checkEmail->bind_param("s", $email);
            $checkEmail->execute();
            $checkEmail->store_result();
            if ($checkEmail->num_rows > 0) {
                throw new Exception("Email already exists");
            }
            $checkEmail->close();

            // Get role ID
            $stmt = $conn->prepare("SELECT UR_ID FROM user_role WHERE UR_ROLE = ?");
            $stmt->bind_param("s", $role);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 0) {
                throw new Exception("Invalid user role");
            }
            $UserRole = $result->fetch_assoc()['UR_ID'];
            $stmt->close();

            // Insert user
            $stmt = $conn->prepare("
            INSERT INTO users (USER_FNAME, USER_LNAME, USER_EMAIL, USER_PASS, UR_ID)
            VALUES (?, ?, ?, ?, ?)
        ");
            $stmt->bind_param("ssssi", $fname, $lname, $email, $hashed_password, $UserRole);
            $stmt->execute();
            $stmt->close();

            // Log
            if (
                !logUserActivity(
                    $conn,
                    $user_id,
                    $userName,
                    $userRole,
                    $ipAddress,
                    $userAgent,
                    'User Created',
                    'User Management',
                    "$fname $lname ($email)",
                    "Role Assigned: $role",
                    'N/A'
                )
            ) {
                throw new Exception("Logging failed");
            }

            $conn->commit();
            echo json_encode(["status" => "success", "message" => "User added successfully"]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Handle Edit User
    elseif ($data['action'] === "edit") {

        if (!isset($data['user_id'], $data['fname'], $data['lname'], $data['email'], $data['role'])) {
            echo json_encode(["status" => "error", "message" => "Missing required fields"]);
            exit();
        }

        if (!$user || !password_verify($data['password'], $user['USER_PASS'])) {
            echo json_encode(["status" => "error", "message" => "Unauthorized access"]);
            exit();
        }

        $editUserId = (int) $data['user_id'];
        $fname = trim($data['fname']);
        $lname = trim($data['lname']);
        $email = trim($data['email']);
        $role = trim($data['role']);
        $password = !empty($data['pass']) ? $data['pass'] : null;

        $conn->begin_transaction();

        try {
            // Fetch old user data
            $oldStmt = $conn->prepare("
            SELECT u.USER_FNAME, u.USER_LNAME, u.USER_EMAIL, ur.UR_ROLE
            FROM users u
            JOIN user_role ur ON u.UR_ID = ur.UR_ID
            WHERE u.USER_ID = ?
        ");
            $oldStmt->bind_param("i", $editUserId);
            $oldStmt->execute();
            $oldUser = $oldStmt->get_result()->fetch_assoc();
            $oldStmt->close();

            if (!$oldUser) {
                throw new Exception("User not found");
            }

            // Check email uniqueness (exclude current user)
            $checkEmail = $conn->prepare("
            SELECT USER_ID FROM users 
            WHERE USER_EMAIL = ? AND USER_ID != ?
        ");
            $checkEmail->bind_param("si", $email, $editUserId);
            $checkEmail->execute();
            $checkEmail->store_result();

            if ($checkEmail->num_rows > 0) {
                throw new Exception("Email already exists");
            }
            $checkEmail->close();

            // Get role ID
            $roleStmt = $conn->prepare("SELECT UR_ID FROM user_role WHERE UR_ROLE = ?");
            $roleStmt->bind_param("s", $role);
            $roleStmt->execute();
            $roleResult = $roleStmt->get_result();
            if ($roleResult->num_rows === 0) {
                throw new Exception("Invalid role");
            }
            $roleId = $roleResult->fetch_assoc()['UR_ID'];
            $roleStmt->close();

            // Update user
            if ($password) {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("
                UPDATE users 
                SET USER_FNAME=?, USER_LNAME=?, USER_EMAIL=?, USER_PASS=?, UR_ID=?
                WHERE USER_ID=?
            ");
                $stmt->bind_param("ssssii", $fname, $lname, $email, $hashed, $roleId, $editUserId);
            } else {
                $stmt = $conn->prepare("
                UPDATE users 
                SET USER_FNAME=?, USER_LNAME=?, USER_EMAIL=?, UR_ID=?
                WHERE USER_ID=?
            ");
                $stmt->bind_param("sssii", $fname, $lname, $email, $roleId, $editUserId);
            }

            if (!$stmt->execute()) {
                throw new Exception("Failed to update user");
            }
            $stmt->close();

            // Log activity (ACTOR = logged-in admin)
            logUserActivity(
                $conn,
                $_SESSION['user_id'],
                $userName,
                $userRole,
                $ipAddress,
                $userAgent,
                'User Updated',
                'User Management',
                "$fname $lname ($email)",
                "Profile details updated",
                'N/A'
            );

            $conn->commit();
            echo json_encode(["status" => "success", "message" => "User updated successfully"]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Handle Archive User
    elseif ($data['action'] === "archive") {

        if (!isset($data['user_id'])) {
            echo json_encode(["status" => "error", "message" => "User ID is required"]);
            exit();
        }

        if (!$user || !password_verify($data['password'], $user['USER_PASS'])) {
            echo json_encode(["status" => "error", "message" => "Unauthorized access"]);
            exit();
        }

        $archiveUserId = (int) $data['user_id'];
        $conn->begin_transaction();

        try {
            // Fetch user info for logging
            $stmt = $conn->prepare("
            SELECT u.USER_FNAME, u.USER_LNAME, ur.UR_ROLE, u.USER_EMAIL
            FROM users u
            JOIN user_role ur ON u.UR_ID = ur.UR_ID
            WHERE u.USER_ID = ?
        ");
            $stmt->bind_param("i", $archiveUserId);
            $stmt->execute();
            $userInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$userInfo) {
                throw new Exception("User not found");
            }

            // Archive user
            $stmt = $conn->prepare("
            UPDATE users SET USER_IS_ARCHIVED = 1 WHERE USER_ID = ?
        ");
            $stmt->bind_param("i", $archiveUserId);
            if (!$stmt->execute()) {
                throw new Exception("Failed to archive user");
            }
            $stmt->close();

            // Log activity
            logUserActivity(
                $conn,
                $_SESSION['user_id'],
                $userName,
                $userRole,
                $ipAddress,
                $userAgent,
                'User Archived',
                'User Management',
                $userInfo['USER_FNAME'] . ' ' . $userInfo['USER_LNAME'] . " ({$userInfo['USER_EMAIL']})",
                "Account archived",
                'N/A'
            );

            $conn->commit();
            echo json_encode(["status" => "success", "message" => "User archived successfully"]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Invalid action"]);
    }

    $conn->close();
    exit();
}