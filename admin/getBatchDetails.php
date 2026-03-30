<?php
// admin/getBatchDetails.php
session_start();
include "../API/db-connector.php";

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Unauthorized"
    ]);
    exit();
}

if (!isset($_GET['ris_no']) || empty($_GET['ris_no'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing RIS number"
    ]);
    exit();
}

$ris_no = $_GET['ris_no'];


// 1. GET BATCH HEADER INFORMATION

$headerQuery = "
    SELECT 
        so.SO_RIS_NO,
        so.OFF_ID,
        o.OFF_CODE,
        o.OFF_NAME,
        MIN(so.CREATED_AT) AS CREATED_AT,
        CONCAT(u.USER_FNAME, ' ', u.USER_LNAME) AS CREATED_BY
    FROM stock_out so
    JOIN office o ON so.OFF_ID = o.OFF_ID
    JOIN users u ON so.CREATED_BY = u.USER_ID
    WHERE so.SO_RIS_NO = ?
    GROUP BY so.SO_RIS_NO, so.OFF_ID, o.OFF_CODE, o.OFF_NAME, u.USER_FNAME, u.USER_LNAME
";

$stmt = $conn->prepare($headerQuery);
$stmt->bind_param("s", $ris_no);
$stmt->execute();
$headerResult = $stmt->get_result();

if ($headerResult->num_rows === 0) {
    echo json_encode([
        "status" => "error",
        "message" => "RIS batch not found"
    ]);
    exit();
}

$header = $headerResult->fetch_assoc();
$stmt->close();



// 2. GET ITEMS UNDER THIS RIS

$itemQuery = "
    SELECT 
        so.ITEM_ID AS item_id,
        so.SO_QUANTITY AS quantity,
        so.SO_REMARKS AS remarks,
        i.ITEM_CODE,
        i.ITEM_DESC,
        i.ITEM_UNIT
    FROM stock_out so
    JOIN item i ON so.ITEM_ID = i.ITEM_ID
    WHERE so.SO_RIS_NO = ?
    ORDER BY so.SO_ID ASC
";

$stmt = $conn->prepare($itemQuery);
$stmt->bind_param("s", $ris_no);
$stmt->execute();
$itemResult = $stmt->get_result();

$items = [];

while ($row = $itemResult->fetch_assoc()) {
    $items[] = [
        "item_id" => (int) $row['item_id'],
        "quantity" => (int) $row['quantity'],
        "remarks" => $row['remarks'],
        "ITEM_CODE" => $row['ITEM_CODE'],
        "ITEM_DESC" => $row['ITEM_DESC'],
        "ITEM_UNIT" => $row['ITEM_UNIT']
    ];
}

$stmt->close();

// 3. RETURN STRUCTURED RESPONSE

echo json_encode([
    "status" => "success",
    "ris_no" => $header['SO_RIS_NO'],
    "off_id" => (int) $header['OFF_ID'],
    "office_name" => $header['OFF_CODE'] . " - " . $header['OFF_NAME'],
    "created_at" => date('Y-m-d H:i', strtotime($header['CREATED_AT'])),
    "created_by" => $header['CREATED_BY'],
    "items" => $items
]);

$conn->close();