<?php
// admin/office-php.php
session_start();
include "../API/db-connector.php";

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
    $null = null;

    // Handle Add Item
    if ($data['action'] == "add") {
        if (!isset($data['offCode'], $data['offName'])) {
            echo json_encode(["status" => "error", "message" => "Missing required fields"]);
            exit();
        }

        $offCode = trim($data['offCode']);
        $offName = trim($data['offName']);

        $conn->begin_transaction();

        try {
            // Insert new item into 'item' table
            $addOffice = "INSERT INTO office (OFF_CODE, OFF_NAME, LAST_UPDATED_BY, OFF_IS_ARCHIVED) VALUES (?, ?, ?, 0)";
            $stmt = $conn->prepare($addOffice);
            if (!$stmt)
                throw new Exception("SQL error: " . $conn->error);
            $stmt->bind_param("ssi", $offCode, $offName, $user_id);

            if (!$stmt->execute())
                throw new Exception("Failed to add office: " . $stmt->error);

            // Get the last inserted off_id
            $off_id = $conn->insert_id;
            $stmt->close();

            logUserActivity(
                $conn,
                $user_id,
                $userName,
                $userRole,
                $ipAddress,
                $userAgent,
                'Office Created',
                'Office',
                $offName,
                "Code: $offCode | Name: $offName",
                'N/A'
            );

            // Commit transaction
            $conn->commit();
            echo json_encode(["status" => "success", "message" => "Office record added successfully!"]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Handle Edit Item
    elseif ($data['action'] == "edit") {
        if (!isset($data['offCode'], $data['offName'],  $data['off_id'])) {
            echo json_encode(["status" => "error", "message" => "Missing required fields"]);
            exit();
        }


        $off_id = intval($data['off_id']);
        $offCode = trim($data['offCode']);
        $offName = trim($data['offName']);

        $conn->begin_transaction();

        try {
            // Get old item data for logging
            $oldStmt = $conn->prepare("SELECT OFF_CODE, OFF_NAME FROM office WHERE OFF_ID = ?");
            $oldStmt->bind_param("i", $off_id);
            $oldStmt->execute();
            $oldData = $oldStmt->get_result()->fetch_assoc();
            $oldStmt->close();

            // Perform update
            $updateQuery = "UPDATE office SET OFF_CODE=?, OFF_NAME=?, LAST_UPDATED_BY=? WHERE OFF_ID=?";
            $stmt = $conn->prepare($updateQuery);
            if (!$stmt)
                throw new Exception("SQL error: " . $conn->error);
            $stmt->bind_param("ssii", $offCode, $offName, $user_id, $off_id);

            if (!$stmt->execute())
                throw new Exception("Failed to update office: " . $stmt->error);
            $stmt->close();

            logUserActivity(
                $conn,
                $user_id,
                $userName,
                $userRole,
                $ipAddress,
                $userAgent,
                'Office Updated',
                'Office',
                $offName,
                "Office details updated",
                'N/A'
            );

            $conn->commit();
            echo json_encode(["status" => "success", "message" => "Office updated successfully!"]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Handle Archive Item (keep this the same)
    elseif ($data['action'] == "archive") {
        if (!isset($data['off_id'])) {
            echo json_encode(["status" => "error", "message" => "Office ID is required for archiving"]);
            exit();
        }

        if (!$user || !password_verify($data['password'], $user['USER_PASS'])) {
            echo json_encode(["status" => "error", "message" => "Unauthorized access"]);
            exit();
        }
        $off_id = intval($data['off_id']);

        // Get item offNameription for logging
        $itemQuery = "SELECT OFF_NAME FROM office WHERE OFF_ID = ?";
        $stmt = $conn->prepare($itemQuery);
        $stmt->bind_param("i", $off_id);
        $stmt->execute();
        $stmt->bind_result($itemoffName);
        $stmt->fetch();
        $stmt->close();

        $conn->begin_transaction();

        try {
            // Archive the item
            $archiveQuery = "UPDATE office SET OFF_IS_ARCHIVED = 1 WHERE OFF_ID = ?";
            $stmt = $conn->prepare($archiveQuery);
            if (!$stmt)
                throw new Exception("SQL error: " . $conn->error);
            $stmt->bind_param("i", $off_id);
            if (!$stmt->execute())
                throw new Exception("Failed to archive office: " . $stmt->error);
            $stmt->close();

            // Log the activity - SHORT MESSAGE
            logUserActivity(
                $conn,
                $user_id,
                $userName,
                $userRole,
                $ipAddress,
                $userAgent,
                'Office Archived',
                'Office',
                $itemoffName,
                "Item archived",
                'N/A'
            );

            $conn->commit();
            echo json_encode(["status" => "success", "message" => "Office archived successfully!"]);
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
?>