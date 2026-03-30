<?php
// API/NotificationHelper.php

class NotificationHelper
{
    private $db;

    public function __construct($dbConnection)
    {
        $this->db = $dbConnection;
    }

    /**
     * Check inventory levels after STOCK IN operations
     * Creates notifications for OVERSTOCK conditions only
     */
    public function checkAfterStockIn($itemIds = [])
    {
        if (empty($itemIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));

        $query = "
            SELECT i.ITEM_ID, i.ITEM_DESC, i.ITEM_CODE, inv.INV_QUANTITY_PIECE, 
                   COALESCE(it.MAX_THRESHOLD, global.MAX_THRESHOLD) as MAX_THRESHOLD
            FROM inventory inv
            JOIN item i ON inv.ITEM_ID = i.ITEM_ID
            LEFT JOIN inventory_thresholds it ON i.ITEM_ID = it.ITEM_ID
            CROSS JOIN (SELECT MAX_THRESHOLD FROM inventory_thresholds WHERE ITEM_ID IS NULL LIMIT 1) global
            WHERE i.ITEM_IS_ARCHIVED = 0
            AND i.ITEM_ID IN ($placeholders)
        ";

        $stmt = $this->db->prepare($query);
        $types = str_repeat('i', count($itemIds));
        $stmt->bind_param($types, ...$itemIds);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($items as $item) {
            if ($item['MAX_THRESHOLD'] && $item['INV_QUANTITY_PIECE'] > $item['MAX_THRESHOLD']) {
                $this->createOverstockNotification($item);
            }
        }
    }

    /**
     * Check inventory levels after STOCK OUT operations
     * Creates notifications for LOW STOCK conditions only
     */
    public function checkAfterStockOut($itemIds = [])
    {
        if (empty($itemIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));

        $query = "
            SELECT i.ITEM_ID, i.ITEM_DESC, i.ITEM_CODE, inv.INV_QUANTITY_PIECE, 
                   COALESCE(it.MIN_THRESHOLD, global.MIN_THRESHOLD) as MIN_THRESHOLD
            FROM inventory inv
            JOIN item i ON inv.ITEM_ID = i.ITEM_ID
            LEFT JOIN inventory_thresholds it ON i.ITEM_ID = it.ITEM_ID
            CROSS JOIN (SELECT MIN_THRESHOLD FROM inventory_thresholds WHERE ITEM_ID IS NULL LIMIT 1) global
            WHERE i.ITEM_IS_ARCHIVED = 0
            AND i.ITEM_ID IN ($placeholders)
        ";

        $stmt = $this->db->prepare($query);
        $types = str_repeat('i', count($itemIds));
        $stmt->bind_param($types, ...$itemIds);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($items as $item) {
            if ($item['INV_QUANTITY_PIECE'] < $item['MIN_THRESHOLD']) {
                $this->createLowStockNotification($item);
            }
        }
    }

    /**
     * Create low stock notification
     */
    private function createLowStockNotification($item)
    {
        $title = "Low Stock Alert: " . $item['ITEM_DESC'];
        $message = "Item {$item['ITEM_CODE']} - {$item['ITEM_DESC']} is running low. Current quantity: {$item['INV_QUANTITY_PIECE']} pieces. Minimum threshold: {$item['MIN_THRESHOLD']} pieces.";

        $this->createNotification(
            'low_stock',
            $title,
            $message,
            $item['ITEM_ID'],
            json_encode([
                'current_quantity' => $item['INV_QUANTITY_PIECE'],
                'threshold' => $item['MIN_THRESHOLD'],
                'item_code' => $item['ITEM_CODE'],
                'triggered_by' => 'stock_out'
            ])
        );
    }

    /**
     * Create overstock notification
     */
    private function createOverstockNotification($item)
    {
        $title = "Overstock Alert: " . $item['ITEM_DESC'];
        $message = "Item {$item['ITEM_CODE']} - {$item['ITEM_DESC']} exceeds maximum threshold. Current quantity: {$item['INV_QUANTITY_PIECE']} pieces. Maximum threshold: {$item['MAX_THRESHOLD']} pieces.";

        $this->createNotification(
            'over_stock',
            $title,
            $message,
            $item['ITEM_ID'],
            json_encode([
                'current_quantity' => $item['INV_QUANTITY_PIECE'],
                'threshold' => $item['MAX_THRESHOLD'],
                'item_code' => $item['ITEM_CODE'],
                'triggered_by' => 'stock_in'
            ])
        );
    }

    /**
     * Generic notification creation
     */
    public function createNotification($type, $title, $message, $itemId = null, $data = null)
    {
        $createdBy = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1;

        $query = "
            INSERT INTO notifications 
            (NOTIF_TYPE, NOTIF_TITLE, NOTIF_MESSAGE, NOTIF_DATA, ITEM_ID, CREATED_BY, CREATED_AT, IS_READ)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), 0)
        ";

        $stmt = $this->db->prepare($query);
        $stmt->bind_param("ssssii", $type, $title, $message, $data, $itemId, $createdBy);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Get unread notifications
     */
    public function getUnreadNotifications($limit = 10)
    {
        $query = "
            SELECT n.*, i.ITEM_CODE, i.ITEM_DESC
            FROM notifications n
            LEFT JOIN item i ON n.ITEM_ID = i.ITEM_ID
            WHERE n.IS_READ = 0
            ORDER BY n.CREATED_AT DESC
            LIMIT ?
        ";

        $stmt = $this->db->prepare($query);
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $notifications = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $notifications;
    }

    /**
     * Get unread count
     */
    public function getUnreadCount()
    {
        $query = "SELECT COUNT(*) as count FROM notifications WHERE IS_READ = 0";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row['count'];
    }

    /**
     * Mark notification as read
     */
    public function markAsRead($notifId)
    {
        $query = "UPDATE notifications SET IS_READ = 1, READ_AT = NOW() WHERE NOTIF_ID = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param("i", $notifId);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    /**
     * Mark all as read
     */
    public function markAllAsRead()
    {
        $query = "UPDATE notifications SET IS_READ = 1, READ_AT = NOW() WHERE IS_READ = 0";
        $stmt = $this->db->prepare($query);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
}
?>