<?php
include "./sidebar.php";
session_regenerate_id(true);
include "../API/db-connector.php";

$role = $_SESSION['role'];

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$whereClause = '';

if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $whereClause = "WHERE 
        USER_NAME LIKE '%$searchTerm%' OR
        USER_ROLE LIKE '%$searchTerm%' OR
        ACTION_TYPE LIKE '%$searchTerm%' OR
        MODULE LIKE '%$searchTerm%' OR
        RECORD_NAME LIKE '%$searchTerm%' OR
        IP_ADDRESS LIKE '%$searchTerm%' OR
        USER_AGENT LIKE '%$searchTerm%' OR
        DETAILS LIKE '%$searchTerm%' OR
        CREATED_AT LIKE '%$searchTerm%'";
}

// Pagination settings
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10; // Default 10 per page
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Get total number of users with search filter
$totalQuery = "SELECT COUNT(*) AS total FROM user_activity_log $whereClause";
$totalResult = $conn->query($totalQuery);
$totalRow = $totalResult->fetch_assoc();
$totalUserAct = $totalRow['total'];

$totalPages = ceil($totalUserAct / $limit);

// Build the main query with search and pagination
$sql = "SELECT * FROM user_activity_log $whereClause ORDER BY ID DESC LIMIT $limit OFFSET $offset";
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
            <h2 class="font-bold mb-4 text-[13px] xs:text-[12px] sm:text-[15px] font-bold text-[#0047bb]">User Activity History</h2>
            <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                <form method="GET" action="" class="relative w-full sm:w-64">
                    <i class="absolute left-4 top-3 bx bx-search text-gray-500"></i>
                    <input type="search" 
                           name="search" 
                           id="searchInput" 
                           placeholder="Search"
                           value="<?= htmlspecialchars($search) ?>"
                           class="w-full pl-10 pr-4 py-2 border rounded-full focus:ring focus:ring-blue-300">
                    <!-- Keep limit in hidden field to maintain it during search -->
                    <input type="hidden" name="limit" value="<?= $limit ?>">
                </form>
            </div>
            <?php if (!empty($search)): ?>
                <div class="mt-2 text-sm text-gray-600">
                    Showing results for: "<strong><?= htmlspecialchars($search) ?></strong>"
                    <a href="?" class="ml-2 text-blue-500 hover:text-blue-700">Clear search</a>
                </div>
            <?php endif; ?>
        </div>

        <div class="table-container overflow-x-auto">
            <table class="min-w-full table-fixed border-separate border-spacing-0">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left">User Name</th>
                        <th class="px-4 py-2 text-left">User Role</th>
                        <th class="px-4 py-2 text-left">Activity</th>
                        <th class="px-4 py-2 text-left">Module</th>
                        <th class="px-4 py-2 text-left">Record Name</th>
                        <th class="px-4 py-2 text-left">Remarks</th>
                        <th class="px-4 py-2 text-left">IP Address</th>
                        <th class="px-4 py-2 text-left">User Agent</th>
                        <th class="px-4 py-2 text-left">Date</th>
                    </tr>
                </thead>
                <tbody id="table-body">
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr class="hover:bg-gray-200 border-b border-gray-300">
                                <td class="px-4 py-2"><?= htmlspecialchars($row['USER_NAME']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($row['USER_ROLE']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($row['ACTION_TYPE']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($row['MODULE']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($row['RECORD_NAME']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($row['DETAILS']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($row['IP_ADDRESS']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($row['USER_AGENT']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars(date('M j, Y \a\t g:i a', strtotime($row['CREATED_AT']))); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr id="no-data-row">
                            <td colspan="20" class="text-center py-2 border-b border-gray-300">
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
                class="px-3 py-1 border rounded <?= ($page == 1 || $totalUserAct == 0) ? 'opacity-50 cursor-not-allowed pointer-events-none' : 'hover:bg-gray-200' ?>">
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
                class="px-3 py-1 border rounded <?= ($page == $totalPages || $totalUserAct == 0) ? 'opacity-50 cursor-not-allowed pointer-events-none' : 'hover:bg-gray-200' ?>">
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
            Results: <?= $offset + 1 ?> - <?= min($offset + $limit, $totalUserAct) ?> of <?= $totalUserAct ?>
            <?php if (!empty($search)): ?>
                for "<?= htmlspecialchars($search) ?>"
            <?php endif; ?>
            data
        </p>
        <?php endif; ?>
    </div>
</body>

<script src="https://unpkg.com/lucide@latest"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    lucide.createIcons();
    
    // Auto-submit form on Enter key in search
    document.getElementById('searchInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            this.form.submit();
        }
    });
</script>
</html>