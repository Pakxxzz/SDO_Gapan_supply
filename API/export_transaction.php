<?php
// admin/export_transaction.php
require '../vendor/autoload.php';
include "../API/db-connector.php";

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Get parameters - removed principal filter
$selectedItem = isset($_GET['item']) ? intval($_GET['item']) : 0;
$fromDate = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$toDate = isset($_GET['to_date']) ? $_GET['to_date'] : '';

// Validate
if ($selectedItem <= 0) {
    die("No item selected for export.");
}
if (empty($fromDate) || empty($toDate)) {
    die("Date range is required for export.");
}

// Normalize date order
if (strtotime($fromDate) > strtotime($toDate)) {
    $temp = $fromDate;
    $fromDate = $toDate;
    $toDate = $temp;
}

// Get item details
$itemQuery = "SELECT ITEM_ID, ITEM_CODE, ITEM_DESC 
              FROM item 
              WHERE ITEM_ID = $selectedItem";
$itemResult = $conn->query($itemQuery);
$itemDetails = $itemResult->fetch_assoc();

if (!$itemDetails) {
    die("Item not found.");
}

// Generate date range
$dateRange = [];
$current = strtotime($fromDate);
$end = strtotime($toDate);

while ($current <= $end) {
    $dateRange[] = date('Y-m-d', $current);
    $current = strtotime('+1 day', $current);
}

// Get all stock movements for this item in date range
$movements = [];

// Get stock in transactions
$stockInQuery = "SELECT 
                    DATE(CREATED_AT) as trans_date,
                    'STOCK_IN' as trans_type,
                    SI_QUANTITY as quantity,
                    SI_REMARKS as remarks
                 FROM stock_in 
                 WHERE ITEM_ID = $selectedItem 
                 AND DATE(CREATED_AT) BETWEEN '$fromDate' AND '$toDate'";

$stockInResult = $conn->query($stockInQuery);
while ($row = $stockInResult->fetch_assoc()) {
    $movements[] = $row;
}

// Get stock out transactions
$stockOutQuery = "SELECT 
                    DATE(CREATED_AT) as trans_date,
                    'STOCK_OUT' as trans_type,
                    SO_QUANTITY as quantity,
                    SO_REMARKS as remarks
                 FROM stock_out 
                 WHERE ITEM_ID = $selectedItem 
                 AND DATE(CREATED_AT) BETWEEN '$fromDate' AND '$toDate'";

$stockOutResult = $conn->query($stockOutQuery);
while ($row = $stockOutResult->fetch_assoc()) {
    $movements[] = $row;
}

// Get alignment adjustments from masterdata
$alignmentQuery = "SELECT 
                    DATE(CREATED_AT) as trans_date,
                    'ALIGNMENT' as trans_type,
                    DIFFERENCE as quantity,
                    CONCAT('Alignment Batch: ', BATCH) as remarks
                 FROM masterdata 
                 WHERE ITEM_ID = $selectedItem 
                 AND DATE(CREATED_AT) BETWEEN '$fromDate' AND '$toDate'
                 AND STATUS = 'Completed'";

$alignmentResult = $conn->query($alignmentQuery);
while ($row = $alignmentResult->fetch_assoc()) {
    $movements[] = $row;
}

// Get initial inventory from baseline or current inventory
$baselineQuery = "SELECT SYSTEM_QUANTITY, DATE_SNAPSHOT 
                 FROM baseline_inventory 
                 WHERE ITEM_ID = $selectedItem 
                 AND DATE_SNAPSHOT <= '$fromDate'
                 ORDER BY DATE_SNAPSHOT DESC 
                 LIMIT 1";
$baselineResult = $conn->query($baselineQuery);

$runningInventory = 0;
$baselineDate = null;

if ($baselineResult->num_rows > 0) {
    $baseline = $baselineResult->fetch_assoc();
    $runningInventory = (int) $baseline['SYSTEM_QUANTITY'];
    $baselineDate = $baseline['DATE_SNAPSHOT'];
    
    // Add all transactions from baselineDate+1 to fromDate-1
    if ($baselineDate < $fromDate) {
        $catchupQuery = "SELECT 
                            DATE(CREATED_AT) as trans_date,
                            CASE 
                                WHEN SI_ID IS NOT NULL THEN 'STOCK_IN'
                                WHEN SO_ID IS NOT NULL THEN 'STOCK_OUT'
                                WHEN MD_ID IS NOT NULL THEN 'ALIGNMENT'
                            END as trans_type,
                            COALESCE(SI_QUANTITY, SO_QUANTITY, DIFFERENCE) as quantity
                        FROM (
                            SELECT 
                                CREATED_AT,
                                SI_ID,
                                NULL as SO_ID,
                                NULL as MD_ID,
                                SI_QUANTITY,
                                NULL as SO_QUANTITY,
                                NULL as DIFFERENCE
                            FROM stock_in 
                            WHERE ITEM_ID = $selectedItem 
                            AND DATE(CREATED_AT) > '$baselineDate' 
                            AND DATE(CREATED_AT) < '$fromDate'
                            UNION ALL
                            SELECT 
                                CREATED_AT,
                                NULL as SI_ID,
                                SO_ID,
                                NULL as MD_ID,
                                NULL as SI_QUANTITY,
                                SO_QUANTITY,
                                NULL as DIFFERENCE
                            FROM stock_out 
                            WHERE ITEM_ID = $selectedItem 
                            AND DATE(CREATED_AT) > '$baselineDate' 
                            AND DATE(CREATED_AT) < '$fromDate'
                            UNION ALL
                            SELECT 
                                CREATED_AT,
                                NULL as SI_ID,
                                NULL as SO_ID,
                                MD_ID,
                                NULL as SI_QUANTITY,
                                NULL as SO_QUANTITY,
                                DIFFERENCE
                            FROM masterdata 
                            WHERE ITEM_ID = $selectedItem 
                            AND DATE(CREATED_AT) > '$baselineDate' 
                            AND DATE(CREATED_AT) < '$fromDate'
                            AND STATUS = 'Completed'
                        ) as movements
                        ORDER BY CREATED_AT";
        
        $catchupResult = $conn->query($catchupQuery);
        while ($trans = $catchupResult->fetch_assoc()) {
            if ($trans['trans_type'] == 'STOCK_IN') {
                $runningInventory += (int) $trans['quantity'];
            } elseif ($trans['trans_type'] == 'STOCK_OUT') {
                $runningInventory -= (int) $trans['quantity'];
            } else {
                $runningInventory += (int) $trans['quantity'];
            }
        }
    }
} else {
    // No baseline, get current inventory and work backwards
    $currentInvQuery = "SELECT INV_QUANTITY_PIECE 
                       FROM inventory 
                       WHERE ITEM_ID = $selectedItem";
    $currentInvResult = $conn->query($currentInvQuery);
    if ($currentInvResult->num_rows > 0) {
        $current = $currentInvResult->fetch_assoc();
        $runningInventory = (int) $current['INV_QUANTITY_PIECE'];
        
        // Subtract all movements up to toDate to get fromDate inventory
        $reverseQuery = "SELECT 
                            DATE(CREATED_AT) as trans_date,
                            CASE 
                                WHEN SI_ID IS NOT NULL THEN 'STOCK_IN'
                                WHEN SO_ID IS NOT NULL THEN 'STOCK_OUT'
                                WHEN MD_ID IS NOT NULL THEN 'ALIGNMENT'
                            END as trans_type,
                            COALESCE(SI_QUANTITY, SO_QUANTITY, DIFFERENCE) as quantity
                        FROM (
                            SELECT 
                                CREATED_AT,
                                SI_ID,
                                NULL as SO_ID,
                                NULL as MD_ID,
                                SI_QUANTITY,
                                NULL as SO_QUANTITY,
                                NULL as DIFFERENCE
                            FROM stock_in 
                            WHERE ITEM_ID = $selectedItem 
                            AND DATE(CREATED_AT) <= '$toDate'
                            UNION ALL
                            SELECT 
                                CREATED_AT,
                                NULL as SI_ID,
                                SO_ID,
                                NULL as MD_ID,
                                NULL as SI_QUANTITY,
                                SO_QUANTITY,
                                NULL as DIFFERENCE
                            FROM stock_out 
                            WHERE ITEM_ID = $selectedItem 
                            AND DATE(CREATED_AT) <= '$toDate'
                            UNION ALL
                            SELECT 
                                CREATED_AT,
                                NULL as SI_ID,
                                NULL as SO_ID,
                                MD_ID,
                                NULL as SI_QUANTITY,
                                NULL as SO_QUANTITY,
                                DIFFERENCE
                            FROM masterdata 
                            WHERE ITEM_ID = $selectedItem 
                            AND DATE(CREATED_AT) <= '$toDate'
                            AND STATUS = 'Completed'
                        ) as movements
                        ORDER BY CREATED_AT DESC";
        
        $reverseResult = $conn->query($reverseQuery);
        while ($trans = $reverseResult->fetch_assoc()) {
            if ($trans['trans_type'] == 'STOCK_IN') {
                $runningInventory -= (int) $trans['quantity'];
            } elseif ($trans['trans_type'] == 'STOCK_OUT') {
                $runningInventory += (int) $trans['quantity'];
            } else {
                $runningInventory -= (int) $trans['quantity'];
            }
        }
    }
}

// Build daily data
$dailyData = [];
foreach ($dateRange as $date) {
    $beginningInventory = $runningInventory;
    
    // Get day's transactions
    $dayReceived = 0;
    $dayDispatched = 0;
    $dayAlignment = 0;
    
    foreach ($movements as $movement) {
        if ($movement['trans_date'] == $date) {
            if ($movement['trans_type'] == 'STOCK_IN') {
                $dayReceived += (int) $movement['quantity'];
            } elseif ($movement['trans_type'] == 'STOCK_OUT') {
                $dayDispatched += (int) $movement['quantity'];
            } else {
                $dayAlignment += (int) $movement['quantity'];
            }
        }
    }
    
    // Calculate ending inventory
    $endingInventory = $beginningInventory + $dayReceived - $dayDispatched + $dayAlignment;
    
    // Add to daily data
    $dailyData[] = [
        'date' => $date,
        'item_code' => $itemDetails['ITEM_CODE'],
        'item_desc' => $itemDetails['ITEM_DESC'],
        'beginning' => $beginningInventory,
        'total_received' => $dayReceived,
        'total_dispatched' => $dayDispatched,
        'total_alignment' => $dayAlignment,
        'ending' => $endingInventory
    ];
    
    // Update running inventory for next day
    $runningInventory = $endingInventory;
}

// Create Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set title
$title = "Daily Inventory Transaction Report";
$sheet->setCellValue('A1', $title);
$sheet->mergeCells('A1:H1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Subtitle - removed principal
$sheet->setCellValue('A2', 'Item: ' . $itemDetails['ITEM_CODE'] . ' - ' . $itemDetails['ITEM_DESC']);
$sheet->setCellValue('A3', 'Date Range: ' . date('M d, Y', strtotime($fromDate)) . ' to ' . date('M d, Y', strtotime($toDate)));
$sheet->setCellValue('A4', 'Generated on: ' . date('Y-m-d H:i:s'));

// Headers - removed principal column
$headers = ["Date", "Item Code", "Description", "Beginning", "Stock In", "Stock Out", "Adjustment", "Ending"];
$sheet->fromArray($headers, NULL, 'A6');

// Style header
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0047BB']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
];
$sheet->getStyle('A6:H6')->applyFromArray($headerStyle);

// Data rows
$rowNum = 7;
foreach ($dailyData as $data) {
    $sheet->setCellValue("A$rowNum", date('M d, Y', strtotime($data['date'])));
    $sheet->setCellValue("B$rowNum", $data['item_code']);
    $sheet->setCellValue("C$rowNum", $data['item_desc']);
    $sheet->setCellValue("D$rowNum", $data['beginning']);
    $sheet->setCellValue("E$rowNum", $data['total_received']);
    $sheet->setCellValue("F$rowNum", $data['total_dispatched']);
    
    // Format adjustment with sign
    if ($data['total_alignment'] != 0) {
        $sheet->setCellValue("G$rowNum", ($data['total_alignment'] > 0 ? '+' : '') . $data['total_alignment']);
    } else {
        $sheet->setCellValue("G$rowNum", 0);
    }
    
    $sheet->setCellValue("H$rowNum", $data['ending']);
    $rowNum++;
}

// Apply conditional formatting for adjustment column
$adjustmentColumn = 'G';
for ($i = 7; $i < $rowNum; $i++) {
    $cellValue = $sheet->getCell($adjustmentColumn . $i)->getValue();
    if (is_numeric($cellValue)) {
        if ($cellValue > 0) {
            $sheet->getStyle($adjustmentColumn . $i)->getFont()->getColor()->setARGB('FF059669');
        } elseif ($cellValue < 0) {
            $sheet->getStyle($adjustmentColumn . $i)->getFont()->getColor()->setARGB('FFDC2626');
        }
    }
}

// Auto-size columns
foreach (range('A', 'H') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Borders
$borderStyle = [
    'borders' => [
        'allBorders' => ['borderStyle' => Border::BORDER_THIN]
    ]
];
$sheet->getStyle('A6:H' . ($rowNum - 1))->applyFromArray($borderStyle);

// Add summary statistics
if (!empty($dailyData)) {
    $summaryRow = $rowNum + 2;
    
    $sheet->setCellValue("A$summaryRow", "Summary Statistics");
    $sheet->getStyle("A$summaryRow")->getFont()->setBold(true)->setSize(12);
    $summaryRow++;
    
    $summaryData = [
        ["Total Days", count($dailyData)],
        ["Total Stock In", array_sum(array_column($dailyData, 'total_received'))],
        ["Total Stock Out", array_sum(array_column($dailyData, 'total_dispatched'))],
        ["Total Adjustment", array_sum(array_column($dailyData, 'total_alignment'))],
    ];
    
    foreach ($summaryData as $summaryRowData) {
        $sheet->setCellValue("A$summaryRow", $summaryRowData[0] . ":");
        $sheet->setCellValue("B$summaryRow", $summaryRowData[1]);
        $summaryRow++;
    }
}

// Filename
$fileName = "Daily_Inventory_Transaction_" . $itemDetails['ITEM_CODE'] . "_" . date('Ymd_His') . ".xlsx";

// Output
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename=\"$fileName\"");
header('Cache-Control: max-age=0');
header('Pragma: no-cache');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;