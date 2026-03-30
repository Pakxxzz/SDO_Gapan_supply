<?php
include "../API/db-connector.php";
header('Content-Type: application/json');

// Get current year and month
$currentMonth = date('Y-m');

// Check if baseline exists for the current month
$sql = "SELECT COUNT(*) AS count FROM baseline_inventory WHERE DATE_FORMAT(DATE_SNAPSHOT, '%Y-%m') = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $currentMonth);
$stmt->execute();
$baselineResult = $stmt->get_result()->fetch_assoc();

// Check if there are any active items
$itemsSql = "SELECT COUNT(*) AS count 
            FROM item 
            INNER JOIN inventory ON item.ITEM_ID = inventory.ITEM_ID 
            WHERE item.ITEM_IS_ARCHIVED = 0";
$itemsStmt = $conn->prepare($itemsSql);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result()->fetch_assoc();

echo json_encode([
    "inventory_exists" => ($baselineResult['count'] > 0),
    "items_exist" => ($itemsResult['count'] > 0),
    "month" => $currentMonth
]);
?>