<?php
// admin/stockOut-batch.php
session_start();
include "../API/db-connector.php";
require_once "../API/NotificationHelper.php";

header('Content-Type: application/json');

function generateRISNumber($conn)
{
    $year = date('Y');
    $month = date('m'); // 01..12

    // Get the highest running number for the current year (ignore month)
    $query = "SELECT MAX(SUBSTRING_INDEX(SO_RIS_NO, '-', -1)) as last_number
              FROM stock_out
              WHERE SO_RIS_NO LIKE 'RIS-$year-%'";

    $result = $conn->query($query);
    $row = $result->fetch_assoc();

    $nextNumber = ($row['last_number'] ?? 0) + 1;

    return "RIS-$year-$month-" . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
}

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
    return $ip === '::1' ? '127.0.0.1' : trim($ip);
}

function getUserAgent()
{
    return $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Invalid request method"]);
    exit();
}

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

// Handle Add Batch
if ($data['action'] == "add_batch") {
    if (!isset($data['off_id'], $data['items']) || !is_array($data['items']) || empty($data['items'])) {
        echo json_encode(["status" => "error", "message" => "Missing required fields"]);
        exit();
    }

    $off_id = intval($data['off_id']);
    $items = $data['items'];
    $ris_no = generateRISNumber($conn);


    $conn->begin_transaction();

    try {
        // Validate inventory for all items first
        foreach ($items as $item) {
            $item_id = intval($item['item_id']);
            $quantity = intval($item['quantity']);

            $checkInv = "SELECT INV_QUANTITY_PIECE, i.ITEM_DESC, i.ITEM_CODE 
                        FROM inventory inv 
                        JOIN item i ON inv.ITEM_ID = i.ITEM_ID 
                        WHERE i.ITEM_ID = ?";
            $checkstmt = $conn->prepare($checkInv);
            $checkstmt->bind_param('i', $item_id);
            $checkstmt->execute();
            $checkstmt->bind_result($inv_quantity, $item_desc, $item_code);
            $checkstmt->fetch();
            $checkstmt->close();

            if ($inv_quantity < $quantity) {
                throw new Exception("Insufficient inventory for {$item_code} - {$item_desc}. Available: {$inv_quantity}");
            }

        }

        // Insert all items
        $insertQuery = "INSERT INTO stock_out (SO_RIS_NO, ITEM_ID, SO_QUANTITY, SO_UNIT_COST, SO_REMARKS, OFF_ID, CREATED_BY) 
                       VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insertQuery);

        foreach ($items as $item) {
            // When inserting into stock_out
            $itemQuery = "SELECT ITEM_COST FROM item WHERE ITEM_ID = ?";
            $priceStmt = $conn->prepare($itemQuery);
            $priceStmt->bind_param("i", $item_id);
            $priceStmt->execute();
            $priceResult = $priceStmt->get_result();
            $itemData = $priceResult->fetch_assoc();
            $current_cost = $itemData['ITEM_COST'];

            $item_id = intval($item['item_id']);
            $quantity = intval($item['quantity']);
            $remarks = trim($item['remarks']);

            $stmt->bind_param("siissii", $ris_no, $item_id, $quantity, $current_cost, $remarks, $off_id, $user_id);
            if (!$stmt->execute()) {
                throw new Exception("Failed to insert stock out record");
            }

            if ($quantity <= 0) {
                throw new Exception("Quantity must be greater than zero.");
            }

            // Update inventory
            $updateInv = "UPDATE inventory SET INV_QUANTITY_PIECE = INV_QUANTITY_PIECE - ?, LAST_UPDATED_BY = ? WHERE ITEM_ID = ?";
            $invStmt = $conn->prepare($updateInv);
            $invStmt->bind_param("iii", $quantity, $user_id, $item_id);
            if (!$invStmt->execute()) {
                throw new Exception("Failed to update inventory");
            }
            $invStmt->close();
            $affectedItemIds[] = $item_id;

            $officeQuery = "SELECT OFF_CODE, OFF_NAME FROM office WHERE OFF_ID = ?";
            $officeStmt = $conn->prepare($officeQuery);
            $officeStmt->bind_param("i", $off_id);
            $officeStmt->execute();
            $officeResult = $officeStmt->get_result()->fetch_assoc();

            $off_code = $officeResult['OFF_CODE'];
            $off_name = $officeResult['OFF_NAME'];

            // Insert into item_movement_history
            $movementQuery = "INSERT INTO item_movement_history 
                (ITEM_ID, MOVEMENT_TYPE, QUANTITY_PIECE, USER_ID, COUNTED_BY, DETAILS, BATCH_NO) 
                VALUES (?, 'Stock Out', ?, ?, ?, ?, ?)";
            $movementStmt = $conn->prepare($movementQuery);
            if (!$movementStmt)
                throw new Exception("SQL error: " . $conn->error);

            $details = "Stock out: $quantity units. RIS No: $ris_no, Office: $off_code, Remarks: $remarks";
            $movementStmt->bind_param("iiiiss", $item_id, $quantity, $user_id, $user_id, $details, $ris_no);

            if (!$movementStmt->execute())
                throw new Exception("Failed to record movement history: " . $movementStmt->error);
            $movementStmt->close();
        }
        $stmt->close();

        // Log activity
        $itemCount = count($items);
        logUserActivity(
            $conn,
            $user_id,
            $userName,
            $userRole,
            $ipAddress,
            $userAgent,
            'Stock Out Batch',
            'Inventory',
            $ris_no,
            "Created RIS batch with $itemCount item(s)",
            $ris_no
        );

        $notifier = new NotificationHelper($conn);
        $notifier->checkAfterStockOut($affectedItemIds);

        $conn->commit();
        echo json_encode(["status" => "success", "message" => "RIS batch created successfully!"]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}

// Handle Edit Batch
elseif ($data['action'] == "edit_batch") {
    if (!isset($data['ris_no'], $data['off_id'], $data['items']) || !is_array($data['items']) || empty($data['items'])) {
        echo json_encode(["status" => "error", "message" => "Missing required fields"]);
        exit();
    }

    if (!$user || !password_verify($data['password'], $user['USER_PASS'])) {
        echo json_encode(["status" => "error", "message" => "Unauthorized access"]);
        exit();
    }

    $ris_no = $data['ris_no'];
    $off_id = intval($data['off_id']);
    $newItems = $data['items'];

    $conn->begin_transaction();

    try {
        // Get old items to revert inventory
        $oldQuery = "SELECT SO_ID, ITEM_ID, SO_QUANTITY FROM stock_out WHERE SO_RIS_NO = ? FOR UPDATE";
        $oldStmt = $conn->prepare($oldQuery);
        $oldStmt->bind_param("s", $ris_no);
        $oldStmt->execute();
        $oldResult = $oldStmt->get_result();
        $oldItems = [];
        while ($row = $oldResult->fetch_assoc()) {
            $oldItems[] = $row;
        }
        $oldStmt->close();

        // Revert inventory (add back old quantities)
        foreach ($oldItems as $old) {
            $revertQuery = "UPDATE inventory SET INV_QUANTITY_PIECE = INV_QUANTITY_PIECE + ? WHERE ITEM_ID = ?";
            $revertStmt = $conn->prepare($revertQuery);
            $revertStmt->bind_param("ii", $old['SO_QUANTITY'], $old['ITEM_ID']);
            if (!$revertStmt->execute()) {
                throw new Exception("Failed to revert inventory");
            }
            $revertStmt->close();

            // Record movement history for reversion
            $movementQuery = "INSERT INTO item_movement_history 
                (ITEM_ID, MOVEMENT_TYPE, QUANTITY_PIECE, USER_ID, COUNTED_BY, DETAILS, BATCH_NO) 
                VALUES (?, 'Adjustment', ?, ?, ?, ?, ?)";
            $movementStmt = $conn->prepare($movementQuery);
            if (!$movementStmt)
                throw new Exception("SQL error: " . $conn->error);

            $details = "Stock adjustment due to batch edit. RIS No: $ris_no";
            $movementStmt->bind_param("iiiiss", $old['ITEM_ID'], $old['SO_QUANTITY'], $user_id, $user_id, $details, $ris_no);

            if (!$movementStmt->execute())
                throw new Exception("Failed to record movement history: " . $movementStmt->error);
            $movementStmt->close();
        }

        // Delete old records
        $deleteQuery = "DELETE FROM stock_out WHERE SO_RIS_NO = ?";
        $deleteStmt = $conn->prepare($deleteQuery);
        $deleteStmt->bind_param("s", $ris_no);
        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete old records");
        }
        $deleteStmt->close();

        // Validate new items against inventory
        foreach ($newItems as $item) {
            $item_id = intval($item['item_id']);
            $quantity = intval($item['quantity']);

            $checkInv = "SELECT INV_QUANTITY_PIECE FROM inventory WHERE ITEM_ID = ?";
            $checkStmt = $conn->prepare($checkInv);
            $checkStmt->bind_param("i", $item_id);
            $checkStmt->execute();
            $checkStmt->bind_result($inv_quantity);
            $checkStmt->fetch();
            $checkStmt->close();

            if ($inv_quantity < $quantity) {
                $itemInfo = $conn->query("SELECT ITEM_CODE, ITEM_DESC FROM item WHERE ITEM_ID = $item_id")->fetch_assoc();
                throw new Exception("Insufficient inventory for {$itemInfo['ITEM_CODE']} - {$itemInfo['ITEM_DESC']}. Available: {$inv_quantity}");
            }
        }

        // Insert new records and update inventory
        $insertQuery = "INSERT INTO stock_out (SO_RIS_NO, ITEM_ID, SO_QUANTITY, SO_REMARKS, OFF_ID, CREATED_BY, LAST_UPDATED_BY) 
                       VALUES (?, ?, ?, ?, ?, ?, ?)";
        $insertStmt = $conn->prepare($insertQuery);

        foreach ($newItems as $item) {
            $item_id = intval($item['item_id']);
            $quantity = intval($item['quantity']);
            $remarks = trim($item['remarks']);

            $insertStmt->bind_param("siisiii", $ris_no, $item_id, $quantity, $remarks, $off_id, $user_id, $user_id);
            if (!$insertStmt->execute()) {
                throw new Exception("Failed to insert new records");
            }

            if ($quantity <= 0) {
                throw new Exception("Quantity must be greater than zero.");
            }

            // Update inventory (subtract new quantities)
            $updateInv = "UPDATE inventory SET INV_QUANTITY_PIECE = INV_QUANTITY_PIECE - ?, LAST_UPDATED_BY = ? WHERE ITEM_ID = ?";
            $invStmt = $conn->prepare($updateInv);
            $invStmt->bind_param("iii", $quantity, $user_id, $item_id);
            if (!$invStmt->execute()) {
                throw new Exception("Failed to update inventory");
            }
            $invStmt->close();
            $newItemIds[] = $item_id;

            $officeQuery = "SELECT OFF_CODE, OFF_NAME FROM office WHERE OFF_ID = ?";
            $officeStmt = $conn->prepare($officeQuery);
            $officeStmt->bind_param("i", $off_id);
            $officeStmt->execute();
            $officeResult = $officeStmt->get_result()->fetch_assoc();

            $off_code = $officeResult['OFF_CODE'];
            $off_name = $officeResult['OFF_NAME'];

            // Insert into item_movement_history for new stock out
            $movementQuery = "INSERT INTO item_movement_history 
                (ITEM_ID, MOVEMENT_TYPE, QUANTITY_PIECE, USER_ID, COUNTED_BY, DETAILS, BATCH_NO) 
                VALUES (?, 'Stock Out', ?, ?, ?, ?, ?)";
            $movementStmt = $conn->prepare($movementQuery);
            if (!$movementStmt)
                throw new Exception("SQL error: " . $conn->error);

            $details = "Stock out: $quantity units. RIS No: $ris_no, Office: $off_code, Remarks: $remarks";
            $movementStmt->bind_param("iiiiss", $item_id, $quantity, $user_id, $user_id, $details, $ris_no);

            if (!$movementStmt->execute())
                throw new Exception("Failed to record movement history: " . $movementStmt->error);
            $movementStmt->close();

        }
        $insertStmt->close();

        // Log activity
        $itemCount = count($newItems);
        logUserActivity(
            $conn,
            $user_id,
            $userName,
            $userRole,
            $ipAddress,
            $userAgent,
            'Stock Out Batch Updated',
            'Inventory',
            $ris_no,
            "Updated RIS batch with $itemCount item(s)",
            $ris_no
        );

        $notifier = new NotificationHelper($conn);
        $notifier->checkAfterStockOut($newItemIds);

        $conn->commit();
        echo json_encode(["status" => "success", "message" => "RIS batch updated successfully!"]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid action"]);
}

$conn->close();
?>