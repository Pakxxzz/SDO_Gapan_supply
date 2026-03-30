<?php
// admin/notifications.php
include "./sidebar.php";
session_regenerate_id(true);
include "../API/db-connector.php";

// Include NotificationHelper
include_once "../API/NotificationHelper.php";

$notificationHelper = new NotificationHelper($conn);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_all_read'])) {
        $notificationHelper->markAllAsRead();
        $_SESSION['success_message'] = 'All notifications marked as read';
        header('Location: notifications.php');
        exit();
    } elseif (isset($_POST['mark_read']) && isset($_POST['notif_id'])) {
        $notificationHelper->markAsRead($_POST['notif_id']);
        $_SESSION['success_message'] = 'Notification marked as read';
        header('Location: notifications.php');
        exit();
    } elseif (isset($_POST['clear_all'])) {
        $clearQuery = "DELETE FROM notifications WHERE IS_READ = 1";
        $conn->query($clearQuery);
        $_SESSION['success_message'] = 'Read notifications cleared';
        header('Location: notifications.php');
        exit();
    }
}

// Get filter parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$type = isset($_GET['type']) ? $_GET['type'] : 'all';

// Pagination settings
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Build WHERE clause
$whereConditions = [];
$params = [];
$types = '';

if ($filter === 'unread') {
    $whereConditions[] = "n.IS_READ = 0";
} elseif ($filter === 'read') {
    $whereConditions[] = "n.IS_READ = 1";
}

if ($type !== 'all') {
    $whereConditions[] = "n.NOTIF_TYPE = ?";
    $params[] = $type;
    $types .= 's';
}

$whereClause = $whereConditions ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Get total count
$countQuery = "SELECT COUNT(*) as total FROM notifications n $whereClause";
$countStmt = $conn->prepare($countQuery);
if ($params) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalResult = $countStmt->get_result()->fetch_assoc();
$totalNotifications = $totalResult['total'];
$countStmt->close();

$totalPages = ceil($totalNotifications / $limit);

// Get notifications with pagination
$query = "SELECT n.*, i.ITEM_CODE, i.ITEM_DESC 
          FROM notifications n 
          LEFT JOIN item i ON n.ITEM_ID = i.ITEM_ID 
          $whereClause 
          ORDER BY n.CREATED_AT DESC 
          LIMIT ? OFFSET ?";

$paginationParams = $params;
$paginationParams[] = $limit;
$paginationParams[] = $offset;
$paginationTypes = $types . 'ii';

$stmt = $conn->prepare($query);
if ($paginationParams) {
    $stmt->bind_param($paginationTypes, ...$paginationParams);
}
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get notification statistics
$statsQuery = "SELECT 
    COUNT(*) as total,
    SUM(IS_READ = 0) as unread,
    SUM(IS_READ = 1) as read_count,
    SUM(NOTIF_TYPE = 'low_stock') as low_stock,
    SUM(NOTIF_TYPE = 'over_stock') as over_stock,
    SUM(NOTIF_TYPE = 'inventory_alignment') as alignment
    FROM notifications";

$statsResult = $conn->query($statsQuery);
$stats = $statsResult->fetch_assoc();

// Generate page links
function generatePageLinks($totalPages, $page, $limit, $filter = '', $type = '')
{
    $links = [];
    $filterParam = !empty($filter) ? "&filter=" . urlencode($filter) : '';
    $typeParam = !empty($type) ? "&type=" . urlencode($type) : '';

    if ($totalPages <= 5) {
        for ($i = 1; $i <= $totalPages; $i++) {
            $links[] = $i;
        }
    } else {
        if ($page <= 3) {
            $links = [1, 2, 3, 4, "...", $totalPages];
        } elseif ($page >= $totalPages - 2) {
            $links = [1, "...", $totalPages - 3, $totalPages - 2, $totalPages - 1, $totalPages];
        } else {
            $links = [1, "...", $page - 1, $page, $page + 1, "...", $totalPages];
        }
    }

    foreach ($links as &$link) {
        if ($link !== "...") {
            $link = [
                'page' => $link,
                'url' => "?page=$link&limit=$limit$filterParam$typeParam"
            ];
        } else {
            $link = [
                'page' => "...",
                'url' => "#"
            ];
        }
    }

    return $links;
}

$pageLinks = generatePageLinks($totalPages, $page, $limit, $filter, $type);

$urlParams = '';
if (!empty($filter)) {
    $urlParams .= "&filter=" . urlencode($filter);
}
if (!empty($type)) {
    $urlParams .= "&type=" . urlencode($type);
}
if ($limit != 10) {
    $urlParams .= "&limit=$limit";
}
?>
<?php
// Helper functions
function getNotificationColor($type)
{
    switch ($type) {
        case 'low_stock':
            return '#dc3545';
        case 'over_stock':
            return '#ffc107';
        case 'inventory_alignment':
            return '#28a745';
        case 'system_alert':
            return '#6f42c1';
        default:
            return '#6c757d';
    }
}

function getNotificationIcon($type, $isRead = false)
{
    $colorClass = $isRead ? 'text-gray-400' : 'text-' . getColorClass($type);

    switch ($type) {
        case 'low_stock':
            return '<i data-lucide="alert-triangle" class="w-5 h-5 sm:w-6 sm:h-6 ' . $colorClass . '"></i>';
        case 'over_stock':
            return '<i data-lucide="alert-circle" class="w-5 h-5 sm:w-6 sm:h-6 ' . $colorClass . '"></i>';
        case 'inventory_alignment':
            return '<i data-lucide="clipboard-check" class="w-5 h-5 sm:w-6 sm:h-6 ' . $colorClass . '"></i>';
        case 'system_alert':
            return '<i data-lucide="alert-circle" class="w-5 h-5 sm:w-6 sm:h-6 ' . $colorClass . '"></i>';
        default:
            return '<i data-lucide="info" class="w-5 h-5 sm:w-6 sm:h-6 ' . $colorClass . '"></i>';
    }
}

function getNotificationIconMobile($type, $isRead = false)
{
    $colorClass = $isRead ? 'text-gray-400' : 'text-' . getColorClass($type);

    switch ($type) {
        case 'low_stock':
            return '<i data-lucide="alert-triangle" class="w-5 h-5 ' . $colorClass . '"></i>';
        case 'over_stock':
            return '<i data-lucide="alert-circle" class="w-5 h-5 ' . $colorClass . '"></i>';
        case 'inventory_alignment':
            return '<i data-lucide="clipboard-check" class="w-5 h-5 ' . $colorClass . '"></i>';
        case 'system_alert':
            return '<i data-lucide="alert-circle" class="w-5 h-5 ' . $colorClass . '"></i>';
        default:
            return '<i data-lucide="info" class="w-5 h-5 ' . $colorClass . '"></i>';
    }
}

function getColorClass($type)
{
    switch ($type) {
        case 'low_stock':
            return 'red-500';
        case 'over_stock':
            return 'yellow-500';
        case 'inventory_alignment':
            return 'green-500';
        case 'system_alert':
            return 'purple-500';
        default:
            return 'gray-500';
    }
}

function getTypeBadgeClass($type)
{
    switch ($type) {
        case 'low_stock':
            return 'bg-red-100 text-red-800';
        case 'over_stock':
            return 'bg-yellow-100 text-yellow-800';
        case 'inventory_alignment':
            return 'bg-green-100 text-green-800';
        case 'system_alert':
            return 'bg-purple-100 text-purple-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
}

function time_elapsed_string($datetime, $full = false)
{
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full)
        $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Advect Marketing Corporation</title>
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="inventory.css?id=<?= time() ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @media (max-width: 640px) {
            .notification-item {
                padding: 1rem;
            }

            .notification-item .flex.items-start {
                flex-direction: column;
            }

            .notification-item .flex-shrink-0 {
                margin-bottom: 0.5rem;
            }

            .notification-item .flex.items-start.justify-between {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 0.75rem;
            }

            .notification-item .flex.flex-wrap.items-center {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .notification-item .flex.flex-col.gap-2 {
                width: 100%;
                flex-direction: row;
                justify-content: flex-end;
            }

            .stat-card {
                padding: 0.75rem;
            }

            .stat-card .text-2xl {
                font-size: 1.25rem;
            }
        }

        @media (min-width: 641px) and (max-width: 768px) {
            .notification-item .flex.items-start.justify-between {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 0.75rem;
            }

            .notification-item .flex.flex-col.gap-2 {
                width: 100%;
                flex-direction: row;
                justify-content: flex-end;
            }
        }

        .stat-card {
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .filter-select {
            width: 100%;
            min-width: 150px;
        }

        @media (max-width: 480px) {
            .filter-select {
                min-width: 100%;
            }
        }

        .pagination-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 0.5rem;
        }

        .pagination-container::-webkit-scrollbar {
            height: 4px;
        }

        .pagination-container::-webkit-scrollbar-thumb {
            background-color: #cbd5e0;
            border-radius: 4px;
        }
    </style>
</head>

<body>
    <div class="content w-full lg:w-[calc(100vw-60px)] mx-auto overflow-x-auto p-4 sm:p-6 lg:p-8">
        <!-- Header -->
        <div class="bg-white shadow-md rounded-lg p-4 sm:p-6 mb-4 sm:mb-6">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h2 class="font-bold text-xl sm:text-2xl text-[#0047bb] mb-1 sm:mb-2">Notifications</h2>
                    <p class="text-sm sm:text-base text-gray-600">Manage and view all system notifications</p>
                </div>
                <div class="flex flex-wrap gap-2 w-full sm:w-auto">
                    <form method="POST" class="flex-1 sm:flex-none">
                        <button type="submit" name="mark_all_read"
                            class="w-full sm:w-auto bg-blue-500 hover:bg-blue-600 text-white px-3 sm:px-4 py-2 rounded-md text-sm font-medium transition-colors">
                            <i data-lucide="check-circle" class="w-4 h-4 inline mr-1"></i>
                            <span class="inline">Mark All Read</span>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Statistics Cards - Responsive Grid -->
            <div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mt-4 sm:mt-6">
                <div class="stat-card bg-blue-50 border border-blue-200 rounded-lg p-3 sm:p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs sm:text-sm font-medium text-blue-800">Total</p>
                            <p class="text-lg sm:text-2xl font-bold text-blue-900"><?= $stats['total'] ?></p>
                        </div>
                        <i data-lucide="bell" class="w-6 h-6 sm:w-8 sm:h-8 text-blue-600"></i>
                    </div>
                </div>
                <div class="stat-card bg-orange-50 border border-orange-200 rounded-lg p-3 sm:p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs sm:text-sm font-medium text-orange-800">Over Stock</p>
                            <p class="text-lg sm:text-2xl font-bold text-orange-900"><?= $stats['over_stock'] ?></p>
                        </div>
                        <i data-lucide="alert-circle" class="w-6 h-6 sm:w-8 sm:h-8 text-orange-600"></i>
                    </div>
                </div>
                <div class="stat-card bg-red-50 border border-red-200 rounded-lg p-3 sm:p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs sm:text-sm font-medium text-red-800">Low Stock</p>
                            <p class="text-lg sm:text-2xl font-bold text-red-900"><?= $stats['low_stock'] ?></p>
                        </div>
                        <i data-lucide="alert-triangle" class="w-6 h-6 sm:w-8 sm:h-8 text-red-600"></i>
                    </div>
                </div>
                <div class="stat-card bg-purple-50 border border-purple-200 rounded-lg p-3 sm:p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs sm:text-sm font-medium text-purple-800">Unread</p>
                            <p class="text-lg sm:text-2xl font-bold text-purple-900"><?= $stats['unread'] ?></p>
                        </div>
                        <i data-lucide="bell-ring" class="w-6 h-6 sm:w-8 sm:h-8 text-purple-600"></i>
                    </div>
                </div>
            </div>

            <!-- Filters Section - Fully Responsive -->
            <div class="mt-4 sm:mt-6">
                <div class="flex flex-col lg:flex-row lg:items-end gap-4">
                    <!-- Filter Controls -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:flex lg:flex-1 gap-3 sm:gap-4">
                        <!-- Status Filter -->
                        <div class="w-full lg:w-48">
                            <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select onchange="updateFilters()" id="statusFilter"
                                class="filter-select border rounded-md px-3 py-2 text-sm w-full focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All Notifications</option>
                                <option value="unread" <?= $filter === 'unread' ? 'selected' : '' ?>>Unread Only</option>
                                <option value="read" <?= $filter === 'read' ? 'selected' : '' ?>>Read Only</option>
                            </select>
                        </div>

                        <!-- Type Filter -->
                        <div class="w-full lg:w-48">
                            <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-1">Type</label>
                            <select onchange="updateFilters()" id="typeFilter"
                                class="filter-select border rounded-md px-3 py-2 text-sm w-full focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All Types</option>
                                <option value="low_stock" <?= $type === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                                <option value="over_stock" <?= $type === 'over_stock' ? 'selected' : '' ?>>Over Stock
                                </option>
                                <option value="inventory_alignment" <?= $type === 'inventory_alignment' ? 'selected' : '' ?>>
                                    Inventory Alignment
                                </option>
                            </select>
                        </div>

                        <!-- Results per page -->
                        <div class="w-full lg:w-36">
                            <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-1">Show</label>
                            <form method="GET" id="limitForm" class="inline w-full">
                                <?php if (!empty($filter)): ?>
                                    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                                <?php endif; ?>
                                <?php if (!empty($type)): ?>
                                    <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                                <?php endif; ?>
                                <select onchange="this.form.submit()" name="limit"
                                    class="border rounded-md px-3 py-2 text-sm w-full focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10 per page</option>
                                    <option value="20" <?= $limit == 20 ? 'selected' : '' ?>>20 per page</option>
                                    <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50 per page</option>
                                </select>
                            </form>
                        </div>
                    </div>

                    <!-- Results Info -->
                    <div class="text-xs sm:text-sm text-gray-600 lg:text-right lg:ml-auto lg:self-end">
                        Showing
                        <span class="font-medium"><?= $totalNotifications > 0 ? $offset + 1 : 0 ?></span>
                        <span class="font-medium">- <?= min($offset + $limit, $totalNotifications) ?></span>
                        of
                        <span class="font-medium"><?= $totalNotifications ?></span>
                        notifications
                    </div>
                </div>
            </div>
        </div>

        <!-- Notifications List -->
        <div class="bg-white shadow-md rounded-lg overflow-hidden">
            <?php if (empty($notifications)): ?>
                <div class="text-center py-8 sm:py-12 px-4">
                    <i data-lucide="bell-off" class="w-12 h-12 sm:w-16 sm:h-16 text-gray-300 mx-auto mb-3 sm:mb-4"></i>
                    <h3 class="text-base sm:text-lg font-medium text-gray-900 mb-1 sm:mb-2">No notifications found</h3>
                    <p class="text-sm sm:text-base text-gray-500">There are no notifications matching your current filters.
                    </p>
                </div>
            <?php else: ?>
                <div class="divide-y divide-gray-200">
                    <?php foreach ($notifications as $notification): ?>
                        <div class="notification-item p-4 sm:p-6 hover:bg-gray-50 transition-colors <?= $notification['IS_READ'] ? 'bg-gray-50' : 'bg-white' ?>"
                            style="border-left: 4px solid <?= getNotificationColor($notification['NOTIF_TYPE']) ?>">

                            <div class="flex flex-col sm:flex-row sm:items-start gap-3 sm:gap-4">
                                <!-- Icon -->
                                <div class="flex-shrink-0 hidden sm:block">
                                    <?= getNotificationIcon($notification['NOTIF_TYPE'], $notification['IS_READ']) ?>
                                </div>
                                <div class="flex-shrink-0 sm:hidden">
                                    <?= getNotificationIconMobile($notification['NOTIF_TYPE'], $notification['IS_READ']) ?>
                                </div>

                                <!-- Content -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3 sm:gap-4">
                                        <div class="flex-1">
                                            <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-1">
                                                <?= htmlspecialchars($notification['NOTIF_TITLE']) ?>
                                            </h3>
                                            <p class="text-sm sm:text-base text-gray-600 mb-2">
                                                <?= htmlspecialchars($notification['NOTIF_MESSAGE']) ?>
                                            </p>

                                            <!-- Meta Information - Responsive -->
                                            <div class="flex flex-wrap gap-2 sm:gap-4 text-xs sm:text-sm text-gray-500">
                                                <span class="inline-flex items-center gap-1">
                                                    <i data-lucide="clock" class="w-3 h-3 sm:w-4 sm:h-4"></i>
                                                    <?= time_elapsed_string($notification['CREATED_AT']) ?>
                                                </span>

                                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium 
                                                           <?= getTypeBadgeClass($notification['NOTIF_TYPE']) ?>">
                                                    <?= ucfirst(str_replace('_', ' ', $notification['NOTIF_TYPE'])) ?>
                                                </span>

                                                <?php if ($notification['ITEM_DESC']): ?>
                                                    <span class="inline-flex items-center gap-1">
                                                        <i data-lucide="package" class="w-3 h-3 sm:w-4 sm:h-4"></i>
                                                        <span class="truncate max-w-[150px] sm:max-w-[200px]">
                                                            <?= htmlspecialchars($notification['ITEM_DESC']) ?>
                                                        </span>
                                                    </span>
                                                <?php endif; ?>

                                                <?php if (!$notification['IS_READ']): ?>
                                                    <span
                                                        class="inline-flex items-center gap-1 px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-xs font-medium">
                                                        <i data-lucide="circle" class="w-2 h-2 fill-current"></i>
                                                        Unread
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Actions -->
                                        <div class="flex flex-row sm:flex-col gap-2 justify-end">
                                            <?php if (!$notification['IS_READ']): ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="notif_id" value="<?= $notification['NOTIF_ID'] ?>">
                                                    <button type="submit" name="mark_read"
                                                        class="inline-flex items-center gap-1 px-3 py-1.5 sm:px-3 sm:py-1 bg-green-100 text-green-800 rounded text-xs font-medium hover:bg-green-200 transition-colors">
                                                        <i data-lucide="check" class="w-3 h-3"></i>
                                                        <span class="sm:inline">Mark Read</span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <span class="text-xs text-gray-400 self-center sm:self-auto">
                                                #<?= $notification['NOTIF_ID'] ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pagination - Responsive -->
        <?php if ($totalPages > 1): ?>
            <div class="mt-4 sm:mt-6">
                <div class="pagination-container flex justify-center">
                    <div class="flex items-center gap-1 sm:gap-2">
                        <!-- Previous Button -->
                        <a href="?page=<?= max(1, $page - 1) ?>&limit=<?= $limit ?><?= $urlParams ?>"
                            class="px-2 sm:px-3 py-1 border rounded text-sm <?= ($page == 1 || $totalNotifications == 0) ? 'opacity-50 cursor-not-allowed pointer-events-none bg-gray-100' : 'hover:bg-gray-200' ?>">
                            <i data-lucide="chevron-left" class="w-4 h-4"></i>
                        </a>

                        <!-- Page Numbers - Hidden on very small screens if too many -->
                        <?php foreach ($pageLinks as $index => $p): ?>
                            <?php if ($p['page'] === "..."): ?>
                                <span class="hidden sm:inline px-2 sm:px-3 py-1 text-gray-400 cursor-default select-none">...</span>
                            <?php else: ?>
                                <a href="<?= $p['url'] ?>"
                                    class="hidden sm:inline-block px-2 sm:px-3 py-1 border rounded text-sm <?= $p['page'] == $page ? 'bg-blue-500 text-white' : 'hover:bg-gray-200' ?>">
                                    <?= $p['page'] ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <!-- Mobile page indicator -->
                        <span class="sm:hidden px-3 py-1 text-sm">
                            <?= $page ?> / <?= $totalPages ?>
                        </span>

                        <!-- Next Button -->
                        <a href="?page=<?= min($totalPages, $page + 1) ?>&limit=<?= $limit ?><?= $urlParams ?>"
                            class="px-2 sm:px-3 py-1 border rounded text-sm <?= ($page == $totalPages || $totalNotifications == 0) ? 'opacity-50 cursor-not-allowed pointer-events-none bg-gray-100' : 'hover:bg-gray-200' ?>">
                            <i data-lucide="chevron-right" class="w-4 h-4"></i>
                        </a>
                    </div>
                </div>

                <!-- Results info -->
                <p class="text-center text-xs sm:text-sm text-gray-600 mt-3 sm:mt-4">
                    Page <?= $page ?> of <?= $totalPages ?> |
                    Results: <?= $totalNotifications > 0 ? $offset + 1 : 0 ?> -
                    <?= min($offset + $limit, $totalNotifications) ?> of <?= $totalNotifications ?>
                    <?php if (!empty($filter) && $filter !== 'all'): ?>
                        | Filter: <?= ucfirst(htmlspecialchars($filter)) ?>
                    <?php endif; ?>
                    <?php if (!empty($type) && $type !== 'all'): ?>
                        | Type: <?= ucfirst(str_replace('_', ' ', htmlspecialchars($type))) ?>
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        // Initialize Lucide icons
        lucide.createIcons();

        // Update filters
        function updateFilters() {
            const statusFilter = document.getElementById('statusFilter').value;
            const typeFilter = document.getElementById('typeFilter').value;
            const limit = <?= $limit ?>;
            window.location.href = `?filter=${statusFilter}&type=${typeFilter}&limit=${limit}&page=1`;
        }

        // Show success messages
        <?php if (isset($_SESSION['success_message'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: '<?= $_SESSION['success_message'] ?>',
                showConfirmButton: true,
                timer: 3000
            });
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        // Auto-refresh notifications every 30 seconds (only on appropriate filters)
        setInterval(() => {
            const urlParams = new URLSearchParams(window.location.search);
            const filter = urlParams.get('filter');
            if (!filter || filter === 'all' || filter === 'unread') {
                window.location.reload();
            }
        }, 30000);

        // Handle responsive icon sizing
        // function updateIconSizes() {
        //     const isMobile = window.innerWidth <= 640;
        //     const icons = document.querySelectorAll('[data-lucide]');
        //     icons.forEach(icon => {
        //         if (icon.classList.contains('w-6') || icon.classList.contains('w-8')) {
        //             // Skip stat card icons, they're handled separately
        //             return;
        //         }
        //         if (isMobile) {
        //             icon.classList.remove('w-4', 'h-4', 'w-5', 'h-5');
        //             icon.classList.add('w-3.5', 'h-3.5');
        //         } else {
        //             icon.classList.remove('w-3.5', 'h-3.5');
        //             icon.classList.add('w-4', 'h-4');
        //         }
        //     });
        //     lucide.createIcons();
        // }

        // Run on load and resize
        window.addEventListener('load', updateIconSizes);
        window.addEventListener('resize', updateIconSizes);
    </script>
</body>

</html>