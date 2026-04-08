<?php
// admin/item.php
include "./sidebar.php";
// session_regenerate_id(true);
include "../API/db-connector.php";

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$whereClause = "WHERE item.ITEM_IS_ARCHIVED = 0";

if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $whereClause .= " AND (
        item.ITEM_CODE LIKE '%$searchTerm%' OR
        item.ITEM_DESC LIKE '%$searchTerm%' OR 
        item.ITEM_UNIT LIKE '%$searchTerm%'
    )";
}

// Pagination settings
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10; // Default 10 per page
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Get total number of items with search filter
$totalQuery = "SELECT COUNT(*) AS total FROM item $whereClause";
$totalResult = $conn->query($totalQuery);
$totalRow = $totalResult->fetch_assoc();
$totalItem = $totalRow['total'];

$totalPages = ceil($totalItem / $limit);
$sql = "SELECT item.*, thresholds.THRESHOLD_ID, thresholds.MIN_THRESHOLD, thresholds.MAX_THRESHOLD
        FROM item 
        LEFT JOIN inventory_thresholds thresholds ON item.ITEM_ID = thresholds.ITEM_ID
        $whereClause 
        LIMIT $limit OFFSET $offset";
$result = $conn->query($sql);

function generatePageLinks($totalPages, $page, $limit, $search = '')
{
    $links = [];
    $searchParam = !empty($search) ? "&search=" . urlencode($search) : '';

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

    // Add search parameter to all links
    foreach ($links as &$link) {
        if ($link !== "...") {
            $link = [
                'page' => $link,
                'url' => "?page=$link&limit=$limit$searchParam"
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

$pageLinks = generatePageLinks($totalPages, $page, $limit, $search);

// Build URL parameters for navigation
$urlParams = '';
if (!empty($search)) {
    $urlParams .= "&search=" . urlencode($search);
}
if ($limit != 10) {
    $urlParams .= "&limit=$limit";
}

// Generate new item code
$stmt = $conn->prepare("
    SELECT MAX(CAST(SUBSTRING(ITEM_CODE, 4) AS UNSIGNED)) AS max_number
    FROM item
    WHERE ITEM_CODE LIKE 'OS-%'
");
$stmt->execute();
$stmt->bind_result($maxNumber);
$stmt->fetch();
$stmt->close();

if ($maxNumber) {
    $newNumber = str_pad($maxNumber + 1, 3, '0', STR_PAD_LEFT);
    $itmCode = "OS-" . $newNumber;
} else {
    $itmCode = "OS-001";
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title></title>
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="inventory.css?id=<?= time() ?>">
</head>

<body>
    <div class="content w-[calc(100vw-60px)] mx-auto overflow-x-auto p-8 px-8">
        <div class="bg-white shadow-md rounded-lg p-6 mb-6">
            <h2 class="font-bold mb-4 text-[13px] xs:text-[12px] sm:text-[15px] font-bold text-[#0047bb]">Item List</h2>
            <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                <form method="GET" action="" class="relative w-full sm:w-64">
                    <i class="absolute left-4 top-3 bx bx-search text-gray-500"></i>
                    <input type="search" name="search" id="searchInput" placeholder="Search"
                        value="<?= htmlspecialchars($search) ?>"
                        class="w-full pl-10 pr-4 py-2 border rounded-full focus:ring focus:ring-blue-300">
                    <!-- Keep limit in hidden field to maintain it during search -->
                    <input type="hidden" name="limit" value="<?= $limit ?>">
                </form>
                <!-- Button to Open Modal -->
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%] items-end">
                    <button id="openModal"
                        class="bg-blue-500 text-sm text-white px-4 py-2 rounded-md hover:bg-blue-600 w-full">
                        Add Item
                    </button>
                </div>
            </div>
            <?php if (!empty($search)): ?>
                <div class="mt-2 text-sm text-gray-600">
                    Showing results for: "<strong><?= htmlspecialchars($search) ?></strong>"
                    <a href="?" class="ml-2 text-blue-500 hover:text-blue-700">Clear search</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Table -->
        <div class="table-container overflow-x-auto">
            <table class="min-w-full table-auto border-separate border-spacing-0">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left">Stock No.</th>
                        <th class="px-4 py-2 text-left">Item Description</th>
                        <th class="px-4 py-2 text-left">Item Unit</th>
                        <th class="px-4 py-2 text-left">Unit Cost</th>
                        <th class="px-4 py-2 text-left">Min Threshold</th>
                        <th class="px-4 py-2 text-left">Max Threshold</th>
                        <th class="px-4 py-2 text-left">Action</th>
                    </tr>
                </thead>
                <tbody id="table-body">
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr class="hover:bg-gray-200 border-b border-gray-300">
                                <td class="px-4 py-2"><?= htmlspecialchars($row['ITEM_CODE']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($row['ITEM_DESC']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($row['ITEM_UNIT']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars(number_format($row['ITEM_COST'], 2)) ?></td>
                                <td class="px-4 py-2">
                                    <?= htmlspecialchars(number_format($row['MIN_THRESHOLD'])) ?? 'Not set' ?>
                                </td>
                                <td class="px-4 py-2">
                                    <?= htmlspecialchars(number_format($row['MAX_THRESHOLD'])) ?? 'Not set' ?>
                                </td>
                                <td class="action-column flex justify-left items-center space-x-2 px-4 py-2">
                                    <button onclick="editItem(<?= htmlspecialchars(json_encode($row)) ?>)"
                                        class="text-blue-600 hover:text-blue-800">
                                        <i data-lucide="edit"></i>
                                    </button>
                                    <button onclick="deleteItem(<?= $row['ITEM_ID'] ?>)"
                                        class="text-red-600 hover:text-red-800">
                                        <i data-lucide="archive"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr id="no-data-row">
                            <td colspan="10" class="text-center py-2 border-b border-gray-300">
                                <?php if (!empty($search)): ?>
                                    No results found for "<?= htmlspecialchars($search) ?>"
                                <?php else: ?>
                                    No data found
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

                <!-- Results per page dropdown -->
                <form method="GET" class="inline">
                    <?php if (!empty($search)): ?>
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <?php endif; ?>
                    <select onchange="this.form.submit()" name="limit" class="border px-2 py-1 rounded text-sm ml-4">
                        <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                        <option value="20" <?= $limit == 20 ? 'selected' : '' ?>>20</option>
                        <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                    </select>
                </form>
            </div>

            <!-- Results info -->
            <p class="text-center text-xs text-gray-500 mt-3">
                Results: <?= $offset + 1 ?> - <?= min($offset + $limit, $totalItem) ?> of <?= $totalItem ?>
                <?php if (!empty($search)): ?>
                    for "<?= htmlspecialchars($search) ?>"
                <?php endif; ?>
                items
            </p>
        <?php endif; ?>
    </div>

    <!-- Modal -->
    <div id="modal" class="fixed inset-0 flex items-center justify-center bg-gray-900 bg-opacity-50 z-[1000] hidden">
        <div class="bg-white m-3 rounded-lg shadow-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
            <h2 id="modalText" class="text-xl font-bold mb-4">Add New Item</h2>
            <form id="form" method="POST">
                <input type="hidden" name="itemId" id="itemId">
                <div class="mb-3">
                    <label class="block font-medium" for="itemCode">Item Code</label>
                    <input type="text" id="itemCode" name="itemCode" value="<?= $itmCode ?>"
                        class="w-full border rounded px-3 py-2" required disabled>
                </div>
                <!-- <div class="mb-3">
                    <label class="block font-medium">Barcode Case</label>
                    <input type="text" id="barCase" name="barCase" class="w-full border rounded px-3 py-2" required>
                </div>
                <div class="mb-3">
                    <label class="block font-medium">Barcode Piece</label>
                    <input type="text" id="barPiece" name="barPiece" class="w-full border rounded px-3 py-2" required>
                </div> -->
                <div class="mb-3">
                    <label class="block font-medium" for="desc">Item Description</label>
                    <input type="text" id="desc" name="desc" class="w-full border rounded px-3 py-2" required>
                </div>
                <div class="mb-3">
                    <label class="block font-medium" for="unit">Item Unit</label>
                    <input type="text" id="unit" name="unit" class="w-full border rounded px-3 py-2" required>
                </div>
                <div class="mb-3">
                    <label class="block font-medium" for="cost">Unit Cost</label>
                    <input type="number" id="cost" name="cost" class="w-full border rounded px-3 py-2" required step="0.01" min="0">
                </div>


                <!-- ADD THRESHOLD FIELDS HERE -->
                <div class="mb-3">
                    <label class="block font-medium text-sm text-gray-700">Inventory Thresholds</label>
                    <div class="grid grid-cols-2 gap-3 mt-2">
                        <div>
                            <label class="block text-xs font-medium text-gray-600" for="minThreshold">Minimum Stock
                                (case)</label>
                            <input type="number" id="minThreshold" name="minThreshold" placeholder="100"
                                class="w-full border rounded px-3 py-2 text-sm" min="0">
                            <p class="text-xs text-gray-500 mt-1">Low stock alert</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600" for="maxThreshold">Maximum Stock
                                (case)</label>
                            <input type="number" id="maxThreshold" name="maxThreshold" placeholder="10000"
                                class="w-full border rounded px-3 py-2 text-sm" min="0">
                            <p class="text-xs text-gray-500 mt-1">Overstock alert</p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <button type="button" id="closeModal"
                        class="bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600">Cancel</button>
                    <button type="submit"
                        class="bg-blue-500 text-white px-4 py-2 rounded-md hover:bg-blue-600">Save</button>
                </div>
            </form>
        </div>
    </div>

</body>
<script src="https://unpkg.com/lucide@latest"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    lucide.createIcons();

    // Auto-submit form on Enter key in search
    document.getElementById('searchInput').addEventListener('keypress', function (e) {
        if (e.key === 'Enter') {
            this.form.submit();
        }
    });
</script>
<script src="item.js?id=<?= time() ?>"></script>

</html>