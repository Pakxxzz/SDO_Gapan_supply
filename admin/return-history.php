// admin/return-history.php (optional - for viewing return records)
<?php
include "./sidebar.php";
include "../API/db-connector.php";

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$dateFrom = isset($_GET['dateFrom']) ? $_GET['dateFrom'] : date('Y-m-d');
$dateTo = isset($_GET['dateTo']) ? $_GET['dateTo'] : null;

$whereClause = "WHERE 1=1";

if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $whereClause .= " AND (
        item_return.RETURN_NO LIKE '%$searchTerm%' OR
        item_return.RIS_NO LIKE '%$searchTerm%' OR
        item.ITEM_CODE LIKE '%$searchTerm%' OR
        item.ITEM_DESC LIKE '%$searchTerm%' OR
        item_return.RETURN_REASON LIKE '%$searchTerm%'
    )";
}

if ($dateFrom) {
    $dateFromEscaped = $conn->real_escape_string($dateFrom);
    $whereClause .= " AND DATE(item_return.RETURN_DATE) >= '$dateFromEscaped'";
}

if ($dateTo) {
    $dateToEscaped = $conn->real_escape_string($dateTo);
    $whereClause .= " AND DATE(item_return.RETURN_DATE) <= '$dateToEscaped'";
}

$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$totalQuery = "SELECT COUNT(*) as total FROM item_return $whereClause";
$totalResult = $conn->query($totalQuery);
$totalRow = $totalResult->fetch_assoc();
$totalItem = $totalRow['total'];
$totalPages = ceil($totalItem / $limit);

$sql = "SELECT 
            item_return.*,
            item.ITEM_CODE,
            item.ITEM_DESC,
            item.ITEM_UNIT,
            CONCAT(users.USER_FNAME, ' ', users.USER_LNAME) as returned_by_name
        FROM item_return
        JOIN item ON item_return.ITEM_ID = item.ITEM_ID
        JOIN users ON item_return.RETURNED_BY = users.USER_ID
        $whereClause
        ORDER BY item_return.RETURN_DATE DESC
        LIMIT $limit OFFSET $offset";

$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return History</title>
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body>
    <div class="content w-[calc(100vw-60px)] mx-auto overflow-x-auto p-8 px-8">
        <div class="bg-white shadow-md rounded-lg p-6 mb-6">
            <h2 class="font-bold mb-4 text-[#0047bb]">Item Return History</h2>
            
            <!-- Search and Filter (similar to stockOut.php) -->
            <div class="flex flex-wrap gap-4 mt-6 mb-6">
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%]">
                    <input type="text" id="searchInput" placeholder="Search returns..." 
                           value="<?= htmlspecialchars($search) ?>"
                           class="w-full border rounded px-4 py-2.5 text-sm">
                </div>
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%]">
                    <input type="date" id="dateFrom" value="<?= htmlspecialchars($dateFrom) ?>"
                           class="w-full border rounded px-4 py-2.5 text-sm">
                </div>
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%]">
                    <input type="date" id="dateTo" value="<?= htmlspecialchars($dateTo) ?>"
                           class="w-full border rounded px-4 py-2.5 text-sm">
                </div>
                <div class="w-full sm:w-[48%] md:w-[31%] lg:w-[23%] flex gap-2">
                    <button onclick="applyFilters()" 
                            class="bg-green-500 text-white px-4 py-2 rounded-md hover:bg-green-600 w-1/2">
                        Filter
                    </button>
                    <button onclick="clearFilters()" 
                            class="bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600 w-1/2">
                        Clear
                    </button>
                </div>
            </div>
        </div>

        <!-- Returns Table -->
        <div class="table-container overflow-x-auto">
            <table class="min-w-full table-auto">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left">Return No.</th>
                        <th class="px-4 py-2 text-left">RIS No.</th>
                        <th class="px-4 py-2 text-left">Item</th>
                        <th class="px-4 py-2 text-left">Quantity</th>
                        <th class="px-4 py-2 text-left">Reason</th>
                        <th class="px-4 py-2 text-left">Returned By</th>
                        <th class="px-4 py-2 text-left">Return Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr class="hover:bg-gray-200 border-b border-gray-300">
                                <td class="px-4 py-2"><?= htmlspecialchars($row['RETURN_NO']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($row['RIS_NO']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($row['ITEM_CODE'] . ' - ' . $row['ITEM_DESC']) ?></td>
                                <td class="px-4 py-2"><?= $row['RETURN_QUANTITY'] ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($row['RETURN_REASON']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($row['returned_by_name']) ?></td>
                                <td class="px-4 py-2"><?= date('M j, Y g:i a', strtotime($row['RETURN_DATE'])) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-4">No return records found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination (similar to stockOut.php) -->
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function applyFilters() {
            const search = document.getElementById('searchInput').value;
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;
            const limit = <?= $limit ?>;

            let url = `?page=1&limit=${limit}`;
            if (search) url += `&search=${encodeURIComponent(search)}`;
            if (dateFrom) url += `&dateFrom=${encodeURIComponent(dateFrom)}`;
            if (dateTo) url += `&dateTo=${encodeURIComponent(dateTo)}`;

            window.location.href = url;
        }

        function clearFilters() {
            const limit = <?= $limit ?>;
            window.location.href = `?page=1&limit=${limit}`;
        }

        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') applyFilters();
        });
    </script>
</body>
</html>