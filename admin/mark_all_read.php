<?php
// mark_all_read.php
session_start();
include "../API/db-connector.php";
include "../API/NotificationHelper.php";

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

try {
    $notificationHelper = new NotificationHelper($conn);
    $success = $notificationHelper->markAllAsRead();
    
    if ($success) {
        echo json_encode(['success' => true, 'message' => 'All notifications marked as read']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to mark notifications as read']);
    }
} catch (Exception $e) {
    error_log("Error in mark_all_read.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>