<?php
// admin/baseline-inventory.php
include "./sidebar.php";
session_regenerate_id(true);
include "../API/db-connector.php";

// Get current date and month
$currentDate = date('Y-m-d', time());
$currentMonth = date('m', time());
$currentYear = date('Y', time());

// Get month parameters (monthly only)
$reportMonth = isset($_GET['report_month']) ? $_GET['report_month'] : $currentMonth;
$reportYear = isset($_GET['report_year']) ? $_GET['report_year'] : $currentYear;

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination settings
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1)
    $page = 1;
$offset = ($page - 1) * $limit;

// Month range logic
$rawFrom = $_GET['from_month'] ?? '';
$rawTo = $_GET['to_month'] ?? '';

// Process from month
if ($rawFrom !== '') {
    if (strlen($rawFrom) === 7) {
        $fromDate = date('Y-m-01', strtotime($rawFrom . '-01'));
    } else {
        $ts = strtotime($rawFrom);
        $fromDate = date('Y-m-01', $ts);
    }
} else {
    $base = sprintf('%04d-%02d-01', (int) $reportYear, (int) $reportMonth);
    $fromDate = date('Y-m-01', strtotime($base));
}

// Process to month
if ($rawTo !== '') {
    if (strlen($rawTo) === 7) {
        $toDate = date('Y-m-t', strtotime($rawTo . '-01'));
    } else {
        $ts = strtotime($rawTo);
        $toDate = date('Y-m-t', $ts);
    }
} else {
    $toDate = date('Y-m-t', strtotime($fromDate));
}

// Ensure fromDate is not after toDate
if (strtotime($fromDate) > strtotime($toDate)) {
    $tmp = $fromDate;
    $fromDate = $toDate;
    $toDate = $tmp;
}

$reportMonth = date('m', strtotime($fromDate));
$reportYear = date('Y', strtotime($fromDate));

// Fetch Inventory Data
function fetchInventoryReport($conn, $fromDate, $toDate, $limit, $offset, $search = '')
{
    $searchCondition = "";
    if (!empty($search)) {
        $searchTerm = $conn->real_escape_string($search);
        $searchCondition = " AND (
            i.ITEM_CODE LIKE '%$searchTerm%' OR 
            i.ITEM_DESC LIKE '%$searchTerm%'
        )";
    }

    $query = "
        SELECT 
            i.ITEM_ID, 
            i.ITEM_CODE, 
            i.ITEM_DESC, 
            i.ITEM_UNIT,
            i.ITEM_UOM,
            IFNULL(inv.INV_QUANTITY_PIECE, 0) as current_inventory,
            (
                SELECT IFNULL(SUM(si.SI_QUANTITY), 0)
                FROM stock_in si
                WHERE si.ITEM_ID = i.ITEM_ID 
                  AND DATE(si.CREATED_AT) BETWEEN ? AND ?
            ) as total_received,
            (
                SELECT IFNULL(SUM(so.SO_QUANTITY), 0)
                FROM stock_out so
                WHERE so.ITEM_ID = i.ITEM_ID 
                  AND DATE(so.CREATED_AT) BETWEEN ? AND ?
            ) as total_dispatched,
            (
                SELECT IFNULL(SUM(imh.QUANTITY_PIECE), 0)
                FROM item_movement_history imh
                WHERE imh.ITEM_ID = i.ITEM_ID 
                  AND imh.MOVEMENT_TYPE = 'Return'
                  AND DATE(imh.MOVEMENT_DATE) BETWEEN ? AND ?
            ) as total_returns,
            (
                SELECT COUNT(*)
                FROM masterdata m
                WHERE m.ITEM_ID = i.ITEM_ID 
                  AND m.STATUS = 'Completed'
                  AND DATE(m.CREATED_AT) BETWEEN ? AND ?
            ) as alignment_count
        FROM item i
        LEFT JOIN inventory inv ON i.ITEM_ID = inv.ITEM_ID
        WHERE i.ITEM_IS_ARCHIVED = 0
        $searchCondition
        GROUP BY i.ITEM_ID
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param(
        "ssssssssii",
        $fromDate,
        $toDate,  // stock_in dates
        $fromDate,
        $toDate,  // stock_out dates
        $fromDate,
        $toDate,  // returns dates
        $fromDate,
        $toDate,  // alignment count dates
        $limit,
        $offset
    );

    $stmt->execute();
    return $stmt->get_result();
}

// Get total count for pagination
$totalQuery = "
    SELECT COUNT(*) AS total 
    FROM item i
    WHERE i.ITEM_IS_ARCHIVED = 0
";
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $totalQuery .= " AND (
        i.ITEM_CODE LIKE '%$searchTerm%' OR 
        i.ITEM_DESC LIKE '%$searchTerm%'
    )";
}
$totalResult = $conn->query($totalQuery);
$totalRow = $totalResult->fetch_assoc();
$totalInventory = (int) $totalRow['total'];
$totalPages = ($limit > 0) ? (int) ceil($totalInventory / $limit) : 1;

// Fetch report data
$result = fetchInventoryReport(
    $conn,
    $fromDate,
    $toDate,
    $limit,
    $offset,
    $search
);

// Get baseline inventory for beginning balance
$baselineQuery = "
    SELECT b.ITEM_ID, b.SYSTEM_QUANTITY as beginning_qty
    FROM baseline_inventory b
    WHERE b.DATE_SNAPSHOT <= ?
    ORDER BY b.DATE_SNAPSHOT DESC
";
$baselineStmt = $conn->prepare($baselineQuery);
$baselineStmt->bind_param("s", $fromDate);
$baselineStmt->execute();
$baselineResult = $baselineStmt->get_result();
$beginningBalances = [];
while ($row = $baselineResult->fetch_assoc()) {
    if (!isset($beginningBalances[$row['ITEM_ID']])) {
        $beginningBalances[$row['ITEM_ID']] = $row['beginning_qty'];
    }
}

// Pagination Links
function generatePageLinks($totalPages, $page, $limit, $reportMonth, $reportYear, $search = '', $fromMonth = '', $toMonth = '')
{
    $links = [];
    $searchParam = '';
    if (!empty($search)) {
        $searchParam .= "&search=" . urlencode($search);
    }
    if (!empty($fromMonth)) {
        $searchParam .= "&from_month=" . urlencode($fromMonth);
    }
    if (!empty($toMonth)) {
        $searchParam .= "&to_month=" . urlencode($toMonth);
    }

    if ($totalPages <= 5) {
        for ($i = 1; $i <= $totalPages; $i++) {
            $links[] = $i;
        }
    } else {
        $links = [1, 2, 3, "...", $totalPages - 1, $totalPages];
        if ($page >= 3 && $page <= $totalPages - 2) {
            $links = [1, "...", $page - 1, $page, $page + 1, "...", $totalPages];
        }
    }

    foreach ($links as &$link) {
        if ($link !== "...") {
            $pageNum = $link;
            $link = [
                'page' => $pageNum,
                'url' => "?report_month=$reportMonth"
                    . "&report_year=$reportYear"
                    . "&page=$pageNum"
                    . "&limit=$limit"
                    . $searchParam
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

$pageLinks = generatePageLinks(
    $totalPages,
    $page,
    $limit,
    $reportMonth,
    $reportYear,
    $search,
    $rawFrom,
    $rawTo
);

// Build URL parameters for navigation
$urlParams = '';
if (!empty($search)) {
    $urlParams .= "&search=" . urlencode($search);
}
if ($limit != 10) {
    $urlParams .= "&limit=$limit";
}
if (!empty($rawFrom)) {
    $urlParams .= "&from_month=" . urlencode($rawFrom);
}
if (!empty($rawTo)) {
    $urlParams .= "&to_month=" . urlencode($rawTo);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Report</title>
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="inventory.css?id=<?= time() ?>">

</head>

<body>
    <div class="content flex-1 w-full overflow-x-auto mx-auto flex flex-col">
        <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
            <h2 class="font-bold mb-4 text-[13px] xs:text-[12px] sm:text-[15px] font-bold text-[#0047bb]">Inventory
                Report</h2>
            <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                <form method="GET" action="" class="relative w-full sm:w-64">
                    <i class="absolute left-4 top-3 bx bx-search text-gray-500"></i>
                    <input type="search" name="search" id="searchInput" placeholder="Search"
                        value="<?= htmlspecialchars($search) ?>"
                        class="w-full pl-10 pr-4 py-2 border rounded-full focus:ring focus:ring-blue-300">
                    <!-- hidden field  -->
                    <input type="hidden" name="limit" value="<?= $limit ?>">
                    <input type="hidden" name="report_month" value="<?= $reportMonth ?>">
                    <input type="hidden" name="report_year" value="<?= $reportYear ?>">
                    <input type="hidden" name="from_month" value="<?= htmlspecialchars(substr($fromDate, 0, 7)) ?>">
                    <input type="hidden" name="to_month" value="<?= htmlspecialchars(substr($toDate, 0, 7)) ?>">
                </form>
                <!-- Export button -->
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%] flex items-end">
                    <a href="../API/export_inventory.php?from_month=<?= htmlspecialchars(substr($fromDate, 0, 7)) ?>&to_month=<?= htmlspecialchars(substr($toDate, 0, 7)) ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
                        class="bg-purple-500 text-sm text-white px-4 py-2 rounded-md hover:bg-purple-600 w-full text-center inline-block">
                        Export to Excel
                    </a>
                </div>
            </div>

            <!-- Month Range Filter -->
            <div>
                <form method="GET" class="flex flex-wrap gap-4 mt-6 mb-6">
                    <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%]">
                        <label class="text-sm text-gray-600 font-medium mb-1">From Month</label>
                        <input type="month" name="from_month" value="<?= htmlspecialchars(substr($fromDate, 0, 7)) ?>"
                            class="w-full border rounded px-4 py-2.5 text-gray-700 text-sm">
                    </div>

                    <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%]">
                        <label class="text-sm text-gray-600 font-medium mb-1">To Month</label>
                        <input type="month" name="to_month" value="<?= htmlspecialchars(substr($toDate, 0, 7)) ?>"
                            class="w-full border rounded px-4 py-2.5 text-gray-700 text-sm">
                    </div>
                    <div class="w-full sm:mt-0 lg:mt-8 md:mt-8 sm:w-[48%] md:w-[31%] lg:w-[23%]">
                        <button type="submit"
                            class="bg-green-500 text-sm text-white px-4 py-2 rounded-md hover:bg-green-600 w-full">
                            Apply
                        </button>
                    </div>
                </form>

                <!-- Search -->
                <!-- <form method="GET" class="flex-1 flex justify-end">
                    <input type="hidden" name="report_month" value="<?= $reportMonth ?>">
                    <input type="hidden" name="report_year" value="<?= $reportYear ?>">
                    <input type="hidden" name="from_month" value="<?= htmlspecialchars(substr($fromDate, 0, 7)) ?>">
                    <input type="hidden" name="to_month" value="<?= htmlspecialchars(substr($toDate, 0, 7)) ?>">
                    <div class="relative">
                        <i class='bx bx-search absolute left-3 top-2.5 text-gray-400'></i>
                        <input type="search" name="search" value="<?= htmlspecialchars($search) ?>"
                            placeholder="Search items..." class="border rounded-full pl-9 pr-4 py-2 text-sm w-64">
                    </div>
                </form> -->
            </div>

            <!-- Period Info -->
            <!-- <div class="text-xs text-gray-500 bg-blue-50 p-2 rounded">
                Period: <?= date('F Y', strtotime($fromDate)) ?> - <?= date('F Y', strtotime($toDate)) ?>
            </div> -->
        </div>

        <!-- Table -->
        <div class="table-container overflow-x-auto">
            <table class="min-w-full table-fixed border-separate border-spacing-0">
                <thead>
                    <tr>
                        <th>Stock No.</th>
                        <th>Item Description</th>
                        <th>Unit</th>
                        <th>Beginning</th>
                        <th>Stock In</th>
                        <th>Stock Out</th>
                        <th>Returns</th>
                        <th>Ending</th>
                        <th># Align</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()):
                            $beginningQty = isset($beginningBalances[$row['ITEM_ID']]) ? $beginningBalances[$row['ITEM_ID']] : 0;
                            $endingInventory = $beginningQty + $row['total_received'] - $row['total_dispatched'] + $row['total_returns'];
                            $unit = $row['ITEM_UNIT'] ?: ($row['ITEM_UOM'] ? $row['ITEM_UOM'] . ' pcs' : 'pcs');
                            ?>
                            <tr>
                                <td class="font-mono text-xs"><?= htmlspecialchars($row['ITEM_CODE']) ?></td>
                                <td><?= htmlspecialchars($row['ITEM_DESC']) ?></td>
                                <td><?= htmlspecialchars($unit) ?></td>
                                <td><?= number_format($beginningQty) ?></td>
                                <td class="text-green-600">+<?= number_format($row['total_received']) ?></td>
                                <td class="text-red-600">-<?= number_format($row['total_dispatched']) ?></td>
                                <td class="text-orange-600">+<?= number_format($row['total_returns']) ?></td>
                                <td class="font-medium <?= $endingInventory < 0 ? 'text-red-600' : 'text-blue-600' ?>">
                                    <?= number_format($endingInventory) ?>
                                </td>
                                <td>
                                    <span class="badge"><?= $row['alignment_count'] ?></span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center py-8 text-gray-500">
                                No data found
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
                    <a href="?report_month=<?= $reportMonth ?>&report_year=<?= $reportYear ?>&page=<?= max(1, $page - 1) ?>&limit=<?= $limit ?><?= $urlParams ?>"
                        class="px-3 py-1 border rounded <?= $page == 1 ? 'opacity-50 pointer-events-none' : 'hover:bg-gray-200' ?>">
                        &lt;
                    </a>
                    <?php foreach ($pageLinks as $p): ?>
                        <?php if ($p['page'] === "..."): ?>
                            <span class="px-3 py-1">...</span>
                        <?php else: ?>
                            <a href="<?= $p['url'] ?>"
                                class="px-3 py-1 border rounded <?= $p['page'] == $page ? 'bg-blue-500 text-white' : 'hover:bg-gray-200' ?>">
                                <?= $p['page'] ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <a href="?report_month=<?= $reportMonth ?>&report_year=<?= $reportYear ?>&page=<?= min($totalPages, $page + 1) ?>&limit=<?= $limit ?><?= $urlParams ?>"
                        class="px-3 py-1 border rounded <?= $page == $totalPages ? 'opacity-50 pointer-events-none' : 'hover:bg-gray-200' ?>">
                        &gt;
                    </a>
                </div>

                <form method="GET" class="flex items-center gap-2">
                    <input type="hidden" name="report_month" value="<?= $reportMonth ?>">
                    <input type="hidden" name="report_year" value="<?= $reportYear ?>">
                    <input type="hidden" name="from_month" value="<?= htmlspecialchars(substr($fromDate, 0, 7)) ?>">
                    <input type="hidden" name="to_month" value="<?= htmlspecialchars(substr($toDate, 0, 7)) ?>">
                    <?php if (!empty($search)): ?>
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <?php endif; ?>
                    <span class="text-xs">Rows:</span>
                    <select name="limit" onchange="this.form.submit()" class="border rounded px-2 py-1 text-sm">
                        <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                        <option value="20" <?= $limit == 20 ? 'selected' : '' ?>>20</option>
                        <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                    </select>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Simple Excel export
        document.getElementById('exportExcelBtn').addEventListener('click', function () {
            const table = document.querySelector('table');
            let csv = [];

            // Get headers
            let headers = [];
            table.querySelectorAll('thead th').forEach(th => {
                headers.push(th.textContent.trim());
            });
            csv.push(headers.join(','));

            // Get data
            table.querySelectorAll('tbody tr').forEach(tr => {
                let row = [];
                tr.querySelectorAll('td').forEach(td => {
                    row.push('"' + td.textContent.trim().replace(/"/g, '""') + '"');
                });
                if (row.length) csv.push(row.join(','));
            });

            // Download
            const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'inventory_report.csv';
            a.click();
        });
    </script>
</body>

</html>