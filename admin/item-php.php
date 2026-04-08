<?php
// admin/item-php.php
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
    $null = null;

    // Handle Add Item
    if ($data['action'] == "add") {
        if (!isset($data['itemCode'], $data['desc'], $data['unit'], $data['cost'], $data['minThreshold'], $data['maxThreshold'])) {
            echo json_encode(["status" => "error", "message" => "Missing required fields"]);
            exit();
        }

        $itemCode = trim($data['itemCode']);
        // $barCase = trim($data['barCase']);
        // $barPiece = trim($data['barPiece']);
        $desc = trim($data['desc']);
        $unit = trim($data['unit']);
        $cost = floatval($data['cost']);
        // $principal = intval($data['principal']);
        $minThreshold = isset($data['minThreshold']) && $data['minThreshold'] !== '' ? intval($data['minThreshold']) : null;
        $maxThreshold = isset($data['maxThreshold']) && $data['maxThreshold'] !== '' ? intval($data['maxThreshold']) : null;

        $conn->begin_transaction();

        try {
            // Insert new item into 'item' table
            $additem = "INSERT INTO item (ITEM_CODE, ITEM_DESC, ITEM_UNIT, ITEM_COST, LAST_UPDATED_BY, ITEM_IS_ARCHIVED) VALUES (?, ?, ?, ?, ?, 0)";
            $stmt = $conn->prepare($additem);
            if (!$stmt)
                throw new Exception("SQL error: " . $conn->error);
            $stmt->bind_param("sssss", $itemCode, $desc, $unit, $cost, $user_id);

            if (!$stmt->execute())
                throw new Exception("Failed to add item: " . $stmt->error);

            // Get the last inserted ITEM_ID
            $item_id = $conn->insert_id;
            $stmt->close();

            // Insert default values into 'inventory' table
            $addInventory = "INSERT INTO inventory (ITEM_ID, INV_QUANTITY_PIECE, LAST_UPDATED_BY) VALUES (?, 0, ?)";
            $stmt = $conn->prepare($addInventory);
            if (!$stmt)
                throw new Exception("SQL error: " . $conn->error);
            $stmt->bind_param("ii", $item_id, $user_id);

            if (!$stmt->execute())
                throw new Exception("Failed to add inventory record: " . $stmt->error);
            $stmt->close();

            // Insert thresholds if provided
            if ($minThreshold !== null || $maxThreshold !== null) {

                // $stmt = $conn->prepare("SELECT ITEM_UNIT FROM item WHERE ITEM_ID = ?");
                // $stmt->bind_param("i", $item_id);
                // $stmt->execute();
                // $itemData = $stmt->get_result()->fetch_assoc();
                // $stmt->close();


                // $totalMinThreshold = $minThreshold !== null ? $minThreshold * $itemData['ITEM_UNIT'] : null;
                // $totalMaxThreshold = $maxThreshold !== null ? $maxThreshold * $itemData['ITEM_UNIT'] : null;

                $thresholdQuery = "INSERT INTO inventory_thresholds (ITEM_ID, MIN_THRESHOLD, MAX_THRESHOLD, CREATED_BY) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($thresholdQuery);
                if (!$stmt)
                    throw new Exception("SQL error: " . $conn->error);
                $stmt->bind_param("iiii", $item_id, $minThreshold, $maxThreshold, $user_id);

                if (!$stmt->execute())
                    throw new Exception("Failed to add thresholds: " . $stmt->error);
                $stmt->close();
            }

            // Log the activity
            $thresholdDetails = "";
            if ($minThreshold !== null || $maxThreshold !== null) {
                $thresholdDetails = "Min: " . ($minThreshold ?? 'Not set') . ", Max: " . ($maxThreshold ?? 'Not set');
            }

            logUserActivity(
                $conn,
                $user_id,
                $userName,
                $userRole,
                $ipAddress,
                $userAgent,
                'item Created',
                'Item Management',
                $desc,
                "Code: $itemCode" . ($thresholdDetails ? ", Thresholds: $thresholdDetails" : ""),
                'N/A'
            );

            // Commit transaction
            $conn->commit();
            echo json_encode(["status" => "success", "message" => "Item and inventory record added successfully!"]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Handle Edit Item
    elseif ($data['action'] == "edit") {
        if (!isset($data['itemCode'],/* $data['barCase'], $data['barPiece'],*/ $data['desc'], $data['unit'], $data['cost'], /*$data['principal'],*/ $data['item_id'])) {
            echo json_encode(["status" => "error", "message" => "Missing required fields"]);
            exit();
        }
        $item_id = intval($data['item_id']);
        $itemCode = trim($data['itemCode']);
        // $barCase = trim($data['barCase']);
        // $barPiece = trim($data['barPiece']);
        $desc = trim($data['desc']);
        $unit = trim($data['unit']);
        $cost = floatval($data['cost']);
        // $principal = trim($data['principal']);
        $minThreshold = isset($data['minThreshold']) && $data['minThreshold'] !== '' ? intval($data['minThreshold']) : null;
        $maxThreshold = isset($data['maxThreshold']) && $data['maxThreshold'] !== '' ? intval($data['maxThreshold']) : null;

        $conn->begin_transaction();

        try {
            // Get old item data for logging
            $oldStmt = $conn->prepare("SELECT ITEM_CODE, /*ITEM_BARCODE_CASE, ITEM_BARCODE_PIECE,*/ ITEM_DESC, ITEM_UNIT, ITEM_COST /*,VEN_ID*/ FROM item WHERE ITEM_ID = ?");
            $oldStmt->bind_param("i", $item_id);
            $oldStmt->execute();
            $oldData = $oldStmt->get_result()->fetch_assoc();
            $oldStmt->close();

            // Perform update
            $updateQuery = "UPDATE item SET ITEM_CODE=?, ITEM_BARCODE_CASE=?, ITEM_BARCODE_PIECE=?, ITEM_DESC=?, ITEM_UNIT=?, ITEM_COST=?, VEN_ID=?, LAST_UPDATED_BY=? WHERE ITEM_ID=?";
            $stmt = $conn->prepare($updateQuery);
            if (!$stmt)
                throw new Exception("SQL error: " . $conn->error);
            $stmt->bind_param("sssssssii", $itemCode, $null, $null, $desc, $unit, $cost, $null, $user_id, $item_id);

            if (!$stmt->execute())
                throw new Exception("Failed to update item: " . $stmt->error);
            $stmt->close();

            // Update or insert thresholds
            $checkThresholdQuery = "SELECT THRESHOLD_ID FROM inventory_thresholds WHERE ITEM_ID = ?";
            $stmt = $conn->prepare($checkThresholdQuery);
            $stmt->bind_param("i", $item_id);
            $stmt->execute();
            $thresholdExists = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            // get item uom
            // $getItem = "SELECT ITEM_ID, ITEM_UOM FROM item WHERE ITEM_ID = $item_id";
            // $result = $conn->query($getItem);
            // $itemData = $result->fetch_assoc();
            // $totalMinThreshold = $minThreshold !== null ? $minThreshold * $itemData['ITEM_UOM'] : null;
            // $totalMaxThreshold = $maxThreshold !== null ? $maxThreshold * $itemData['ITEM_UOM'] : null;
            if ($thresholdExists) {
                // Update existing thresholds
                $updateThresholdQuery = "UPDATE inventory_thresholds SET MIN_THRESHOLD = ?, MAX_THRESHOLD = ?, UPDATED_AT = NOW() WHERE ITEM_ID = ?";
                $stmt = $conn->prepare($updateThresholdQuery);
                if (!$stmt)
                    throw new Exception("SQL error: " . $conn->error);
                $stmt->bind_param("iii", $minThreshold, $maxThreshold, $item_id);
            } else {
                // Insert new thresholds
                $insertThresholdQuery = "INSERT INTO inventory_thresholds (ITEM_ID, MIN_THRESHOLD, MAX_THRESHOLD, CREATED_BY) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($insertThresholdQuery);
                if (!$stmt)
                    throw new Exception("SQL error: " . $conn->error);
                $stmt->bind_param("iiii", $item_id, $minThreshold, $maxThreshold, $user_id);
            }

            if (!$stmt->execute())
                throw new Exception("Failed to update thresholds: " . $stmt->error);
            $stmt->close();

            // Log the activity
            $thresholdDetails = "";
            if ($minThreshold !== null || $maxThreshold !== null) {
                $thresholdDetails = "Min: " . ($minThreshold ?? 'Not set') . ", Max: " . ($maxThreshold ?? 'Not set');
            }

            logUserActivity(
                $conn,
                $user_id,
                $userName,
                $userRole,
                $ipAddress,
                $userAgent,
                'Item Updated',
                'Item Management',
                $desc,
                "Item details updated",
                'N/A'
            );

            $conn->commit();
            echo json_encode(["status" => "success", "message" => "Item updated successfully!"]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }

    // Handle Archive Item (keep this the same)
    elseif ($data['action'] == "archive") {
        if (!isset($data['item_id'])) {
            echo json_encode(["status" => "error", "message" => "Item ID is required for archiving"]);
            exit();
        }

        if (!$user || !password_verify($data['password'], $user['USER_PASS'])) {
            echo json_encode(["status" => "error", "message" => "Unauthorized access"]);
            exit();
        }
        $item_id = intval($data['item_id']);

        // Check if item has remaining stock
        $brandQuery = "SELECT INV_QUANTITY_PIECE FROM inventory WHERE ITEM_ID = ?";
        $stmt = $conn->prepare($brandQuery);
        if (!$stmt) {
            echo json_encode(["status" => "error", "message" => "SQL error: " . $conn->error]);
            exit();
        }
        $stmt->bind_param("i", $item_id);
        $stmt->execute();
        $stmt->bind_result($quantity_piece);
        $stmt->fetch();
        $stmt->close();

        if ($quantity_piece > 0) {
            echo json_encode(["status" => "error", "message" => "You cannot archive an item with remaining stock"]);
            exit();
        }

        // Get item description for logging
        $itemQuery = "SELECT ITEM_DESC FROM item WHERE ITEM_ID = ?";
        $stmt = $conn->prepare($itemQuery);
        $stmt->bind_param("i", $item_id);
        $stmt->execute();
        $stmt->bind_result($itemDesc);
        $stmt->fetch();
        $stmt->close();

        $conn->begin_transaction();

        try {
            // Archive the item
            $archiveQuery = "UPDATE item SET ITEM_IS_ARCHIVED = 1 WHERE ITEM_ID = ?";
            $stmt = $conn->prepare($archiveQuery);
            if (!$stmt)
                throw new Exception("SQL error: " . $conn->error);
            $stmt->bind_param("i", $item_id);
            if (!$stmt->execute())
                throw new Exception("Failed to archive item: " . $stmt->error);
            $stmt->close();

            // Log the activity - SHORT MESSAGE
            logUserActivity(
                $conn,
                $user_id,
                $userName,
                $userRole,
                $ipAddress,
                $userAgent,
                'Item Archived',
                'Item Management',
                $itemDesc,
                "Item archived",
                'N/A'
            );

            $conn->commit();
            echo json_encode(["status" => "success", "message" => "Item archived successfully!"]);
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