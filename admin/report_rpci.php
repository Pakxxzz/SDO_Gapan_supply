<?php
// admin/report_rpci.php
include "./sidebar.php";
include "../API/db-connector.php";

// Get Year Filter (Default to current year)
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Only show completed months (months that have ended)
$currentMonth = date('m');
$currentYear = date('Y');

// Query to get monthly physical count snapshots - using 1st day of NEXT month as cut-off
$sql = "SELECT 
            MONTH(b.DATE_SNAPSHOT) as MONTH_NUM,
            MONTHNAME(b.DATE_SNAPSHOT) as MONTH_NAME,
            YEAR(b.DATE_SNAPSHOT) as YEAR_VAL,
            COUNT(DISTINCT b.ITEM_ID) as TOTAL_ITEMS,
            SUM(b.SYSTEM_QUANTITY) as TOTAL_QUANTITY,
            b.DATE_SNAPSHOT as CUTOFF_DATE
        FROM baseline_inventory b
        WHERE YEAR(b.DATE_SNAPSHOT) = $selectedYear
        AND DAY(b.DATE_SNAPSHOT) = 1 -- Only get first day of month snapshots
        AND (
            YEAR(b.DATE_SNAPSHOT) < $currentYear 
            OR (YEAR(b.DATE_SNAPSHOT) = $currentYear AND MONTH(b.DATE_SNAPSHOT) <= $currentMonth)
        )
        GROUP BY MONTH_NUM, YEAR_VAL
        ORDER BY b.DATE_SNAPSHOT DESC";

$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report of Physical Count of Inventories (RPCI)</title>
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="inventory.css?id=<?= time() ?>">
</head>

<body>
    <div class="content w-[calc(100vw-60px)] mx-auto overflow-x-auto p-8 px-8">
        <div class="bg-white shadow-md rounded-lg p-6 mb-6">
            <h2 class="font-bold mb-4 text-[15px] text-[#0047bb] ">
                Report of Physical Count of Inventories (RPCI) - Monthly Cut-off
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
                <div class="text-sm text-gray-600">
                    <i class="bx bx-info-circle"></i> Showing month-end inventories (as of 1st day of next month)
                </div>
            </div>

            <!-- Current Month Indicator -->
            <!-- <div class="mt-4 bg-yellow-50 border-l-4 border-yellow-400 p-3 text-sm">
                <p class="font-medium text-yellow-700"><i class="bx bx-time"></i> Current Period: <?= date('F Y') ?>
            </p>
            <p class="text-yellow-600">Current month data will be available on
                <strong><?= date('F 1, Y', strtotime('+1 month')) ?></strong> (first day of next month).</p>
        </div> -->
    </div>

    <div class="table-container overflow-x-auto bg-white rounded-lg shadow">
        <table class="min-w-full table-auto border-separate border-spacing-0">
            <thead>
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-bold uppercase">Month (Cut-off)</th>
                    <!-- <th class="px-6 py-3 text-left text-xs font-bold uppercase">As of Date</th> -->
                    <th class="px-6 py-3 text-left text-xs font-bold uppercase">Total Items</th>
                    <th class="px-6 py-3 text-left text-xs font-bold uppercase">Total Quantity</th>
                    <th class="px-6 py-3 text-center text-xs font-bold uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <?php
                        $as_of_date = date('F d, Y', strtotime($row['CUTOFF_DATE']));
                        // For display: March cut-off shows as "March 2026 (as of April 1, 2026)"
                        $cutoff_month = date('F', strtotime($row['CUTOFF_DATE'] . ' -1 month'));
                        $cutoff_year = date('Y', strtotime($row['CUTOFF_DATE'] . ' -1 month'));
                        $month_year = $cutoff_month . " " . $cutoff_year;
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900">
                                <?= $month_year ?>
                            </td>
                            <!-- <td class="px-6 py-4 whitespace-nowrap">
                                    <?= $as_of_date ?>
                    </td> -->
                            <td class="px-6 py-4"><?= number_format($row['TOTAL_ITEMS']) ?></td>
                            <td class="px-6 py-4"><?= number_format($row['TOTAL_QUANTITY']) ?></td>
                            <td class="px-6 py-4 text-center space-x-3">
                                <button
                                    onclick="viewPhysicalCount(<?= date('m', strtotime($row['CUTOFF_DATE'] . ' -1 month')) ?>, <?= date('Y', strtotime($row['CUTOFF_DATE'] . ' -1 month')) ?>)"
                                    class="text-blue-600 hover:text-blue-800">
                                    <i class="bx bx-show text-xl"></i>
                                </button>
                                <a href="../API/exportRPCI_Monthly.php?month=<?= date('m', strtotime($row['CUTOFF_DATE'] . ' -1 month')) ?>&year=<?= date('Y', strtotime($row['CUTOFF_DATE'] . ' -1 month')) ?>"
                                    class="text-purple-600 hover:text-purple-800">
                                    <i class="bx bx-file text-xl"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="px-4 py-4 text-center text-gray-500">
                            No completed physical count data available for the selected year.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Note Section -->
    <!-- <div class="mt-6 bg-blue-50 border-l-4 border-blue-400 p-4 text-sm text-gray-700">
            <p class="font-medium text-blue-700"><i class="bx bx-note mr-1"></i> About RPCI:</p>
            <p>This report shows month-end physical counts using snapshots taken on the <strong>first day of the following month</strong>.</p>
            <p class="mt-1">Example: March 2026 cut-off uses the snapshot from <strong>April 1, 2026</strong>.</p>
        </div> -->
    </div>

    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function viewPhysicalCount(month, year) {
            window.location.href = `physical_count_view.php?month=${month}&year=${year}`;
        }
    </script>
</body>

</html>