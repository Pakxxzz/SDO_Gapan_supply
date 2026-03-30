<?php
// admin/notifications_widget.php

// Include required files
include_once "../API/NotificationHelper.php";

// Check if database connection exists
if (!isset($conn)) {
    include "../API/db-connector.php";
}

// Create NotificationHelper instance
$notificationHelper = new NotificationHelper($conn);
$unreadNotifications = $notificationHelper->getUnreadNotifications(5);
$unreadCount = $notificationHelper->getUnreadCount();
?>

<style>
.notification-widget {
    position: relative;
    display: inline-block;
}

.notification-icon {
    position: relative;
    cursor: pointer;
    padding: 10px;
    font-size: 1.2em;
    color: #666;
    transition: color 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.notification-icon:hover {
    color: #0047bb;
}

.notification-badge {
    position: absolute;
    top: 5px;
    right: 5px;
    background: #dc3545;
    color: white;
    border-radius: 50%;
    padding: 2px 6px;
    font-size: 0.7em;
    font-weight: bold;
    min-width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid white;
}

.notification-dropdown {
    display: none;
    position: absolute;
    right: 0;
    top: 100%;
    width: 400px;
    background: white;
    border: 1px solid #e1e5e9;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    z-index: 1000;
}

.notification-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e1e5e9;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8f9fa;
    border-radius: 8px 8px 0 0;
}

.notification-title {
    font-weight: 600;
    color: #2d3748;
    margin: 0;
    font-size: 16px;
}

.mark-all-read-btn {
    background: #0047bb;
    color: white;
    border: none;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.2s ease;
}

.mark-all-read-btn:hover {
    background: #003399;
}

.notification-list {
    max-height: 400px;
    overflow-y: auto;
}

.notification-item {
    padding: 16px 20px;
    border-bottom: 1px solid #f1f3f4;
    cursor: pointer;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    transition: background 0.2s ease;
}

.notification-item:hover {
    background: #f8f9fa;
}

.notification-item:last-child {
    border-bottom: none;
}

.notification-item.low_stock {
    border-left: 4px solid #dc3545;
}

.notification-item.over_stock {
    border-left: 4px solid #ffc107;
}

.notification-item.inventory_alignment {
    border-left: 4px solid #28a745;
}

.notification-icon-type {
    flex-shrink: 0;
    margin-top: 2px;
}

.icon-low-stock {
    color: #dc3545;
    width: 18px;
    height: 18px;
}

.icon-over-stock {
    color: #ffc107;
    width: 18px;
    height: 18px;
}

.icon-alignment {
    color: #28a745;
    width: 18px;
    height: 18px;
}

.icon-default {
    color: #6c757d;
    width: 18px;
    height: 18px;
}

.notification-content {
    flex: 1;
    min-width: 0;
}

.notification-item .notification-title {
    font-weight: 600;
    margin-bottom: 4px;
    font-size: 14px;
    color: #2d3748;
}

.notification-message {
    font-size: 13px;
    color: #6c757d;
    line-height: 1.4;
    margin-bottom: 4px;
}

.notification-time {
    font-size: 11px;
    color: #9ca3af;
    font-weight: 500;
}

.no-notifications {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    text-align: center;
    color: #9ca3af;
}

.no-notif-icon {
    width: 48px;
    height: 48px;
    color: #d1d5db;
    margin-bottom: 12px;
}

.notification-footer {
    padding: 12px 20px;
    text-align: center;
    border-top: 1px solid #e1e5e9;
    background: #f8f9fa;
    border-radius: 0 0 8px 8px;
}

.view-all-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #0047bb;
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: color 0.2s ease;
}

.view-all-link:hover {
    color: #003399;
}

.view-all-link i {
    width: 14px;
    height: 14px;
}

/* Scrollbar styling */
.notification-list::-webkit-scrollbar {
    width: 6px;
}

.notification-list::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.notification-list::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.notification-list::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}
</style>

<div class="notification-widget">
    <div class="notification-icon" onclick="toggleNotifications()">
        <i data-lucide="bell"></i> <!-- Changed to bell icon -->
        <?php if ($unreadCount > 0): ?>
            <span class="notification-badge"><?php echo $unreadCount; ?></span>
        <?php endif; ?>
    </div>
    
    <div class="notification-dropdown" id="notificationDropdown">
        <div class="notification-header">
            <h4 class="notification-title">Notifications</h4>
            <?php if ($unreadCount > 0): ?>
                <button type="button" onclick="markAllAsRead()" class="mark-all-read-btn">
                    Mark all read
                </button>
            <?php endif; ?>
        </div>
        
        <div class="notification-list">
            <?php if (empty($unreadNotifications)): ?>
                <div class="notification-item no-notifications">
                    <i data-lucide="bell-off" class="no-notif-icon"></i>
                    <span>No new notifications</span>
                </div>
            <?php else: ?>
                <?php foreach ($unreadNotifications as $notification): ?>
                    <div class="notification-item <?php echo $notification['NOTIF_TYPE']; ?>" 
                         onclick="viewNotification(<?php echo $notification['NOTIF_ID']; ?>)">
                        <div class="notification-icon-type">
                            <?php if ($notification['NOTIF_TYPE'] == 'low_stock'): ?>
                                <i data-lucide="alert-triangle" class="icon-low-stock"></i>
                            <?php elseif ($notification['NOTIF_TYPE'] == 'over_stock'): ?>
                                <i data-lucide="alert-circle" class="icon-over-stock"></i>
                            <?php elseif ($notification['NOTIF_TYPE'] == 'inventory_alignment'): ?>
                                <i data-lucide="clipboard-check" class="icon-alignment"></i>
                            <?php else: ?>
                                <i data-lucide="info" class="icon-default"></i>
                            <?php endif; ?>
                        </div>
                        <div class="notification-content">
                            <div class="notification-title"><?php echo htmlspecialchars($notification['NOTIF_TITLE']); ?></div>
                            <div class="notification-message"><?php echo htmlspecialchars($notification['NOTIF_MESSAGE']); ?></div>
                            <div class="notification-time"><?php echo time_elapsed_string($notification['CREATED_AT']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <div class="notification-footer">
            <a href="notifications.php" class="view-all-link">
                <i data-lucide="list"></i>
                View All Notifications
            </a>
        </div>
    </div>
</div>



<script>
// Initialize Lucide icons when the widget loads
function initNotificationIcons() {
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
}

function toggleNotifications() {
    const dropdown = document.getElementById('notificationDropdown');
    const isVisible = dropdown.style.display === 'block';
    
    dropdown.style.display = isVisible ? 'none' : 'block';
    
    // Reinitialize icons when dropdown opens
    if (!isVisible) {
        setTimeout(initNotificationIcons, 10);
    }
}

function markAllAsRead() {
    console.log('Mark all as read clicked');
    
    fetch('mark_all_read.php', { 
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        console.log('Mark all read response:', data);
        if (data.success) {
            // Reload the page to reflect changes
            location.reload();
        } else {
            console.error('Failed to mark all as read:', data.message);
            alert('Failed to mark all notifications as read. Please try again.');
        }
    })
    .catch(error => {
        console.error('Error marking all as read:', error);
        alert('An error occurred while marking notifications as read.');
    });
}

function viewNotification(notifId) {
    console.log('Viewing notification:', notifId);
    
    // First mark as read
    fetch('mark_read.php?id=' + notifId)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Redirect to relevant page based on notification type
            // For now, redirect to inventory page
            window.location.href = 'inventory.php';
        } else {
            console.error('Failed to mark notification as read');
        }
    })
    .catch(error => {
        console.error('Error marking notification as read:', error);
    });
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('notificationDropdown');
    const icon = document.querySelector('.notification-icon');
    
    if (dropdown && !dropdown.contains(event.target) && !icon.contains(event.target)) {
        dropdown.style.display = 'none';
    }
});

// Initialize icons when page loads
document.addEventListener('DOMContentLoaded', function() {
    initNotificationIcons();
});

// Reinitialize icons when navigating (for SPAs)
document.addEventListener('ajaxComplete', initNotificationIcons);
</script>