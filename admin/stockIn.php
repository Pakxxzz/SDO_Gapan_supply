<?php
// admin/stockIn.php
include "./sidebar.php";
include "../API/db-connector.php";

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$dateFrom = isset($_GET['dateFrom']) ? $_GET['dateFrom'] : date('Y-m-d');
$dateTo = isset($_GET['dateTo']) ? $_GET['dateTo'] : null;

$whereClause = "WHERE item.ITEM_IS_ARCHIVED = 0";

if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $whereClause .= " AND (
        item.ITEM_CODE LIKE '%$searchTerm%' OR
        item.ITEM_DESC LIKE '%$searchTerm%' OR
        item.ITEM_UNIT LIKE '%$searchTerm%' OR
        stock_in.SI_REMARKS LIKE '%$searchTerm%' OR
        stock_in.SI_QUANTITY LIKE '%$searchTerm%' OR
        stock_in.CREATED_AT LIKE '%$searchTerm%'
    )";
}

if ($dateFrom) {
    $dateFromEscaped = $conn->real_escape_string($dateFrom);
    $whereClause .= " AND stock_in.CREATED_AT >= STR_TO_DATE('$dateFromEscaped', '%Y-%m-%dT%H:%i')";
}

if ($dateTo) {
    $dateToEscaped = $conn->real_escape_string($dateTo);
    $whereClause .= " AND stock_in.CREATED_AT <= STR_TO_DATE('$dateToEscaped', '%Y-%m-%dT%H:%i')";
}

$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$totalQuery = "SELECT COUNT(*) AS total FROM stock_in LEFT JOIN item ON stock_in.ITEM_ID = item.ITEM_ID $whereClause";
$totalResult = $conn->query($totalQuery);
$totalRow = $totalResult->fetch_assoc();
$totalItem = $totalRow['total'];

$totalPages = ceil($totalItem / $limit);
$sql = "SELECT stock_in.*, item.ITEM_ID, item.ITEM_CODE, item.ITEM_DESC, item.ITEM_UNIT, item.ITEM_IS_ARCHIVED
        FROM stock_in 
        LEFT JOIN item ON stock_in.ITEM_ID = item.ITEM_ID
        $whereClause ORDER BY stock_in.CREATED_AT DESC
        LIMIT $limit OFFSET $offset";
$result = $conn->query($sql);

function generatePageLinks($totalPages, $page, $limit, $search = '', $dateFrom = '', $dateTo = '')
{
    $links = [];

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
    $urlParams .= "&limit=$limit";
}

$itemQuery = "SELECT ITEM_ID, ITEM_CODE, ITEM_DESC FROM item WHERE ITEM_IS_ARCHIVED = 0 ORDER BY ITEM_CODE ASC";
$itemResult = $conn->query($itemQuery);
$itemsArray = [];
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
    <title>Stock In Management</title>
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="inventory.css?id=<?= time() ?>">
    <style>
        [x-cloak] {
            display: none !important;
        }

        .dropdown-menu {
            z-index: 50;
        }

        #batchModal {
            z-index: 1000;
        }
    </style>
</head>

<body>
    <div class="content w-[calc(100vw-60px)] mx-auto overflow-x-auto p-8 px-8">
        <div class="bg-white shadow-md rounded-lg p-6 mb-6">
            <h2 class="font-bold mb-4 text-[13px] xs:text-[12px] sm:text-[15px] font-bold text-[#0047bb]">Stock In</h2>
            <div class="flex flex-wrap mb-3 sm:flex-row justify-between items-center gap-4 mb-6">
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
                    <button id="openModal"
                        class="bg-blue-500 text-sm text-white px-4 py-2 rounded-md hover:bg-blue-600 w-full">
                        Stock In
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap gap-4 mb-6">
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%]">
                    <label for="dateFrom" class="text-sm text-gray-600 font-medium mb-1">From</label>
                    <input type="date" id="dateFrom" class="w-full border rounded px-4 py-2.5 text-gray-700 text-sm"
                        value="<?= htmlspecialchars($_GET['dateFrom'] ?? '') ?>" />
                </div>

                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%]">
                    <label for="dateTo" class="text-sm text-gray-600 font-medium mb-1">To</label>
                    <input type="date" id="dateTo" class="w-full border rounded px-4 py-2.5 text-gray-700 text-sm"
                        value="<?= htmlspecialchars($_GET['dateTo'] ?? '') ?>" />
                </div>

                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%] flex items-end">
                    <button onclick="applyFilters()"
                        class="bg-green-500 text-sm text-white px-4 py-2 rounded-md hover:bg-green-600 w-full">
                        Apply Filters
                    </button>
                </div>

                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%] flex items-end">
                    <button onclick="clearFilters()"
                        class="bg-gray-500 text-sm text-white px-4 py-2 rounded-md hover:bg-gray-600 w-full">
                        Clear Filters
                    </button>
                </div>
            </div>
        </div>

        <div class="table-container overflow-x-auto">
            <table class="min-w-full table-auto border-separate border-spacing-0">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left">Stock No.</th>
                        <th class="px-4 py-2 text-left">Item Description</th>
                        <th class="px-4 py-2 text-left">Item Unit</th>
                        <th class="px-4 py-2 text-left">Quantity</th>
                        <th class="px-4 py-2 text-left">Remarks</th>
                        <th class="px-4 py-2 text-left">Date</th>
                        <th class="px-4 py-2 text-left">Created By</th>
                        <th class="px-4 py-2 text-left">Action</th>
                    </tr>
                </thead>
                <tbody id="table-body">
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <?php $createdBy = "SELECT USER_FNAME, USER_LNAME FROM users WHERE USER_ID = " . $row['CREATED_BY'];
                            $createdByResult = $conn->query($createdBy);
                            $createdByName = '';
                            if ($createdByResult && $createdByResult->num_rows > 0) {
                                $createdByRow = $createdByResult->fetch_assoc();
                                $createdByName = htmlspecialchars($createdByRow['USER_FNAME'] . ' ' . $createdByRow['USER_LNAME']);
                            }
                            ?>
                            <tr class="hover:bg-gray-200 border-b border-gray-300">
                                <td class="px-4 py-2"><?= htmlspecialchars($row['ITEM_CODE']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($row['ITEM_DESC']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($row['ITEM_UNIT']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($row['SI_QUANTITY']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($row['SI_REMARKS']) ?></td>
                                <td class="px-4 py-2"><?= date('M j, Y \a\t g:i a', strtotime($row['CREATED_AT'])) ?></td>
                                <td class="px-4 py-2">
                                    <?= htmlspecialchars($createdByName) ?>
                                </td>
                                <td class="action-column flex justify-left items-center space-x-2 px-4 py-2">
                                    <button onclick="editItem(<?= htmlspecialchars(json_encode($row)) ?>)"
                                        class="text-blue-600 hover:text-blue-800">
                                        <i data-lucide="edit"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr id="no-data-row">
                            <td colspan="10" class="text-center py-2 border-b border-gray-300">
                                <?php if (!empty($search)): ?>
                                    No results found for "<?= htmlspecialchars($search) ?>"
                                <?php elseif ($dateFrom === date('Y-m-d') && $dateTo === date("Y-m-d")): ?>
                                    No stock in records found for today
                                <?php elseif (!empty($dateFrom) || !empty($dateTo)): ?>
                                    No results found for the selected date range
                                <?php else: ?>
                                    No stock in records found
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

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
                items
            </p>
        <?php endif; ?>
    </div>

    <div id="batchModal"
        class="fixed inset-0 flex items-center justify-center bg-gray-900 bg-opacity-50 z-[1000] hidden">
        <div
            class="bg-white m-3 rounded-lg shadow-lg p-4 sm:p-6 w-full sm:w-[95%] lg:w-[800px] max-h-[90vh] overflow-y-auto text-sm sm:text-base">
            <h2 id="modalTitle" class="text-xl font-bold mb-4">Stock In Batch</h2>

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
                <div class="mb-4">
                    <div class="flex justify-between items-center mb-2">
                        <label class="block font-medium">Items</label>
                        <button type="button" id="addItemRow"
                            class="text-blue-600 hover:text-blue-800 text-sm flex items-center">
                            <i data-lucide="plus-circle" class="w-4 h-4 mr-1"></i> Add Item
                        </button>
                    </div>
                    <div id="itemsContainer">
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

    <div id="modal" class="fixed inset-0 flex items-center justify-center bg-gray-900 bg-opacity-50 z-[1000] hidden">
        <div class="bg-white m-3 rounded-lg shadow-lg p-6 w-96 max-h-[90vh] overflow-y-auto">
            <h2 id="editModalText" class="text-xl font-bold mb-4">Edit Stock In</h2>
            <form id="editForm">
                <input type="hidden" name="stockInId" id="stockInId">
                <div class="mb-3">
                    <label class="block font-medium" for="edit_stock_no">Stock No.</label>
                    <select name="stock_no" id="edit_stock_no" class="w-full border rounded px-3 py-2">
                        <option value="" selected disabled>Select Stock No.</option>
                        <?php
                        $itemResult->data_seek(0);
                        while ($row = $itemResult->fetch_assoc()): ?>
                            <option value="<?= $row['ITEM_ID'] ?>" data-code="<?= htmlspecialchars($row['ITEM_CODE']) ?>"
                                data-desc="<?= htmlspecialchars($row['ITEM_DESC']) ?>">
                                <?= htmlspecialchars($row['ITEM_CODE']) . " - " . htmlspecialchars($row['ITEM_DESC']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="block font-medium" for="edit_quantity">Quantity</label>
                    <input type="number" id="edit_quantity" name="quantity" class="w-full border rounded px-3 py-2"
                        required>
                </div>
                <div class="mb-3">
                    <label class="block font-medium" for="edit_remarks">Remarks</label>
                    <input type="text" id="edit_remarks" name="remarks" class="w-full border rounded px-3 py-2"
                        required>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" id="closeEditModal"
                        class="bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600">Cancel</button>
                    <button type="submit"
                        class="bg-blue-500 text-white px-4 py-2 rounded-md hover:bg-blue-600">Update</button>
                </div>
            </form>
        </div>
    </div>

</body>
<script src="https://unpkg.com/lucide@latest"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    const itemsData = <?= json_encode($itemsArray) ?>;

    lucide.createIcons();

    document.getElementById('searchInput').addEventListener('keypress', function (e) {
        if (e.key === 'Enter') {
            this.form.submit();
        }
    });

    function applyFilters() {
        const dateFrom = document.getElementById("dateFrom").value;
        const dateTo = document.getElementById("dateTo").value;
        const search = document.getElementById("searchInput").value;
        const limit = document.querySelector('input[name="limit"]').value || <?= $limit ?>;

        let url = `?page=1&limit=${limit}`;

        if (dateFrom) url += `&dateFrom=${encodeURIComponent(dateFrom)}`;
        if (dateTo) url += `&dateTo=${encodeURIComponent(dateTo)}`;
        if (search) url += `&search=${encodeURIComponent(search)}`;

        window.location.href = url;
    }

    function clearFilters() {
        const limit = document.querySelector('input[name="limit"]').value || <?= $limit ?>;
        window.location.href = `?page=1&limit=${limit}`;
    }

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
                        <option value="">Select Item</option>
                        ${itemsData.map(item => `
                            <option value="${item.ITEM_ID}" data-code="${item.ITEM_CODE}" data-desc="${item.ITEM_DESC}" 
                                ${selectedId === item.ITEM_ID ? 'selected' : ''}>
                                ${item.ITEM_CODE} - ${item.ITEM_DESC}
                            </option>
                        `).join('')}
                    </select>
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

    document.getElementById('openModal').addEventListener('click', function () {
        document.getElementById('modalTitle').textContent = 'Stock In Batch';
        const container = document.getElementById('itemsContainer');
        container.innerHTML = '';
        container.appendChild(createItemRow());
        document.getElementById('batchModal').classList.remove('hidden');
        lucide.createIcons();
    });

    document.getElementById('addItemRow').addEventListener('click', function () {
        document.getElementById('itemsContainer').appendChild(createItemRow());
        lucide.createIcons();
    });

    function closeBatchModal() {
        document.getElementById('batchModal').classList.add('hidden');
        if (itemSearch) {
            itemSearch.value = '';
            searchResults.classList.add('hidden');
        }
    }

    document.getElementById('batchForm').addEventListener('submit', async function (e) {
        e.preventDefault();

        const items = [];
        const itemSelects = document.querySelectorAll('#itemsContainer .item-select');
        const quantities = document.querySelectorAll('#itemsContainer .quantity-input');
        const remarks = document.querySelectorAll('#itemsContainer input[name="remarks[]"]');

        // Track duplicate items
        const itemIds = [];
        let hasDuplicate = false;
        let duplicateItems = [];

        for (let i = 0; i < itemSelects.length; i++) {
            if (!itemSelects[i].value || !quantities[i].value || !remarks[i].value) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing Fields',
                    text: 'Please fill in all item fields'
                });
                return;
            }

            const itemId = itemSelects[i].value;
            const itemCode = itemSelects[i].options[itemSelects[i].selectedIndex].text.split(' - ')[0];
            const itemDesc = itemSelects[i].options[itemSelects[i].selectedIndex].text.split(' - ')[1];
            const quantity = parseInt(quantities[i].value);

            items.push({
                item_id: itemId,
                quantity: quantity,
                remarks: remarks[i].value,
                item_code: itemCode,
                item_desc: itemDesc
            });

            // Check for duplicates
            if (itemIds.includes(itemId)) {
                hasDuplicate = true;
                duplicateItems.push(`${itemCode} - ${itemDesc} (${quantity} units)`);
            }
            itemIds.push(itemId);
        }

        // If duplicates found, show confirmation dialog
        if (hasDuplicate) {
            const duplicateList = duplicateItems.filter((v, i, a) => a.indexOf(v) === i).join('\n• ');

            const confirmDuplicate = await Swal.fire({
                title: 'Duplicate Items Detected',
                html: `You are adding the same item multiple times:<br><br>• ${duplicateList}<br><br>Is this intentional?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, continue',
                cancelButtonText: 'No, review items'
            });

            if (!confirmDuplicate.isConfirmed) {
                return;
            }
        }

        Swal.fire({
            title: 'Processing...',
            text: 'Please wait',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        try {
            const response = await fetch('stockIn-php.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'add_batch',
                    items: items.map(({ item_id, quantity, remarks }) => ({
                        item_id: item_id,
                        quantity: quantity,
                        remarks: remarks
                    }))
                })
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
        }
    });

    const itemSearch = document.getElementById('itemSearch');
    const searchResults = document.getElementById('searchResults');

    const searchableItems = itemsData.map(item => ({
        id: item.ITEM_ID,
        code: item.ITEM_CODE,
        desc: item.ITEM_DESC,
        searchText: `${item.ITEM_CODE} ${item.ITEM_DESC}`.toLowerCase()
    }));

    itemSearch.addEventListener('input', function () {
        const searchTerm = this.value.toLowerCase().trim();

        if (searchTerm === '') {
            searchResults.classList.add('hidden');
            return;
        }

        const filtered = searchableItems.filter(item =>
            item.searchText.includes(searchTerm)
        ).slice(0, 10);

        if (filtered.length > 0) {
            searchResults.innerHTML = filtered.map(item => `
                <div class="p-3 hover:bg-blue-50 cursor-pointer border-b last:border-0 flex justify-between items-center" 
                     onclick="selectSearchItem(${item.id})">
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

    window.selectSearchItem = function (itemId) {
        const itemRows = document.querySelectorAll('#itemsContainer .item-row');
        let targetRow = null;

        for (let row of itemRows) {
            const select = row.querySelector('.item-select');
            if (!select.value) {
                targetRow = row;
                break;
            }
        }

        if (!targetRow) {
            targetRow = createItemRow();
            document.getElementById('itemsContainer').appendChild(targetRow);
        }

        const select = targetRow.querySelector('.item-select');
        select.value = itemId;
        targetRow.querySelector('.quantity-input').focus();

        itemSearch.value = '';
        searchResults.classList.add('hidden');
        lucide.createIcons();
    };

    document.addEventListener('click', function (e) {
        if (itemSearch && !itemSearch.contains(e.target) && !searchResults?.contains(e.target)) {
            searchResults?.classList.add('hidden');
        }
    });

    itemSearch.addEventListener('focus', function () {
        if (this.value.trim() !== '') {
            this.dispatchEvent(new Event('input'));
        }
    });

    itemSearch.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            searchResults.classList.add('hidden');
        }
    });

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

    function editItem(data) {
        document.getElementById("stockInId").value = data.SI_ID;
        document.getElementById("edit_stock_no").value = data.ITEM_ID;
        document.getElementById("edit_quantity").value = data.SI_QUANTITY;
        document.getElementById("edit_remarks").value = data.SI_REMARKS;
        document.getElementById("modal").classList.remove("hidden");
    }

    document.getElementById("closeEditModal").addEventListener("click", function () {
        document.getElementById("modal").classList.add("hidden");
        document.getElementById("editForm").reset();
    });

    document.getElementById("editForm").addEventListener("submit", async function (event) {
        event.preventDefault();

        const formData = {
            stockInId: document.getElementById("stockInId").value.trim(),
            item_id: document.getElementById("edit_stock_no").value.trim(),
            quantity: document.getElementById("edit_quantity").value.trim(),
            remarks: document.getElementById("edit_remarks").value.trim(),
            action: "edit",
        };

        if (!formData.quantity || !formData.remarks || !formData.item_id) {
            Swal.fire({
                icon: "warning",
                title: "Missing Fields",
                text: "Please fill in all required fields before submitting.",
            });
            return;
        }

        const result = await Swal.fire({
            title: "Are you sure?",
            text: "This action will update the stock in record.",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#3085d6",
            confirmButtonText: "Yes, update it!",
            reverseButtons: true,
        });

        if (!result.isConfirmed) return;

        const password = await promptPassword();
        if (!password) return;

        Swal.fire({
            title: "Updating...",
            text: "Please wait while we update the item",
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            },
        });

        fetch("stockIn-php.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                ...formData,
                password: password,
            }),
        })
            .then((response) => response.json())
            .then((data) => {
                if (data.status === "success") {
                    Swal.fire({
                        icon: "success",
                        title: "Success!",
                        text: data.message,
                    }).then(() => {
                        document.getElementById("editForm").reset();
                        document.getElementById("modal").classList.add("hidden");
                        setTimeout(() => location.reload(), 100);
                    });
                } else {
                    Swal.fire({ icon: "error", title: "Error", text: data.message });
                }
            })
            .catch((error) => {
                console.error("Fetch error:", error);
                Swal.fire({
                    icon: "error",
                    title: "Request Failed",
                    text: "An error occurred. Please try again.",
                });
            });
    });
</script>

</html>