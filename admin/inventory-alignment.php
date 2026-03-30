<?php
// admin/inventory-alignment.php
include "./sidebar.php";
session_regenerate_id(true);
include "../API/db-connector.php";

$fk = $_SESSION['user_id'];


//  Filters (GET)
$search = isset($_GET['search']) ? trim($_GET['search']) : '';


// Pagination

$limit = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;


//  WHERE conditions

$whereClauses = ["1=1"];

// Search filter
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $whereClauses[] = "(
        md.BATCH LIKE '%$searchTerm%' OR
        i.ITEM_CODE LIKE '%$searchTerm%' OR
        i.ITEM_DESC LIKE '%$searchTerm%' OR
        md.STATUS LIKE '%$searchTerm%' OR
        CONCAT(md.SYSTEM_QUANTITY, '') LIKE '%$searchTerm%' OR
        CONCAT(md.ACTUAL_CASE_QUANTITY, '') LIKE '%$searchTerm%' OR
        CONCAT(md.REMAIN_PIECE, '') LIKE '%$searchTerm%' OR
        CONCAT(md.TOTAL_QUANTITY, '') LIKE '%$searchTerm%' OR
        CONCAT(md.DIFFERENCE, '') LIKE '%$searchTerm%'
    )";
}

$whereSQL = implode(" AND ", $whereClauses);

// Total count of unique BATCH
$totalQuery = "SELECT COUNT(DISTINCT md.BATCH) AS total
               FROM masterdata md
               JOIN item i ON md.ITEM_ID = i.ITEM_ID
               WHERE $whereSQL
                 AND md.STATUS IN ('Pending', 'Counting', 'Approved', 'Counted')";
$totalResult = $conn->query($totalQuery);
$totalRow = $totalResult ? $totalResult->fetch_assoc() : ['total' => 0];
$totalMd = (int) ($totalRow['total'] ?? 0);

$totalPages = ($limit > 0) ? (int) ceil($totalMd / $limit) : 0;
if ($totalPages > 0 && $page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

//Get distinct BATCH for current page 

$batchQuery = "SELECT DISTINCT md.BATCH
               FROM masterdata md
               JOIN item i ON md.ITEM_ID = i.ITEM_ID
               WHERE $whereSQL
                 AND md.STATUS IN ('Pending', 'Counting', 'Approved', 'Counted')
               ORDER BY md.CREATED_AT DESC
               LIMIT $limit OFFSET $offset";
$batchResult = $conn->query($batchQuery);

$batchList = [];
while ($batchResult && ($row = $batchResult->fetch_assoc())) {
    $batchList[] = "'" . $conn->real_escape_string($row['BATCH']) . "'";
}

// Main query - records for these batches

if (!empty($batchList)) {
    $inClause = implode(",", $batchList);

    $sql = "SELECT md.*,
                i.ITEM_ID, i.ITEM_CODE, i.ITEM_BARCODE_CASE, i.ITEM_BARCODE_PIECE, i.ITEM_DESC, i.ITEM_UNIT
            FROM masterdata md
            JOIN item i ON md.ITEM_ID = i.ITEM_ID
            WHERE md.BATCH IN ($inClause)
              AND $whereSQL
              AND md.STATUS IN ('Pending','Counting','Approved','Counted')
            ORDER BY md.CREATED_AT DESC, i.ITEM_DESC ASC";

    $result = $conn->query($sql);
} else {
    $result = false;
}

$users = "SELECT ur.UR_ROLE FROM users u JOIN user_role ur ON u.UR_ID = ur.UR_ID WHERE u.USER_ID = ?";
$stmt = $conn->prepare($users);
$stmt->bind_param("i", $fk);
$stmt->execute();
$userResult = $stmt->get_result();
$user = $userResult->fetch_assoc();
$stmt->close();

$userRole = $user['UR_ROLE'] ?? 'Unknown';

// Pagination helper

function generatePageLinks($totalPages, $page, $limit, $search = '')
{
    $links = [];

    $urlParams = '';
    if (!empty($search))
        $urlParams .= "&search=" . urlencode($search);

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
            $link = ['page' => $p, 'url' => "?page=$p&limit=$limit$urlParams"];
        } else {
            $link = ['page' => "...", 'url' => "#"];
        }
    }

    return $links;
}

$pageLinks = generatePageLinks($totalPages, $page, $limit, $search);

// URL params for prev/next

$urlParams = '';
if (!empty($search))
    $urlParams .= "&search=" . urlencode($search);
if ($limit != 10)
    $urlParams .= "&limit=$limit";
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Alignment</title>
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="inventory.css?id=<?= time() ?>">

    <style>
        .negative-diff {
            color: #e53e3e;
            font-weight: bold;
        }

        .positive-diff {
            color: #38a169;
            font-weight: bold;
        }

        .zero-diff {
            color: #a0aec0;
        }

        .status-pending {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .status-counting {
            background-color: #fef3c7;
            color: #92400e;
        }

        .status-completed {
            background-color: #d1fae5;
            color: #065f46;
        }

        .status-approved {
            background-color: #ede9fe;
            color: #5b21b6;
        }
    </style>
</head>

<body>
    <div class="content flex-1 w-full overflow-x-auto mx-auto flex flex-col">
        <div class="bg-white shadow-md rounded-lg p-6 mb-6">
            <h2 class="font-bold mb-4 text-[13px] xs:text-[12px] sm:text-[15px] font-bold text-[#0047bb]">Inventory
                Alignment</h2>
            <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                <form method="GET" action="" class="relative w-full sm:w-64">
                    <i class="absolute left-4 top-3 bx bx-search text-gray-500"></i>
                    <input type="search" name="search" id="searchInput" placeholder="Search"
                        value="<?= htmlspecialchars($search) ?>"
                        class="w-full pl-10 pr-4 py-2 border rounded-full focus:ring focus:ring-blue-300">
                    <!-- Keep limit in hidden field to maintain it during search -->
                    <input type="hidden" name="limit" value="<?= $limit ?>">
                </form>
                <!-- Start New Alignment -->
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%] flex items-end">
                    <button id="startAlignment"
                        class="bg-blue-500 text-sm text-white px-4 py-2 rounded-md hover:bg-blue-600 w-full">
                        Start Month-end Alignment
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

        <div class="w-full mx-auto pb-15">
            <?php
            $itemsByBatch = [];
            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $batch = $row['BATCH'];
                    $itemsByBatch[$batch][] = $row;
                }
            } else {
                echo '<div class="text-center text-gray-500 mt-10 text-sm">';
                echo !empty($search) ? 'No inventory alignment records found for "' . htmlspecialchars($search) . '"' : 'No inventory alignment records found.';
                echo '</div>';
            }
            ?>

            <?php foreach ($itemsByBatch as $batch => $items): ?>
                <div class="batch-container bg-white shadow-md rounded-lg p-6 mb-6"
                    data-batch="<?= htmlspecialchars($batch) ?>">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-[#0047bb]">
                            <?= 'Batch No.: ' . htmlspecialchars($batch) . ' | Date: ' .
                                htmlspecialchars(date('M j, Y', strtotime($items[0]['CREATED_AT']))) . ' At ' .
                                htmlspecialchars(date('g:i A', strtotime($items[0]['CREATED_AT']))) ?>
                        </h3>
                        <span class="px-3 py-1 rounded-full text-xs font-semibold bg-purple-100 text-purple-800">
                            Alignment
                        </span>
                    </div>

                    <div class="table-container overflow-x-auto">
                        <table class="min-w-full table-fixed border-separate border-spacing-0">
                            <thead>
                                <tr>
                                    <th class="px-4 py-2 text-left">SKU Code</th>
                                    <th class="px-4 py-2 text-left">Item Description</th>
                                    <th class="px-4 py-2 text-left">Unit</th>
                                    <th class="px-4 py-2 text-left">System Qty</th>
                                    <th class="px-4 py-2 text-left">Counted Qty</th>
                                    <th class="px-4 py-2 text-left">Difference</th>
                                    <th class="px-4 py-2 text-left">Status</th>
                                    <th class="px-4 py-2 text-left">Action</th>
                                </tr>
                            </thead>

                            <tbody id="table-body">
                                <?php foreach ($items as $row): ?>
                                    <tr class="hover:bg-gray-50 border-b border-gray-200" id="row-<?= $row['MD_ID'] ?>">
                                        <td class="px-4 py-2"><?= htmlspecialchars($row['ITEM_CODE']) ?></td>
                                        <td class="px-4 py-2 break-words"><?= htmlspecialchars($row['ITEM_DESC']) ?></td>
                                        <td class="px-4 py-2"><?= htmlspecialchars($row['ITEM_UNIT']) ?></td>
                                        <td class="px-4 py-2">
                                            <?= htmlspecialchars(number_format((float) $row['SYSTEM_QUANTITY'])) ?>
                                        </td>
                                        <td class="px-4 py-2">
                                            <?= htmlspecialchars(number_format((float) $row['TOTAL_QUANTITY'])) ?>
                                        </td>
                                        <!-- <td class="px-4 py-2">
                                            <?php
                                            if ($row['INVENTORY_IMPACT'] == 0) {
                                                // Count only mode - show counted value separately
                                                echo '<span class="text-amber-600">' . htmlspecialchars(number_format((float) $row['COUNTED_ONLY'])) . '</span>';
                                                echo '<span class="text-xs text-gray-500 block">(Item not found)</span>';
                                            } else {
                                                echo htmlspecialchars(number_format((float) $row['TOTAL_QUANTITY']));
                                            }
                                            ?>
                                        </td> -->
                                        <td class="px-4 py-2 <?=
                                            $row['DIFFERENCE'] < 0 ? 'negative-diff' :
                                            ($row['DIFFERENCE'] > 0 ? 'positive-diff' : 'zero-diff') ?>">
                                            <?php
                                            if ($row['INVENTORY_IMPACT'] == 0) {
                                                echo '<span class="text-gray-500">N/A (Item not found)</span>';
                                            } else {
                                                echo htmlspecialchars(number_format((float) $row['DIFFERENCE']));
                                            }
                                            ?>
                                        </td>
                                        <td class="px-4 py-2">
                                            <span
                                                class="px-2 py-1 rounded-full text-xs
                                            <?= $row['STATUS'] == 'Pending' ? 'status-pending' :
                                                ($row['STATUS'] == 'Counting' ? 'status-counting' :
                                                    ($row['STATUS'] == 'Completed' ? 'status-completed' :
                                                        ($row['STATUS'] == 'Approved' ? 'status-approved' : 'status-approved'))) ?>">
                                                <?= htmlspecialchars($row['STATUS']) ?>
                                            </span>
                                        </td>


                                        <td class="action-column flex justify-left items-center space-x-2 px-4 py-2">
                                            <?php if ($row['STATUS'] === 'Counting' && ($userRole === 'Admin' || $userRole === 'Admin Staff')): ?>
                                                <button onclick="editThis(<?= htmlspecialchars(json_encode($row)) ?>)"
                                                    class="text-blue-600 hover:text-blue-800">
                                                    <i data-lucide="PackageSearch"></i>
                                                <?php endif; ?>
                                                <?php if ($row['STATUS'] === 'Counted' && ($userRole === 'Admin' || $userRole === 'Admin Staff')): ?>
                                                    <button onclick="editThis(<?= htmlspecialchars(json_encode($row)) ?>)"
                                                        class="text-blue-600 hover:text-blue-800">
                                                        <i data-lucide="edit"></i>
                                                    <?php endif; ?>
                                                </button>
                                        </td>

                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (($row['STATUS'] ?? '') === 'Counted'): ?>
                        <div class="flex justify-end mt-4 space-x-2">
                            <button onclick="postBatch('<?= htmlspecialchars($batch) ?>')"
                                class="bg-blue-500 edit text-white px-4 py-2 rounded-md hover:bg-green-600">
                                Post
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <!-- Pagination -->
            <?php if ($totalPages > 0): ?>
                <div class="flex justify-center mt-4">
                    <a href="?page=<?= max(1, $page - 1) ?>&limit=<?= $limit ?><?= $urlParams ?>"
                        class="px-3 py-1 border rounded <?= ($page == 1 || $totalMd == 0) ? 'opacity-50 cursor-not-allowed pointer-events-none' : 'hover:bg-gray-200' ?>">
                        &lt;
                    </a>

                    <?php foreach ($pageLinks as $p): ?>
                        <?php if ($p['page'] === "..."): ?>
                            <span class="px-3 py-1 border rounded text-gray-500 cursor-default select-none">...</span>
                        <?php else: ?>
                            <a href="<?= $p['url'] ?>"
                                class="px-3 py-1 border rounded <?= ($p['page'] == $page) ? 'bg-blue-500 text-white' : 'hover:bg-gray-200' ?>">
                                <?= $p['page'] ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <a href="?page=<?= min($totalPages, $page + 1) ?>&limit=<?= $limit ?><?= $urlParams ?>"
                        class="px-3 py-1 border rounded <?= ($page == $totalPages || $totalMd == 0) ? 'opacity-50 cursor-not-allowed pointer-events-none' : 'hover:bg-gray-200' ?>">
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

                <p class="text-center text-xs text-gray-500 mt-3">
                    Show Batches: <?= ($totalMd > 0 ? $offset + 1 : 0) ?> - <?= min($offset + $limit, $totalMd) ?> of
                    <?= $totalMd ?>
                    <?php if (!empty($search)): ?> for "<?= htmlspecialchars($search) ?>"<?php endif; ?>
                    items
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal"
        class="fixed inset-0 flex items-center justify-center bg-gray-900 bg-opacity-50 z-[1000] hidden">
        <div class="bg-white m-3 rounded-lg shadow-lg p-6 w-full max-w-md">
            <h2 id="modalTitle" class="text-xl font-bold mb-4">Edit Alignment Item</h2>
            <form id="editForm">
                <input type="hidden" name="md_id" id="mdId">
                <input type="hidden" name="action" value="edit_item">
                <input type="hidden" name="inventory_impact" id="inventoryImpact" value="1">
                <input type="hidden" name="counted_only" id="countedOnly" value="0">

                <div class="mb-4">
                    <label class="block font-medium">Item Description</label>
                    <p id="itemDescription" class="text-gray-700"></p>
                </div>

                <div class="mb-4">
                    <label class="block font-medium">System Quantity</label>
                    <p id="systemQuantity" class="text-gray-700 font-bold"></p>
                </div>

                <!-- Mode Toggle -->
                <div class="mb-4">
                    <label class="block font-medium mb-2">Update Mode</label>
                    <div class="flex gap-4">
                        <label class="flex items-center">
                            <input type="radio" name="mode" value="inventory" class="mode-radio mr-2" checked>
                            <span class="text-sm">Update Inventory</span>
                        </label>
                        <label class="flex items-center">
                            <input type="radio" name="mode" value="count_only" class="mode-radio mr-2">
                            <span class="text-sm">Item not found</span>
                        </label>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block font-medium" for="actualCases">Counted Quantity</label>
                    <input type="number" name="actual_cases" id="actualCases" class="w-full border rounded px-3 py-2"
                        min="0" required>
                    <p id="modeHint" class="text-xs text-blue-600 mt-1">Will update inventory quantity</p>
                </div>

                <div class="flex justify-end gap-2">
                    <button type="button" id="closeModal"
                        class="bg-gray-300 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-400">Cancel</button>
                    <button type="submit"
                        class="bg-blue-500 text-white px-4 py-2 rounded-md hover:bg-blue-600">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        lucide.createIcons();

        // Enter key submits search form
        document.getElementById('searchInput').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') this.form.submit();
        });

        function clearFilters() {
            const params = new URLSearchParams();
            params.set('page', '1');
            params.set('limit', '<?= (int) $limit ?>');
            window.location.href = '?' + params.toString();
        }

        // Close modal
        document.getElementById("closeModal").addEventListener("click", function () {
            document.getElementById("editModal").classList.add("hidden");
            document.getElementById("editForm").reset(); // Clear form

        });
        // Edit function
        function editThis(itemData) {
            document.getElementById('mdId').value = itemData.MD_ID;
            document.getElementById('itemDescription').textContent = itemData.ITEM_DESC;
            document.getElementById('systemQuantity').textContent = itemData.SYSTEM_QUANTITY;

            // Set values based on existing data
            document.getElementById('actualCases').value = itemData.ACTUAL_CASE_QUANTITY ?? 0;

            // Set mode based on existing INVENTORY_IMPACT if available
            const inventoryImpact = itemData.INVENTORY_IMPACT ?? 1;
            if (inventoryImpact == 0) {
                document.querySelector('input[name="mode"][value="count_only"]').checked = true;
                document.getElementById('inventoryImpact').value = 0;
                document.getElementById('modeHint').textContent = 'Will not change inventory';
                document.getElementById('modeHint').className = 'text-xs text-amber-600 mt-1';
            } else {
                document.querySelector('input[name="mode"][value="inventory"]').checked = true;
                document.getElementById('inventoryImpact').value = 1;
                document.getElementById('modeHint').textContent = 'Will update inventory quantity';
                document.getElementById('modeHint').className = 'text-xs text-blue-600 mt-1';
            }

            document.getElementById('editModal').classList.remove('hidden');
            document.getElementById('actualCases').focus();
        }

        // Mode toggle handler
        document.querySelectorAll('.mode-radio').forEach(radio => {
            radio.addEventListener('change', function () {
                const mode = this.value;
                const inventoryImpact = document.getElementById('inventoryImpact');
                const modeHint = document.getElementById('modeHint');

                if (mode === 'count_only') {
                    inventoryImpact.value = 0;
                    modeHint.textContent = 'Count only - will NOT change inventory';
                    modeHint.className = 'text-xs text-amber-600 mt-1';
                } else {
                    inventoryImpact.value = 1;
                    modeHint.textContent = 'Will update inventory quantity';
                    modeHint.className = 'text-xs text-blue-600 mt-1';
                }
            });
        });

        // Modified form submit handler
        document.getElementById('editForm').addEventListener('submit', async function (e) {
            e.preventDefault();

            const formData = new FormData(this);

            // Ensure inventory_impact is set correctly
            const mode = document.querySelector('input[name="mode"]:checked')?.value;
            formData.set('inventory_impact', mode === 'count_only' ? '0' : '1');

            // If count only mode, set counted_only to actual_cases
            if (mode === 'count_only') {
                formData.set('counted_only', formData.get('actual_cases'));
            }

            const jsonData = {};
            for (const [key, value] of formData.entries()) jsonData[key] = value;

            Swal.fire({
                title: 'Updating...',
                text: 'Please wait while we update the item',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            fetch('inventory-alignment-php.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(jsonData)
            })
                .then(res => res.json())
                .then(data => {
                    Swal.close();
                    if (data.status === 'success') {
                        Swal.fire({ icon: 'success', title: 'Success', text: data.message })
                            .then(() => {
                                document.getElementById('editModal').classList.add('hidden');
                                location.reload();
                            });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: data.message });
                    }
                })
                .catch(err => {
                    Swal.close();
                    console.error(err);
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong while updating the item.' });
                });
        });

        // Password verification function
        async function promptPassword() {
            const { value: password } = await Swal.fire({
                title: 'Verification Required',
                input: 'password',
                inputLabel: 'Enter your password to continue',
                inputPlaceholder: 'Password',
                showCancelButton: true,
                reverseButtons: true,
                inputValidator: (value) => (!value ? 'Password is required!' : undefined)
            });
            return password;
        }

        async function postBatch(batchNumber) {
            Swal.fire({
                title: 'Post This Batch?',
                text: `Are you sure you want to post batch ${batchNumber}? This action cannot be undone.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Post',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#10B981',
                reverseButtons: true,
            }).then(async (result) => {
                if (result.isConfirmed) {
                    const password = await promptPassword();
                    if (!password) return;

                    Swal.fire({
                        title: 'Updating...',
                        text: 'Please wait while we update the inventory',
                        allowOutsideClick: false,
                        didOpen: () => Swal.showLoading()
                    });

                    fetch('inventory-alignment-php.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'post_batch', batch: batchNumber, password })
                    })
                        .then(r => r.json())
                        .then(data => {
                            Swal.close();
                            if (data.status === 'success') {
                                Swal.fire({ icon: 'success', title: 'Batch Posted', text: data.message })
                                    .then(() => location.reload());
                            } else {
                                Swal.fire({ icon: 'error', title: 'Post Failed', text: data.message });
                            }
                        })
                        .catch(err => {
                            Swal.close();
                            console.error(err);
                            Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong while posting the batch.' });
                        });
                }
            });
        }

        // Start Alignment - Simplified (no modal)
        document.getElementById("startAlignment").addEventListener("click", function () {
            Swal.fire({
                title: "Start Month-end Alignment?",
                text: "This will copy ALL inventory items to the alignment system.",
                icon: "question",
                showCancelButton: true,
                confirmButtonText: "Yes, Start Alignment",
                cancelButtonText: "Cancel",
                reverseButtons: true,
            }).then(async (result) => {
                if (result.isConfirmed) {
                    const password = await promptPassword();
                    if (!password) return;

                    Swal.fire({
                        title: "Starting Alignment...",
                        text: "Please wait while we initialize the alignment",
                        allowOutsideClick: false,
                        didOpen: () => Swal.showLoading(),
                    });

                    fetch("inventory-alignment-php.php", {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({
                            action: "start_alignment",
                            password: password,
                            alignment_type: "Month_end",
                            selected_items: []
                        }),
                    })
                        .then((response) => response.json())
                        .then((data) => {
                            Swal.close();
                            if (data.status === "success") {
                                Swal.fire({
                                    icon: "success",
                                    title: "Alignment Started",
                                    text: data.message
                                }).then(() => location.reload());
                            } else {
                                Swal.fire({
                                    icon: "error",
                                    title: "Error",
                                    text: data.message
                                });
                            }
                        })
                        .catch((error) => {
                            Swal.close();
                            console.error("Error:", error);
                            Swal.fire({
                                icon: "error",
                                title: "Error",
                                text: "Something went wrong while starting the alignment."
                            });
                        });
                }
            });
        });
    </script>
</body>

</html>