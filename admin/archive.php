<?php
include "./sidebar.php";
session_regenerate_id(true);
include "../API/db-connector.php";

$fk = $_SESSION['user_id'];

// Determine which archive to show
$archiveType = isset($_GET['type']) ? $_GET['type'] : 'user';
$validTypes = ['user', 'item', 'office']; // Removed 'vendor' and 'location'
if (!in_array($archiveType, $validTypes)) {
    $archiveType = 'user';
}

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination settings
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Build queries based on archive type with search functionality
if ($archiveType === 'user') {
    // User archive
    $whereClause = "WHERE u.USER_IS_ARCHIVED = 1";
    if (!empty($search)) {
        $searchTerm = $conn->real_escape_string($search);
        $whereClause .= " AND (
            u.USER_FNAME LIKE '%$searchTerm%' OR
            u.USER_LNAME LIKE '%$searchTerm%' OR
            u.USER_EMAIL LIKE '%$searchTerm%' OR
            ur.UR_ROLE LIKE '%$searchTerm%'
        )";
    }

    $countQuery = "SELECT COUNT(*) as total FROM users u JOIN user_role ur ON u.UR_ID = ur.UR_ID $whereClause";
    $query = "SELECT u.*, ur.UR_ID, ur.UR_ROLE FROM users u JOIN user_role ur ON u.UR_ID = ur.UR_ID $whereClause ORDER BY u.USER_LNAME, u.USER_FNAME LIMIT $limit OFFSET $offset";
    $tableName = 'users';
    $idField = 'USER_ID';
    $nameField = "CONCAT(USER_FNAME, ' ', USER_LNAME)";
    $colspan = 4; // Name, Email, Role, Actions
} elseif ($archiveType === 'office') {
    $whereClause = "WHERE OFF_IS_ARCHIVED = 1";
    if (!empty($search)) {
        $searchTerm = $conn->real_escape_string($search);
        $whereClause .= " AND (
            OFF_CODE LIKE '%$searchTerm%' OR
            OFF_NAME LIKE '%$searchTerm%' 
        )";
    }

    $countQuery = "SELECT COUNT(*) as total FROM office $whereClause";
    $query = "SELECT * FROM office $whereClause ORDER BY OFF_CODE LIMIT $limit OFFSET $offset";
    $tableName = 'office';
    $idField = 'OFF_ID';
    $nameField = 'OFF_NAME';
    $colspan = 3;
} else {
    // Item archive
    $whereClause = "WHERE i.ITEM_IS_ARCHIVED = 1";
    if (!empty($search)) {
        $searchTerm = $conn->real_escape_string($search);
        $whereClause .= " AND (
            i.ITEM_CODE LIKE '%$searchTerm%' OR
            i.ITEM_DESC LIKE '%$searchTerm%' OR
            i.ITEM_BARCODE_CASE LIKE '%$searchTerm%' OR
            i.ITEM_BARCODE_PIECE LIKE '%$searchTerm%'
        )";
    }

    $countQuery = "SELECT COUNT(*) as total FROM item i $whereClause";
    $query = "SELECT i.* 
              FROM item i 
              $whereClause 
              ORDER BY i.ITEM_DESC LIMIT $limit OFFSET $offset";
    $tableName = 'item';
    $idField = 'ITEM_ID';
    $nameField = 'ITEM_DESC';
    $colspan = 4; // Item Code, Description, UOM, Actions (removed Vendor column)
}

// Get total count
$totalResult = $conn->query($countQuery);
$totalRow = $totalResult->fetch_assoc();
$totalRecords = $totalRow['total'];
$totalPages = ceil($totalRecords / $limit);

// Get records
$result = $conn->query($query);

function generatePageLinks($totalPages, $page, $archiveType, $limit, $search = '')
{
    $links = [];

    // Build URL parameters
    $urlParams = '';
    if (!empty($search)) {
        $urlParams .= "&search=" . urlencode($search);
    }

    if ($totalPages <= 5) {
        for ($i = 1; $i <= $totalPages; $i++) {
            $links[] = [
                'page' => $i,
                'url' => "?type=$archiveType&page=$i&limit=$limit$urlParams"
            ];
        }
    } else {
        $links = [
            ['page' => 1, 'url' => "?type=$archiveType&page=1&limit=$limit$urlParams"],
            ['page' => 2, 'url' => "?type=$archiveType&page=2&limit=$limit$urlParams"],
            ['page' => 3, 'url' => "?type=$archiveType&page=3&limit=$limit$urlParams"],
            ['page' => "...", 'url' => "#"],
            ['page' => $totalPages - 1, 'url' => "?type=$archiveType&page=" . ($totalPages - 1) . "&limit=$limit$urlParams"],
            ['page' => $totalPages, 'url' => "?type=$archiveType&page=$totalPages&limit=$limit$urlParams"]
        ];

        if ($page >= 3 && $page <= $totalPages - 2) {
            $links = [
                ['page' => 1, 'url' => "?type=$archiveType&page=1&limit=$limit$urlParams"],
                ['page' => "...", 'url' => "#"],
                ['page' => $page - 1, 'url' => "?type=$archiveType&page=" . ($page - 1) . "&limit=$limit$urlParams"],
                ['page' => $page, 'url' => "?type=$archiveType&page=$page&limit=$limit$urlParams"],
                ['page' => $page + 1, 'url' => "?type=$archiveType&page=" . ($page + 1) . "&limit=$limit$urlParams"],
                ['page' => "...", 'url' => "#"],
                ['page' => $totalPages, 'url' => "?type=$archiveType&page=$totalPages&limit=$limit$urlParams"]
            ];
        }
    }

    return $links;
}

$pageLinks = generatePageLinks($totalPages, $page, $archiveType, $limit, $search);

// Build URL parameters for navigation
$urlParams = '';
if (!empty($search)) {
    $urlParams .= "&search=" . urlencode($search);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive Management</title>
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="inventory.css?id=<?= time() ?>">
    <style>
        .status-archived {
            background-color: #fef3c7;
            color: #92400e;
        }

        .tab-active {
            border-bottom: 3px solid #0047bb;
            color: #0047bb;
            font-weight: bold;
        }

        #no-data-row {
            display: none;
        }
    </style>
</head>

<body>
    <div class="content w-[calc(100vw-60px)] mx-auto overflow-x-auto p-8 px-8">
        <div class="bg-white shadow-md rounded-lg p-6 mb-6">
            <h2 class="font-bold mb-4 text-[13px] xs:text-[12px] sm:text-[15px] font-bold text-[#0047bb]">
                Archive Management
            </h2>

            <!-- Archive Type Tabs - Removed Principals and Locations -->
            <div class="flex border-b mb-4">
                <a href="?type=user<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
                    class="px-4 py-2 <?= $archiveType === 'user' ? 'tab-active' : 'text-gray-600' ?>">Users</a>
                <a href="?type=item<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
                    class="px-4 py-2 <?= $archiveType === 'item' ? 'tab-active' : 'text-gray-600' ?>">Items</a>
                <a href="?type=office<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
                    class="px-4 py-2 <?= $archiveType === 'office' ? 'tab-active' : 'text-gray-600' ?>">Office</a>
            </div>

            <div class="flex flex-wrap gap-4">
                <!-- Search Input -->
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%]">
                    <label for="searchInput" class="text-xs font-medium mb-2 invisible">Search</label>
                    <form method="GET" action="" class="relative">
                        <input type="hidden" name="type" value="<?= $archiveType ?>">
                        <input type="hidden" name="limit" value="<?= $limit ?>">
                        <i class="absolute left-4 top-3 bx bx-search text-gray-500 text-sm"></i>
                        <input type="search" name="search" id="searchInput" placeholder="Search archived records"
                            value="<?= htmlspecialchars($search) ?>"
                            class="w-full pl-10 pr-4 py-2 border rounded-full focus:ring focus:ring-blue-300 text-sm text-black" />
                    </form>
                </div>
            </div>

            <?php if (!empty($search)): ?>
                <div class="mt-4 text-sm text-gray-600">
                    Showing results for: "<strong><?= htmlspecialchars($search) ?></strong>"
                    <a href="?type=<?= $archiveType ?>&limit=<?= $limit ?>"
                        class="ml-2 text-blue-500 hover:text-blue-700">Clear search</a>
                </div>
            <?php endif; ?>
        </div>

        <div class="w-full mx-auto pb-15">
            <!-- Table -->
            <?php if ($result && $result->num_rows > 0): ?>
                <div class="table-container overflow-x-auto">
                    <table class="min-w-full table-fixed border-separate border-spacing-0">
                        <thead>
                            <tr>
                                <?php if ($archiveType === 'user'): ?>
                                    <th class="px-4 py-2 text-left">Name</th>
                                    <th class="px-4 py-2 text-left">Email</th>
                                    <th class="px-4 py-2 text-left">Role</th>
                                <?php elseif ($archiveType === 'office'): ?>
                                    <th class="px-4 py-2 text-left">Office Code</th>
                                    <th class="px-4 py-2 text-left">Office Name</th>
                                <?php else: ?>
                                    <th class="px-4 py-2 text-left">Stock No.</th>
                                    <th class="px-4 py-2 text-left">Description</th>
                                    <th class="px-4 py-2 text-left">UOM</th>
                                <?php endif; ?>
                                <th class="px-4 py-2 text-left">Actions</th>
                            </tr>
                        </thead>

                        <tbody id="table-body">
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr class="hover:bg-gray-50 border-b border-gray-200">
                                    <?php if ($archiveType === 'user'): ?>
                                        <td class="px-4 py-2"><?= htmlspecialchars($row['USER_FNAME'] . ' ' . $row['USER_LNAME']) ?>
                                        </td>
                                        <td class="px-4 py-2"><?= htmlspecialchars($row['USER_EMAIL']) ?></td>
                                        <td class="px-4 py-2"><?= htmlspecialchars($row['UR_ROLE']) ?></td>
                                    <?php elseif ($archiveType === 'office'): ?>
                                        <td class="px-4 py-2"><?= htmlspecialchars($row['OFF_CODE']) ?></td>
                                        <td class="px-4 py-2"><?= htmlspecialchars($row['OFF_NAME']) ?></td>
                                    <?php else: ?>
                                        <td class="px-4 py-2"><?= htmlspecialchars($row['ITEM_CODE']) ?></td>
                                        <td class="px-4 py-2 break-words"><?= htmlspecialchars($row['ITEM_DESC']) ?></td>
                                        <td class="px-4 py-2"><?= htmlspecialchars($row['ITEM_UOM']) ?></td>
                                    <?php endif; ?>
                                    <td class="action-column flex justify-left items-center space-x-2 px-4 py-2">
                                        <button onclick="restoreRecord(<?= $row[$idField] ?>, '<?= $tableName ?>')"
                                            class="text-blue-600 hover:text-blue-800">
                                            <i data-lucide="archive-restore"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            <!-- No data row (hidden by default) -->
                            <tr id="no-data-row">
                                <td colspan="<?= $colspan ?>" class="text-center py-4 text-gray-500">No matching records
                                    found</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center text-gray-500 mt-10 text-sm">
                    <?php if (!empty($search)): ?>
                        No archived records found for "<?= htmlspecialchars($search) ?>"
                    <?php else: ?>
                        No archived records found.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Pagination -->
            <?php if ($totalPages > 0): ?>
                <div class="flex justify-center mt-4">
                    <a href="?type=<?= $archiveType ?>&page=<?= max(1, $page - 1) ?>&limit=<?= $limit ?><?= $urlParams ?>"
                        class="px-3 py-1 border rounded <?= ($page == 1 || $totalRecords == 0) ? 'opacity-50 cursor-not-allowed pointer-events-none' : 'hover:bg-gray-200' ?>">
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

                    <a href="?type=<?= $archiveType ?>&page=<?= min($totalPages, $page + 1) ?>&limit=<?= $limit ?><?= $urlParams ?>"
                        class="px-3 py-1 border rounded <?= ($page == $totalPages || $totalRecords == 0) ? 'opacity-50 cursor-not-allowed pointer-events-none' : 'hover:bg-gray-200' ?>">
                        &gt;
                    </a>

                    <!-- Items per page dropdown -->
                    <form method="GET" class="inline">
                        <input type="hidden" name="type" value="<?= $archiveType ?>">
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
                    Results: <?= $offset + 1 ?> - <?= min($offset + $limit, $totalRecords) ?> of <?= $totalRecords ?>
                    archived records
                    <?php if (!empty($search)): ?>
                        for "<?= htmlspecialchars($search) ?>"
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function changeItemsPerPage(limit) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('limit', limit);
            urlParams.set('page', 1);
            window.location.href = '?' + urlParams.toString();
        }

        // Auto-submit search form on Enter key
        document.getElementById('searchInput').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                this.form.submit();
            }
        });

        // Function to prompt for password
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

        // Function to restore a record
        function restoreRecord(id, table) {
            // First - Ask for confirmation
            Swal.fire({
                title: "Are you sure?",
                text: "Are you sure you want to restore this record? It will be moved back to the active list.",
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#d33",
                cancelButtonColor: "#3085d6",
                confirmButtonText: "Yes, restore it!",
                reverseButtons: true,
            }).then((result) => {
                if (result.isConfirmed) {
                    // Second - Ask for password
                    promptPassword().then((password) => {
                        if (!password) return;

                        // Third - Show loading state
                        Swal.fire({
                            title: "Restoring...",
                            text: "Please wait while we restore the recodard",
                            allowOutsideClick: false,
                            didOpen: () => {
                                Swal.showLoading();
                            },
                        });

                        // Fourth - Make API call
                        fetch("archive-php.php", {
                            method: "POST",
                            headers: { "Content-Type": "application/json" },
                            body: JSON.stringify({
                                action: "restore",
                                id: id,
                                table: table,
                                password: password
                            }),
                        })
                            .then((response) => response.json())
                            .then((data) => {
                                if (data.status === "success") {
                                    Swal.fire({
                                        icon: "success",
                                        title: "Restored!",
                                        text: data.message,
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
                                console.error("Error:", error);
                                Swal.fire({
                                    icon: "error",
                                    title: "Request Failed",
                                    text: "An error occurred. Please try again.",
                                });
                            });
                    });
                }
            });
        }
    </script>

</body>

</html>