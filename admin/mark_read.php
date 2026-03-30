<?php
// mark_read.php
session_start();
include "../API/db-connector.php";
include "../API/NotificationHelper.php";

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

if (isset($_GET['id'])) {
    $notifId = intval($_GET['id']);
    
    try {
        $notificationHelper = new NotificationHelper($conn);
        $success = $notificationHelper->markAsRead($notifId);
        
        echo json_encode(['success' => $success]);
    } catch (Exception $e) {
        error_log("Error in mark_read.php: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No notification ID provided']);
}
?>