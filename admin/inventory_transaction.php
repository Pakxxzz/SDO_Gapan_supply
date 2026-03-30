<?php
// admin/inventory_transaction.php
include "./sidebar.php";
session_regenerate_id(true);
include "../API/db-connector.php";

// Get current date
$currentDate = date('Y-m-d', time());

// Get parameters
$selectedItem = isset($_GET['item']) ? intval($_GET['item']) : 0;
$fromDate = isset($_GET['from_date']) ? $_GET['from_date'] : null;
$toDate = isset($_GET['to_date']) ? $_GET['to_date'] : null;

// Pagination
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1)
    $page = 1;
$offset = ($page - 1) * $limit;

// Normalize date order
if (strtotime($fromDate) > strtotime($toDate)) {
    $temp = $fromDate;
    $fromDate = $toDate;
    $toDate = $temp;
}

//  Fetch Items 
$items = [];

// Get all active items
$itemQuery = "SELECT ITEM_ID, ITEM_CODE, ITEM_DESC 
              FROM item 
              WHERE ITEM_IS_ARCHIVED = 0 
              ORDER BY ITEM_DESC";
$itemResult = $conn->query($itemQuery);
while ($row = $itemResult->fetch_assoc()) {
    $items[] = $row;
}

//  Generate Date Range 
$dateRange = [];
$current = strtotime($fromDate);
$end = strtotime($toDate);

while ($current <= $end) {
    $dateRange[] = date('Y-m-d', $current);
    $current = strtotime('+1 day', $current);
}

//  Fetch Data for Selected Item 
$dailyData = [];
$itemDetails = null;

if ($selectedItem > 0) {
    // Get item details
    $itemDetailsQuery = "SELECT i.* 
                         FROM item i 
                         WHERE i.ITEM_ID = $selectedItem";
    $itemDetailsResult = $conn->query($itemDetailsQuery);
    $itemDetails = $itemDetailsResult->fetch_assoc();

    // Get all stock movements for this item in date range
    $movements = [];

    // Get stock in transactions
    $stockInQuery = "SELECT 
                        DATE(CREATED_AT) as trans_date,
                        'STOCK_IN' as trans_type,
                        SI_QUANTITY as quantity,
                        SI_REMARKS as remarks
                     FROM stock_in 
                     WHERE ITEM_ID = $selectedItem 
                     AND DATE(CREATED_AT) BETWEEN '$fromDate' AND '$toDate'";

    $stockInResult = $conn->query($stockInQuery);
    while ($row = $stockInResult->fetch_assoc()) {
        $movements[] = $row;
    }

    // Get stock out transactions
    $stockOutQuery = "SELECT 
                        DATE(CREATED_AT) as trans_date,
                        'STOCK_OUT' as trans_type,
                        SO_QUANTITY as quantity,
                        SO_REMARKS as remarks
                     FROM stock_out 
                     WHERE ITEM_ID = $selectedItem 
                     AND DATE(CREATED_AT) BETWEEN '$fromDate' AND '$toDate'";

    $stockOutResult = $conn->query($stockOutQuery);
    while ($row = $stockOutResult->fetch_assoc()) {
        $movements[] = $row;
    }

    // Get alignment adjustments from masterdata
    $alignmentQuery = "SELECT 
                        DATE(CREATED_AT) as trans_date,
                        'ALIGNMENT' as trans_type,
                        DIFFERENCE as quantity,
                        CONCAT('Alignment Batch: ', BATCH) as remarks
                     FROM masterdata 
                     WHERE ITEM_ID = $selectedItem 
                     AND DATE(CREATED_AT) BETWEEN '$fromDate' AND '$toDate'
                     AND STATUS = 'Completed'";

    $alignmentResult = $conn->query($alignmentQuery);
    while ($row = $alignmentResult->fetch_assoc()) {
        $movements[] = $row;
    }

    // Get initial inventory from baseline or current inventory
    // Find the most recent baseline before fromDate
    $baselineQuery = "SELECT SYSTEM_QUANTITY, DATE_SNAPSHOT 
                     FROM baseline_inventory 
                     WHERE ITEM_ID = $selectedItem 
                     AND DATE_SNAPSHOT <= '$fromDate'
                     ORDER BY DATE_SNAPSHOT DESC 
                     LIMIT 1";
    $baselineResult = $conn->query($baselineQuery);

    $runningInventory = 0;
    $baselineDate = null;

    if ($baselineResult->num_rows > 0) {
        $baseline = $baselineResult->fetch_assoc();
        $runningInventory = (int) $baseline['SYSTEM_QUANTITY'];
        $baselineDate = $baseline['DATE_SNAPSHOT'];

        // Add all transactions from baselineDate+1 to fromDate-1
        if ($baselineDate < $fromDate) {
            $catchupQuery = "SELECT 
                                DATE(CREATED_AT) as trans_date,
                                CASE 
                                    WHEN SI_ID IS NOT NULL THEN 'STOCK_IN'
                                    WHEN SO_ID IS NOT NULL THEN 'STOCK_OUT'
                                    WHEN MD_ID IS NOT NULL THEN 'ALIGNMENT'
                                END as trans_type,
                                COALESCE(SI_QUANTITY, SO_QUANTITY, DIFFERENCE) as quantity
                            FROM (
                                SELECT 
                                    CREATED_AT,
                                    SI_ID,
                                    NULL as SO_ID,
                                    NULL as MD_ID,
                                    SI_QUANTITY,
                                    NULL as SO_QUANTITY,
                                    NULL as DIFFERENCE
                                FROM stock_in 
                                WHERE ITEM_ID = $selectedItem 
                                AND DATE(CREATED_AT) > '$baselineDate' 
                                AND DATE(CREATED_AT) < '$fromDate'
                                UNION ALL
                                SELECT 
                                    CREATED_AT,
                                    NULL as SI_ID,
                                    SO_ID,
                                    NULL as MD_ID,
                                    NULL as SI_QUANTITY,
                                    SO_QUANTITY,
                                    NULL as DIFFERENCE
                                FROM stock_out 
                                WHERE ITEM_ID = $selectedItem 
                                AND DATE(CREATED_AT) > '$baselineDate' 
                                AND DATE(CREATED_AT) < '$fromDate'
                                UNION ALL
                                SELECT 
                                    CREATED_AT,
                                    NULL as SI_ID,
                                    NULL as SO_ID,
                                    MD_ID,
                                    NULL as SI_QUANTITY,
                                    NULL as SO_QUANTITY,
                                    DIFFERENCE
                                FROM masterdata 
                                WHERE ITEM_ID = $selectedItem 
                                AND DATE(CREATED_AT) > '$baselineDate' 
                                AND DATE(CREATED_AT) < '$fromDate'
                                AND STATUS = 'Completed'
                            ) as movements
                            ORDER BY CREATED_AT";

            $catchupResult = $conn->query($catchupQuery);
            while ($trans = $catchupResult->fetch_assoc()) {
                if ($trans['trans_type'] == 'STOCK_IN') {
                    $runningInventory += (int) $trans['quantity'];
                } elseif ($trans['trans_type'] == 'STOCK_OUT') {
                    $runningInventory -= (int) $trans['quantity'];
                } else {
                    $runningInventory += (int) $trans['quantity'];
                }
            }
        }
    } else {
        // No baseline, get current inventory and work backwards
        $currentInvQuery = "SELECT INV_QUANTITY_PIECE 
                           FROM inventory 
                           WHERE ITEM_ID = $selectedItem";
        $currentInvResult = $conn->query($currentInvQuery);
        if ($currentInvResult->num_rows > 0) {
            $current = $currentInvResult->fetch_assoc();
            $runningInventory = (int) $current['INV_QUANTITY_PIECE'];

            // Subtract all movements up to toDate to get fromDate inventory
            $reverseQuery = "SELECT 
                                DATE(CREATED_AT) as trans_date,
                                CASE 
                                    WHEN SI_ID IS NOT NULL THEN 'STOCK_IN'
                                    WHEN SO_ID IS NOT NULL THEN 'STOCK_OUT'
                                    WHEN MD_ID IS NOT NULL THEN 'ALIGNMENT'
                                END as trans_type,
                                COALESCE(SI_QUANTITY, SO_QUANTITY, DIFFERENCE) as quantity
                            FROM (
                                SELECT 
                                    CREATED_AT,
                                    SI_ID,
                                    NULL as SO_ID,
                                    NULL as MD_ID,
                                    SI_QUANTITY,
                                    NULL as SO_QUANTITY,
                                    NULL as DIFFERENCE
                                FROM stock_in 
                                WHERE ITEM_ID = $selectedItem 
                                AND DATE(CREATED_AT) <= '$toDate'
                                UNION ALL
                                SELECT 
                                    CREATED_AT,
                                    NULL as SI_ID,
                                    SO_ID,
                                    NULL as MD_ID,
                                    NULL as SI_QUANTITY,
                                    SO_QUANTITY,
                                    NULL as DIFFERENCE
                                FROM stock_out 
                                WHERE ITEM_ID = $selectedItem 
                                AND DATE(CREATED_AT) <= '$toDate'
                                UNION ALL
                                SELECT 
                                    CREATED_AT,
                                    NULL as SI_ID,
                                    NULL as SO_ID,
                                    MD_ID,
                                    NULL as SI_QUANTITY,
                                    NULL as SO_QUANTITY,
                                    DIFFERENCE
                                FROM masterdata 
                                WHERE ITEM_ID = $selectedItem 
                                AND DATE(CREATED_AT) <= '$toDate'
                                AND STATUS = 'Completed'
                            ) as movements
                            ORDER BY CREATED_AT DESC";

            $reverseResult = $conn->query($reverseQuery);
            while ($trans = $reverseResult->fetch_assoc()) {
                if ($trans['trans_type'] == 'STOCK_IN') {
                    $runningInventory -= (int) $trans['quantity'];
                } elseif ($trans['trans_type'] == 'STOCK_OUT') {
                    $runningInventory += (int) $trans['quantity'];
                } else {
                    $runningInventory -= (int) $trans['quantity'];
                }
            }
        }
    }

    // Build daily data
    foreach ($dateRange as $date) {
        $beginningInventory = $runningInventory;

        // Get day's transactions
        $dayReceived = 0;
        $dayDispatched = 0;
        $dayAlignment = 0;

        foreach ($movements as $movement) {
            if ($movement['trans_date'] == $date) {
                if ($movement['trans_type'] == 'STOCK_IN') {
                    $dayReceived += (int) $movement['quantity'];
                } elseif ($movement['trans_type'] == 'STOCK_OUT') {
                    $dayDispatched += (int) $movement['quantity'];
                } else {
                    $dayAlignment += (int) $movement['quantity'];
                }
            }
        }

        // Calculate ending inventory
        $endingInventory = $beginningInventory + $dayReceived - $dayDispatched + $dayAlignment;

        // Add to daily data
        $dailyData[] = [
            'date' => $date,
            'item_code' => $itemDetails['ITEM_CODE'],
            'item_desc' => $itemDetails['ITEM_DESC'],
            'beginning' => $beginningInventory,
            'total_received' => $dayReceived,
            'total_dispatched' => $dayDispatched,
            'total_alignment' => $dayAlignment,
            'ending' => $endingInventory
        ];

        // Update running inventory for next day
        $runningInventory = $endingInventory;
    }
}

//  Pagination for Daily Data 
$totalDays = count($dailyData);
if ($limit > 0) {
    $totalPages = ceil($totalDays / $limit);
    $paginatedData = array_slice($dailyData, $offset, $limit);
} else {
    $totalPages = 1;
    $paginatedData = $dailyData;
}

// Build URL parameters for pagination
$urlParams = '';
if ($selectedItem > 0) {
    $urlParams .= "&item=" . $selectedItem;
}
if (!empty($fromDate)) {
    $urlParams .= "&from_date=" . urlencode($fromDate);
}
if (!empty($toDate)) {
    $urlParams .= "&to_date=" . urlencode($toDate);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Inventory Transaction Report</title>
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="inventory.css?id=<?= time() ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .date-cell {
            font-weight: 600;
            color: #2d3748;
        }

        .positive {
            color: #3b82f6;
        }

        .negative {
            color: #ef4444;
        }

        .neutral {
            color: #6b7280;
        }

        .alignment-positive {
            color: #3b82f6;
            font-weight: 600;
        }

        .alignment-negative {
            color: #ef4444;
            font-weight: 600;
        }

        .alignment-neutral {
            color: #6b7280;
        }
    </style>
</head>

<body>
    <div class="content w-[calc(100vw-60px)] mx-auto overflow-x-auto p-8 px-8">
        <div class="bg-white shadow-md rounded-lg p-6 mb-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="font-bold mb-4 text-[13px] xs:text-[12px] sm:text-[15px] text-[#0047bb]">
                    Daily Inventory Transaction Report
                </h2>

                <?php if ($selectedItem > 0 && !empty($dailyData)): ?>
                    <button id="exportExcelBtn"
                        class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-md flex items-center">
                        <i class='bx bx-export mr-2'></i> Export to Excel
                    </button>
                <?php endif; ?>
            </div>

            <!-- Filter Section -->
            <div class="bg-gray-50 p-4 rounded-lg mb-4">
                <h3 class="font-bold text-gray-700 mb-3 text-[13px] xs:text-[12px] sm:text-[15px]">Select Filters</h3>

                <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                    <!-- Item Filter -->
                    <div>
                        <label class="block mb-1 text-[13px] xs:text-[12px] sm:text-[15px] text-gray-700 font-bold">
                            Item:
                        </label>
                        <select name="item"
                            class="w-full text-[13px] xs:text-[12px] sm:text-[15px] pl-4 pr-4 py-2 border rounded focus:ring focus:ring-blue-300">
                            <option value="0">-- Select Item --</option>
                            <?php foreach ($items as $item): ?>
                                <option value="<?= $item['ITEM_ID'] ?>" <?= $selectedItem == $item['ITEM_ID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($item['ITEM_CODE'] . ' - ' . $item['ITEM_DESC']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- From Date -->
                    <div>
                        <label class="block mb-1 text-[13px] xs:text-[12px] sm:text-[15px] text-gray-700 font-bold">
                            From Date:
                        </label>
                        <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>"
                            class="w-full text-[13px] xs:text-[12px] sm:text-[15px] pl-4 pr-4 py-2 border rounded focus:ring focus:ring-blue-300">
                    </div>

                    <!-- To Date -->
                    <div>
                        <label class="block mb-1 text-[13px] xs:text-[12px] sm:text-[15px] text-gray-700 font-bold">
                            To Date:
                        </label>
                        <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>"
                            class="w-full text-[13px] xs:text-[12px] sm:text-[15px] pl-4 pr-4 py-2 border rounded focus:ring focus:ring-blue-300">
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex items-end gap-2">
                        <button type="submit"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-[13px] xs:text-[12px] sm:text-[15px] w-full">
                            Apply
                        </button>
                        <a href="?"
                            class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md text-[13px] xs:text-[12px] sm:text-[15px] w-full text-center">
                            Clear
                        </a>
                    </div>
                </form>

                <?php if ($selectedItem > 0 && isset($itemDetails)): ?>
                    <div class="mt-4 p-3 bg-blue-50 rounded">
                        <p class="text-sm text-blue-800">
                            <span class="font-bold">Selected:</span>
                            <?= htmlspecialchars($itemDetails['ITEM_CODE']) ?> -
                            <?= htmlspecialchars($itemDetails['ITEM_DESC']) ?>
                        </p>
                        <p class="text-sm text-blue-600 mt-1">
                            <span class="font-bold">Date Range:</span>
                            <?= htmlspecialchars(date('M d, Y', strtotime($fromDate))) ?> to
                            <?= htmlspecialchars(date('M d, Y', strtotime($toDate))) ?>
                            (<?= count($dateRange) ?> days)
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Table -->
            <?php if ($selectedItem > 0): ?>
                <div class="table-container  overflow-x-auto">
                    <table class="min-w-full table-auto border-separate border-spacing-0">
                        <thead>
                            <tr>
                                <th class="px-4 py-3 text-left border-b">Date</th>
                                <th class="px-4 py-3 text-left border-b">Stock No.</th>
                                <th class="px-4 py-3 text-left border-b">Description</th>
                                <th class="px-4 py-3 text-left border-b">Beginning</th>
                                <th class="px-4 py-3 text-left border-b">Stock In</th>
                                <th class="px-4 py-3 text-left border-b">Stock Out</th>
                                <th class="px-4 py-3 text-left border-b">Adjustment</th>
                                <th class="px-4 py-3 text-left border-b">Ending</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($paginatedData)): ?>
                                <?php foreach ($paginatedData as $data):
                                    $alignmentClass = 'alignment-neutral';
                                    if ($data['total_alignment'] > 0) {
                                        $alignmentClass = 'alignment-positive';
                                    } elseif ($data['total_alignment'] < 0) {
                                        $alignmentClass = 'alignment-negative';
                                    }
                                    ?>
                                    <tr class="hover:bg-gray-50 border-b border-gray-200">
                                        <td class="px-4 py-3 ">
                                            <?= htmlspecialchars(date('M d, Y', strtotime($data['date']))) ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?= htmlspecialchars($data['item_code']) ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?= htmlspecialchars($data['item_desc']) ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?= number_format($data['beginning']) ?>
                                        </td>
                                        <td class="px-4 py-3 <?= $data['total_received'] > 0 ? 'positive' : 'neutral' ?>">
                                            <?= number_format($data['total_received']) ?>
                                        </td>
                                        <td class="px-4 py-3 <?= $data['total_dispatched'] > 0 ? 'negative' : 'neutral' ?>">
                                            <?= number_format($data['total_dispatched']) ?>
                                        </td>
                                        <td class="px-4 py-3 <?= $alignmentClass ?>">
                                            <?php if ($data['total_alignment'] != 0): ?>
                                                <?= ($data['total_alignment'] > 0 ? '+' : '') . number_format($data['total_alignment']) ?>
                                            <?php else: ?>
                                                0
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 font-bold">
                                            <?= number_format($data['ending']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 border-b border-gray-300">
                                        No data found for the selected item and date range.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 0): ?>
                    <div class="flex flex-col items-center mt-4 gap-2">
                        <div class="flex items-center gap-1">
                            <a href="?item=<?= $selectedItem ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&page=<?= max(1, $page - 1) ?>&limit=<?= $limit ?>"
                                class="px-3 py-1 border rounded <?= ($page == 1 || $totalDays == 0) ? 'opacity-50 cursor-not-allowed pointer-events-none' : 'hover:bg-gray-200' ?>">
                                &lt;
                            </a>

                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <?php if ($totalPages <= 5 || $i == 1 || $i == $totalPages || abs($i - $page) <= 1): ?>
                                    <a href="?item=<?= $selectedItem ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&page=<?= $i ?>&limit=<?= $limit ?>"
                                        class="px-3 py-1 border rounded <?= $i == $page ? 'bg-blue-500 text-white' : 'hover:bg-gray-200' ?>">
                                        <?= $i ?>
                                    </a>
                                <?php elseif (($i == 2 && $page > 3) || ($i == $totalPages - 1 && $page < $totalPages - 2)): ?>
                                    <span class="px-3 py-1 text-gray-400 cursor-default">...</span>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <a href="?item=<?= $selectedItem ?>&from_date=<?= $fromDate ?>&to_date=<?= $toDate ?>&page=<?= min($totalPages, $page + 1) ?>&limit=<?= $limit ?>"
                                class="px-3 py-1 border rounded <?= ($page == $totalPages || $totalDays == 0) ? 'opacity-50 cursor-not-allowed pointer-events-none' : 'hover:bg-gray-200' ?>">
                                &gt;
                            </a>
                        </div>

                        <!-- Results per page dropdown -->
                        <form method="GET" class="inline-flex items-center gap-2">
                            <input type="hidden" name="item" value="<?= htmlspecialchars($selectedItem) ?>">
                            <input type="hidden" name="from_date" value="<?= htmlspecialchars($fromDate) ?>">
                            <input type="hidden" name="to_date" value="<?= htmlspecialchars($toDate) ?>">

                            <label for="limit" class="text-xs text-gray-600">Rows per page:</label>
                            <select onchange="this.form.submit()" name="limit" id="limit"
                                class="border px-2 py-1 rounded text-sm">
                                <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                                <option value="20" <?= $limit == 20 ? 'selected' : '' ?>>20</option>
                                <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                            </select>
                        </form>

                            <p class="text-center text-xs text-gray-500 mt-3">
                            Results: <?= $offset + 1 ?> - <?= min($offset + $limit, $totalDays) ?> of <?= $totalDays ?>
                        </p>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="bx bx-info-circle text-yellow-400 text-xl"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-yellow-700">
                                Select an item and date range to view daily inventory transactions.
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>


    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const exportBtn = document.getElementById('exportExcelBtn');
        if (exportBtn) {
            exportBtn.addEventListener('click', function () {
                const url = new URL(window.location.href);
                url.pathname = 'SDO_gapan_supply/API/export_transaction.php';
                window.location.href = url.toString();
            });
        }
    </script>
</body>

</html>