<?php
session_start();
include "../API/db-connector.php";

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit();
}

if (!isset($_GET['ris_no']) || empty($_GET['ris_no'])) {
    echo json_encode(["status" => "error", "message" => "Missing RIS number"]);
    exit();
}

$ris_no = $_GET['ris_no'];

$query = "
    SELECT 
        ir.RETURN_NO,
        ir.ITEM_ID,
        ir.RETURN_QUANTITY,
        ir.RETURN_REASON,
        DATE_FORMAT(ir.RETURN_DATE, '%Y-%m-%d %H:%i') as RETURN_DATE,
        i.ITEM_CODE,
        i.ITEM_DESC,
        CONCAT(u.USER_FNAME, ' ', u.USER_LNAME) as RETURNED_BY_NAME
    FROM item_return ir
    JOIN item i ON ir.ITEM_ID = i.ITEM_ID
    JOIN users u ON ir.RETURNED_BY = u.USER_ID
    WHERE ir.RIS_NO = ?
    ORDER BY ir.RETURN_DATE DESC
";

$stmt = $conn->prepare($query);
if (!$stmt) {
    echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
    exit();
}

$stmt->bind_param("s", $ris_no);
$stmt->execute();
$result = $stmt->get_result();

$returns = [];
while ($row = $result->fetch_assoc()) {
    $returns[] = [
        'RETURN_NO' => $row['RETURN_NO'],
        'ITEM_ID' => $row['ITEM_ID'],
        'RETURN_QUANTITY' => $row['RETURN_QUANTITY'],
        'RETURN_REASON' => $row['RETURN_REASON'],
        'RETURN_DATE' => $row['RETURN_DATE'],
        'ITEM_CODE' => $row['ITEM_CODE'],
        'ITEM_DESC' => $row['ITEM_DESC'],
        'RETURNED_BY_NAME' => $row['RETURNED_BY_NAME']
    ];
}

echo json_encode([
    "status" => "success",
    "returns" => $returns
]);

$conn->close();
?>