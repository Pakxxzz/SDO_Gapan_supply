<?php
// admin/returnItem.php
session_start();
include "../API/db-connector.php";
require_once "../API/NotificationHelper.php";

header('Content-Type: application/json');

function generateReturnNumber($conn)
{
    $year = date('Y');
    $month = date('m');

    $query = "SELECT MAX(SUBSTRING_INDEX(RETURN_NO, '-', -1)) as last_number
              FROM item_return
              WHERE RETURN_NO LIKE 'RET-$year-$month-%'";

    $result = $conn->query($query);
    $row = $result->fetch_assoc();

    $nextNumber = ($row['last_number'] ?? 0) + 1;

    return "RET-$year-$month-" . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
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
$stmt = $conn->prepare("SELECT u.USER_ID, u.USER_FNAME, u.USER_LNAME, ur.UR_ROLE, u.USER_PASS FROM users u JOIN user_role ur ON u.UR_ID = ur.UR_ID WHERE u.USER_ID = ?");
if (!$stmt) {
    echo json_encode(["status" => "error", "message" => "Database error"]);
    exit();
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(["status" => "error", "message" => "User not found"]);
    exit();
}

$userName = $user['USER_FNAME'] . ' ' . $user['USER_LNAME'];
$userRole = $user['UR_ROLE'];

$ipAddress = getUserIp();
$userAgent = getUserAgent();

if ($data['action'] == "return_items") {
    if (!isset($data['ris_no'], $data['items']) || !is_array($data['items']) || empty($data['items'])) {
        echo json_encode(["status" => "error", "message" => "Missing required fields"]);
        exit();
    }

    $ris_no = $data['ris_no'];
    $items = $data['items'];
    $return_no = generateReturnNumber($conn);

    $conn->begin_transaction();

    try {
        // Validate that items exist in the original RIS and check return limits
        foreach ($items as $item) {
            $item_id = intval($item['item_id']);
            $return_qty = intval($item['return_quantity']);

            if ($return_qty <= 0) {
                throw new Exception("Return quantity must be greater than zero");
            }

            // Check original stock out record with LOCK
            $checkQuery = "SELECT SO_QUANTITY FROM stock_out WHERE SO_RIS_NO = ? AND ITEM_ID = ? FOR UPDATE";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->bind_param("si", $ris_no, $item_id);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $stockOut = $checkResult->fetch_assoc();
            $checkStmt->close();

            if (!$stockOut) {
                throw new Exception("Item ID $item_id not found in original RIS $ris_no");
            }

            $original_qty = $stockOut['SO_QUANTITY'];

            // Check total returned quantity for this item
            $returnedQuery = "SELECT COALESCE(SUM(RETURN_QUANTITY), 0) as total_returned 
                             FROM item_return WHERE RIS_NO = ? AND ITEM_ID = ? FOR UPDATE";
            $returnedStmt = $conn->prepare($returnedQuery);
            $returnedStmt->bind_param("si", $ris_no, $item_id);
            $returnedStmt->execute();
            $returnedResult = $returnedStmt->get_result();
            $returnedRow = $returnedResult->fetch_assoc();
            $total_returned = $returnedRow['total_returned'] ?? 0;
            $returnedStmt->close();

            // Validate return quantity doesn't exceed remaining available
            $remaining = $original_qty - $total_returned;
            if ($return_qty > $remaining) {
                throw new Exception("Return quantity ($return_qty) exceeds remaining available quantity ($remaining). Already returned: $total_returned");
            }
        }

        // Insert return records and update inventory
        $insertQuery = "INSERT INTO item_return (RETURN_NO, RIS_NO, ITEM_ID, RETURN_QUANTITY, RETURN_REASON, RETURNED_BY, RETURN_DATE)
                       VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $insertStmt = $conn->prepare($insertQuery);
        if (!$insertStmt) {
            throw new Exception("Failed to prepare insert statement: " . $conn->error);
        }

        foreach ($items as $item) {
            $item_id = intval($item['item_id']);
            $return_qty = intval($item['return_quantity']);
            $reason = trim($item['reason']);

            if (empty($reason)) {
                throw new Exception("Return reason is required for item ID $item_id");
            }

            // Bind parameters - 6 params for 7 columns (RETURN_DATE uses NOW())
            $insertStmt->bind_param("ssiiss", $return_no, $ris_no, $item_id, $return_qty, $reason, $user_id);
            if (!$insertStmt->execute()) {
                throw new Exception("Failed to insert return record: " . $insertStmt->error);
            }

            // Update inventory (add back returned quantity)
            $updateInv = "UPDATE inventory SET INV_QUANTITY_PIECE = INV_QUANTITY_PIECE + ? WHERE ITEM_ID = ?";
            $invStmt = $conn->prepare($updateInv);
            if (!$invStmt) {
                throw new Exception("Failed to prepare inventory update: " . $conn->error);
            }
            $invStmt->bind_param("ii", $return_qty, $item_id);
            if (!$invStmt->execute()) {
                throw new Exception("Failed to update inventory: " . $invStmt->error);
            }
            $invStmt->close();

            // Insert into item_movement_history
            $movementQuery = "INSERT INTO item_movement_history 
                (ITEM_ID, MOVEMENT_TYPE, QUANTITY_PIECE, USER_ID, COUNTED_BY, DETAILS, BATCH_NO) 
                VALUES (?, 'Return', ?, ?, ?, ?, ?)";
            $movementStmt = $conn->prepare($movementQuery);
            if (!$movementStmt) {
                throw new Exception("Failed to prepare movement history: " . $conn->error);
            }

            $details = "Return: $return_qty units. RIS No: $ris_no, Return No: $return_no, Reason: $reason";
            // Bind 6 params for 7 columns (MOVEMENT_TYPE is hardcoded as 'return')
            $movementStmt->bind_param("iiiiss", $item_id, $return_qty, $user_id, $user_id, $details, $return_no);

            if (!$movementStmt->execute()) {
                throw new Exception("Failed to record movement history: " . $movementStmt->error);
            }
            $movementStmt->close();
        }
        $insertStmt->close();

        // Log activity
        $itemCount = count($items);
        logUserActivity(
            $conn,
            $user_id,
            $userName,
            $userRole,
            $ipAddress,
            $userAgent,
            'Item Return',
            'Inventory',
            $return_no,
            "Processed return for RIS $ris_no with $itemCount item(s)",
            $return_no
        );

        $conn->commit();
        echo json_encode([
            "status" => "success", 
            "message" => "Items returned successfully!",
            "return_no" => $return_no
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid action"]);
}

$conn->close();
?>