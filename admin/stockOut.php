<?php
// admin/stockOut.php
include "./sidebar.php";
include "../API/db-connector.php";

// Search and filter functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$dateFrom = isset($_GET['dateFrom']) ? $_GET['dateFrom'] : date('Y-m-d');
$dateTo = isset($_GET['dateTo']) ? $_GET['dateTo'] : null;

$whereClause = "WHERE (
    item.ITEM_IS_ARCHIVED = 0
    OR item.ITEM_ID IN (SELECT DISTINCT ITEM_ID FROM stock_out)
)";

if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $whereClause .= " AND (
        item.ITEM_CODE LIKE '%$searchTerm%' OR
        item.ITEM_DESC LIKE '%$searchTerm%' OR
        item.ITEM_UNIT LIKE '%$searchTerm%' OR
        office.OFF_CODE LIKE '%$searchTerm%' OR
        stock_out.SO_REMARKS LIKE '%$searchTerm%' OR
        stock_out.SO_QUANTITY LIKE '%$searchTerm%' OR
        stock_out.SO_RIS_NO LIKE '%$searchTerm%'
    )";
}

// Add date filters
if ($dateFrom) {
    $dateFromEscaped = $conn->real_escape_string($dateFrom);
    $whereClause .= " AND stock_out.CREATED_AT >= '$dateFromEscaped 00:00:00'";
}

if ($dateTo) {
    $dateToEscaped = $conn->real_escape_string($dateTo);
    $whereClause .= " AND stock_out.CREATED_AT <= '$dateToEscaped 23:59:59'";
}

// Pagination settings
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Get total number of RIS batches with filters
$totalQuery = "SELECT COUNT(DISTINCT stock_out.SO_RIS_NO) as total 
               FROM stock_out 
               LEFT JOIN item ON stock_out.ITEM_ID = item.ITEM_ID 
               JOIN office ON stock_out.OFF_ID = office.OFF_ID 
               $whereClause";
$totalResult = $conn->query($totalQuery);
$totalRow = $totalResult->fetch_assoc();
$totalItem = $totalRow['total'];

$totalPages = ceil($totalItem / $limit);

// Get RIS batches with summary data
$sql = "SELECT 
            stock_out.SO_RIS_NO,
            MIN(office.OFF_ID) as OFF_ID,
            MIN(office.OFF_CODE) as OFF_CODE,
            MIN(office.OFF_NAME) as OFF_NAME,
            COUNT(stock_out.SO_ID) as TOTAL_ITEMS,
            SUM(stock_out.SO_QUANTITY) as TOTAL_QUANTITY,
            MIN(stock_out.CREATED_AT) as CREATED_AT,
            MAX(stock_out.CREATED_AT) as LAST_UPDATED,
            MIN(users.USER_FNAME) as CREATED_BY_FNAME,
            MIN(users.USER_LNAME) as CREATED_BY_LNAME
        FROM stock_out
        LEFT JOIN item ON stock_out.ITEM_ID = item.ITEM_ID
        JOIN office ON stock_out.OFF_ID = office.OFF_ID
        LEFT JOIN users ON stock_out.CREATED_BY = users.USER_ID
        $whereClause
        GROUP BY stock_out.SO_RIS_NO
        ORDER BY CREATED_AT DESC
        LIMIT $limit OFFSET $offset";

$result = $conn->query($sql);

function generatePageLinks($totalPages, $page, $limit, $search = '', $dateFrom = '', $dateTo = '')
{
    $links = [];

    // Build URL parameters
    $urlParams = '';
    if (!empty($search))
        $urlParams .= "&search=" . urlencode($search);
    if (!empty($dateFrom))
        $urlParams .= "&dateFrom=" . urlencode($dateFrom);
    if (!empty($dateTo))
        $urlParams .= "&dateTo=" . urlencode($dateTo);

    if ($totalPages <= 5) {
        for ($i = 1; $i <= $totalPages; $i++) {
            $links[] = [
                'page' => $i,
                'url' => "?page=$i&limit=$limit$urlParams"
            ];
        }
    } else {
        $links = [
            ['page' => 1, 'url' => "?page=1&limit=$limit$urlParams"],
            ['page' => 2, 'url' => "?page=2&limit=$limit$urlParams"],
            ['page' => 3, 'url' => "?page=3&limit=$limit$urlParams"],
            ['page' => "...", 'url' => "#"],
            ['page' => $totalPages - 1, 'url' => "?page=" . ($totalPages - 1) . "&limit=$limit$urlParams"],
            ['page' => $totalPages, 'url' => "?page=$totalPages&limit=$limit$urlParams"]
        ];

        if ($page >= 3 && $page <= $totalPages - 2) {
            $links = [
                ['page' => 1, 'url' => "?page=1&limit=$limit$urlParams"],
                ['page' => "...", 'url' => "#"],
                ['page' => $page - 1, 'url' => "?page=" . ($page - 1) . "&limit=$limit$urlParams"],
                ['page' => $page, 'url' => "?page=$page&limit=$limit$urlParams"],
                ['page' => $page + 1, 'url' => "?page=" . ($page + 1) . "&limit=$limit$urlParams"],
                ['page' => "...", 'url' => "#"],
                ['page' => $totalPages, 'url' => "?page=$totalPages&limit=$limit$urlParams"]
            ];
        }
    }

    return $links;
}

$pageLinks = generatePageLinks($totalPages, $page, $limit, $search, $dateFrom, $dateTo);

// Build URL parameters for navigation
$urlParams = '';

if (!empty($search)) {
    $urlParams .= "&search=" . urlencode($search);
}

if (!empty($dateFrom)) {
    $urlParams .= "&dateFrom=" . urlencode($dateFrom);
}

if (!empty($dateTo)) {
    $urlParams .= "&dateTo=" . urlencode($dateTo);
}

if ($limit != 10) {
    $urlParams .= "&limit=" . $limit;
}

// Fetch items for dropdown with available quantity
$itemQuery = "SELECT i.ITEM_ID, i.ITEM_CODE, i.ITEM_DESC, i.ITEM_UNIT, 
              COALESCE(inv.INV_QUANTITY_PIECE, 0) as AVAILABLE_QTY 
              FROM item i 
              LEFT JOIN inventory inv ON i.ITEM_ID = inv.ITEM_ID 
              WHERE i.ITEM_IS_ARCHIVED = 0 
              ORDER BY i.ITEM_CODE ASC";
$itemResult = $conn->query($itemQuery);

$offQuery = "SELECT * FROM office WHERE OFF_IS_ARCHIVED = 0 ORDER BY OFF_CODE ASC";
$offResult = $conn->query($offQuery);

// Store items in array for JavaScript
$itemsArray = [];
$itemResult->data_seek(0);
while ($row = $itemResult->fetch_assoc()) {
    $itemsArray[] = $row;
}
$itemResult->data_seek(0);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Out Management</title>
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="inventory.css?id=<?= time() ?>">
    <style>
        @media print {
            @page {
                size: A4;
                margin: 1cm;
            }
        }

        .return-badge {
            background-color: #f97316;
            color: white;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 9999px;
            margin-left: 5px;
        }

        /* Dropdown positioning fix */
        .action-column .relative {
            position: relative;
        }

        [x-cloak] {
            display: none !important;
        }

        /* Ensure dropdown appears above other content */
        /* To this (or remove it entirely): */
        .dropdown-menu {
            z-index: 50;
        }

        #batchModal,
        #viewModal {
            z-index: 1000;
        }

        /* Dropdown animations */
        [x-cloak] {
            display: none !important;
        }

        /* Better button hover effects */
        .action-column button {
            transition: all 0.2s ease;
        }

        /* Dropdown menu styling */
        .dropdown-menu {
            transform-origin: top right;
            animation: dropdownFade 0.2s ease;
        }

        @keyframes dropdownFade {
            from {
                opacity: 0;
                transform: scale(0.95);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        /* Optional: Add a badge for return count if needed */
        .return-badge {
            background-color: #f97316;
            color: white;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 9999px;
            margin-left: 8px;
        }

        /* Prevent the table container from clipping the dropdown */
        .table-container {
            padding-bottom: 30px;
            /* Gives room for the bottom-most dropdowns */
        }

        /* Ensure the dropdown always stays on top of subsequent table rows */
        tr:hover {
            z-index: 10;
            position: relative;
        }

        [x-cloak] {
            display: none !important;
        }

        /* Desktop-friendly dropdown positioning */
        @media (min-width: 640px) {
            .dropdown-menu {
                min-width: 14rem;
            }
        }
    </style>
</head>

<body>
    <div class="content w-[calc(100vw-60px)] mx-auto overflow-x-auto p-8 px-8">
        <div class="bg-white shadow-md rounded-lg p-6 mb-6">
            <h2 class="font-bold mb-4 text-[13px] xs:text-[12px] sm:text-[15px] font-bold text-[#0047bb]">Requisition &
                Issue Slip (RIS) Management</h2>
            <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                <form method="GET" action="" class="relative w-full sm:w-64">
                    <i class="absolute left-4 top-3 bx bx-search text-gray-500"></i>
                    <input type="search" name="search" id="searchInput" placeholder="Search"
                        value="<?= htmlspecialchars($search) ?>"
                        class="w-full pl-10 pr-4 py-2 border rounded-full focus:ring focus:ring-blue-300">
                    <input type="hidden" name="limit" value="<?= $limit ?>">
                    <?php if (!empty($dateFrom)): ?>
                        <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                    <?php endif; ?>

                    <?php if (!empty($dateTo)): ?>
                        <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                    <?php endif; ?>
                </form>
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%] items-end">
                    <button id="openAddModal"
                        class="bg-blue-500 text-sm text-white px-4 py-2 rounded-md hover:bg-blue-600 w-full">
                        New RIS Batch
                    </button>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="flex flex-wrap gap-4 mt-6 mb-6">
                <!-- From Date-Time -->
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%]">
                    <label for="dateFrom" class="text-sm text-gray-600 font-medium mb-1">From</label>
                    <input type="date" id="dateFrom" class="w-full border rounded px-4 py-2.5 text-gray-700 text-sm"
                        value="<?= htmlspecialchars($_GET['dateFrom'] ?? '') ?>" />
                </div>

                <!-- To Date-Time -->
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%]">
                    <label for="dateTo" class="text-sm text-gray-600 font-medium mb-1">To</label>
                    <input type="date" id="dateTo" class="w-full border rounded px-4 py-2.5 text-gray-700 text-sm"
                        value="<?= htmlspecialchars($_GET['dateTo'] ?? '') ?>" />
                </div>

                <!-- Filter Button -->
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%] flex items-end">
                    <button onclick="applyFilters()"
                        class="bg-green-500 text-sm text-white px-4 py-2 rounded-md hover:bg-green-600 w-full">
                        Apply Filters
                    </button>
                </div>

                <!-- Clear Filters Button -->
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%] flex items-end">
                    <button onclick="clearFilters()"
                        class="bg-gray-500 text-sm text-white px-4 py-2 rounded-md hover:bg-gray-600 w-full">
                        Clear Filters
                    </button>
                </div>
            </div>
        </div>

        <!-- Table -->
        <div class="table-container overflow-x-auto">
            <table class="min-w-full table-fixed border-separate border-spacing-0">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left">RIS No.</th>
                        <th class="px-4 py-2 text-left">Office</th>
                        <th class="px-4 py-2 text-left">Total Items</th>
                        <th class="px-4 py-2 text-left">Total Quantity</th>
                        <th class="px-4 py-2 text-left">Date Created</th>
                        <th class="px-4 py-2 text-left">Created By</th>
                        <th class="px-4 py-2 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody id="table-body">
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($batch = $result->fetch_assoc()):
                            $risNo = $batch['SO_RIS_NO'];
                            $createdBy = $batch['CREATED_BY_FNAME'] . ' ' . $batch['CREATED_BY_LNAME'];
                            ?>
                            <tr class="hover:bg-gray-200 border-b border-gray-300">
                                <td class="px-4 py-2 font-medium"><?= htmlspecialchars($risNo) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($batch['OFF_CODE']) ?></td>
                                <td class="px-4 py-2"><?= $batch['TOTAL_ITEMS'] ?> item(s)</td>
                                <td class="px-4 py-2"><?= $batch['TOTAL_QUANTITY'] ?></td>
                                <td class="px-4 py-2"><?= date('M j, Y \a\t g:i a', strtotime($batch['CREATED_AT'])) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($createdBy) ?></td>
                                <td class="px-4 py-2 text-right">
                                    <div x-data="{ open: false }" class="relative inline-block text-left">
                                        <button @click="open = !open" @click.away="open = false"
                                            class="flex items-center justify-center w-9 h-9 rounded-full hover:bg-gray-100 text-gray-500 hover:text-blue-600 transition-all duration-200 focus:outline-none">
                                            <i class='bx bx-dots-vertical-rounded text-xl'></i>
                                        </button>

                                        <div x-show="open" x-cloak x-transition:enter="transition ease-out duration-100"
                                            x-transition:enter-start="transform opacity-0 scale-95"
                                            x-transition:enter-end="transform opacity-100 scale-100"
                                            x-transition:leave="transition ease-in duration-75"
                                            x-transition:leave-start="transform opacity-100 scale-100"
                                            x-transition:leave-end="transform opacity-0 scale-95"
                                            class="absolute right-0 mt-2 w-48 bg-white border border-gray-200 rounded-lg shadow-xl z-[100] overflow-hidden">

                                            <div class="py-1 text-sm text-gray-700">
                                                <button onclick="viewBatch('<?= htmlspecialchars($risNo) ?>')"
                                                    class="flex items-center w-full px-4 py-2 hover:bg-blue-50 hover:text-blue-700 transition-colors">
                                                    <i class='bx bx-show mr-3 text-lg text-blue-500'></i>
                                                    View Details
                                                </button>

                                                <button onclick="editBatch('<?= htmlspecialchars($risNo) ?>')"
                                                    class="flex items-center w-full px-4 py-2 hover:bg-amber-50 hover:text-amber-700 transition-colors">
                                                    <i class='bx bx-edit-alt mr-3 text-lg text-amber-500'></i>
                                                    Edit Batch
                                                </button>

                                                <div class="border-t border-gray-100 my-1"></div>

                                                <button onclick="returnItems('<?= htmlspecialchars($risNo) ?>')"
                                                    class="flex items-center w-full px-4 py-2 hover:bg-orange-50 hover:text-orange-700 transition-colors">
                                                    <i class='bx bx-undo mr-3 text-lg text-orange-500'></i>
                                                    Process Return
                                                </button>

                                                <button onclick="viewReturns('<?= htmlspecialchars($risNo) ?>')"
                                                    class="flex items-center w-full px-4 py-2 hover:bg-indigo-50 hover:text-indigo-700 transition-colors">
                                                    <i class='bx bx-history mr-3 text-lg text-indigo-500'></i>
                                                    Return History
                                                </button>

                                                <div class="border-t border-gray-100 my-1"></div>

                                                <button onclick="exportToExcel('<?= htmlspecialchars($risNo) ?>')"
                                                    class="flex items-center w-full px-4 py-2 hover:bg-green-50 hover:text-green-700 transition-colors">
                                                    <i class='bx bx-file mr-3 text-lg text-green-600'></i>
                                                    Export to Excel
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr id="no-data-row">
                            <td colspan="7" class="text-center py-2 border-b border-gray-300">
                                <?php if (!empty($search)): ?>
                                    No results found for "<?= htmlspecialchars($search) ?>"
                                <?php elseif ($dateFrom === date('Y-m-d') && $dateTo === date("Y-m-d")): ?>
                                    No stock out records found for today
                                <?php elseif (!empty($dateFrom) || !empty($dateTo)): ?>
                                    No results found for the selected date range
                                <?php else: ?>
                                    No stock out records found
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 0): ?>
            <div class="flex justify-center mt-4">
                <a href="?page=<?= max(1, $page - 1) ?>&limit=<?= $limit ?><?= $urlParams ?>"
                    class="px-3 py-1 border rounded <?= ($page == 1 || $totalItem == 0) ? 'opacity-50 cursor-not-allowed pointer-events-none' : 'hover:bg-gray-200' ?>">
                    &lt;
                </a>
                <?php foreach ($pageLinks as $p): ?>
                    <?php if ($p['page'] === "..."): ?>
                        <span class="px-3 py-1 text-gray-400 cursor-default select-none">...</span>
                    <?php else: ?>
                        <a href="<?= $p['url'] ?>"
                            class="px-3 py-1 border rounded <?= $p['page'] == $page ? 'bg-blue-500 text-white' : 'hover:bg-gray-200' ?>">
                            <?= $p['page'] ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>

                <a href="?page=<?= min($totalPages, $page + 1) ?>&limit=<?= $limit ?><?= $urlParams ?>"
                    class="px-3 py-1 border rounded <?= ($page == $totalPages || $totalItem == 0) ? 'opacity-50 cursor-not-allowed pointer-events-none' : 'hover:bg-gray-200' ?>">
                    &gt;
                </a>

                <form method="GET" class="inline">
                    <?php if (!empty($search)): ?>
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <?php endif; ?>
                    <?php if (!empty($dateFrom)): ?>
                        <input type="hidden" name="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>">
                    <?php endif; ?>
                    <?php if (!empty($dateTo)): ?>
                        <input type="hidden" name="dateTo" value="<?= htmlspecialchars($dateTo) ?>">
                    <?php endif; ?>
                    <select onchange="this.form.submit()" name="limit" class="border px-2 py-1 rounded text-sm ml-4">
                        <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                        <option value="20" <?= $limit == 20 ? 'selected' : '' ?>>20</option>
                        <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                    </select>
                </form>
            </div>

            <p class="text-center text-xs text-gray-500 mt-3">
                Results: <?= $offset + 1 ?> - <?= min($offset + $limit, $totalItem) ?> of <?= $totalItem ?>
                <?php if (!empty($search)): ?>
                    for "<?= htmlspecialchars($search) ?>"
                <?php endif; ?>
                batches
            </p>
        <?php endif; ?>
    </div>

    <!-- View Modal -->
    <div id="viewModal"
        class="fixed inset-0 flex items-center justify-center bg-gray-900 bg-opacity-50 z-[1000] hidden">
        <div
            class="bg-white m-3 rounded-lg shadow-lg p-4 sm:p-6 w-full sm:w-[90%] lg:w-[900px] max-h-[90vh] overflow-y-auto text-sm sm:text-base">
            <h2 class="text-xl font-bold mb-4">RIS Details: <span id="viewRisNo"></span></h2>

            <div class="mb-4 grid grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-gray-600">Office:</p>
                    <p id="viewOffice" class="font-medium"></p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Date Created:</p>
                    <p id="viewDate" class="font-medium"></p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Created By:</p>
                    <p id="viewCreatedBy" class="font-medium"></p>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Purpose:</p>
                    <p class="font-medium">For Office Purposes</p>
                </div>
            </div>

            <table class="min-w-full border rounded-lg overflow-scroll mb-4">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left border">Stock No.</th>
                        <th class="px-4 py-2 text-left border">Item Description</th>
                        <th class="px-4 py-2 text-left border">Unit</th>
                        <th class="px-4 py-2 text-left border">Quantity</th>
                        <th class="px-4 py-2 text-left border">Remarks</th>
                    </tr>
                </thead>
                <tbody id="viewItemsContainer">
                    <!-- Items will be loaded here -->
                </tbody>
            </table>

            <!-- Returns History Section -->
            <div id="returnsHistorySection" class="mt-4 hidden">
                <h3 class="text-lg font-semibold mb-2">Return History</h3>
                <table class="min-w-full border rounded-lg overflow-scroll">
                    <thead>
                        <tr>
                            <th class="px-4 py-2 text-left border">Return No.</th>
                            <th class="px-4 py-2 text-left border">Item</th>
                            <th class="px-4 py-2 text-left border">Qty</th>
                            <th class="px-4 py-2 text-left border">Reason</th>
                            <th class="px-4 py-2 text-left border">Date</th>
                            <th class="px-4 py-2 text-left border">Returned By</th>
                        </tr>
                    </thead>
                    <tbody id="returnsHistoryContainer">
                        <!-- Returns will be loaded here -->
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end gap-2 mt-4">
                <button type="button" onclick="closeViewModal()"
                    class="bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600">Close</button>
                <button type="button" onclick="returnItems(document.getElementById('viewRisNo').textContent)"
                    class="bg-orange-500 text-white px-4 py-2 rounded-md hover:bg-orange-600">
                    Process Return
                </button>
                <button type="button" onclick="exportToExcel(document.getElementById('viewRisNo').textContent)"
                    class="bg-purple-500 text-white px-4 py-2 rounded-md hover:bg-purple-600">Export to Excel</button>
            </div>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div id="batchModal"
        class="fixed inset-0 flex items-center justify-center bg-gray-900 bg-opacity-50 z-[1000] hidden">
        <div class="bg-white m-3 sm:m-3 rounded-lg shadow-lg p-4 sm:p-6 
            w-full sm:w-[95%] lg:w-[800px] 
            max-h-[95vh] overflow-y-auto 
            text-sm sm:text-base">
            <h2 id="modalTitle" class="text-xl font-bold mb-4">New RIS Batch</h2>

            <!-- SEARCH BAR ADDED HERE -->
            <div class="mb-4 p-3 bg-blue-50 rounded-lg border border-blue-200">
                <label class="block font-medium text-sm text-blue-800 mb-2">Search Item</label>
                <div class="relative">
                    <i class="absolute left-3 top-3 bx bx-search text-gray-400"></i>
                    <input type="text" id="itemSearch" placeholder="Type item code or description..."
                        class="w-full pl-10 pr-4 py-2 border rounded-lg focus:ring focus:ring-blue-300 text-sm">
                </div>
                <div id="searchResults"
                    class="absolute z-20 w-[752px] mt-1 bg-white border rounded-lg shadow-lg max-h-60 overflow-y-auto hidden">
                </div>
            </div>

            <form id="batchForm">
                <input type="hidden" name="ris_no" id="ris_no">

                <div class="mb-4">
                    <label class="block font-medium mb-1" for="off">Office</label>
                    <select name="off" id="off" class="w-full border rounded px-3 py-2" required>
                        <option value="" selected disabled>Select office</option>
                        <?php while ($row = $offResult->fetch_assoc()): ?>
                            <option value="<?= $row['OFF_ID'] ?>">
                                <?= htmlspecialchars($row['OFF_CODE']) ?> - <?= htmlspecialchars($row['OFF_NAME']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <div class="flex justify-between items-center mb-2">
                        <label class="block font-medium">Items</label>
                        <button type="button" id="addItemRow"
                            class="text-blue-600 hover:text-blue-800 text-sm flex items-center">
                            <i data-lucide="plus-circle" class="w-4 h-4 mr-1"></i> Add Item
                        </button>
                    </div>

                    <div id="itemsContainer">
                        <!-- Item rows will be dynamically added here -->
                    </div>
                </div>

                <div class="flex justify-end gap-2 mt-4">
                    <button type="button" onclick="closeBatchModal()"
                        class="bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600">Cancel</button>
                    <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded-md hover:bg-blue-600">Save
                        Batch</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script>
        // Item data from PHP
        const itemsData = <?= json_encode($itemsArray) ?>;

        lucide.createIcons();

        document.getElementById('searchInput').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                this.form.submit();
            }
        });

        // Date filter functions
        function applyFilters() {
            const dateFrom = document.getElementById("dateFrom").value;
            const dateTo = document.getElementById("dateTo").value;
            const search = document.getElementById("searchInput").value;
            const limit = <?= $limit ?>;

            let url = `?page=1&limit=${limit}`;

            if (search) {
                url += `&search=${encodeURIComponent(search)}`;
            }

            if (dateFrom) {
                url += `&dateFrom=${encodeURIComponent(dateFrom)}`;
            }

            if (dateTo) {
                url += `&dateTo=${encodeURIComponent(dateTo)}`;
            }

            window.location.href = url;
        }

        function clearFilters() {
            const limit = <?= $limit ?>;
            window.location.href = `?page=1&limit=${limit}`;
        }

        // Function to prompt for admin password
        async function promptPassword() {
            const { value: password } = await Swal.fire({
                title: "Verification Required",
                input: "password",
                inputLabel: "Enter your password to continue",
                inputPlaceholder: "Password",
                showCancelButton: true,
                reverseButtons: true,
                inputValidator: (value) => {
                    if (!value) {
                        return "Password is required!";
                    }
                },
            });

            return password;
        }

        // Generate item options HTML
        function getItemOptions(selectedId = null) {
            let options = '<option value="">Select Item</option>';
            itemsData.forEach(item => {
                const selected = (selectedId && item.ITEM_ID == selectedId) ? 'selected' : '';
                options += `<option value="${item.ITEM_ID}" data-available="${item.AVAILABLE_QTY}" ${selected}>` +
                    `${item.ITEM_CODE} - ${item.ITEM_DESC} (Avail: ${item.AVAILABLE_QTY})</option>`;
            });
            return options;
        }

        // Create item row HTML
        function createItemRow(itemData = null) {
            const selectedId = itemData ? parseInt(itemData.item_id) : null;
            const quantity = itemData ? itemData.quantity : '';
            const remarks = itemData ? itemData.remarks : '';

            const row = document.createElement('div');
            row.className = 'item-row border border-blue-200 rounded p-3 mb-3 bg-blue-50';
            row.innerHTML = `
                <div class="grid grid-cols-1 sm:grid-cols-12 gap-2 text-sm sm:text-base">
                    <div class="sm:col-span-6">
                        <select name="item_id[]" class="w-full border rounded px-2 py-1 text-sm item-select" required>
                            ${getItemOptions(selectedId)}
                        </select>
                        <div class="text-xs text-gray-500 mt-1 available-hint"></div>
                    </div>
                    <div class="sm:col-span-2">
                        <input type="number" name="quantity[]" placeholder="Qty" min="1" value="${quantity}"
                               class="w-full border rounded px-2 py-1 text-sm quantity-input" required>
                    </div>
                    <div class="sm:col-span-3">
                        <input type="text" name="remarks[]" placeholder="Remarks" value="${remarks.replace(/"/g, '&quot;')}"
                               class="w-full border rounded px-2 py-1 text-sm" required>
                    </div>
                    <div class="sm:col-span-1 flex items-center justify-center">
                        <button type="button" class="remove-item border flex items-center justify-center rounded border-red-200 w-full p-2 text-red-500 hover:text-red-700">
                            <i data-lucide="x" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>
            `;

            // Attach validation
            const select = row.querySelector('.item-select');
            const quantityInput = row.querySelector('.quantity-input');
            const hint = row.querySelector('.available-hint');

            select.addEventListener('change', function () {
                const option = this.options[this.selectedIndex];
                const available = parseInt(option.dataset.available || 0);

                hint.textContent = `Available: ${available}`;
                quantityInput.max = available;

                // Reset quantity if exceeds available
                if (parseInt(quantityInput.value) > available) {
                    quantityInput.value = '';
                }

                // Clear previous validation state
                quantityInput.setCustomValidity('');
                quantityInput.reportValidity();
            });

            quantityInput.addEventListener('input', function () {
                const option = select.options[select.selectedIndex];
                const available = parseInt(option.dataset.available || 0);
                const entered = parseInt(this.value || 0);

                if (available === 0) {
                    this.setCustomValidity('This item has no available stock');
                } else if (entered > available) {
                    this.setCustomValidity(`Quantity cannot exceed available stock (${available})`);
                } else {
                    this.setCustomValidity('');
                }

                this.reportValidity();
            });

            // Trigger change to show available hint
            if (selectedId) {
                setTimeout(() => {
                    select.dispatchEvent(new Event('change'));
                }, 100);
            }

            row.querySelector('.remove-item').addEventListener('click', function () {
                if (document.querySelectorAll('#itemsContainer .item-row').length > 1) {
                    row.remove();
                } else {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Cannot Remove',
                        text: 'At least one item is required'
                    });
                }
            });

            return row;
        }

        // Open Add Modal
        document.getElementById('openAddModal').addEventListener('click', function () {
            document.getElementById('modalTitle').textContent = 'New RIS Batch';
            document.getElementById('ris_no').value = '';
            document.getElementById('off').value = ''

            const container = document.getElementById('itemsContainer');
            container.innerHTML = '';
            container.appendChild(createItemRow());

            document.getElementById('batchModal').classList.remove('hidden');
            document.getElementById('off').focus();
            lucide.createIcons();
        });

        // Edit Batch
        function editBatch(risNo) {
            fetch(`getBatchDetails.php?ris_no=${encodeURIComponent(risNo)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('modalTitle').textContent = 'Edit RIS Batch';
                        document.getElementById('ris_no').value = risNo;
                        document.getElementById('off').value = data.off_id;

                        const container = document.getElementById('itemsContainer');
                        container.innerHTML = '';

                        data.items.forEach(item => {
                            container.appendChild(createItemRow(item));
                        });

                        document.getElementById('batchModal').classList.remove('hidden');
                        document.getElementById('off').focus();
                        lucide.createIcons();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Failed to load batch details'
                        });
                    }
                });
        }

        // Add Item Row button
        document.getElementById('addItemRow').addEventListener('click', function () {
            document.getElementById('itemsContainer').appendChild(createItemRow());
            lucide.createIcons();
        });

        // Close Modal
        function closeBatchModal() {
            document.getElementById('batchModal').classList.add('hidden');
            if (itemSearch) {
                itemSearch.value = '';
                searchResults.classList.add('hidden');
            }
        }

        // Form Submission
        document.getElementById('batchForm').addEventListener('submit', async function (e) {
            e.preventDefault();

            const items = [];
            const itemSelects = document.querySelectorAll('#itemsContainer .item-select');
            const quantities = document.querySelectorAll('#itemsContainer .quantity-input');
            const remarks = document.querySelectorAll('#itemsContainer input[name="remarks[]"]');

            for (let i = 0; i < itemSelects.length; i++) {
                if (!itemSelects[i].value || !quantities[i].value || !remarks[i].value) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Missing Fields',
                        text: 'Please fill in all item fields'
                    });
                    return;
                }

                items.push({
                    item_id: itemSelects[i].value,
                    quantity: quantities[i].value,
                    remarks: remarks[i].value
                });
            }

            const data = {
                ris_no: document.getElementById('ris_no').value,
                off_id: document.getElementById('off').value,
                items: items,
                action: document.getElementById('ris_no').value ? 'edit_batch' : 'add_batch',
            };

            let password = null;

            if (data.action === "edit_batch") {
                const result = await Swal.fire({
                    title: "Are you sure?",
                    text: "This action will update the RIS record.",
                    icon: "warning",
                    showCancelButton: true,
                    confirmButtonColor: "#3085d6",
                    confirmButtonText: "Yes, update it!",
                    reverseButtons: true,
                });

                if (!result.isConfirmed) return;

                password = await promptPassword();
                if (!password) return;
            }

            if (!data.off_id) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing Fields',
                    text: 'Please select an office'
                });
                return;
            }

            Swal.fire({
                title: 'Processing...',
                text: 'Please wait',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            try {
                const response = await fetch('stockOut-php.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ...data, password: password })
                });

                const result = await response.json();

                if (result.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: result.message
                    }).then(() => {
                        closeBatchModal();
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: result.message
                    });
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Request Failed',
                    text: 'An error occurred. Please try again.'
                });
                console.log(data)
            }
        });

        // View Batch
        function viewBatch(risNo) {
            document.getElementById('viewRisNo').textContent = risNo;

            // Fetch batch details
            fetch(`getBatchDetails.php?ris_no=${encodeURIComponent(risNo)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.getElementById('viewOffice').textContent = data.office_name;
                        document.getElementById('viewDate').textContent = data.created_at;
                        document.getElementById('viewCreatedBy').textContent = data.created_by;

                        const container = document.getElementById('viewItemsContainer');
                        container.innerHTML = '';

                        data.items.forEach(item => {
                            const row = document.createElement('tr');
                            row.className = 'border-t';
                            row.innerHTML = `
                                <td class="px-4 py-2 border">${item.ITEM_CODE}</td>
                                <td class="px-4 py-2 border">${item.ITEM_DESC}</td>
                                <td class="px-4 py-2 border">${item.ITEM_UNIT || 'N/A'}</td>
                                <td class="px-4 py-2 border">${item.quantity}</td>
                                <td class="px-4 py-2 border">${item.remarks}</td>
                            `;
                            container.appendChild(row);
                        });

                        // Fetch returns for this RIS
                        fetch(`getReturns.php?ris_no=${encodeURIComponent(risNo)}`)
                            .then(response => response.json())
                            .then(returnData => {
                                const returnsSection = document.getElementById('returnsHistorySection');
                                const returnsContainer = document.getElementById('returnsHistoryContainer');

                                if (returnData.status === 'success' && returnData.returns.length > 0) {
                                    returnsContainer.innerHTML = '';
                                    returnData.returns.forEach(ret => {
                                        const row = document.createElement('tr');
                                        row.className = 'border-t';
                                        row.innerHTML = `
                                            <td class="px-4 py-2 border">${ret.RETURN_NO}</td>
                                            <td class="px-4 py-2 border">${ret.ITEM_CODE}</td>
                                            <td class="px-4 py-2 border">${ret.RETURN_QUANTITY}</td>
                                            <td class="px-4 py-2 border">${ret.RETURN_REASON}</td>
                                            <td class="px-4 py-2 border">${ret.RETURN_DATE}</td>
                                            <td class="px-4 py-2 border">${ret.RETURNED_BY_NAME}</td>
                                        `;
                                        returnsContainer.appendChild(row);
                                    });
                                    returnsSection.classList.remove('hidden');
                                } else {
                                    returnsSection.classList.add('hidden');
                                }
                            });

                        document.getElementById('viewModal').classList.remove('hidden');
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Failed to load batch details'
                        });
                    }
                });
        }

        // Return Item Functionality
        function returnItems(risNo) {
            fetch(`getBatchDetails.php?ris_no=${encodeURIComponent(risNo)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        // First check if any returns already exist for this RIS
                        fetch(`getReturns.php?ris_no=${encodeURIComponent(risNo)}`)
                            .then(returnResponse => returnResponse.json())
                            .then(returnData => {
                                // Build return items HTML with remaining quantity calculation
                                let itemsHtml = '';
                                data.items.forEach(item => {
                                    // Calculate remaining quantity (original - returned)
                                    const returnedItems = returnData.returns?.filter(r => r.ITEM_ID === item.item_id) || [];
                                    const totalReturned = returnedItems.reduce((sum, r) => sum + parseInt(r.RETURN_QUANTITY), 0);
                                    const remainingQty = parseInt(item.quantity) - totalReturned;

                                    if (remainingQty <= 0) {
                                        // Item fully returned, show as disabled
                                        itemsHtml += `
                                            <div class="border p-3 mb-2 rounded bg-gray-100 opacity-50">
                                                <div class="flex justify-between items-center mb-2">
                                                    <div>
                                                        <span class="font-medium">${item.ITEM_CODE}</span> - ${item.ITEM_DESC}
                                                    </div>
                                                    <span class="text-sm">Fully Returned (${totalReturned}/${item.quantity})</span>
                                                </div>
                                                <div class="text-xs text-gray-500">This item has been fully returned</div>
                                            </div>
                                        `;
                                    } else {
                                        itemsHtml += `
                                            <div class="border p-3 mb-2 rounded">
                                                <div class="flex justify-between items-center mb-2">
                                                    <div>
                                                        <span class="font-medium">${item.ITEM_CODE}</span> - ${item.ITEM_DESC}
                                                    </div>
                                                    <span class="text-sm">Original: ${item.quantity} | Returned: ${totalReturned} | Remaining: ${remainingQty}</span>
                                                </div>
                                                <div class="grid grid-cols-2 gap-2">
                                                    <div>
                                                        <input type="number" 
                                                               id="return_qty_${item.item_id}" 
                                                               class="w-full border rounded px-2 py-1 text-sm return-qty" 
                                                               placeholder="Return Qty" 
                                                               min="1" 
                                                               max="${remainingQty}"
                                                               data-item-id="${item.item_id}"
                                                               data-max="${remainingQty}">
                                                    </div>
                                                    <div>
                                                        <input type="text" 
                                                               id="reason_${item.item_id}" 
                                                               class="w-full border rounded px-2 py-1 text-sm return-reason" 
                                                               placeholder="Return reason">
                                                    </div>
                                                </div>
                                            </div>
                                        `;
                                    }
                                });

                                Swal.fire({
                                    title: `Return Items - ${risNo}`,
                                    html: `
                                        <div class="text-left max-h-96 overflow-y-auto">
                                            <p class="text-sm text-gray-600 mb-3">Select items to return</p>
                                            ${itemsHtml}
                                        </div>
                                    `,
                                    width: '600px',
                                    showCancelButton: true,
                                    confirmButtonText: 'Process Return',
                                    cancelButtonText: 'Cancel',
                                    reverseButtons: true,
                                    preConfirm: () => {
                                        const returnItems = [];
                                        let hasItems = false;

                                        data.items.forEach(item => {
                                            const qtyInput = document.getElementById(`return_qty_${item.item_id}`);
                                            const reasonInput = document.getElementById(`reason_${item.item_id}`);

                                            // Skip if input doesn't exist (fully returned items)
                                            if (!qtyInput) return;

                                            const qty = parseInt(qtyInput.value);

                                            if (qty > 0) {
                                                if (!reasonInput.value.trim()) {
                                                    Swal.showValidationMessage(`Reason required for ${item.ITEM_CODE}`);
                                                    hasItems = false;
                                                    return false;
                                                }
                                                if (qty > parseInt(qtyInput.dataset.max)) {
                                                    Swal.showValidationMessage(`Quantity exceeds remaining (${qtyInput.dataset.max})`);
                                                    hasItems = false;
                                                    return false;
                                                }
                                                returnItems.push({
                                                    item_id: item.item_id,
                                                    return_quantity: qty,
                                                    reason: reasonInput.value.trim()
                                                });
                                                hasItems = true;
                                            }
                                        });

                                        if (!hasItems) {
                                            Swal.showValidationMessage('Select at least one item to return');
                                            return false;
                                        }

                                        return returnItems;
                                    }
                                }).then((result) => {
                                    if (result.isConfirmed) {
                                        processReturn(risNo, result.value);
                                    }
                                });
                            });
                    }
                });
        }

        // Process return submission
        function processReturn(risNo, items) {
            Swal.fire({
                title: 'Processing...',
                text: 'Please wait',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            fetch('returnItem.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'return_items',
                    ris_no: risNo,
                    items: items
                })
            })
                .then(response => response.json())
                .then(result => {
                    if (result.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: `${result.message} (Return No: ${result.return_no})`
                        }).then(() => location.reload());
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: result.message
                        });
                    }
                })
                .catch(error => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Request Failed',
                        text: 'An error occurred. Please try again.'
                    });
                });
        }

        // View Returns History
        function viewReturns(risNo) {
            fetch(`getReturns.php?ris_no=${encodeURIComponent(risNo)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success' && data.returns.length > 0) {
                        let returnsHtml = '';
                        data.returns.forEach(ret => {
                            returnsHtml += `
                                <tr class="border-t">
                                    <td class="px-4 py-2 border">${ret.RETURN_NO}</td>
                                    <td class="px-4 py-2 border">${ret.ITEM_CODE} - ${ret.ITEM_DESC}</td>
                                    <td class="px-4 py-2 border">${ret.RETURN_QUANTITY}</td>
                                    <td class="px-4 py-2 border">${ret.RETURN_REASON}</td>
                                    <td class="px-4 py-2 border">${ret.RETURN_DATE}</td>
                                    <td class="px-4 py-2 border">${ret.RETURNED_BY_NAME}</td>
                                </tr>
                            `;
                        });

                        Swal.fire({
                            title: `Return History - ${risNo}`,
                            html: `
                                <div class="overflow-x-auto">
                                    <table class="min-w-full text-sm">
                                        <thead>
                                            <tr>
                                                <th class="px-2 py-1">Return No.</th>
                                                <th class="px-2 py-1">Item</th>
                                                <th class="px-2 py-1">Qty</th>
                                                <th class="px-2 py-1">Reason</th>
                                                <th class="px-2 py-1">Date</th>
                                                <th class="px-2 py-1">Returned By</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${returnsHtml}
                                        </tbody>
                                    </table>
                                </div>
                            `,
                            width: '900px',
                            confirmButtonText: 'Close'
                        });
                    } else {
                        Swal.fire({
                            icon: 'info',
                            title: 'No Returns',
                            text: 'No returns found for this RIS'
                        });
                    }
                });
        }

        // Item search functionality for modal
        const itemSearch = document.getElementById('itemSearch');
        const searchResults = document.getElementById('searchResults');

        // Create searchable items array from itemsData
        const searchableItems = itemsData.map(item => ({
            id: item.ITEM_ID,
            code: item.ITEM_CODE,
            desc: item.ITEM_DESC,
            available: item.AVAILABLE_QTY,
            searchText: `${item.ITEM_CODE} ${item.ITEM_DESC} ${item.ITEM_UNIT || ''}`.toLowerCase()
        }));

        // Search function
        itemSearch.addEventListener('input', function () {
            const searchTerm = this.value.toLowerCase().trim();

            if (searchTerm === '') {
                searchResults.classList.add('hidden');
                return;
            }

            // Filter items
            const filtered = searchableItems.filter(item =>
                item.searchText.includes(searchTerm)
            ).slice(0, 10); // Limit to 10 results for performance

            // Display results
            if (filtered.length > 0) {
                searchResults.innerHTML = filtered.map(item => `
                    <div class="p-3 hover:bg-blue-50 cursor-pointer border-b last:border-0 flex justify-between items-center" 
                         onclick="selectSearchItem(${item.id}, ${item.available})">
                        <div>
                            <div class="text-sm font-medium">${item.code}</div>
                            <div class="text-xs text-gray-600">${item.desc}</div>
                        </div>
                    </div>
                `).join('');
                searchResults.classList.remove('hidden');
            } else {
                searchResults.innerHTML = '<div class="p-3 text-sm text-gray-500 text-center">No items found</div>';
                searchResults.classList.remove('hidden');
            }
        });

        // Select search result and add to form
        window.selectSearchItem = function (itemId, availableQty) {
            // Get the first empty item row or create new one
            const itemRows = document.querySelectorAll('#itemsContainer .item-row');
            let targetRow = null;

            // Find first row with no item selected
            for (let row of itemRows) {
                const select = row.querySelector('.item-select');
                if (!select.value) {
                    targetRow = row;
                    break;
                }
            }

            // If no empty row found, create new one
            if (!targetRow) {
                targetRow = createItemRow();
                document.getElementById('itemsContainer').appendChild(targetRow);
            }

            // Set the selected value
            const select = targetRow.querySelector('.item-select');
            select.value = itemId;

            // Trigger change event to update available hint
            select.dispatchEvent(new Event('change'));

            // Focus on quantity input
            targetRow.querySelector('.quantity-input').focus();

            // Clear search and hide results
            itemSearch.value = '';
            searchResults.classList.add('hidden');

            lucide.createIcons();
        };

        // Hide search results when clicking outside
        document.addEventListener('click', function (e) {
            if (!itemSearch?.contains(e.target) && !searchResults?.contains(e.target)) {
                searchResults?.classList.add('hidden');
            }
        });

        // Show results when focusing on search if there's text
        itemSearch?.addEventListener('focus', function () {
            if (this.value.trim() !== '') {
                this.dispatchEvent(new Event('input'));
            }
        });

        // Keyboard navigation
        itemSearch?.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                searchResults.classList.add('hidden');
            }
        });

        function closeViewModal() {
            document.getElementById('viewModal').classList.add('hidden');
        }

        // Export to Excel
        function exportToExcel(risNo) {
            window.location.href = `../API/exportRIS.php?ris_no=${encodeURIComponent(risNo)}`;
        }
    </script>
</body>

</html>