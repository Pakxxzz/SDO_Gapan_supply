<?php
// admin/get_item_thresholds.php    
session_start();
include "../API/db-connector.php";
session_regenerate_id(true);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $json = file_get_contents("php://input");
    $data = json_decode($json, true);

    if (!isset($data['item_id']) || !is_numeric($data['item_id'])) {
        echo json_encode(["status" => "error", "message" => "Invalid item ID"]);
        exit();
    }

    $item_id = intval($data['item_id']);

    // Get thresholds for the specific item
    $thresholdQuery = "SELECT MIN_THRESHOLD, MAX_THRESHOLD 
                      FROM inventory_thresholds 
                      WHERE ITEM_ID = ?";
    $stmt = $conn->prepare($thresholdQuery);
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();

    // get item UOM
    // $getItem = "SELECT ITEM_UOM FROM item WHERE ITEM_ID = ?";
    // $itemStmt = $conn->prepare($getItem);
    // $itemStmt->bind_param("i", $item_id);
    // $itemStmt->execute();
    // $ItemResult = $itemStmt->get_result();
    // $itemData = $ItemResult->fetch_assoc();

    if ($result->num_rows > 0) {
        $threshold = $result->fetch_assoc();
        echo json_encode([
            "status" => "success",
            "min_threshold" => $threshold['MIN_THRESHOLD'],
            "max_threshold" => $threshold['MAX_THRESHOLD']
        ]);
    } else {
        // Return empty if no specific thresholds set
        echo json_encode([
            "status" => "success",
            "min_threshold" => null,
            "max_threshold" => null
        ]);
    }

    $stmt->close();
    $conn->close();
    exit();
}
?>