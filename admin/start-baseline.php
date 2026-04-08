<?php
include "../API/db-connector.php";
session_start();

if ($_SESSION['role'] !== 'Admin' || !isset($_SESSION['user_id'])) {
    echo ("Unauthorized access.");
    exit;
}

$user_id = $_SESSION['user_id']; // Fixed: Uppercase 'USER_ID'
$currentTime = time();
$today = date('Y-m-1');

// Check if baseline already exists (prevent duplicate submissions)
$check = $conn->prepare("SELECT COUNT(*) FROM baseline_inventory WHERE DATE_SNAPSHOT = ?");
$check->bind_param("s", $today);
$check->execute();
if ($check->get_result()->fetch_row()[0] > 0) {
    header("Location: admin-dashboard.php");
    exit;
}

// Fetch all active items
$items = mysqli_query($conn, "
    SELECT item.ITEM_ID, inventory.INV_QUANTITY_PIECE, item.ITEM_COST
    FROM item
    INNER JOIN inventory ON item.ITEM_ID = inventory.ITEM_ID
    WHERE item.ITEM_IS_ARCHIVED = 0
");

if (!$items) {
    die("Error fetching items: " . mysqli_error($conn));
}

// Check if items exist
if (mysqli_num_rows($items) > 0) {
    $stmt = $conn->prepare("
        INSERT INTO baseline_inventory 
        (ITEM_ID, USER_ID, SYSTEM_QUANTITY, HISTORICAL_UNIT_COST, DATE_SNAPSHOT)
        VALUES (?, ?, ?, ?, ?)
    ");

    while ($item = mysqli_fetch_assoc($items)) {
        $stmt->bind_param(
            "iiiss",
            $item['ITEM_ID'],
            $user_id,
            $item['INV_QUANTITY_PIECE'],
            $item['ITEM_COST'],
            $today
        );
        $stmt->execute();
    }

    header("Location: admin-dashboard.php");
    exit;
} else {
    header("Location: item.php?error=no_items");
    exit;
}
?>