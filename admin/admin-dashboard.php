<?php
// admin/admin-dashboard.php
include "./sidebar.php";
session_regenerate_id(true);
include "../API/db-connector.php";


// Get current date for filters
$currentMonth = date('m');
$currentYear = date('Y');

// STATISTICS CARDS
// 1. Total Items
$totalItemsQuery = "SELECT COUNT(*) as total FROM item WHERE ITEM_IS_ARCHIVED = 0";
$totalItemsResult = $conn->query($totalItemsQuery);
if (!$totalItemsResult) {
    die("Query failed: " . $conn->error);
}
$totalItems = $totalItemsResult->fetch_assoc()['total'];

// 2. Total Inventory Value (estimated - using quantity as value proxy)
$inventoryValueQuery = "SELECT SUM(INV_QUANTITY_PIECE) as total_qty FROM inventory i JOIN item it ON i.ITEM_ID = it.ITEM_ID WHERE it.ITEM_IS_ARCHIVED = 0";
$inventoryValueResult = $conn->query($inventoryValueQuery);
$totalInventoryQty = $inventoryValueResult->fetch_assoc()['total_qty'] ?? 0;

// 3. Low Stock Items Count
$lowStockQuery = "SELECT COUNT(*) as total FROM inventory i 
                  JOIN inventory_thresholds t ON i.ITEM_ID = t.ITEM_ID 
                  WHERE t.MIN_THRESHOLD IS NOT NULL 
                  AND i.INV_QUANTITY_PIECE <= t.MIN_THRESHOLD";
$lowStockResult = $conn->query($lowStockQuery);
$lowStockCount = $lowStockResult->fetch_assoc()['total'] ?? 0;

// 4. Over Stock Items Count
$overStockQuery = "SELECT COUNT(*) as total FROM inventory i 
                   JOIN inventory_thresholds t ON i.ITEM_ID = t.ITEM_ID 
                   WHERE i.INV_QUANTITY_PIECE >= t.MAX_THRESHOLD AND t.MAX_THRESHOLD IS NOT NULL";
$overStockResult = $conn->query($overStockQuery);
$overStockCount = $overStockResult->fetch_assoc()['total'] ?? 0;

// 5. Stock In This Month
$stockInMonthQuery = "SELECT SUM(SI_QUANTITY) as total FROM stock_in 
                      WHERE MONTH(CREATED_AT) = ? AND YEAR(CREATED_AT) = ?";
$stmt = $conn->prepare($stockInMonthQuery);
$stmt->bind_param("ii", $currentMonth, $currentYear);
$stmt->execute();
$stockInMonth = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
$stmt->close();

// 6. Stock Out This Month
$stockOutMonthQuery = "SELECT SUM(SO_QUANTITY) as total FROM stock_out 
                       WHERE MONTH(CREATED_AT) = ? AND YEAR(CREATED_AT) = ?";
$stmt = $conn->prepare($stockOutMonthQuery);
$stmt->bind_param("ii", $currentMonth, $currentYear);
$stmt->execute();
$stockOutMonth = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
$stmt->close();

// 7. Total Offices
$officesQuery = "SELECT COUNT(*) as total FROM office WHERE OFF_IS_ARCHIVED = 0";
$officesResult = $conn->query($officesQuery);
$totalOffices = $officesResult->fetch_assoc()['total'];

// 8. Active Users
$usersQuery = "SELECT COUNT(*) as total FROM users WHERE USER_IS_ARCHIVED = 0 AND UR_ID = 3";
$usersResult = $conn->query($usersQuery);
$totalUsers = $usersResult->fetch_assoc()['total'];

// CHART DATA
// 1. Monthly Stock Movement (Last 6 Months)
$monthlyMovementQuery = "
    SELECT 
        DATE_FORMAT(MOVEMENT_DATE, '%Y-%m') as month,
        SUM(CASE WHEN MOVEMENT_TYPE = 'Stock In' THEN QUANTITY_PIECE ELSE 0 END) as stock_in,
        SUM(CASE WHEN MOVEMENT_TYPE = 'Stock Out' THEN QUANTITY_PIECE ELSE 0 END) as stock_out,
        SUM(CASE WHEN MOVEMENT_TYPE = 'Aligned' THEN QUANTITY_PIECE ELSE 0 END) as aligned,
        SUM(CASE WHEN MOVEMENT_TYPE = 'Adjustment' THEN QUANTITY_PIECE ELSE 0 END) as adjustment,
        SUM(CASE WHEN MOVEMENT_TYPE = 'Return' THEN QUANTITY_PIECE ELSE 0 END) as returnData
    FROM item_movement_history
    WHERE MOVEMENT_DATE >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(MOVEMENT_DATE, '%Y-%m')
    ORDER BY month ASC
";
$monthlyMovementResult = $conn->query($monthlyMovementQuery);

$months = [];
$stockInData = [];
$stockOutData = [];
$alignedData = [];
$adjustmentData = [];
$returnData = [];

while ($row = $monthlyMovementResult->fetch_assoc()) {
    $months[] = date('M Y', strtotime($row['month'] . '-01'));
    $stockInData[] = (int) $row['stock_in'];
    $stockOutData[] = (int) $row['stock_out'];
    $alignedData[] = (int) $row['aligned'];
    $adjustmentData[] = (int) $row['adjustment'];
    $returnData[] = (int) $row['returnData'];
}

// 2. Top 5 Items by Stock Quantity
$topItemsQuery = "
    SELECT 
        i.ITEM_CODE,
        i.ITEM_DESC,
        inv.INV_QUANTITY_PIECE as quantity
    FROM inventory inv
    JOIN item i ON inv.ITEM_ID = i.ITEM_ID
    WHERE i.ITEM_IS_ARCHIVED = 0
    ORDER BY inv.INV_QUANTITY_PIECE DESC
    LIMIT 5
";
$topItemsResult = $conn->query($topItemsQuery);
$topItems = [];
$topItemsQuantities = [];
while ($row = $topItemsResult->fetch_assoc()) {
    $topItems[] = $row['ITEM_CODE'];
    $topItemsQuantities[] = (int) $row['quantity'];
}

// 3. Stock Status Distribution
$stockStatusQuery = "
    SELECT 
        CASE 
             WHEN t.MIN_THRESHOLD IS NOT NULL AND inv.INV_QUANTITY_PIECE <= t.MIN_THRESHOLD THEN 'Low Stock'
             WHEN t.MAX_THRESHOLD IS NOT NULL AND inv.INV_QUANTITY_PIECE >= t.MAX_THRESHOLD THEN 'Over Stock'
             ELSE 'Normal'
        END as status,
        COUNT(*) as count
    FROM inventory inv
    LEFT JOIN inventory_thresholds t ON inv.ITEM_ID = t.ITEM_ID
    GROUP BY status
";
$stockStatusResult = $conn->query($stockStatusQuery);
$statusLabels = [];
$statusCounts = [];
while ($row = $stockStatusResult->fetch_assoc()) {
    $statusLabels[] = $row['status'];
    $statusCounts[] = (int) $row['count'];
}

// 4. Recent Activities
$recentActivitiesQuery = "
    SELECT 
        al.ACTION_TYPE,
        al.MODULE,
        al.RECORD_NAME,
        al.DETAILS,
        al.CREATED_AT,
        CONCAT(u.USER_FNAME, ' ', u.USER_LNAME) as user_name
    FROM user_activity_log al
    LEFT JOIN users u ON al.USER_ID = u.USER_ID
    ORDER BY al.CREATED_AT DESC
    LIMIT 10
";
$recentActivities = $conn->query($recentActivitiesQuery);

// 5. Stock by Office (for RIS distribution)
$officeStockQuery = "
    SELECT 
        o.OFF_CODE,
        COUNT(DISTINCT so.SO_ID) as request_count,
        SUM(so.SO_QUANTITY) as total_quantity
    FROM stock_out so
    JOIN office o ON so.OFF_ID = o.OFF_ID
    WHERE MONTH(so.CREATED_AT) = ? AND YEAR(so.CREATED_AT) = ?
    GROUP BY o.OFF_ID, o.OFF_NAME
    ORDER BY total_quantity DESC
    LIMIT 5
";
$stmt = $conn->prepare($officeStockQuery);
$stmt->bind_param("ii", $currentMonth, $currentYear);
$stmt->execute();
$officeStockData = $stmt->get_result();
$stmt->close();

$officeNames = [];
$officeQuantities = [];
while ($row = $officeStockData->fetch_assoc()) {
    $officeNames[] = $row['OFF_CODE'];
    $officeQuantities[] = (int) $row['total_quantity'];
}

// 6. Daily Movement for Current Month
$dailyMovementQuery = "
    SELECT 
        DAY(MOVEMENT_DATE) as day,
        SUM(CASE WHEN MOVEMENT_TYPE = 'Stock In' THEN QUANTITY_PIECE ELSE 0 END) as daily_in,
        SUM(CASE WHEN MOVEMENT_TYPE = 'Stock Out' THEN QUANTITY_PIECE ELSE 0 END) as daily_out,
        SUM(CASE WHEN MOVEMENT_TYPE = 'Aligned' THEN QUANTITY_PIECE ELSE 0 END) as daily_aligned,
        SUM(CASE WHEN MOVEMENT_TYPE = 'Adjustment' THEN QUANTITY_PIECE ELSE 0 END) as daily_adjustment,
        SUM(CASE WHEN MOVEMENT_TYPE = 'Return' THEN QUANTITY_PIECE ELSE 0 END) as daily_return
    FROM item_movement_history
    WHERE MONTH(MOVEMENT_DATE) = ? AND YEAR(MOVEMENT_DATE) = ?
    GROUP BY DAY(MOVEMENT_DATE)
    ORDER BY day ASC
";
$stmt = $conn->prepare($dailyMovementQuery);
$stmt->bind_param("ii", $currentMonth, $currentYear);
$stmt->execute();
$dailyMovement = $stmt->get_result();
$stmt->close();

$days = [];
$dailyIn = [];
$dailyOut = [];
$dailyAligned = [];
$dailyAdjustment = [];
$dailyReturn = [];
while ($row = $dailyMovement->fetch_assoc()) {
    $days[] = 'Day ' . $row['day'];
    $dailyIn[] = (int) $row['daily_in'];
    $dailyOut[] = (int) $row['daily_out'];
    $dailyAligned[] = (int) $row['daily_aligned'];
    $dailyAdjustment[] = (int) $row['daily_adjustment'];
    $dailyReturn[] = (int) $row['daily_return'];
}

// 7. Items Needing Attention (Low/Over Stock)
$attentionItemsQuery = "
    SELECT 
        i.ITEM_CODE,
        i.ITEM_DESC,
        inv.INV_QUANTITY_PIECE as current_qty,
        t.MIN_THRESHOLD,
        t.MAX_THRESHOLD,
        CASE 
            WHEN inv.INV_QUANTITY_PIECE <= t.MIN_THRESHOLD THEN 'Low Stock'
            WHEN inv.INV_QUANTITY_PIECE >= t.MAX_THRESHOLD THEN 'Over Stock'
        END as alert_type
    FROM inventory inv
    JOIN item i ON inv.ITEM_ID = i.ITEM_ID
    LEFT JOIN inventory_thresholds t ON inv.ITEM_ID = t.ITEM_ID
    WHERE (inv.INV_QUANTITY_PIECE <= t.MIN_THRESHOLD OR inv.INV_QUANTITY_PIECE >= t.MAX_THRESHOLD)
    AND i.ITEM_IS_ARCHIVED = 0
    ORDER BY 
        CASE 
            WHEN inv.INV_QUANTITY_PIECE <= t.MIN_THRESHOLD THEN 1
            ELSE 2
        END,
        inv.INV_QUANTITY_PIECE ASC
    LIMIT 5
";
$attentionItems = $conn->query($attentionItemsQuery);

// Prepare data for JavaScript
$dashboardData = [
    'stats' => [
        'totalItems' => $totalItems,
        'totalInventoryQty' => $totalInventoryQty,
        'lowStockCount' => $lowStockCount,
        'overStockCount' => $overStockCount,
        'stockInMonth' => $stockInMonth,
        'stockOutMonth' => $stockOutMonth,
        'totalOffices' => $totalOffices,
        'totalUsers' => $totalUsers
    ],
    'charts' => [
        'months' => $months,
        'stockInData' => $stockInData,
        'stockOutData' => $stockOutData,
        'alignedData' => $alignedData,
        'adjustmentData' => $adjustmentData,
        'returnData' => $returnData,
        'topItems' => $topItems,
        'topItemsQuantities' => $topItemsQuantities,
        'statusLabels' => $statusLabels,
        'statusCounts' => $statusCounts,
        'officeNames' => $officeNames,
        'officeQuantities' => $officeQuantities,
        'days' => $days,
        'dailyIn' => $dailyIn,
        'dailyOut' => $dailyOut,
        'dailyAligned' => $dailyAligned,
        'dailyAdjustment' => $dailyAdjustment,
        'dailyReturn' => $dailyReturn
    ]
];


?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Inventory Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="inventory.css?id=<?= time() ?>">
    <style>
        .stat-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        /* Live indicator styles */
        .live-indicator {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: white;
            border-radius: 30px;
            padding: 8px 16px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 12px;
        }

        .live-dot {
            width: 10px;
            height: 10px;
            background-color: #10b981;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            }

            70% {
                transform: scale(1);
                box-shadow: 0 0 0 10px rgba(16, 185, 129, 0);
            }

            100% {
                transform: scale(0.95);
                box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
            }
        }

        .updating {
            opacity: 0.5;
            transition: opacity 0.3s;
        }

        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            border-left: 4px solid #10b981;
            border-radius: 8px;
            padding: 12px 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1001;
            transform: translateX(400px);
            transition: transform 0.3s ease;
        }

        .toast-notification.show {
            transform: translateX(0);
        }

        .toast-notification.error {
            border-left-color: #ef4444;
        }
    </style>
</head>

<body class="bg-gray-50">
    <div class="flex-1 w-full mx-auto flex flex-col custom-scroll">
        <!-- Main Content Area -->
        <div class="p-4 md:p-6 overflow-y-auto" style="height: calc(100dvh - 80px);">
            <!-- Welcome Section -->
            <div class="mb-6">
                <h1 class="text-2xl md:text-3xl font-bold text-gray-800">Welcome back, <?= htmlspecialchars($_SESSION['FNAME']) ?>!</h1>
                <p class="text-gray-600">Here's what's happening with your inventory today.</p>
            </div>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6" id="statsContainer">
                <!-- Total Items -->
                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-blue-500" data-stat="totalItems">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 font-medium">Total Items</p>
                            <p class="text-2xl font-bold text-gray-800 stat-value"><?= number_format($totalItems) ?></p>
                        </div>
                        <div class="bg-blue-100 p-3 rounded-full">
                            <i data-lucide="package" class='text-blue-600 text-xl'></i>
                        </div>
                    </div>
                    <div class="mt-2 text-xs text-gray-500">
                        <span class="text-green-500">↑</span> Active items in inventory
                    </div>
                </div>

                <!-- Total Quantity -->
                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-green-500"
                    data-stat="totalInventoryQty">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 font-medium">Total Quantity</p>
                            <p class="text-2xl font-bold text-gray-800 stat-value">
                                <?= number_format($totalInventoryQty) ?>
                            </p>
                        </div>
                        <div class="bg-green-100 p-3 rounded-full">
                            <i class='text-green-600 text-xl' data-lucide="hash"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-xs text-gray-500">
                        Items in stock
                    </div>
                </div>

                <!-- Low Stock Alert -->
                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-yellow-500"
                    data-stat="lowStockCount">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 font-medium">Low Stock Items</p>
                            <p
                                class="text-2xl font-bold <?= $lowStockCount > 0 ? 'text-yellow-600' : 'text-gray-800' ?> stat-value">
                                <?= number_format($lowStockCount) ?>
                            </p>
                        </div>
                        <div class="bg-yellow-100 p-3 rounded-full">
                            <i class='text-yellow-600 text-xl' data-lucide="alert-circle"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-xs text-gray-500 stat-subtext">
                        <?php if ($lowStockCount > 0): ?>
                            <span class="text-yellow-600">Need reordering</span>
                        <?php else: ?>
                            All items are well-stocked
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Over Stock Alert -->
                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-red-500"
                    data-stat="overStockCount">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 font-medium">Over Stock Items</p>
                            <p
                                class="text-2xl font-bold <?= $overStockCount > 0 ? 'text-red-600' : 'text-gray-800' ?> stat-value">
                                <?= number_format($overStockCount) ?>
                            </p>
                        </div>
                        <div class="bg-red-100 p-3 rounded-full">
                            <i class='text-red-600 text-xl' data-lucide="alert-triangle"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-xs text-gray-500 stat-subtext">
                        <?php if ($overStockCount > 0): ?>
                            <span class="text-red-600">Excess inventory</span>
                        <?php else: ?>
                            Within threshold limits
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Stock In This Month -->
                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-indigo-500"
                    data-stat="stockInMonth">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 font-medium">Stock In (This Month)</p>
                            <p class="text-2xl font-bold text-gray-800 stat-value"><?= number_format($stockInMonth) ?>
                            </p>
                        </div>
                        <div class="bg-indigo-100 p-3 rounded-full">
                            <i class='text-indigo-600 text-xl' data-lucide="package-plus"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-xs text-gray-500">
                        <span class="text-green-500">↑</span> Incoming items
                    </div>
                </div>

                <!-- Stock Out This Month -->
                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-purple-500"
                    data-stat="stockOutMonth">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 font-medium">Stock Out (This Month)</p>
                            <p class="text-2xl font-bold text-gray-800 stat-value"><?= number_format($stockOutMonth) ?>
                            </p>
                        </div>
                        <div class="bg-purple-100 p-3 rounded-full">
                            <i class='text-purple-600 text-xl' data-lucide="package-minus"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-xs text-gray-500">
                        <span class="text-red-500">↓</span> Outgoing items
                    </div>
                </div>

                <!-- Total Offices -->
                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-teal-500"
                    data-stat="totalOffices">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 font-medium">Offices</p>
                            <p class="text-2xl font-bold text-gray-800 stat-value"><?= number_format($totalOffices) ?>
                            </p>
                        </div>
                        <div class="bg-teal-100 p-3 rounded-full">
                            <i class='text-teal-600 text-xl' data-lucide="briefcase"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-xs text-gray-500">
                        Active offices/departments
                    </div>
                </div>

                <!-- Active Users -->
                <div class="stat-card bg-white rounded-lg shadow p-4 border-l-4 border-pink-500" data-stat="totalUsers">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 font-medium">Users</p>
                            <p class="text-2xl font-bold text-gray-800 stat-value"><?= number_format($totalUsers) ?></p>
                        </div>
                        <div class="bg-pink-100 p-3 rounded-full">
                            <i class='text-pink-600 text-xl' data-lucide="users"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-xs text-gray-500">
                        Active system users
                    </div>
                </div>
            </div>

            <!-- Charts Row 1 -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- Monthly Stock Movement Chart -->
                <div class="bg-white rounded-lg shadow p-4">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">Daily Stock Movement (Current Month)</h2>
                    <div class="chart-container" style="height: 400px;">
                        <canvas id="dailyMovementChart"></canvas>
                    </div>
                </div>

                <!-- Stock Status Distribution -->
                <div class="bg-white rounded-lg shadow p-4">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">Stock Status Distribution</h2>
                    <div class="chart-container">
                        <canvas id="stockStatusChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Charts Row 2 -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- Top Items by Quantity -->
                <div class="bg-white rounded-lg shadow p-4">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">Top 5 Items by Quantity</h2>
                    <div class="chart-container">
                        <canvas id="topItemsChart"></canvas>
                    </div>
                </div>

                <!-- Office Stock Distribution -->
                <div class="bg-white rounded-lg shadow p-4">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">Stock Requests by Office (This Month)</h2>
                    <div class="chart-container">
                        <canvas id="officeStockChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Daily Movement Chart -->
            <div class="bg-white rounded-lg shadow p-4 mb-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Monthly Stock Movement (Last 6 Months)</h2>
                <div class="chart-container">
                    <canvas id="monthlyMovementChart"></canvas>
                </div>
            </div>

            <!-- Tables Row -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- Items Needing Attention -->
                <div class="bg-white rounded-lg shadow overflow-hidden" id="attentionItemsContainer">
                    <div class="p-4 border-b border-gray-200 bg-gray-50">
                        <h2 class="text-lg font-semibold text-gray-800">Items Needing Attention</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item
                                        Code</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                        Description</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Current
                                        Qty</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200" id="attentionItemsTable">
                                <?php if ($attentionItems->num_rows > 0): ?>
                                    <?php while ($item = $attentionItems->fetch_assoc()): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                                <?= htmlspecialchars($item['ITEM_CODE']) ?>
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-500">
                                                <?= htmlspecialchars(substr($item['ITEM_DESC'], 0, 30)) ?>...
                                            </td>
                                            <td class="px-4 py-3 text-sm text-gray-900">
                                                <?= number_format($item['current_qty']) ?>
                                            </td>
                                            <td class="px-4 py-3 text-xs ">
                                                <?php if ($item['alert_type'] == 'Low Stock'): ?>
                                                    <span class="px-1 py-1 rounded-full bg-yellow-100 text-yellow-800">Low
                                                        Stock</span>
                                                <?php else: ?>
                                                    <span class="px-1 py-1 rounded-full bg-red-100 text-red-800">Over
                                                        Stock</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="px-4 py-6 text-center text-gray-500">No items need attention
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Recent Activities -->
                <div class="bg-white rounded-lg shadow overflow-hidden" id="recentActivitiesContainer">
                    <div class="p-4 border-b border-gray-200 bg-gray-50">
                        <h2 class="text-lg font-semibold text-gray-800">Recent Activities</h2>
                    </div>
                    <div class="overflow-y-auto" style="max-height: 300px;">
                        <ul class="divide-y divide-gray-200" id="recentActivitiesList">
                            <?php while ($activity = $recentActivities->fetch_assoc()): ?>
                                <li class="p-4 hover:bg-gray-50">
                                    <div class="flex items-start space-x-3">
                                        <div class="flex-shrink-0">
                                            <?php
                                            $icon = 'alert-circle';
                                            $color = 'blue';
                                            if (strpos($activity['ACTION_TYPE'], 'Adjusted') !== false) {
                                                $icon = 'wrench';
                                                $color = 'purple';
                                            } elseif (strpos($activity['ACTION_TYPE'], 'Stock Out') !== false) {
                                                $icon = 'package-minus';
                                                $color = 'red';
                                            } elseif (strpos($activity['ACTION_TYPE'], 'Stock In') !== false) {
                                                $icon = 'package-plus';
                                                $color = 'green';
                                            }
                                            ?>
                                            <i data-lucide="<?= $icon ?>" class='text-<?= $color ?>-500 text-xl'></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-medium text-gray-900">
                                                <?= htmlspecialchars($activity['ACTION_TYPE']) ?>
                                            </p>
                                            <p class="text-xs text-gray-500 mt-1">
                                                <?= htmlspecialchars($activity['DETAILS']) ?>
                                            </p>
                                            <div class="flex justify-between items-center mt-2">
                                                <span
                                                    class="text-xs text-gray-400"><?= date('M d, Y h:i A', strtotime($activity['CREATED_AT'])) ?></span>
                                                <span class="text-xs font-medium text-gray-600">by
                                                    <?= htmlspecialchars($activity['user_name'] ?? 'System') ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </li>
                            <?php endwhile; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Live Indicator -->
    <!-- <div class="live-indicator">
        <div class="live-dot"></div>
        <span class="text-gray-600">Live Updates</span>
        <span class="text-gray-400 text-xs" id="lastUpdateTime">Just now</span>
    </div> -->

    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        let charts = {};
        let updateInterval;
        let isUpdating = false;

        // Initialize Lucide icons
        lucide.createIcons();

        // Initialize all charts
        function initCharts() {
            // Monthly Movement Chart
            const monthlyCtx = document.getElementById('monthlyMovementChart').getContext('2d');
            charts.monthlyMovement = new Chart(monthlyCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($months) ?>,
                    datasets: [{
                        label: 'Stock In',
                        data: <?= json_encode($stockInData) ?>,
                        borderColor: 'rgba(54, 162, 235, 0.8)',
                        backgroundColor: 'rgba(34, 121, 197, 0.1)',
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'Stock Out',
                        data: <?= json_encode($stockOutData) ?>,
                        borderColor: 'rgb(239, 68, 68)',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'Adjustment',
                        data: <?= json_encode($adjustmentData) ?>,
                        borderColor: 'rgb(239, 236, 68)',
                        backgroundColor: 'rgba(208, 239, 68, 0.1)',
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'Aligned',
                        data: <?= json_encode($alignedData) ?>,
                        borderColor: 'rgb(97, 239, 68)',
                        backgroundColor: 'rgba(68, 239, 114, 0.1)',
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'Return',
                        data: <?= json_encode($returnData) ?>,
                        borderColor: 'rgb(239, 139, 68)',
                        backgroundColor: 'rgba(239, 222, 68, 0.1)',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    }
                }
            });

            // Stock Status Chart
            const statusCtx = document.getElementById('stockStatusChart').getContext('2d');
            const labels = <?= json_encode($statusLabels) ?>;
            const colorMap = {
                'Normal': 'rgba(54, 162, 235, 0.8)',
                'Over Stock': 'rgba(234, 179, 8, 0.8)',
                'Low Stock': 'rgba(239, 68, 68, 0.8)'
            };
            const dynamicColors = labels.map(label => colorMap[label] || 'rgba(200, 200, 200, 0.8)');

            charts.stockStatus = new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: <?= json_encode($statusCounts) ?>,
                        backgroundColor: dynamicColors,
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // Top Items Chart
            const topItemsCtx = document.getElementById('topItemsChart').getContext('2d');
            charts.topItems = new Chart(topItemsCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($topItems) ?>,
                    datasets: [{
                        label: 'Quantity',
                        data: <?= json_encode($topItemsQuantities) ?>,
                        backgroundColor: [
                            'rgba(255, 99, 132, 0.8)',
                            'rgba(54, 162, 235, 0.8)',
                            'rgba(255, 206, 86, 0.8)',
                            'rgba(75, 192, 192, 0.8)',
                            'rgba(153, 102, 255, 0.8)'
                        ],
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });

            // Office Stock Chart
            if (<?= json_encode($officeNames) ?>.length > 0) {
                const officeCtx = document.getElementById('officeStockChart').getContext('2d');
                charts.officeStock = new Chart(officeCtx, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode($officeNames) ?>,
                        datasets: [{
                            label: 'Quantity Requested',
                            data: <?= json_encode($officeQuantities) ?>,
                            backgroundColor: [
                                'rgba(54, 162, 235, 0.8)', 'rgba(255, 99, 132, 0.8)', 'rgba(255, 206, 86, 0.8)',
                                'rgba(75, 192, 192, 0.8)', 'rgba(153, 102, 255, 0.8)', 'rgba(255, 159, 64, 0.8)',
                                'rgba(199, 199, 199, 0.8)', 'rgba(83, 102, 255, 0.8)', 'rgba(40, 167, 69, 0.8)',
                                'rgba(232, 62, 140, 0.8)'
                            ],
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            }

            // Daily Movement Chart
            if (<?= json_encode($days) ?>.length > 0) {
                const dailyCtx = document.getElementById('dailyMovementChart').getContext('2d');
                charts.dailyMovement = new Chart(dailyCtx, {
                    type: 'line',
                    data: {
                        labels: <?= json_encode($days) ?>,
                        datasets: [{
                            label: 'Stock In',
                            data: <?= json_encode($dailyIn) ?>,
                            borderColor: 'rgba(54, 162, 235, 0.8)',
                            backgroundColor: 'rgba(34, 121, 197, 0.1)',
                            fill: true,
                            tension: 0.4
                        }, {
                            label: 'Stock Out',
                            data: <?= json_encode($dailyOut) ?>,
                            borderColor: 'rgb(239, 68, 68)',
                            backgroundColor: 'rgba(239, 68, 68, 0.1)',
                            fill: true,
                            tension: 0.4
                        }, {
                            label: 'Adjustment',
                            data: <?= json_encode($dailyAdjustment) ?>,
                            borderColor: 'rgb(239, 236, 68)',
                            backgroundColor: 'rgba(208, 239, 68, 0.1)',
                            fill: true,
                            tension: 0.4
                        }, {
                            label: 'Aligned',
                            data: <?= json_encode($dailyAligned) ?>,
                            borderColor: 'rgb(97, 239, 68)',
                            backgroundColor: 'rgba(68, 239, 114, 0.1)',
                            fill: true,
                            tension: 0.4
                        }, {
                            label: 'Return',
                            data: <?= json_encode($dailyReturn) ?>,
                            borderColor: 'rgb(239, 139, 68)',
                            backgroundColor: 'rgba(239, 222, 68, 0.1)',
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                            }
                        }
                    }
                });
            }
        }

        // Update dashboard data
        // Update dashboard data
        async function updateDashboard() {
            if (isUpdating) return;

            isUpdating = true;

            // Add visual feedback that update is happening
            const liveIndicator = document.querySelector('.live-dot');
            if (liveIndicator) {
                liveIndicator.style.backgroundColor = '#f59e0b'; // Orange during update
            }

            try {
                console.log('Fetching dashboard data...');

                const response = await fetch('../API/dashboard-api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'getDashboardData',
                        timestamp: Date.now()
                    })
                });

                console.log('Response status:', response.status);

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();
                console.log('Response data:', data);

                if (data.success) {
                    if (data.data.stats) {
                        updateStats(data.data.stats);
                    }
                    if (data.data.charts) {
                        updateCharts(data.data.charts);
                    }
                    if (data.data.attentionItems) {
                        updateAttentionItems(data.data.attentionItems);
                    }
                    if (data.data.recentActivities) {
                        updateRecentActivities(data.data.recentActivities);
                    }
                    updateLastUpdateTime();
                    // showToast('Dashboard updated successfully');

                    // Reset indicator to green
                    if (liveIndicator) {
                        liveIndicator.style.backgroundColor = '#10b981';
                    }
                } else {
                    throw new Error(data.message || 'Failed to update');
                }
            } catch (error) {
                console.error('Error updating dashboard:', error);
                showToast('Failed to update dashboard: ' + error.message, 'error');

                // Change indicator to red on error
                if (liveIndicator) {
                    liveIndicator.style.backgroundColor = '#ef4444';
                    setTimeout(() => {
                        liveIndicator.style.backgroundColor = '#10b981';
                    }, 3000);
                }
            } finally {
                isUpdating = false;
            }
        }
        // Update statistics cards
        function updateStats(stats) {
            // Update each stat value with animation
            for (const [key, value] of Object.entries(stats)) {
                const element = document.querySelector(`[data-stat="${key}"] .stat-value`);
                if (element) {
                    const oldValue = parseInt(element.innerText.replace(/,/g, '')) || 0;
                    animateNumber(element, oldValue, value);
                }
            }

            // Update subtexts for low stock and over stock
            if (stats.lowStockCount !== undefined) {
                const lowStockCard = document.querySelector('[data-stat="lowStockCount"]');
                const subtext = lowStockCard?.querySelector('.stat-subtext');
                if (subtext) {
                    subtext.innerHTML = stats.lowStockCount > 0
                        ? '<span class="text-yellow-600">Need reordering</span>'
                        : 'All items are well-stocked';
                }
            }

            if (stats.overStockCount !== undefined) {
                const overStockCard = document.querySelector('[data-stat="overStockCount"]');
                const subtext = overStockCard?.querySelector('.stat-subtext');
                if (subtext) {
                    subtext.innerHTML = stats.overStockCount > 0
                        ? '<span class="text-red-600">Excess inventory</span>'
                        : 'Within threshold limits';
                }
            }
        }

        // Animate number changes
        function animateNumber(element, start, end) {
            const duration = 500;
            const stepTime = 20;
            const steps = duration / stepTime;
            const increment = (end - start) / steps;
            let current = start;
            let step = 0;

            const interval = setInterval(() => {
                step++;
                current += increment;
                if (step >= steps) {
                    current = end;
                    clearInterval(interval);
                }
                element.innerText = Math.round(current).toLocaleString();
            }, stepTime);
        }

        // Update charts
        // Update charts with new data
        function updateCharts(chartData) {
            console.log('Updating charts with data:', chartData); // Debug log

            // Update monthly movement chart
            if (charts.monthlyMovement && chartData.monthlyMovement) {
                if (chartData.monthlyMovement.stockIn) {
                    charts.monthlyMovement.data.datasets[0].data = chartData.monthlyMovement.stockIn;
                    charts.monthlyMovement.data.datasets[1].data = chartData.monthlyMovement.stockOut;
                    charts.monthlyMovement.data.datasets[2].data = chartData.monthlyMovement.adjustment;
                    charts.monthlyMovement.data.datasets[3].data = chartData.monthlyMovement.aligned;
                    charts.monthlyMovement.data.datasets[4].data = chartData.monthlyMovement.return;
                    charts.monthlyMovement.update();
                    console.log('Monthly movement chart updated');
                }
            }

            // Update stock status chart
            if (charts.stockStatus && chartData.stockStatus) {
                if (chartData.stockStatus.labels && chartData.stockStatus.labels.length > 0) {
                    charts.stockStatus.data.labels = chartData.stockStatus.labels;
                    charts.stockStatus.data.datasets[0].data = chartData.stockStatus.counts;
                    charts.stockStatus.update();
                    console.log('Stock status chart updated');
                }
            }

            // Update top items chart
            if (charts.topItems && chartData.topItems) {
                if (chartData.topItems.labels && chartData.topItems.labels.length > 0) {
                    charts.topItems.data.labels = chartData.topItems.labels;
                    charts.topItems.data.datasets[0].data = chartData.topItems.data;
                    charts.topItems.update();
                    console.log('Top items chart updated');
                }
            }

            // Update office stock chart
            if (charts.officeStock && chartData.officeStock) {
                if (chartData.officeStock.labels && chartData.officeStock.labels.length > 0) {
                    charts.officeStock.data.labels = chartData.officeStock.labels;
                    charts.officeStock.data.datasets[0].data = chartData.officeStock.data;
                    charts.officeStock.update();
                    console.log('Office stock chart updated');
                }
            }

            // Update daily movement chart - FIXED VERSION
            if (charts.dailyMovement && chartData.dailyMovement) {
                console.log('Updating daily movement chart with:', chartData.dailyMovement);

                // Check if we have data for all datasets
                if (chartData.dailyMovement && chartData.dailyMovement.in) {
                    charts.dailyMovement.data.datasets[0].data = chartData.dailyMovement.in;
                    console.log('Daily In data:', chartData.dailyMovement.in);
                }
                if (chartData.dailyMovement.out) {
                    charts.dailyMovement.data.datasets[1].data = chartData.dailyMovement.out;
                    console.log('Daily Out data:', chartData.dailyMovement.out);
                }
                if (chartData.dailyMovement.adjustment) {
                    charts.dailyMovement.data.datasets[2].data = chartData.dailyMovement.adjustment;
                    console.log('Daily Adjustment data:', chartData.dailyMovement.adjustment);
                }
                if (chartData.dailyMovement.aligned) {
                    charts.dailyMovement.data.datasets[3].data = chartData.dailyMovement.aligned;
                    console.log('Daily Aligned data:', chartData.dailyMovement.aligned);
                }
                if (chartData.dailyMovement.return) {
                    charts.dailyMovement.data.datasets[4].data = chartData.dailyMovement.return;
                    console.log('Daily Return data:', chartData.dailyMovement.return);
                }

                // Update the chart
                charts.dailyMovement.update();
                console.log('Daily movement chart updated successfully');
            } else {
                console.warn('Daily movement chart or data not available:', {
                    chartExists: !!charts.dailyMovement,
                    dataExists: !!chartData.dailyMovement
                });
            }
        }
        // Update attention items table
        function updateAttentionItems(items) {
            const tableBody = document.getElementById('attentionItemsTable');
            if (!tableBody) return;

            if (items.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">No items need attention</td></tr>';
                return;
            }

            let html = '';
            for (const item of items) {
                const statusClass = item.alert_type === 'Low Stock' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800';
                const statusText = item.alert_type === 'Low Stock' ? 'Low Stock' : 'Over Stock';
                html += `
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm font-medium text-gray-900">${escapeHtml(item.ITEM_CODE)}</td>
                        <td class="px-4 py-3 text-sm text-gray-500">${escapeHtml(item.ITEM_DESC.substring(0, 30))}...</td>
                        <td class="px-4 py-3 text-sm text-gray-900">${item.current_qty.toLocaleString()}</td>
                        <td class="px-4 py-3"><span class="px-1 py-1 text-xs font-medium rounded-full ${statusClass}">${statusText}</span></td>
                    </tr>
                `;
            }
            tableBody.innerHTML = html;
        }

        // Update recent activities
        function updateRecentActivities(activities) {
            const activitiesList = document.getElementById('recentActivitiesList');
            if (!activitiesList) return;

            if (activities.length === 0) {
                activitiesList.innerHTML = '<li class="p-4 text-center text-gray-500">No recent activities</li>';
                return;
            }

            let html = '';
            for (const activity of activities) {
                let icon = 'alert-circle';
                let color = 'blue';
                if (activity.ACTION_TYPE.includes('Adjusted')) {
                    icon = 'wrench';
                    color = 'purple';
                } else if (activity.ACTION_TYPE.includes('Stock Out')) {
                    icon = 'package-minus';
                    color = 'red';
                } else if (activity.ACTION_TYPE.includes('Stock In')) {
                    icon = 'package-plus';
                    color = 'green';
                }

                html += `
                    <li class="p-4 hover:bg-gray-50">
                        <div class="flex items-start space-x-3">
                            <div class="flex-shrink-0">
                                <i data-lucide="${icon}" class="text-${color}-500 text-xl"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900">${escapeHtml(activity.ACTION_TYPE)}</p>
                                <p class="text-xs text-gray-500 mt-1">${escapeHtml(activity.DETAILS)}</p>
                                <div class="flex justify-between items-center mt-2">
                                    <span class="text-xs text-gray-400">${activity.CREATED_AT}</span>
                                    <span class="text-xs font-medium text-gray-600">by ${escapeHtml(activity.user_name || 'System')}</span>
                                </div>
                            </div>
                        </div>
                    </li>
                `;
            }
            activitiesList.innerHTML = html;
            lucide.createIcons();
        }

        // Update last update time
        function updateLastUpdateTime() {
            const timeElement = document.getElementById('lastUpdateTime');
            if (timeElement) {
                const now = new Date();
                timeElement.innerText = now.toLocaleTimeString();
            }
        }

        // Show toast notification
        function showToast(message, type = 'success') {
            const existingToast = document.querySelector('.toast-notification');
            if (existingToast) {
                existingToast.remove();
            }

            const toast = document.createElement('div');
            toast.className = `toast-notification ${type === 'error' ? 'error' : ''}`;
            toast.innerHTML = `
                <div class="flex items-center gap-2">
                    <i data-lucide="${type === 'error' ? 'alert-circle' : 'check-circle'}" class="w-4 h-4 ${type === 'error' ? 'text-red-500' : 'text-green-500'}"></i>
                    <span class="text-sm text-gray-700">${message}</span>
                </div>
            `;
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.classList.add('show');
            }, 10);

            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 3000);

            lucide.createIcons();
        }

        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Start auto-refresh
        function startAutoRefresh(interval = 3000) {
            if (updateInterval) {
                clearInterval(updateInterval);
            }
            updateInterval = setInterval(updateDashboard, interval);
        }

        // Initialize dashboard
        document.addEventListener('DOMContentLoaded', function () {
            initCharts();
            startAutoRefresh(3000); // Update every 30 seconds

            // Optional: Add manual refresh button listener
            const refreshBtn = document.getElementById('manualRefreshBtn');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', () => {
                    updateDashboard();
                });
            }
        });
    </script>
</body>

</html>