<?php
// admin/physical_count_view.php
include "./sidebar.php";
include "../API/db-connector.php";

$month = isset($_GET['month']) ? intval($_GET['month']) : date('m') - 1; // Default to previous month
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Calculate the snapshot date (first day of next month)
$snapshot_month = $month + 1;
$snapshot_year = $year;
if ($snapshot_month > 12) {
    $snapshot_month = 1;
    $snapshot_year = $year + 1;
}
$snapshot_date = sprintf("%04d-%02d-01", $snapshot_year, $snapshot_month);

// Only allow viewing if snapshot month is not in the future
$currentYear = date('Y');
$currentMonth = date('m');

if ($snapshot_year > $currentYear || ($snapshot_year == $currentYear && $snapshot_month > $currentMonth)) {
    echo "<script>alert('Physical count data for this month is not available yet. It will be available on " . date('F 1, Y', strtotime('+1 month')) . ".'); window.location.href='report_rpci.php';</script>";
    exit;
}

$monthName = date('F', mktime(0, 0, 0, $month, 10));
$snapshot_display = date('F d, Y', strtotime($snapshot_date));

// Fetch physical count data using the snapshot date (first day of next month)
// ONLY item details and quantity
$sql = "SELECT 
            i.ITEM_CODE,
            i.ITEM_DESC,
            i.ITEM_UNIT,
            b.SYSTEM_QUANTITY as PHYSICAL_COUNT
        FROM baseline_inventory b
        JOIN item i ON b.ITEM_ID = i.ITEM_ID
        WHERE b.DATE_SNAPSHOT = ?
        ORDER BY i.ITEM_CODE ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $snapshot_date);
$stmt->execute();
$result = $stmt->get_result();

// Get total counts
$total_items = $result->num_rows;
$total_quantity = 0;

// Store data for display
$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
    $total_quantity += $row['PHYSICAL_COUNT'];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Physical Count Details - <?= $monthName ?>
<?= $year ?>
</title>
<link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="inventory.css?id=<?= time() ?>">
</head>

<body>
    <div class=" content w-[calc(100vw-60px)] mx-auto overflow-x-auto p-8 px-8">
<!-- Breadcrumb -->
<div class="mb-4 text-sm">
    <a href="report_rpci.php" class="text-blue-600 hover:underline">RPCI Reports</a>
    <i class="bx bx-chevron-right"></i>
    <span class="text-gray-600">Physical Count:
        <?= $monthName ?>
        <?= $year ?>
    </span>
</div>

<!-- Header Card -->
<div class="bg-white shadow-md rounded-lg p-6 mb-6">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
        <div>
            <h2 class="font-bold text-xl text-[#0047bb] mb-2">
                Physical Count of Inventories
            </h2>
            <p class="text-gray-600">
                <i class="bx bx-calendar"></i> Month: <span class="font-semibold">
                    <?= $monthName ?>
                    <?= $year ?>
                </span>
            </p>
            <!-- <p class="text-gray-600 mt-1">
                        <i class="bx bx-calendar-check"></i> Snapshot Date: <span class="font-semibold"><?= $snapshot_display ?></span>
                        <span class="text-xs bg-blue-100 text-blue-800 ml-2 px-2 py-1 rounded">First day of next month</span>
                    </p> -->
        </div>
        <div class="mt-4 md:mt-0 flex gap-3">
            <a href="../API/exportRPCI_Monthly.php?month=<?= $month ?>&year=<?= $year ?>"
                class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg text-sm flex items-center gap-2">
                <i class="bx bx-file"></i> Export to Excel
            </a>
            <a href="report_rpci.php"
                class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm flex items-center gap-2">
                <i class="bx bx-arrow-back"></i> Back
            </a>
        </div>
    </div>

    <!-- Summary Cards - Simplified -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
        <div class="bg-blue-50 p-4 rounded-lg">
            <p class="text-sm text-blue-600 font-medium">Total Items Counted</p>
            <p class="text-2xl font-bold text-blue-800">
                <?= number_format($total_items) ?>
            </p>
        </div>
        <div class="bg-green-50 p-4 rounded-lg">
            <p class="text-sm text-green-600 font-medium">Total Quantity</p>
            <p class="text-2xl font-bold text-green-800">
                <?= number_format($total_quantity) ?>
            </p>
        </div>
    </div>
</div>

<!-- Items Table - ONLY Item Details and Quantity -->
<div class="bg-white shadow-md rounded-lg overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead>
                <tr>
                    <th class="px-6 py-3">Stock No.</th>
                    <th class="px-6 py-3">Item Description</th>
                    <th class="px-6 py-3">Unit</th>
                    <th class="px-6 py-3 text-right">Quantity</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (count($items) > 0): ?>
                    <?php foreach ($items as $item): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-mono">
                                <?= htmlspecialchars($item['ITEM_CODE']) ?>
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <?= htmlspecialchars($item['ITEM_DESC']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm"><?= htmlspecialchars($item['ITEM_UNIT']) ?></td>
                                            <td class=" px-6 py-4 whitespace-nowrap text-sm text-right font-medium">
                                <?= number_format($item['PHYSICAL_COUNT']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <!-- Totals Row -->
                    <tr class="bg-gray-100 font-semibold">
                        <td colspan="3" class="px- py-4 text-sm text-right">TOTAL:</td>
                        <td class="px-6 py-4 text-sm text-right">
                            <?= number_format($total_quantity) ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="px-6 py-12 text-center text-gray-500">
                            <i class="bx bx-data text-4xl mb-2 block"></i>
                            No physical count data found for
                            <?= $monthName ?>
                            <?= $year ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Footer Note -->
        <div class="mt-4 text-xs text-gray-500 text-right">
            Generated on: <?= date('F d, Y h:i A') ?>
        </div>
    </div>
        <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>

</html>