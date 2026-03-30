<?php
// admin/item-movement.php
include "./sidebar.php";
session_regenerate_id(true);
include "../API/db-connector.php";

// Check if this is an Excel export request
$isExport = isset($_GET['export']) && $_GET['export'] === 'excel';

// Pagination settings
$limit = isset($_GET['limit']) ? intval(preg_replace('/\s+/', '', $_GET['limit'])) : 10;
$limit = intval($limit);
if ($limit <= 0)
    $limit = 10;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$dateFrom = isset($_GET['dateFrom']) ? $_GET['dateFrom'] : null;
$dateTo = isset($_GET['dateTo']) ? $_GET['dateTo'] : null;
$movementType = isset($_GET['movement_type']) ? trim($_GET['movement_type']) : null;
// $checkerId = isset($_GET['warehouse-head']) ? intval($_GET['warehouse-head']) : null;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$offset = ($page - 1) * $limit;

// Build WHERE conditions
$whereClauses = ["i.ITEM_IS_ARCHIVED = 0", "imh.QUANTITY_PIECE != 0"];

// MODIFIED: Date filter logic to match stockOut.php structure
if ($dateFrom) {
    $dateFromEscaped = $conn->real_escape_string($dateFrom);
    $whereClauses[] = "imh.MOVEMENT_DATE >= '$dateFromEscaped 00:00:00'";
}

if ($dateTo) {
    $dateToEscaped = $conn->real_escape_string($dateTo);
    $whereClauses[] = "imh.MOVEMENT_DATE <= '$dateToEscaped 23:59:59'";
}

// if ($checkerId) {
//     $whereClauses[] = "imh.CHECKER_ID = $checkerId";
// }

if ($movementType) {
    $movementTypeEscaped = $conn->real_escape_string($movementType);
    $whereClauses[] = "imh.MOVEMENT_TYPE = '$movementTypeEscaped'";
}

// Add search functionality
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $whereClauses[] = "(
        imh.BATCH_NO LIKE '%$searchTerm%' OR
        i.ITEM_CODE LIKE '%$searchTerm%' OR 
        i.ITEM_DESC LIKE '%$searchTerm%' OR
        imh.MOVEMENT_TYPE LIKE '%$searchTerm%' OR
        imh.DETAILS LIKE '%$searchTerm%'
    )";
}

$whereSQL = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// Get total number of records
$totalQuery = "SELECT COUNT(*) AS total 
               FROM item_movement_history imh 
               JOIN item i ON imh.ITEM_ID = i.ITEM_ID 
               $whereSQL";
$totalResult = $conn->query($totalQuery);

if (!$totalResult) {
    die("Error in total query: " . $conn->error);
}

$totalRow = $totalResult->fetch_assoc();
$totalImh = $totalRow['total'];
$totalPages = ceil($totalImh / $limit);

// Movement types for dropdown
// $movementTypes = ['Received', 'Delivery', 'Disposed', 'Aligned'];
$movementTypes = ['Aligned', 'Stock In', 'Stock Out', 'Adjustment', 'Return'];

// For Excel export, get all records without pagination
if ($isExport) {
    $sql = "SELECT imh.*, i.ITEM_DESC, i.ITEM_CODE, i.ITEM_UNIT
            FROM item_movement_history imh 
            JOIN item i ON imh.ITEM_ID = i.ITEM_ID 
            $whereSQL 
            ORDER BY imh.IMH_ID DESC";
} else {
    // For normal page view, get paginated records
    $sql = "SELECT imh.*, i.ITEM_DESC, i.ITEM_CODE, i.ITEM_UNIT
            FROM item_movement_history imh 
            JOIN item i ON imh.ITEM_ID = i.ITEM_ID 
            $whereSQL 
            ORDER BY imh.IMH_ID DESC 
            LIMIT $limit OFFSET $offset";
}

$result = $conn->query($sql);

function generatePageLinks($totalPages, $page, $limit, $dateFrom = '', $dateTo = '', $movementType = '', $search = '')
{
    $links = [];

    $limit = trim($limit);
    // Build URL parameters
    $urlParams = '';
    if (!empty($dateFrom))
        $urlParams .= "&dateFrom=" . urlencode($dateFrom);
    if (!empty($dateTo))
        $urlParams .= "&dateTo=" . urlencode($dateTo);
    if (!empty($movementType))
        $urlParams .= "&movement_type=" . urlencode($movementType);
    if (!empty($search))
        $urlParams .= "&search=" . urlencode($search);

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

$pageLinks = generatePageLinks($totalPages, $page, $limit, $dateFrom, $dateTo, $movementType, $search);

// Build URL parameters for navigation
$urlParams = '';
if (!empty($dateFrom))
    $urlParams .= "&dateFrom=" . urlencode($dateFrom);
if (!empty($dateTo))
    $urlParams .= "&dateTo=" . urlencode($dateTo);
if (!empty($movementType))
    $urlParams .= "&movement_type=" . urlencode($movementType);
if (!empty($search))
    $urlParams .= "&search=" . urlencode($search);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Movement History</title>
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="inventory.css?id=<?= time() ?>">
</head>

<body>
    <div class="content flex-1 w-full overflow-x-auto mx-auto flex flex-col">
        <div class="bg-white shadow-md rounded-lg p-6 mb-6">
            <h2 class="font-bold mb-4 text-[13px] xs:text-[12px] sm:text-[15px] font-bold text-[#0047bb]">Item
                Movement History</h2>
            <!-- MODIFIED: Filter section structure copied from stockOut.php -->
            <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                <form method="GET" action="" class="relative w-full sm:w-64">
                    <i class="absolute left-4 top-3 bx bx-search text-gray-500"></i>
                    <input type="search" name="search" id="searchInput" placeholder="Search"
                        value="<?= htmlspecialchars($search) ?>"
                        class="w-full pl-10 pr-4 py-2 border rounded-full focus:ring focus:ring-blue-300">

                </form>
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%] items-end">
                    <!-- Export to Excel Button -->
                    <a href="../API/export_item_movement.php?dateFrom=<?= htmlspecialchars($dateFrom) ?>&dateTo=<?= htmlspecialchars($dateTo) ?>&movement_type=<?= htmlspecialchars($movementType) ?>&search=<?= urlencode($search) ?>"
                        class="bg-purple-600 hover:bg-purple-700 text-white flex justify-center align-center px-4 py-2 rounded-md flex items-center">
                        <i class='bx bx-export mr-2'></i> Export to Excel
                    </a>
                </div>
            </div>

            <!-- MODIFIED: Filter section with date inputs matching stockOut.php structure -->
            <div class="flex flex-wrap gap-4 mt-6 mb-6">
                <!-- From Date -->
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

                <!-- Movement Type Filter -->
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%]">
                    <label for="movementTypeFilter" class="text-sm text-gray-600 font-medium mb-1">Movement Type</label>
                    <select id="movementTypeFilter" class="w-full border rounded px-4 py-2.5 text-gray-700 text-sm">
                        <option value="">All Types</option>
                        <?php foreach ($movementTypes as $type): ?>
                            <option value="<?= $type ?>" <?= $movementType == $type ? 'selected' : '' ?>>
                                <?= $type ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Filter Button -->
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%] flex items-end">
                    <button type="button" onclick="applyFilters()"
                        class="bg-green-500 text-sm text-white px-4 py-2 rounded-md hover:bg-green-600 w-full">
                        Apply Filters
                    </button>
                </div>

                <!-- Clear Filters Button -->
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%] flex items-end">
                    <button type="" button"" onclick="clearFilters()"
                        class="bg-gray-500 text-sm text-white px-4 py-2 rounded-md hover:bg-gray-600 w-full">
                        Clear Filters
                    </button>
                </div>
            </div>
        </div>

        <div class="w-full mx-auto pb-15">
            <!-- Table -->
            <div class="table-container overflow-x-auto">
                <table class="min-w-full table-fixed border-separate border-spacing-0" id="movementTable">
                    <thead>
                        <tr>
                            <th class="px-4 py-2 text-left">Batch Number</th>
                            <th class="px-4 py-2 text-left">Stock No.</th>
                            <th class="px-4 py-2 text-left">Item Description</th>
                            <th class="px-4 py-2 text-left">Movement Type</th>
                            <th class="px-4 py-2 text-left">Quantity</th>
                            <th class="px-4 py-2 text-left">Remarks</th>
                            <th class="px-4 py-2 text-left">Date</th>
                        </tr>
                    </thead>

                    <tbody id="table-body">
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php foreach ($result as $row):
                                $movementType = htmlspecialchars($row['MOVEMENT_TYPE']);
                                $quantity = (int) $row['QUANTITY_PIECE'];

                                // Apply negative if movement type is Delivery or Disposed
                                if ($movementType === "Aligned" || $movementType === "Stock Out" || ($movementType === "Adjustment" && $quantity < 0)) {
                                    $displayQuantity = -abs($quantity);
                                } else {
                                    $displayQuantity = $quantity;
                                }

                                // Assign class based on movement type
                                if ($movementType === "Stock Out" || ($movementType === "Aligned" && $quantity < 0)) {
                                    $quantityClass = 'text-red-500 font-bold';
                                } else if ($movementType === "Adjustment") {
                                    $quantityClass = 'text-yellow-500 font-bold';
                                } else {
                                    $quantityClass = 'text-blue-500 font-bold';
                                }
                                ?>
                                <tr class="hover:bg-gray-200 border-b border-gray-300">
                                    <td class=" px-4 py-2">
                                        <?= htmlspecialchars($row['BATCH_NO']) ?>
                                    </td>
                                    <td class="px-4 py-2"><?= htmlspecialchars($row['ITEM_CODE']) ?>
                                    </td>
                                    <td class="px-4 py-2 break-words"><?= htmlspecialchars($row['ITEM_DESC']) ?></td>
                                    <td class=" px-4 py-2">
                                        <?= $movementType ?>
                                    </td>
                                    <td class="px-4 py-2 <?= $quantityClass ?>">
                                        <?= number_format($displayQuantity) ?>
                                        <?= htmlspecialchars($row['ITEM_UNIT']) ?>
                                    </td>

                                    <td class="px-4 py-2 break-words">
                                        <?= htmlspecialchars($row['DETAILS']) ?>
                                    </td>
                                    <td class=" px-4 py-2">
                                        <?= htmlspecialchars(date('M j, Y \a\t g:i a', strtotime($row['MOVEMENT_DATE']))) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="px-4 py-2 text-center text-gray-500">
                                    <?php if (!empty($search)): ?>
                                        No item movements found for "
                                        <?= htmlspecialchars($search) ?>"
                                    <?php elseif ($dateFrom === date('Y-m-d') && $dateTo === date("Y-m-d")): ?>
                                        No item movements found for today
                                    <?php elseif (!empty($dateFrom) || !empty($dateTo)): ?>
                                        No item movements found for the selected date range
                                    <?php else: ?>
                                        No item movements found.
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalImh > 0 && !$isExport && $totalPages > 0): ?>
            <div class="flex justify-center mt-4">
                <a href="?page=<?= max(1, $page - 1) ?>&limit=<?= intval($limit) ?><?= $urlParams ?>"
                    class="px-3 py-1 border rounded <?= ($page == 1) ? 'opacity-50 cursor-not-allowed pointer-events-none' : 'hover:bg-gray-200' ?>">
                    &lt;
                </a>

                <?php foreach ($pageLinks as $p): ?>
                    <?php if ($p['page'] === "..."): ?>
                        <span class="px-3 py-1 border rounded text-gray-500 cursor-default select-none">...</span>
                    <?php else: ?>
                        <a href="<?= $p['url'] ?>" class=" px-3 py-1 border rounded
                        <?= $p['page'] == $page ? 'bg-blue-500 text-white' : 'hover:bg-gray-200' ?>">
                            <?= $p['page'] ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>

                <a href="?page=<?= min($totalPages, $page + 1) ?>&limit=<?= intval($limit) ?><?= $urlParams ?>" class=" px-3 py-1 border rounded <?= ($page == $totalPages) ? 'opacity-50 cursor-not-allowed
                    pointer-events-none' : 'hover:bg-gray-200' ?>">
                    &gt;
                </a>

                <!-- Results per page dropdown -->
                <form method="GET" class="inline">
                    <?php
                    // Preserve all existing GET parameters except 'limit' and 'page'
                    $preserveParams = $_GET;
                    unset($preserveParams['limit']);
                    unset($preserveParams['page']);

                    // Add hidden inputs for all existing parameters
                    foreach ($preserveParams as $key => $value) {
                        if (is_array($value)) {
                            foreach ($value as $val) {
                                echo '<input type="hidden" name="' . htmlspecialchars($key) . '[]" value="' . htmlspecialchars($val) . '">';
                            }
                        } else {
                            echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">';
                        }
                    }
                    ?>
                    <select id="limitSelect" onchange="changeLimit()" class="border px-2 py-1 rounded text-sm ml-4">
                        <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                        <option value="20" <?= $limit == 20 ? 'selected' : '' ?>>20</option>
                        <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                    </select>
                </form>
            </div>

            <!-- Results info -->
            <p class="text-center text-xs text-gray-500 mt-3">
                Results:
                <?= $offset + 1 ?> -
                <?= min($offset + $limit, $totalImh) ?> of
                <?= $totalImh ?>
                <?php if (!empty($search)): ?>
                    for "
                    <?= htmlspecialchars($search) ?>"
                <?php endif; ?>
                items
            </p>
        <?php endif; ?>
    </div>

    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>

        function changeLimit() {
            const limit = document.getElementById('limitSelect').value;
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('limit', limit);
            urlParams.set('page', '1');
            window.location.href = '?' + urlParams.toString();
        }
        function applyFilters() {
            const dateFrom = document.getElementById("dateFrom").value;
            const dateTo = document.getElementById("dateTo").value;
            const movementType = document.getElementById("movementTypeFilter").value;
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

            if (movementType) {
                url += `&movement_type=${encodeURIComponent(movementType)}`;
            }


            window.location.href = url;
        }

        function clearFilters() {
            const limit = <?= $limit ?>;
            window.location.href = `?page=1&limit=${limit}`;
        }

        // Auto-submit search on Enter key
        document.getElementById("searchInput").addEventListener("keypress", function (e) {
            if (e.key === "Enter") {
                e.preventDefault();
                applyFilters();
            }
        });

        // Optional: Add enter key support for other inputs
        ['dateFrom', 'dateTo', 'movementTypeFilter'].forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.addEventListener("keypress", function (e) {
                    if (e.key === "Enter") {
                        e.preventDefault();
                        applyFilters();
                    }
                });
            }
        });
    </script>
</body>

</html>