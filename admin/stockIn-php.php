<?php
// admin/stockIn-php.php
session_start();
include "../API/db-connector.php";
require_once "../API/NotificationHelper.php";

function generateStockInBatchNumber($conn) {
    $year = date('Y');
    $month = date('m');

    $query = "SELECT MAX(SUBSTRING_INDEX(BATCH_NO, '-', -1)) as last_number
              FROM item_movement_history
              WHERE MOVEMENT_TYPE = 'Stock In' 
                AND BATCH_NO LIKE 'SI-$year$month-%'";

    $result = $conn->query($query);
    $row = $result->fetch_assoc();

    $nextNumber = ($row['last_number'] ?? 0) + 1;

    return "SI-$year$month-" . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
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

    $ip = trim($ip);

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

    if (!isset($data['action'])) {
        echo json_encode(["status" => "error", "message" => "No action specified"]);
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

    $userName = $user['USER_FNAME'] . ' ' . $user['USER_LNAME'];
    $userRole = $user['UR_ROLE'];

    $ipAddress = getUserIp();
    $userAgent = getUserAgent();

    if ($data['action'] == "add_batch") {
        if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
            echo json_encode(["status" => "error", "message" => "No items provided"]);
            exit();
        }

        $items = $data['items'];
        $batchNo = generateStockInBatchNumber($conn);

        $conn->begin_transaction();

        try {
            $affectedItemIds = [];

            $insertQuery = "INSERT INTO stock_in (ITEM_ID, SI_QUANTITY, SI_REMARKS, CREATED_BY, LAST_UPDATED_BY) 
                           VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($insertQuery);

            foreach ($items as $item) {
                $item_id = intval($item['item_id']);
                $quantity = intval($item['quantity']);
                $remarks = trim($item['remarks']);

                if ($quantity <= 0) {
                    throw new Exception("Quantity must be greater than zero for all items.");
                }

                $stmt->bind_param("iisii", $item_id, $quantity, $remarks, $user_id, $user_id);
                if (!$stmt->execute()) {
                    throw new Exception("Failed to insert stock in record for item ID: $item_id");
                }
                $stockInId = $stmt->insert_id;
                $affectedItemIds[] = $item_id;

                $updateInv = "UPDATE inventory SET INV_QUANTITY_PIECE = INV_QUANTITY_PIECE + ?, LAST_UPDATED_BY = ? WHERE ITEM_ID = ?";
                $invStmt = $conn->prepare($updateInv);
                $invStmt->bind_param("iii", $quantity, $user_id, $item_id);
                if (!$invStmt->execute()) {
                    throw new Exception("Failed to update inventory for item ID: $item_id");
                }
                $invStmt->close();

                $movementQuery = "INSERT INTO item_movement_history 
                    (ITEM_ID, MOVEMENT_TYPE, QUANTITY_PIECE, USER_ID, COUNTED_BY, DETAILS, BATCH_NO) 
                    VALUES (?, 'Stock In', ?, ?, ?, ?, ?)";
                $movementStmt = $conn->prepare($movementQuery);
                $details = "Stock in: $quantity units. Remarks: $remarks";
                $movementStmt->bind_param("iiiiss", $item_id, $quantity, $user_id, $user_id, $details, $batchNo);
                if (!$movementStmt->execute()) {
                    throw new Exception("Failed to record movement history for item ID: $item_id");
                }
                $movementStmt->close();

                $itemQuery = "SELECT ITEM_CODE, ITEM_DESC FROM item WHERE ITEM_ID = ?";
                $itemStmt = $conn->prepare($itemQuery);
                $itemStmt->bind_param("i", $item_id);
                $itemStmt->execute();
                $itemData = $itemStmt->get_result()->fetch_assoc();
                $itemStmt->close();

                logUserActivity(
                    $conn,
                    $user_id,
                    $userName,
                    $userRole,
                    $ipAddress,
                    $userAgent,
                    'Stock In',
                    'Inventory',
                    $itemData['ITEM_DESC'],
                    "Added $quantity units to {$itemData['ITEM_CODE']} | Remarks: $remarks",
                    $batchNo
                );
            }
            $stmt->close();

            $notifier = new NotificationHelper($conn);
            $notifier->checkAfterStockIn($affectedItemIds);

            $conn->commit();
            echo json_encode([
                "status" => "success", 
                "message" => count($items) . " item(s) stocked in successfully!",
                "batch_no" => $batchNo
            ]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }
    elseif ($data['action'] == "add") {
        if (!isset($data['item_id'], $data['quantity'], $data['remarks'])) {
            echo json_encode(["status" => "error", "message" => "Missing required fields"]);
            exit();
        }

        $item_id = trim($data['item_id']);
        $quantity = trim($data['quantity']);
        $remarks = trim($data['remarks']);

        $conn->begin_transaction();
        try {
            $additem = "INSERT INTO stock_in (ITEM_ID, SI_QUANTITY, SI_REMARKS, CREATED_BY) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($additem);
            if (!$stmt)
                throw new Exception("SQL error: " . $conn->error);
            $stmt->bind_param("iisi", $item_id, $quantity, $remarks, $user_id);

            if (!$stmt->execute())
                throw new Exception("Failed to stock in: " . $stmt->error);

            $stockInId = $stmt->insert_id;
            $stmt->close();

            $updateInventory = "UPDATE inventory SET INV_QUANTITY_PIECE = INV_QUANTITY_PIECE + ?, LAST_UPDATED_BY = ? WHERE ITEM_ID = ?";
            $stmt = $conn->prepare($updateInventory);
            if (!$stmt)
                throw new Exception("SQL error: " . $conn->error);
            $stmt->bind_param("iii", $quantity, $user_id, $item_id);

            if (!$stmt->execute())
                throw new Exception("Failed to update inventory record: " . $stmt->error);
            $stmt->close();

            $movementQuery = "INSERT INTO item_movement_history 
                (ITEM_ID, MOVEMENT_TYPE, QUANTITY_PIECE, USER_ID, COUNTED_BY, DETAILS, BATCH_NO) 
                VALUES (?, 'Stock In', ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($movementQuery);
            if (!$stmt)
                throw new Exception("SQL error: " . $conn->error);

            $batchNo = 'SI-' . date('Ymd') . '-' . $stockInId;
            $details = "Stock in: $quantity units. Remarks: $remarks";
            $stmt->bind_param("iiiiss", $item_id, $quantity, $user_id, $user_id, $details, $batchNo);

            if (!$stmt->execute())
                throw new Exception("Failed to record movement history: " . $stmt->error);
            $stmt->close();

            $notifier = new NotificationHelper($conn);
            $notifier->checkAfterStockIn([$item_id]);

            $itemQuery = "SELECT ITEM_CODE, ITEM_DESC FROM item WHERE ITEM_ID = ?";
            $stmt = $conn->prepare($itemQuery);
            $stmt->bind_param("i", $item_id);
            $stmt->execute();
            $itemResult = $stmt->get_result();
            if ($itemResult && $itemData = $itemResult->fetch_assoc()) {
                $details = "Item Code: " . $itemData['ITEM_CODE'];
                $desc = $itemData['ITEM_DESC'];
            }
            $stmt->close();

            logUserActivity(
                $conn,
                $user_id,
                $userName,
                $userRole,
                $ipAddress,
                $userAgent,
                'Stock In',
                'Inventory',
                $desc,
                "Added $quantity units to $details | Remarks: $remarks",
                $batchNo
            );

            $conn->commit();
            echo json_encode(["status" => "success", "message" => "Stock record increased successfully!"]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
    }
    elseif ($data['action'] == "edit") {

        if (!isset($data['stockInId'], $data['item_id'], $data['quantity'], $data['remarks'])) {
            echo json_encode(["status" => "error", "message" => "Missing required fields"]);
            exit();
        }

        if (!$user || !password_verify($data['password'], $user['USER_PASS'])) {
            echo json_encode(["status" => "error", "message" => "Unauthorized access"]);
            exit();
        }

        $stockInId = intval($data['stockInId']);
        $item_id = intval($data['item_id']);
        $quantity = intval($data['quantity']);
        $remarks = trim($data['remarks']);

        $conn->begin_transaction();

        try {

            $oldQuery = "SELECT ITEM_ID, SI_QUANTITY FROM stock_in WHERE SI_ID = ? FOR UPDATE";
            $stmt = $conn->prepare($oldQuery);
            $stmt->bind_param("i", $stockInId);
            $stmt->execute();
            $oldData = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$oldData) {
                throw new Exception("Stock In record not found.");
            }

            $oldItemId = $oldData['ITEM_ID'];
            $oldQuantity = $oldData['SI_QUANTITY'];
            $updateQuery = "
            UPDATE stock_in 
            SET ITEM_ID = ?, 
                SI_QUANTITY = ?, 
                SI_REMARKS = ?, 
                LAST_UPDATED_BY = ?
            WHERE SI_ID = ?
        ";

            $stmt = $conn->prepare($updateQuery);
            $stmt->bind_param("iisii", $item_id, $quantity, $remarks, $user_id, $stockInId);

            if (!$stmt->execute()) {
                throw new Exception("Failed to update stock in: " . $stmt->error);
            }

            $stmt->close();

            if ($oldItemId != $item_id) {

                $stmt = $conn->prepare("
                UPDATE inventory 
                SET INV_QUANTITY_PIECE = INV_QUANTITY_PIECE - ? 
                WHERE ITEM_ID = ?
            ");
                $stmt->bind_param("ii", $oldQuantity, $oldItemId);
                $stmt->execute();
                $stmt->close();

                $movementQuery = "INSERT INTO item_movement_history 
                    (ITEM_ID, MOVEMENT_TYPE, QUANTITY_PIECE, USER_ID, COUNTED_BY, DETAILS, BATCH_NO) 
                    VALUES (?, 'Adjustment', ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($movementQuery);
                if (!$stmt)
                    throw new Exception("SQL error: " . $conn->error);

                $batchNo = 'ADJ-' . date('Ymd') . '-' . $stockInId;
                $details = "Stock removed from item due to edit. Old Item ID: $oldItemId";
                $stmt->bind_param("iiiiss", $oldItemId, $oldQuantity, $user_id, $user_id, $details, $batchNo);

                if (!$stmt->execute())
                    throw new Exception("Failed to record movement history: " . $stmt->error);
                $stmt->close();

                $stmt = $conn->prepare("
                UPDATE inventory 
                SET INV_QUANTITY_PIECE = INV_QUANTITY_PIECE + ?, 
                    LAST_UPDATED_BY = ?
                WHERE ITEM_ID = ?
            ");
                $stmt->bind_param("iii", $quantity, $user_id, $item_id);
                $stmt->execute();
                $stmt->close();

                $movementQuery = "INSERT INTO item_movement_history 
                    (ITEM_ID, MOVEMENT_TYPE, QUANTITY_PIECE, USER_ID, COUNTED_BY, DETAILS, BATCH_NO) 
                    VALUES (?, 'Adjustment', ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($movementQuery);
                if (!$stmt)
                    throw new Exception("SQL error: " . $conn->error);

                $details = "Stock added to new item due to edit. New Item ID: $item_id";
                $stmt->bind_param("iiiiss", $item_id, $quantity, $user_id, $user_id, $details, $batchNo);

                if (!$stmt->execute())
                    throw new Exception("Failed to record movement history: " . $stmt->error);
                $stmt->close();

            } else {

                $difference = $quantity - $oldQuantity;

                $stmt = $conn->prepare("
                UPDATE inventory 
                SET INV_QUANTITY_PIECE = INV_QUANTITY_PIECE + ?, 
                    LAST_UPDATED_BY = ?
                WHERE ITEM_ID = ?
            ");
                $stmt->bind_param("iii", $difference, $user_id, $item_id);
                $stmt->execute();
                $stmt->close();

                $movementQuery = "INSERT INTO item_movement_history 
                    (ITEM_ID, MOVEMENT_TYPE, QUANTITY_PIECE, USER_ID, COUNTED_BY, DETAILS, BATCH_NO) 
                    VALUES (?, 'Adjustment', ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($movementQuery);
                if (!$stmt)
                    throw new Exception("SQL error: " . $conn->error);

                $batchNo = 'ADJ-' . date('Ymd') . '-' . $stockInId;
                $details = "Stock adjusted: Old qty $oldQuantity, New qty $quantity. Remarks: $remarks";
                $stmt->bind_param("iiiiss", $item_id, $quantity, $user_id, $user_id, $details, $batchNo);

                if (!$stmt->execute())
                    throw new Exception("Failed to record movement history: " . $stmt->error);
                $stmt->close();
            }

            $notifier = new NotificationHelper($conn);
            $notifier->checkAfterStockIn([$item_id]);

            $itemQuery = "SELECT ITEM_CODE, ITEM_DESC FROM item WHERE ITEM_ID = ?";
            $stmt = $conn->prepare($itemQuery);
            $stmt->bind_param("i", $item_id);
            $stmt->execute();
            $itemData = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $itemCode = $itemData['ITEM_CODE'] ?? 'Unknown';
            $desc = $itemData['ITEM_DESC'] ?? 'Unknown Item';

            logUserActivity(
                $conn,
                $user_id,
                $userName,
                $userRole,
                $ipAddress,
                $userAgent,
                'Stock In Adjusted',
                'Inventory',
                $desc,
                "Stock No.: $itemCode | Old Qty: $oldQuantity | New Qty: $quantity | Remarks: $remarks",
                $batchNo
            );
            $conn->commit();

            echo json_encode([
                "status" => "success",
                "message" => "Stock In updated successfully!"
            ]);

        } catch (Exception $e) {

            $conn->rollback();

            echo json_encode([
                "status" => "error",
                "message" => $e->getMessage()
            ]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Invalid action"]);
    }

    $conn->close();
    exit();
}
?>