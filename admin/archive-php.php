<?php
session_start();
include "../API/db-connector.php";
session_regenerate_id(true);

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
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
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

    if (!$user || !password_verify($data['password'], $user['USER_PASS'])) {
        echo json_encode(["status" => "error", "message" => "Unauthorized access"]);
        exit();
    }

    $userName = $user['USER_FNAME'] . ' ' . $user['USER_LNAME'];
    $userRole = $user['UR_ROLE'];

    $ipAddress = getUserIp();
    $userAgent = getUserAgent();

    // Handle Restore Action
    if ($data['action'] == "restore") {
        if (!isset($data['id'], $data['table'])) {
            echo json_encode(["status" => "error", "message" => "Missing required fields"]);
            exit();
        }

        $id = intval($data['id']);
        $table = $data['table'];

        // Validate table name - removed vendor and location
        $validTables = ['users', 'item', 'office'];
        if (!in_array($table, $validTables)) {
            echo json_encode(["status" => "error", "message" => "Invalid table specified"]);
            exit();
        }

        $conn->begin_transaction();

        try {
            // Get record name for logging - handle each table differently
            $recordName = "";

            if ($table === 'users') {
                $nameQuery = "SELECT USER_FNAME, USER_LNAME FROM users WHERE USER_ID = ?";
                $stmt = $conn->prepare($nameQuery);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $record = $result->fetch_assoc();
                $recordName = ($record['USER_FNAME'] ?? '') . ' ' . ($record['USER_LNAME'] ?? '');
                $stmt->close();
            } elseif ($table === 'office') {
                $nameQuery = "SELECT OFF_CODE FROM office WHERE OFF_ID = ?";
                $stmt = $conn->prepare($nameQuery);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $record = $result->fetch_assoc();
                $recordName = $record['OFF_CODE'] ?? 'Unknown Item';
                $stmt->close();
            } else { // item table
                $nameQuery = "SELECT ITEM_CODE FROM item WHERE ITEM_ID = ?";
                $stmt = $conn->prepare($nameQuery);
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $record = $result->fetch_assoc();
                $recordName = $record['ITEM_CODE'] ?? 'Unknown Item';
                $stmt->close();
            }

            if (empty($recordName)) {
                $recordName = "Unknown Record";
            }

            // Determine the archive field based on table
            $archiveField = '';
            $idField = '';

            if ($table === 'users') {
                $archiveField = 'USER_IS_ARCHIVED';
                $idField = 'USER_ID';
            } elseif ($table === 'office') {
                $archiveField = 'OFF_IS_ARCHIVED';
                $idField = 'OFF_ID';
            } else { // item table
                $archiveField = 'ITEM_IS_ARCHIVED';
                $idField = 'ITEM_ID';
            }

            // Restore the record 
            $recordFieldValue = 0;
            $restoreQuery = "UPDATE $table SET $archiveField = ? WHERE $idField = ?";
            $stmt = $conn->prepare($restoreQuery);
            if (!$stmt)
                throw new Exception("SQL error: " . $conn->error);
            $stmt->bind_param("ii", $recordFieldValue, $id);

            if (!$stmt->execute()) {
                throw new Exception("Failed to restore record: " . $stmt->error);
            }
            $stmt->close();

            $restoreData = $table === "users" ? "Restored Account" : "Restored Item";

            // Log the activity
            logUserActivity(
                $conn,
                $user_id,
                $userName,
                $userRole,
                $ipAddress,
                $userAgent,
                $restoreData,
                ucfirst($table),
                $recordName,
                "Restored from archive",
                'N/A'
            );

            $conn->commit();
            echo json_encode(["status" => "success", "message" => "Record restored successfully!"]);
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