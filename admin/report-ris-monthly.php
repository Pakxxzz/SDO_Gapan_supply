<?php
// admin/report_ris_monthly.php
include "./sidebar.php";
include "../API/db-connector.php";

// Get Year Filter (Default to current year)
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Query to get monthly summary
$sql = "SELECT 
            MONTH(stock_out.CREATED_AT) as MONTH_NUM,
            MONTHNAME(stock_out.CREATED_AT) as MONTH_NAME,
            YEAR(stock_out.CREATED_AT) as YEAR_VAL,
            COUNT(DISTINCT stock_out.SO_RIS_NO) as TOTAL_RIS,
            SUM(stock_out.SO_QUANTITY) as TOTAL_QTY,
            COUNT(stock_out.SO_ID) as TOTAL_ITEMS
        FROM stock_out
        WHERE YEAR(stock_out.CREATED_AT) = $selectedYear
        GROUP BY MONTH_NUM
        ORDER BY MONTH_NUM DESC";

$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Report of Supplies and Materials Issued</title>
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="inventory.css?id=<?= time() ?>">
</head>

<body>
    <div class="content w-[calc(100vw-60px)] mx-auto overflow-x-auto p-8 px-8">
        <div class="bg-white shadow-md rounded-lg p-6 mb-6">
            <h2 class="font-bold mb-4 text-[15px] text-[#0047bb] ">
                Report of Supplies and Materials Issued (Monthly)
            </h2>

            <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                <form method="GET" class="flex items-center gap-2">
                    <label class="text-sm font-medium">Select Year:</label>
                    <select name="year" onchange="this.form.submit()"
                        class="border rounded px-4 py-2 text-sm focus:ring-blue-300">
                        <?php
                        for ($i = date('Y'); $i >= 2026; $i--) {
                            echo "<option value='$i' " . ($selectedYear == $i ? 'selected' : '') . ">$i</option>";
                        }
                        ?>
                    </select>
                </form>
            </div>
        </div>

        <div class="table-container overflow-x-auto bg-white rounded-lg shadow">
            <table class="min-w-full table-auto border-separate border-spacing-0">
                <thead>
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-bold uppercase">Month</th>
                        <th class="px-6 py-3 text-left text-xs font-bold uppercase">Total RIS Slips</th>
                        <th class="px-6 py-3 text-left text-xs font-bold uppercase">Items Issued</th>
                        <th class="px-6 py-3 text-left text-xs font-bold uppercase">Total Quantity</th>
                        <th class="px-6 py-3 text-center text-xs font-bold uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900">
                                    <?= $row['MONTH_NAME'] . " " . $row['YEAR_VAL'] ?>
                                </td>
                                <td class="px-6 py-4"><?= $row['TOTAL_RIS'] ?></td>
                                <td class="px-6 py-4"><?= $row['TOTAL_ITEMS'] ?></td>
                                <td class="px-6 py-4"><?= number_format($row['TOTAL_QTY']) ?></td>
                                <td class="px-6 py-4 text-center space-x-3">
                                    <button onclick="viewMonthlyDetail(<?= $row['MONTH_NUM'] ?>, <?= $row['YEAR_VAL'] ?>)"
                                        class="text-blue-600 hover:text-blue-800">
                                        <i class="bx bx-show text-xl"></i>
                                    </button>
                                    <a href="../API/exportRIS_Monthly.php?month=<?= $row['MONTH_NUM'] ?>&year=<?= $row['YEAR_VAL'] ?>"
                                        class="text-purple-600 hover:text-purple-800">
                                        <i class="bx bx-file text-xl"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="px-4 py-4 text-center text-gray-500">No data available for the selected
                                year.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function viewMonthlyDetail(month, year) {
            // 1. Format the first day of the month
            const firstDay = `${year}-${String(month).padStart(2, '0')}-01`;

            // 2. Get the last day of the month
            // Setting day to 0 in the Date constructor gets the last day of the PREVIOUS month.
            // Since JS months are 0-indexed, passing 'month' directly here works correctly.
            const lastDateObj = new Date(year, month, 0);

            // 3. Manually extract Year, Month, and Day to avoid UTC timezone shifts
            const y = lastDateObj.getFullYear();
            const m = String(lastDateObj.getMonth() + 1).padStart(2, '0');
            const d = String(lastDateObj.getDate()).padStart(2, '0');

            const lastDay = `${y}-${m}-${d}`;

            window.location.href = `stockOut.php?dateFrom=${firstDay}&dateTo=${lastDay}`;
        }
    </script>
</body>

</html>