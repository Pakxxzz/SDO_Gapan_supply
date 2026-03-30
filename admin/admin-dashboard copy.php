<?php
include "./sidebar.php";
session_regenerate_id(true);
include "../db-connector.php";

// Check if snapshot exists for today
$today = date('Y-m-d');
$snapshotExists = false;

$snapshotCheck = "SELECT COUNT(*) as count FROM baseline_inventory WHERE DATE_SNAPSHOT = '$today'";
$result = mysqli_query($conn, $snapshotCheck);
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $snapshotExists = $row['count'] > 0;
}

// Get statistics for dashboard
$stats = [];

// Total items
$query = "SELECT COUNT(*) as total FROM item WHERE ITEM_IS_ARCHIVED = 0";
$result = mysqli_query($conn, $query);
$stats['total_items'] = $result ? mysqli_fetch_assoc($result)['total'] : 0;

// Total vendors
$query = "SELECT COUNT(*) as total FROM vendor WHERE VEN_IS_ARCHIVED = 0";
$result = mysqli_query($conn, $query);
$stats['total_vendors'] = $result ? mysqli_fetch_assoc($result)['total'] : 0;

// Total users
$query = "SELECT COUNT(*) as total FROM users WHERE USER_IS_ARCHIVED = 0 AND USER_ROLE != 'Admin'";
$result = mysqli_query($conn, $query);
$stats['total_users'] = $result ? mysqli_fetch_assoc($result)['total'] : 0;

// Total inventory value (approximate)
$query = "SELECT SUM(INV_QUANTITY_PIECE) as total FROM inventory";
$result = mysqli_query($conn, $query);
$stats['total_inventory'] = $result ? mysqli_fetch_assoc($result)['total'] : 0;

// Recent activities
$query = "SELECT * FROM user_activity_log ORDER BY CREATED_AT DESC LIMIT 20";
$result = mysqli_query($conn, $query);
$recent_activities = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];

// Recent inventory movements
$query = "SELECT imh.MOVEMENT_TYPE, imh.QUANTITY_PIECE, imh.MOVEMENT_DATE, i.ITEM_DESC 
          FROM item_movement_history imh 
          JOIN item i ON imh.ITEM_ID = i.ITEM_ID 
          ORDER BY imh.MOVEMENT_DATE DESC 
          LIMIT 20";
$result = mysqli_query($conn, $query);
$inventory_movements = $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .card-hover:hover {
            transform: translateY(-5px);
            transition: transform 0.3s ease;
        }
        .scrollbar-hide::-webkit-scrollbar {
            display: none;
        }
        .scrollbar-hide {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
    </style>
</head>

<body class="h-screen bg-gray-50">
    <div class="content w-full mx-auto p-8 px-8 h-screen overflow-y-auto pb-15">
        <div class="flex justify-between items-center mb-6">
            <h1 class="font-bold mb-4 text-[13px] xs:text-[12px] sm:text-[15px] font-bold text-[#0047bb]">
                Dashboard
            </h1>
            <!-- <div class="flex items-center">
                <span class="text-sm mr-2 text-gray-600">Snapshot for <?php echo $today; ?>:</span>
                <span class="px-3 py-1 rounded-full text-xs font-medium <?php echo $snapshotExists ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                    <?php echo $snapshotExists ? 'Completed' : 'Pending'; ?>
                </span>
            </div> -->
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-8">
            <div class="bg-white p-6 rounded-lg shadow-md card-hover border-l-4 border-blue-500">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Total Items</p>
                        <p class="text-2xl font-bold text-gray-800"><?php echo $stats['total_items']; ?></p>
                    </div>
                    <div class="bg-blue-100 p-3 rounded-full">
                        <i data-lucide="package" class="text-blue-600 text-2xl"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-2">All active products in inventory</p>
                <a href="item.php" class="text-sm text-blue-500 mt-2 inline-block">Review Items →</a>
            </div>

            <div class="bg-white p-6 rounded-lg shadow-md card-hover border-l-4 border-green-500">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Total Principals</p>
                        <p class="text-2xl font-bold text-gray-800"><?php echo $stats['total_vendors']; ?></p>
                    </div>
                    <div class="bg-green-100 p-3 rounded-full">
                        <i data-lucide="building" class="text-green-600 text-2xl"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-2">Active principal</p>
                <a href="vendor.php" class="text-sm text-green-500 mt-2 inline-block">Review Principals →</a>
            </div>

            <div class="bg-white p-6 rounded-lg shadow-md card-hover border-l-4 border-purple-500">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm font-medium text-gray-600">System Users</p>
                        <p class="text-2xl font-bold text-gray-800"><?php echo $stats['total_users']; ?></p>
                    </div>
                    <div class="bg-purple-100 p-3 rounded-full">
                        <i data-lucide="users" class="text-purple-600 text-2xl"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-2">Active system users</p>
                <a href="user-management.php" class="text-sm text-purple-500 mt-2 inline-block">Review Users →</a>
            </div>

            <div class="bg-white p-6 rounded-lg shadow-md card-hover border-l-4 border-orange-500">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Total Inventory</p>
                        <p class="text-2xl font-bold text-gray-800"><?php echo number_format($stats['total_inventory']); ?></p>
                    </div>
                    <div class="bg-orange-100 p-3 rounded-full">
                        <i data-lucide="boxes" class="text-orange-600 text-2xl"></i>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-2">Total pieces in inventory</p>
                <a href="inventory.php" class="text-sm text-orange-500 mt-2 inline-block">Review Inventory →</a>
            </div>
        </div>

        <!-- Activities and Inventory Movements -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Recent Activities -->
            <div class="bg-white p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                    <i data-lucide="activity" class="mr-2 w-5 h-5"></i> Recent Activities
                </h2>
                <div class="h-64 overflow-y-auto scrollbar-hide">
                    <?php if (!empty($recent_activities)): ?>
                        <ul class="space-y-3">
                            <?php foreach ($recent_activities as $activity): ?>
                                <li class="border-l-4 border-blue-500 pl-3 py-2">
                                    <p class="text-sm font-medium"><?php echo htmlspecialchars($activity['ACTION_TYPE']); ?></p>
                                    <p class="text-xs text-gray-600"><?php echo htmlspecialchars($activity['RECORD_NAME']); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo date('M j, g:i A', strtotime($activity['CREATED_AT'])); ?></p>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-gray-500 text-sm py-8 text-center">No recent activities</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Inventory Movements -->
            <div class="bg-white p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                    <i data-lucide="trending-up" class="mr-2 w-5 h-5"></i> Recent Inventory Movements
                </h2>
                <div class="h-64 overflow-y-auto scrollbar-hide">
                    <?php if (!empty($inventory_movements)): ?>
                        <ul class="space-y-3">
                            <?php foreach ($inventory_movements as $movement): ?>
                                <li class="border-l-4 
                                    <?php echo $movement['MOVEMENT_TYPE'] == 'Received' ? 'border-blue-500' : 
                                          ($movement['MOVEMENT_TYPE'] == 'Delivery' ? 'border-red-500' : 
                                          ($movement['MOVEMENT_TYPE'] == 'Disposed' ? 'border-red-500' : 'border-yellow-500')); ?> 
                                    pl-3 py-2">
                                    <div class="flex justify-between">
                                        <p class="text-sm font-medium"><?php echo htmlspecialchars($movement['ITEM_DESC']); ?></p>
                                        <span class="px-2 py-1 text-xs rounded-full 
                                            <?php echo $movement['MOVEMENT_TYPE'] == 'Received' ? 'bg-blue-100 text-black-800' : 
                                                  ($movement['MOVEMENT_TYPE'] == 'Delivery' ? 'bg-red-100 text-black-800' : 
                                                  ($movement['MOVEMENT_TYPE'] == 'Disposed' ? 'bg-red-100 text-black-800' : 'bg-yellow-100 text-black-800')); ?>">
                                            <?php echo $movement['MOVEMENT_TYPE']; ?>
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-600">Qty: <?php echo number_format($movement['QUANTITY_PIECE']); ?> pieces</p>
                                    <p class="text-xs text-gray-500"><?php echo date('M j, g:i A', strtotime($movement['MOVEMENT_DATE'])); ?></p>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-gray-500 text-sm py-8 text-center">No recent inventory movements</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>

<script src="https://unpkg.com/lucide@latest"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    lucide.createIcons();
</script>

</html>