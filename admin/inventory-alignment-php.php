<?php
session_start();
include "../API/db-connector.php";
session_regenerate_id(true);

header('Content-Type: application/json');

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

// Verify request method
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

// Verify session
if (!isset($_SESSION['user_id'])) {
    echo json_encode(["status" => "error", "message" => "Session expired"]);
    exit();
}

// Get user details (without password verification yet)
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

if (!$user) {
    echo json_encode(["status" => "error", "message" => "User not found"]);
    exit();
}

// Only verify password for specific actions that require it
if (isset($data['password']) && !password_verify($data['password'], $user['USER_PASS'])) {
    echo json_encode(["status" => "error", "message" => "Unauthorized access - Invalid password"]);
    exit();
}

$userName = $user['USER_FNAME'] . ' ' . $user['USER_LNAME'];
$userRole = $user['UR_ROLE'];

$ipAddress = getUserIp();
$userAgent = getUserAgent();

$conn->begin_transaction();

try {
    if ($data['action'] === 'start_alignment') {
        // Check if password was provided for this action
        if (!isset($data['password'])) {
            throw new Exception("Password required to start alignment");
        }

        // ✅ Check if there's already an open alignment batch
        $checkQuery = "SELECT COUNT(*) AS cnt 
                       FROM masterdata 
                       WHERE STATUS IN ('Pending', 'Counting', 'Approved', 'Counted')";
        $check = $conn->query($checkQuery)->fetch_assoc();

        if ($check['cnt'] > 0) {
            throw new Exception("There is already an active alignment batch in progress. Please complete it first.");
        }

        // Create unique batch number - always Month_end as requested
        $batchNo = "ALN-" . date("YmdHis");
        $alignmentType = 'Month_end'; // Force Month_end always

        // ✅ Copy ALL inventory items into masterdata (no selection needed)
        $query = "INSERT INTO masterdata 
                    (BATCH, ITEM_ID, SYSTEM_QUANTITY, ACTUAL_CASE_QUANTITY, REMAIN_PIECE, 
                     TOTAL_QUANTITY, DIFFERENCE, STATUS, ALIGNMENT_TYPE, CREATED_AT, USER_ID, CHECKER_ID) 
                  SELECT ?, i.ITEM_ID, inv.INV_QUANTITY_PIECE, 0, 0, 0, 0, 'Counting', ?, NOW(), ?, ?
                  FROM inventory inv
                  JOIN item i ON inv.ITEM_ID = i.ITEM_ID 
                  WHERE i.ITEM_IS_ARCHIVED = 0";

        $stmt = $conn->prepare($query);
        $checkerId = $user_id;
        $stmt->bind_param("ssii", $batchNo, $alignmentType, $user_id, $checkerId);
        $stmt->execute();
        $inserted = $stmt->affected_rows;
        $stmt->close();

        if ($inserted <= 0) {
            throw new Exception("No inventory records found to align");
        }

        // Log user activity
        $userFullName = $user['USER_FNAME'] . ' ' . $user['USER_LNAME'];
        logUserActivity(
            $conn,
            $user_id,
            $userName,
            $userRole,
            $ipAddress,
            $userAgent,
            "Started Inventory Alignment",
            "Inventory Alignment",
            "$batchNo",
            "Created new $alignmentType alignment batch $batchNo with $inserted items",
            $batchNo
        );

        $conn->commit();

        echo json_encode([
            "status" => "success",
            "message" => "Inventory alignment started successfully. Batch: $batchNo ($inserted items)"
        ]);

    } elseif ($data['action'] === 'post_batch') {
        // ✅ Post batch to inventory (respect INVENTORY_IMPACT flag)
        if (!isset($data['password'])) {
            throw new Exception("Password required for batch submission");
        }

        if (!isset($data['batch'])) {
            throw new Exception("Batch number is required for posting");
        }

        $batch = $data['batch'];

        // ✅ FIRST: Get all items in this batch BEFORE updating inventory
        $getBatchItemsQuery = "SELECT md.*, i.VEN_ID 
                          FROM masterdata md 
                          JOIN item i ON md.ITEM_ID = i.ITEM_ID 
                          WHERE md.BATCH = ? AND md.STATUS = 'Counted'";
        $stmt = $conn->prepare($getBatchItemsQuery);
        $stmt->bind_param("s", $batch);
        $stmt->execute();
        $batchItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($batchItems)) {
            throw new Exception("No approved items found in this batch");
        }

        // ✅ Update inventory ONLY for items with INVENTORY_IMPACT = 1
        $updateInventoryQuery = "UPDATE inventory inv
                            JOIN masterdata md ON inv.ITEM_ID = md.ITEM_ID
                            SET inv.INV_QUANTITY_PIECE = md.TOTAL_QUANTITY,
                                inv.LAST_UPDATED_BY = ?,
                                inv.UPDATE_AT = NOW()
                            WHERE md.BATCH = ? 
                              AND md.STATUS = 'Counted'
                              AND md.INVENTORY_IMPACT = 1";

        $stmt = $conn->prepare($updateInventoryQuery);
        $stmt->bind_param("is", $_SESSION['user_id'], $batch);
        $stmt->execute();
        $updatedInvRows = $stmt->affected_rows;
        $stmt->close();

        // ✅ Record movement history ONLY for items that changed inventory
        $movementCount = 0;
        foreach ($batchItems as $item) {
            // Only log if inventory impact is true AND there's a difference
            if ($item['INVENTORY_IMPACT'] == 1 && $item['DIFFERENCE'] != 0) {
                $movementType = 'Aligned';
                $quantityPiece = $item['DIFFERENCE'];

                $historyQuery = "INSERT INTO item_movement_history 
                            (ITEM_ID, MOVEMENT_TYPE, QUANTITY_PIECE, MOVEMENT_DATE, 
                             BATCH_NO, USER_ID, COUNTED_BY, DETAILS)
                            VALUES (?, ?, ?, NOW(), ?, ?, ?, ?)";

                $stmt = $conn->prepare($historyQuery);
                $details = "Inventory alignment: System {$item['SYSTEM_QUANTITY']} vs Counted {$item['TOTAL_QUANTITY']}";
                $stmt->bind_param(
                    "isissss",
                    $item['ITEM_ID'],
                    $movementType,
                    $quantityPiece,
                    $batch,
                    $user_id,
                    $item['CHECKER_ID'],
                    $details
                );
                $stmt->execute();
                $movementCount++;
                $stmt->close();
            }
        }

        // Update batch status to Completed
        $completeQuery = "UPDATE masterdata SET STATUS = 'Completed', UPDATED_AT = NOW() WHERE BATCH = ?";
        $stmt = $conn->prepare($completeQuery);
        $stmt->bind_param("s", $batch);
        $stmt->execute();
        $stmt->close();

        // Log user activity
        $userFullName = $user['USER_FNAME'] . ' ' . $user['USER_LNAME'];
        logUserActivity(
            $conn,
            $user_id,
            $userName,
            $userRole,
            $ipAddress,
            $userAgent,
            "Posted Inventory Alignment",
            "Inventory Alignment",
            "$batch",
            "Posted inventory alignment batch $batch: $updatedInvRows inventory updates, $movementCount movement records",
            $batch
        );

        $conn->commit();

        echo json_encode([
            "status" => "success",
            "message" => "Batch $batch posted. $updatedInvRows inventory items updated. $movementCount movement records created."
        ]);
        exit;
    } elseif ($data['action'] === 'edit_item') {
        // ✅ Edit individual item in alignment
        if (!isset($data['md_id']) || !isset($data['actual_cases'])) {
            throw new Exception("Missing required fields for editing item");
        }

        // Check if inventory_impact flag exists
        $inventoryImpact = isset($data['inventory_impact']) ? (int) $data['inventory_impact'] : 1;
        $countedOnly = isset($data['counted_only']) ? (int) $data['counted_only'] : 0;

        // Get item details
        $getItemQuery = "SELECT m.SYSTEM_QUANTITY, m.BATCH, m.TOTAL_QUANTITY 
                    FROM masterdata m 
                    WHERE m.MD_ID = ?";
        $stmt = $conn->prepare($getItemQuery);
        $stmt->bind_param("i", $data['md_id']);
        $stmt->execute();
        $itemData = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$itemData) {
            throw new Exception("Item not found in alignment");
        }

        // Calculate based on mode
        $systemQuantity = $itemData['SYSTEM_QUANTITY'];
        $actualQty = intval($data['actual_cases']);

        if ($inventoryImpact == 0) {
            // COUNT ONLY MODE: Don't change system quantity
            $totalQuantity = $systemQuantity; // Keep system quantity unchanged
            $difference = 0; // No difference for inventory
            $countedOnly = $actualQty; // Store counted value separately
        } else {
            // INVENTORY UPDATE MODE: Update actual inventory
            $totalQuantity = $actualQty;
            $difference = $totalQuantity - $systemQuantity;
            $countedOnly = 0; // No separate counted value
        }

        // Update the item
        $updateQuery = "UPDATE masterdata 
                    SET ACTUAL_CASE_QUANTITY = ?, 
                        TOTAL_QUANTITY = ?, 
                        COUNTED_ONLY = ?,
                        INVENTORY_IMPACT = ?,
                        DIFFERENCE = ?, 
                        STATUS = 'Counted',
                        UPDATED_AT = NOW()
                    WHERE MD_ID = ?";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("iiiiii", $actualQty, $totalQuantity, $countedOnly, $inventoryImpact, $difference, $data['md_id']);
        $stmt->execute();
        $updated = $stmt->affected_rows;
        $stmt->close();

        if ($updated <= 0) {
            throw new Exception("Failed to update item. Item may not exist.");
        }

        // Log user activity
        $modeText = ($inventoryImpact == 0) ? "COUNT ONLY" : "INVENTORY UPDATE";
        $userFullName = $user['USER_FNAME'] . ' ' . $user['USER_LNAME'];
        logUserActivity(
            $conn,
            $user_id,
            $userName,
            $userRole,
            $ipAddress,
            $userAgent,
            "Edited Alignment Item",
            "Inventory Alignment",
            $itemData['BATCH'],
            "Updated item in batch {$itemData['BATCH']}: $actualQty pieces ($modeText mode)",
            $itemData['BATCH']
        );

        $conn->commit();

        echo json_encode([
            "status" => "success",
            "message" => "Item updated successfully. Mode: " . ($inventoryImpact == 0 ? "Item not found" : "Inventory Update")
        ]);
    } else {
        throw new Exception("Invalid action specified");
    }

} catch (Exception $e) {
    $conn->rollback();
    error_log("Error in alignment process: " . $e->getMessage());
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}

$conn->close();
?>