<?php
// admin/dashboard-api.php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include "../API/db-connector.php";

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($action === 'getDashboardData') {
    $currentMonth = date('m');
    $currentYear = date('Y');
    
    // Get statistics
    $stats = [];
    
    // Total Items
    $result = $conn->query("SELECT COUNT(*) as total FROM item WHERE ITEM_IS_ARCHIVED = 0");
    $stats['totalItems'] = (int) $result->fetch_assoc()['total'];
    
    // Total Inventory Quantity
    $result = $conn->query("SELECT SUM(INV_QUANTITY_PIECE) as total_qty FROM inventory i JOIN item it ON i.ITEM_ID = it.ITEM_ID WHERE it.ITEM_IS_ARCHIVED = 0");
    $stats['totalInventoryQty'] = (int) ($result->fetch_assoc()['total_qty'] ?? 0);
    
    // Low Stock Count
    $result = $conn->query("SELECT COUNT(*) as total FROM inventory i JOIN inventory_thresholds t ON i.ITEM_ID = t.ITEM_ID WHERE i.INV_QUANTITY_PIECE <= t.MIN_THRESHOLD");
    $stats['lowStockCount'] = (int) ($result->fetch_assoc()['total'] ?? 0);
    
    // Over Stock Count
    $result = $conn->query("SELECT COUNT(*) as total FROM inventory i JOIN inventory_thresholds t ON i.ITEM_ID = t.ITEM_ID WHERE i.INV_QUANTITY_PIECE >= t.MAX_THRESHOLD AND t.MAX_THRESHOLD IS NOT NULL");
    $stats['overStockCount'] = (int) ($result->fetch_assoc()['total'] ?? 0);
    
    // Stock In This Month
    $stmt = $conn->prepare("SELECT SUM(SI_QUANTITY) as total FROM stock_in WHERE MONTH(CREATED_AT) = ? AND YEAR(CREATED_AT) = ?");
    $stmt->bind_param("ii", $currentMonth, $currentYear);
    $stmt->execute();
    $stats['stockInMonth'] = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    
    // Stock Out This Month
    $stmt = $conn->prepare("SELECT SUM(SO_QUANTITY) as total FROM stock_out WHERE MONTH(CREATED_AT) = ? AND YEAR(CREATED_AT) = ?");
    $stmt->bind_param("ii", $currentMonth, $currentYear);
    $stmt->execute();
    $stats['stockOutMonth'] = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    
    // Total Offices
    $result = $conn->query("SELECT COUNT(*) as total FROM office WHERE OFF_IS_ARCHIVED = 0");
    $stats['totalOffices'] = (int) $result->fetch_assoc()['total'];
    
    // Active Users
    $result = $conn->query("SELECT COUNT(*) as total FROM users WHERE USER_IS_ARCHIVED = 0 AND UR_ID = 3");
    $stats['totalUsers'] = (int) $result->fetch_assoc()['total'];
    
    // Chart Data
    $charts = [];
    
    // Monthly Stock Movement
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
    $result = $conn->query($monthlyMovementQuery);
    $months = [];
    $stockInData = [];
    $stockOutData = [];
    $alignedData = [];
    $adjustmentData = [];
    $returnData = [];
    while ($row = $result->fetch_assoc()) {
        $months[] = date('M Y', strtotime($row['month'] . '-01'));
        $stockInData[] = (int) $row['stock_in'];
        $stockOutData[] = (int) $row['stock_out'];
        $alignedData[] = (int) $row['aligned'];
        $adjustmentData[] = (int) $row['adjustment'];
        $returnData[] = (int) $row['returnData'];
    }
    $charts['monthlyMovement'] = [
        'months' => $months,
        'stockIn' => $stockInData,
        'stockOut' => $stockOutData,
        'aligned' => $alignedData,
        'adjustment' => $adjustmentData,
        'return' => $returnData
    ];
    
    // Stock Status Distribution
    $stockStatusQuery = "
        SELECT 
            CASE 
                WHEN inv.INV_QUANTITY_PIECE <= t.MIN_THRESHOLD THEN 'Low Stock'
                WHEN inv.INV_QUANTITY_PIECE >= t.MAX_THRESHOLD THEN 'Over Stock'
                ELSE 'Normal'
            END as status,
            COUNT(*) as count
        FROM inventory inv
        LEFT JOIN inventory_thresholds t ON inv.ITEM_ID = t.ITEM_ID
        GROUP BY status
    ";
    $result = $conn->query($stockStatusQuery);
    $statusLabels = [];
    $statusCounts = [];
    while ($row = $result->fetch_assoc()) {
        $statusLabels[] = $row['status'];
        $statusCounts[] = (int) $row['count'];
    }
    $charts['stockStatus'] = [
        'labels' => $statusLabels,
        'counts' => $statusCounts
    ];
    
    // Top Items
    $topItemsQuery = "
        SELECT 
            i.ITEM_CODE,
            inv.INV_QUANTITY_PIECE as quantity
        FROM inventory inv
        JOIN item i ON inv.ITEM_ID = i.ITEM_ID
        WHERE i.ITEM_IS_ARCHIVED = 0
        ORDER BY inv.INV_QUANTITY_PIECE DESC
        LIMIT 5
    ";
    $result = $conn->query($topItemsQuery);
    $topItemLabels = [];
    $topItemData = [];
    while ($row = $result->fetch_assoc()) {
        $topItemLabels[] = $row['ITEM_CODE'];
        $topItemData[] = (int) $row['quantity'];
    }
    $charts['topItems'] = [
        'labels' => $topItemLabels,
        'data' => $topItemData
    ];
    
    // Office Stock
    $officeStockQuery = "
        SELECT 
            o.OFF_CODE,
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
    $result = $stmt->get_result();
    $officeLabels = [];
    $officeData = [];
    while ($row = $result->fetch_assoc()) {
        $officeLabels[] = $row['OFF_CODE'];
        $officeData[] = (int) $row['total_quantity'];
    }
    $stmt->close();
    $charts['officeStock'] = [
        'labels' => $officeLabels,
        'data' => $officeData
    ];
    
    // Daily Movement
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
    $result = $stmt->get_result();
    $dailyIn = [];
    $dailyOut = [];
    $dailyAligned = [];
    $dailyAdjustment = [];
    $dailyReturn = [];
    while ($row = $result->fetch_assoc()) {
        $dailyIn[] = (int) $row['daily_in'];
        $dailyOut[] = (int) $row['daily_out'];
        $dailyAligned[] = (int) $row['daily_aligned'];
        $dailyAdjustment[] = (int) $row['daily_adjustment'];
        $dailyReturn[] = (int) $row['daily_return'];
    }
    $stmt->close();
    $charts['dailyMovement'] = [
        'in' => $dailyIn,
        'out' => $dailyOut,
        'aligned' => $dailyAligned,
        'adjustment' => $dailyAdjustment,
        'return' => $dailyReturn
    ];
    
    // Attention Items
    $attentionItemsQuery = "
        SELECT 
            i.ITEM_CODE,
            i.ITEM_DESC,
            inv.INV_QUANTITY_PIECE as current_qty,
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
    $result = $conn->query($attentionItemsQuery);
    $attentionItems = [];
    while ($row = $result->fetch_assoc()) {
        $attentionItems[] = $row;
    }
    
    // Recent Activities
    $recentActivitiesQuery = "
        SELECT 
            al.ACTION_TYPE,
            al.DETAILS,
            al.CREATED_AT,
            CONCAT(u.USER_FNAME, ' ', u.USER_LNAME) as user_name
        FROM user_activity_log al
        LEFT JOIN users u ON al.USER_ID = u.USER_ID
        ORDER BY al.CREATED_AT DESC
        LIMIT 10
    ";
    $result = $conn->query($recentActivitiesQuery);
    $recentActivities = [];
    while ($row = $result->fetch_assoc()) {
        $row['CREATED_AT'] = date('M d, Y h:i A', strtotime($row['CREATED_AT']));
        $recentActivities[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'stats' => $stats,
            'charts' => $charts,
            'attentionItems' => $attentionItems,
            'recentActivities' => $recentActivities
        ]
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}