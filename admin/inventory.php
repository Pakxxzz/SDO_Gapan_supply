<?php
// Admin/inventory.php
include "./sidebar.php";
session_regenerate_id(true);
include "../API/db-connector.php";

// GET filters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// qty filter: all | zero | nonzero
$qtyFilter = isset($_GET['qty_filter']) ? strtolower(trim($_GET['qty_filter'])) : 'all';
if (!in_array($qtyFilter, ['all', 'zero', 'nonzero'], true))
    $qtyFilter = 'all';


// Build FROM + WHERE
$fromClause = "FROM inventory
               JOIN item ON item.ITEM_ID = inventory.ITEM_ID
               LEFT JOIN inventory_thresholds it ON it.ITEM_ID = item.ITEM_ID";

$whereClause = "WHERE item.ITEM_IS_ARCHIVED = 0";

// Search functionality
if ($search !== '') {
    $searchTerm = $conn->real_escape_string($search);

    $whereClause .= " AND (
        item.ITEM_CODE LIKE '%$searchTerm%' OR
        item.ITEM_DESC LIKE '%$searchTerm%' OR
        item.ITEM_UNIT LIKE '%$searchTerm%' OR
        inventory.INV_QUANTITY_PIECE LIKE '%$searchTerm%' OR
        it.MIN_THRESHOLD LIKE '%$searchTerm%' OR
        it.MAX_THRESHOLD LIKE '%$searchTerm%'
    )";
}

// Quantity filter (zero/nonzero)
if ($qtyFilter === 'zero') {
    $whereClause .= " AND inventory.INV_QUANTITY_PIECE = 0";
} elseif ($qtyFilter === 'nonzero') {
    $whereClause .= " AND inventory.INV_QUANTITY_PIECE > 0";
}


// Total count (for pagination)
$totalQuery = "SELECT COUNT(*) AS total
               $fromClause
               $whereClause";
$totalResult = $conn->query($totalQuery);
$totalRow = $totalResult ? $totalResult->fetch_assoc() : ['total' => 0];
$totalInventory = (int) ($totalRow['total'] ?? 0);

$totalPages = ($limit > 0) ? (int) ceil($totalInventory / $limit) : 0;
if ($totalPages > 0 && $page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}


// Main query - Only the columns we need
$sql = "SELECT 
            item.ITEM_CODE,
            item.ITEM_DESC,
            item.ITEM_UNIT,
            item.ITEM_COST,
            inventory.INV_QUANTITY_PIECE,
            it.MIN_THRESHOLD,
            it.MAX_THRESHOLD
        $fromClause
        $whereClause
        ORDER BY item.ITEM_CODE ASC
        LIMIT $limit OFFSET $offset";

$result = $conn->query($sql);


// Pagination links
function generatePageLinks($totalPages, $page, $limit, $search = '', $qtyFilter = 'all')
{
    $links = [];

    $extra = '';
    if ($search !== '')
        $extra .= "&search=" . urlencode($search);
    if ($qtyFilter !== 'all')
        $extra .= "&qty_filter=" . urlencode($qtyFilter);

    if ($totalPages <= 5) {
        for ($i = 1; $i <= $totalPages; $i++)
            $links[] = $i;
    } else {
        $links = [1, 2, 3, "...", $totalPages - 1, $totalPages];
        if ($page >= 3 && $page <= $totalPages - 2) {
            $links = [1, "...", $page - 1, $page, $page + 1, "...", $totalPages];
        }
    }

    foreach ($links as &$link) {
        if ($link !== "...") {
            $p = (int) $link;
            $link = [
                'page' => $p,
                'url' => "?page=$p&limit=$limit$extra"
            ];
        } else {
            $link = ['page' => "...", 'url' => "#"];
        }
    }
    return $links;
}

$pageLinks = generatePageLinks($totalPages, $page, $limit, $search, $qtyFilter);

// Build URL params for prev/next
$urlParams = '';
if ($search !== '')
    $urlParams .= "&search=" . urlencode($search);
if ($qtyFilter !== 'all')
    $urlParams .= "&qty_filter=" . urlencode($qtyFilter);
if ($limit != 10)
    $urlParams .= "&limit=$limit";
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management</title>
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="inventory.css?id=<?= time() ?>">
    <style>
        .threshold-low {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .threshold-normal {
            background-color: #d3daff;
            color: #0047bb;
        }

        .threshold-high {
            background-color: #fef3c7;
            color: #92400e;
        }
    </style>
</head>

<body>
    <div class="content w-[calc(100vw-60px)] mx-auto overflow-x-auto p-8 px-8">
        <div class="bg-white shadow-md rounded-lg p-6 mb-6">
            <h2 class="font-bold mb-4 text-[13px] xs:text-[12px] sm:text-[15px] font-bold text-[#0047bb]">Inventory</h2>
            <div class="flex flex-col mb-3 sm:flex-row justify-between items-center gap-4">
                <form method="GET" action="" class="relative w-full sm:w-64">
                    <i class="absolute left-4 top-3 bx bx-search text-gray-500"></i>
                    <input type="search" name="search" id="searchInput" placeholder="Search"
                        value="<?= htmlspecialchars($search) ?>"
                        class="w-full pl-10 pr-4 py-2 border rounded-full focus:ring focus:ring-blue-300">
                    <!-- Keep limit in hidden field to maintain it during search -->
                    <input type="hidden" name="limit" value="<?= $limit ?>">
                </form>
                <!-- Button to Open Modal -->
                <!-- <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%] items-end">
                    <button id="openModal"
                        class="bg-blue-500 text-sm text-white px-4 py-2 rounded-md hover:bg-blue-600 w-full">
                        Add Item
                    </button>
                </div> -->
            </div>
            <?php if (!empty($search)): ?>
                <div class="mt-2 text-sm text-gray-600">
                    Showing results for: "<strong>
                        <?= htmlspecialchars($search) ?>
                    </strong>"
                    <a href="?" class="ml-2 text-blue-500 hover:text-blue-700">Clear search</a>
                </div>
            <?php endif; ?>
            <!-- FILTER BAR -->
            <div class="flex flex-wrap gap-4">
                <!-- Quantity Filter -->
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%]">
                    <label for="qtyFilter" class="text-sm text-gray-600 font-medium mb-1">Stock Filter</label>
                    <select id="qtyFilter" class="w-full border rounded px-4 py-2.5 text-gray-700 text-sm">
                        <option value="all" <?= $qtyFilter === 'all' ? 'selected' : '' ?>>All Quantity</option>
                        <option value="zero" <?= $qtyFilter === 'zero' ? 'selected' : '' ?>>Zero Quantity</option>
                        <option value="nonzero" <?= $qtyFilter === 'nonzero' ? 'selected' : '' ?>>With Stock</option>
                    </select>
                </div>

                <!-- Apply Filters Button -->
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%] flex items-end">
                    <button onclick="applyInventoryFilters()"
                        class="bg-green-500 text-sm text-white px-4 py-2 rounded-md hover:bg-green-600 w-full">
                        Apply Filters
                    </button>
                </div>

                <!-- Clear Filters Button -->
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%] flex items-end">
                    <button onclick="clearInventoryFilters()"
                        class="bg-gray-500 text-sm text-white px-4 py-2 rounded-md hover:bg-gray-600 w-full">
                        Clear Filters
                    </button>
                </div>
            </div>

        </div>

        <!-- Table -->
        <div class="table-container overflow-x-auto">
            <table class="min-w-full table-auto border-separate border-spacing-0">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left">Stock No.</th>
                        <th class="px-4 py-2 text-left">Item Description</th>
                        <th class="px-4 py-2 text-left">Unit</th>
                        <th class="px-4 py-2 text-left">Quantity</th>
                        <th class="px-4 py-2 text-left">Unit Cost</th>
                        <th class="px-4 py-2 text-left">Total Amount</th>
                        <th class="px-4 py-2 text-left">Threshold</th>
                        <th class="px-4 py-2 text-left">Status</th>
                    </tr>
                </thead>
                <tbody id="table-body">
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <?php
                            $quantity = (int) ($row['INV_QUANTITY_PIECE'] ?? 0);
                            $min_threshold = (int) ($row['MIN_THRESHOLD'] ?? 100);
                            $max_threshold = (int) ($row['MAX_THRESHOLD'] ?? 1000);

                            $difference = 0;
                            $difference_class = '';
                            $difference_text = '';

                            if ($quantity == 0 && $quantity < $min_threshold) {
                                $difference_class = 'text-red-600 font-semibold';
                                $difference_text = $difference; // already negative
                            } elseif ($quantity < $min_threshold) {
                                $difference = $quantity - $min_threshold; // negative value
                                $difference_class = 'text-red-600 font-semibold';
                                $difference_text = $difference; // already negative
                            } elseif ($quantity > $max_threshold) {
                                $difference = $quantity - $max_threshold; // positive value
                                $difference_class = 'text-yellow-600 font-semibold';
                                $difference_text = '+' . $difference; // force + sign
                            } else {
                                $difference = 0;
                                $difference_class = 'text-blue-600 font-semibold';
                                $difference_text = '0';
                            }


                            // Determine status class
                            $status_class = 'threshold-normal';
                            $status_text = 'Normal';

                            if ($quantity == 0 && $quantity < $min_threshold) {
                                $status_class = 'threshold-low';
                                $status_text = 'Out of Stock';
                            } elseif ($quantity < $min_threshold) {
                                $status_class = 'threshold-low';
                                $status_text = 'Low Stock';
                            } elseif ($quantity > $max_threshold) {
                                $status_class = 'threshold-high';
                                $status_text = 'Overstock';
                            }

                            ?>
                            <tr class="hover:bg-gray-200 border-b border-gray-300">
                                <td class="px-4 py-3"><?= htmlspecialchars($row['ITEM_CODE']) ?></td>
                                <td class="px-4 py-3"><?= htmlspecialchars($row['ITEM_DESC']) ?></td>
                                <td class="px-4 py-3"><?= htmlspecialchars($row['ITEM_UNIT'] ?: 'N/A') ?>
                                </td>
                                <td class="px-4 py-3"><?= number_format($quantity) ?></td>
                                <td class="px-4 py-3">₱<?= htmlspecialchars(number_format($row['ITEM_COST'], 2)) ?></td>
                                <td class="px-4 py-3">₱<?= htmlspecialchars(number_format($row['ITEM_COST'] * $quantity, 2)) ?>
                                <td class="px-4 py-3">
                                    <span class="<?= $difference_class ?>">
                                        <?= $difference_text ?>
                                    </span>
                                </td>

                                <td class="px-4 py-3 text-xs">
                                    <span class="px-2 py-1 rounded-full font-medium <?= $status_class ?>">
                                        <?= $status_text ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr id="no-data-row">
                            <td colspan="6" class="text-center py-2 border-b border-gray-300">
                                <?php if (!empty($search)): ?>
                                    No inventory items found matching "<strong><?= htmlspecialchars($search) ?></strong>"
                                <?php else: ?>
                                    No inventory items found
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 0): ?>
            <div class="flex flex-col items-center mt-6">
                <div class="flex justify-center items-center gap-2">
                    <a href="?page=<?= max(1, $page - 1) ?>&limit=<?= $limit ?><?= $urlParams ?>"
                        class="px-3 py-1 border rounded text-sm <?= ($page == 1 || $totalInventory == 0) ? 'opacity-50 cursor-not-allowed pointer-events-none bg-gray-100' : 'hover:bg-gray-100' ?>">
                        &lt;
                    </a>

                    <?php foreach ($pageLinks as $p): ?>
                        <?php if ($p['page'] === "..."): ?>
                            <span class="px-3 py-1 text-gray-400 cursor-default select-none">...</span>
                        <?php else: ?>
                            <a href="<?= $p['url'] ?>"
                                class="px-3 py-1 border rounded text-sm <?= ($p['page'] == $page) ? 'bg-blue-500 text-white' : 'hover:bg-gray-100' ?>">
                                <?= $p['page'] ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <a href="?page=<?= min($totalPages, $page + 1) ?>&limit=<?= $limit ?><?= $urlParams ?>"
                        class="px-3 py-1 border rounded text-sm <?= ($page == $totalPages || $totalInventory == 0) ? 'opacity-50 cursor-not-allowed pointer-events-none bg-gray-100' : 'hover:bg-gray-100' ?>">
                        &gt;
                    </a>

                    <!-- Results per page dropdown -->
                    <form method="GET" class="inline ml-4">
                        <?php if ($search !== ''): ?>
                            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <?php endif; ?>
                        <?php if ($qtyFilter !== 'all'): ?>
                            <input type="hidden" name="qty_filter" value="<?= htmlspecialchars($qtyFilter) ?>">
                        <?php endif; ?>

                        <select onchange="this.form.submit()" name="limit" class="border px-2 py-1 rounded text-sm">
                            <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                            <option value="20" <?= $limit == 20 ? 'selected' : '' ?>>20</option>
                            <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                        </select>
                    </form>
                </div>

                <!-- Results info -->
                <p class="text-center text-xs text-gray-500 mt-3">
                    Results: <?= ($totalInventory > 0 ? ($offset + 1) : 0) ?> to
                    <?= min($offset + $limit, $totalInventory) ?>
                    of <?= number_format($totalInventory) ?>
                    <?php if (!empty($search)): ?>
                        for "<?= htmlspecialchars($search) ?>" 
                    <?php endif; ?>
                    items
                </p>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function applyInventoryFilters() {
            const params = new URLSearchParams();
            params.set('page', '1');
            params.set('limit', '<?= (int) $limit ?>');

            const search = document.getElementById('searchInput')?.value?.trim() || '';
            const qty = document.getElementById('qtyFilter')?.value || 'all';

            if (search) params.set('search', search);
            if (qty && qty !== 'all') params.set('qty_filter', qty);

            window.location.href = '?' + params.toString();
        }

        function clearInventoryFilters() {
            const params = new URLSearchParams();
            params.set('page', '1');
            params.set('limit', '<?= (int) $limit ?>');
            window.location.href = '?' + params.toString();
        }

        // Auto-submit search on Enter key
        document.getElementById('searchInput')?.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                applyInventoryFilters();
            }
        });
    </script>
</body>

</html>