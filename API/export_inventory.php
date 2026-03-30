<?php
// API/export_inventory.php

require '../vendor/autoload.php';
include "../API/db-connector.php";

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// Get parameters
$fromMonth = $_GET['from_month'] ?? '';
$toMonth = $_GET['to_month'] ?? '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Process month range
if (empty($fromMonth) || empty($toMonth)) {
    $currentDate = date('Y-m-d');
    $fromDate = date('Y-m-01', strtotime($currentDate));
    $toDate = date('Y-m-t', strtotime($currentDate));
} else {
    $fromDate = date('Y-m-01', strtotime($fromMonth . '-01'));
    $toDate = date('Y-m-t', strtotime($toMonth . '-01'));
    
    if (strtotime($fromDate) > strtotime($toDate)) {
        $tmp = $fromDate;
        $fromDate = $toDate;
        $toDate = $tmp;
    }
}

// Function to fetch ALL data for export (no pagination)
function fetchAllInventoryForExport($conn, $fromDate, $toDate, $search = '')
{
    $searchCondition = "";
    if (!empty($search)) {
        $searchTerm = $conn->real_escape_string($search);
        $searchCondition = " AND (
            i.ITEM_CODE LIKE '%$searchTerm%' OR 
            i.ITEM_DESC LIKE '%$searchTerm%'
        )";
    }

    $query = "
        SELECT 
            i.ITEM_ID, 
            i.ITEM_CODE, 
            i.ITEM_DESC, 
            i.ITEM_UNIT,
            i.ITEM_UOM,
            IFNULL(inv.INV_QUANTITY_PIECE, 0) as current_inventory,
            (
                SELECT IFNULL(SUM(si.SI_QUANTITY), 0)
                FROM stock_in si
                WHERE si.ITEM_ID = i.ITEM_ID 
                  AND DATE(si.CREATED_AT) BETWEEN ? AND ?
            ) as total_received,
            (
                SELECT IFNULL(SUM(so.SO_QUANTITY), 0)
                FROM stock_out so
                WHERE so.ITEM_ID = i.ITEM_ID 
                  AND DATE(so.CREATED_AT) BETWEEN ? AND ?
            ) as total_dispatched,
            (
                SELECT IFNULL(SUM(imh.QUANTITY_PIECE), 0)
                FROM item_movement_history imh
                WHERE imh.ITEM_ID = i.ITEM_ID 
                  AND imh.MOVEMENT_TYPE = 'Return'
                  AND DATE(imh.MOVEMENT_DATE) BETWEEN ? AND ?
            ) as total_returns,
            (
                SELECT COUNT(*)
                FROM masterdata m
                WHERE m.ITEM_ID = i.ITEM_ID 
                  AND m.STATUS = 'Completed'
                  AND DATE(m.CREATED_AT) BETWEEN ? AND ?
            ) as alignment_count
        FROM item i
        LEFT JOIN inventory inv ON i.ITEM_ID = inv.ITEM_ID
        WHERE i.ITEM_IS_ARCHIVED = 0
        $searchCondition
        GROUP BY i.ITEM_ID
        ORDER BY i.ITEM_CODE
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param(
        "ssssssss",
        $fromDate,
        $toDate,
        $fromDate,
        $toDate,
        $fromDate,
        $toDate,
        $fromDate,
        $toDate
    );

    $stmt->execute();
    return $stmt->get_result();
}

// Get beginning balances
function getBeginningBalances($conn, $fromDate)
{
    $baselineQuery = "
        SELECT b.ITEM_ID, b.SYSTEM_QUANTITY as beginning_qty
        FROM baseline_inventory b
        WHERE b.DATE_SNAPSHOT <= ?
        ORDER BY b.DATE_SNAPSHOT DESC
    ";
    $baselineStmt = $conn->prepare($baselineQuery);
    $baselineStmt->bind_param("s", $fromDate);
    $baselineStmt->execute();
    $baselineResult = $baselineStmt->get_result();
    
    $beginningBalances = [];
    while ($row = $baselineResult->fetch_assoc()) {
        if (!isset($beginningBalances[$row['ITEM_ID']])) {
            $beginningBalances[$row['ITEM_ID']] = $row['beginning_qty'];
        }
    }
    return $beginningBalances;
}

// Fetch data
$result = fetchAllInventoryForExport($conn, $fromDate, $toDate, $search);
$beginningBalances = getBeginningBalances($conn, $fromDate);

// Create Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Build period label
$periodLabel = date('M Y', strtotime($fromDate)) . " - " . date('M Y', strtotime($toDate));

// Set report title
$reportTitle = "INVENTORY REPORT - " . $periodLabel;
$sheet->setCellValue('A1', $reportTitle);
$sheet->mergeCells('A1:I1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Report generation info
$sheet->setCellValue('A2', 'Generated on: ' . date('Y-m-d H:i:s'));
$sheet->getStyle('A2')->getFont()->setItalic(true);

// Empty row before headers
$headerRow = 4;

// Headers
$headers = [
    "Stock No.", 
    "Item Description", 
    "Unit", 
    "Beginning", 
    "Stock In", 
    "Stock Out", 
    "Returns", 
    "Ending", 
    "# Align"
];
$sheet->fromArray($headers, NULL, 'A' . $headerRow);

// Style header row
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF']
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '0047BB']
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000'],
        ],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ]
];
$sheet->getStyle('A' . $headerRow . ':I' . $headerRow)->applyFromArray($headerStyle);

// Data rows
$rowNum = $headerRow + 1;
$totalBeginning = 0;
$totalReceived = 0;
$totalDispatched = 0;
$totalReturns = 0;
$totalEnding = 0;
$itemCount = 0;

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $beginningQty = isset($beginningBalances[$row['ITEM_ID']]) ? $beginningBalances[$row['ITEM_ID']] : 0;
        $received = (int)$row['total_received'];
        $dispatched = (int)$row['total_dispatched'];
        $returns = (int)$row['total_returns'];
        $endingInventory = $beginningQty + $received - $dispatched + $returns;
        $unit = $row['ITEM_UNIT'] ?: ($row['ITEM_UOM'] ? $row['ITEM_UOM'] . ' pcs' : 'pcs');

        // Accumulate totals
        $totalBeginning += $beginningQty;
        $totalReceived += $received;
        $totalDispatched += $dispatched;
        $totalReturns += $returns;
        $totalEnding += $endingInventory;
        $itemCount++;

        $sheet->setCellValue("A$rowNum", $row['ITEM_CODE']);
        $sheet->setCellValue("B$rowNum", $row['ITEM_DESC']);
        $sheet->setCellValue("C$rowNum", $unit);
        $sheet->setCellValue("D$rowNum", $beginningQty);
        $sheet->setCellValue("E$rowNum", $received);
        $sheet->setCellValue("F$rowNum", $dispatched);
        $sheet->setCellValue("G$rowNum", $returns);
        $sheet->setCellValue("H$rowNum", $endingInventory);
        $sheet->setCellValue("I$rowNum", $row['alignment_count']);
        
        $rowNum++;
    }

    // Add totals row
    $totalRow = $rowNum;
    $sheet->setCellValue("A$totalRow", "TOTAL");
    $sheet->setCellValue("D$totalRow", $totalBeginning);
    $sheet->setCellValue("E$totalRow", $totalReceived);
    $sheet->setCellValue("F$totalRow", $totalDispatched);
    $sheet->setCellValue("G$totalRow", $totalReturns);
    $sheet->setCellValue("H$totalRow", $totalEnding);
    $sheet->setCellValue("I$totalRow", $itemCount);

    // Style totals row
    $totalStyle = [
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF']
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '2E5FAA']
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '000000'],
            ],
        ]
    ];
    $sheet->getStyle("A$totalRow:I$totalRow")->applyFromArray($totalStyle);
    $sheet->mergeCells("A$totalRow:C$totalRow");
    $sheet->getStyle("A$totalRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Apply borders to data range
    $dataRange = 'A' . $headerRow . ':I' . $totalRow;
    $borderStyle = [
        'borders' => [
            'outline' => [
                'borderStyle' => Border::BORDER_MEDIUM,
                'color' => ['rgb' => '000000'],
            ],
            'inside' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '000000'],
            ],
        ],
    ];
    $sheet->getStyle($dataRange)->applyFromArray($borderStyle);

    // Apply number formatting to numeric columns
    // $numericRange = 'D' . ($headerRow + 1) . ':H' . $totalRow;
    // $sheet->getStyle($numericRange)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);

    // Auto-size columns
    foreach (range('A', 'I') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Set specific widths for better readability
    $sheet->getColumnDimension('B')->setWidth(40); // Item Description
    $sheet->getColumnDimension('C')->setWidth(15); // Unit
    $sheet->getColumnDimension('D')->setWidth(15); // Beginning
    $sheet->getColumnDimension('E')->setWidth(15); // Stock In
    $sheet->getColumnDimension('F')->setWidth(15); // Stock Out
    $sheet->getColumnDimension('G')->setWidth(15); // Returns
    $sheet->getColumnDimension('H')->setWidth(15); // Ending
    $sheet->getColumnDimension('I')->setWidth(10); // # Align

    // Center align numeric columns
    $sheet->getStyle('D:I')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Wrap text for description column
    $sheet->getStyle('B:B')->getAlignment()->setWrapText(true);

} else {
    $sheet->setCellValue("A$rowNum", "No inventory data found for the selected period.");
    $sheet->mergeCells("A$rowNum:I$rowNum");
    $sheet->getStyle("A$rowNum")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A$rowNum")->getFont()->setItalic(true);
}

// Add summary info
$summaryRow = $rowNum + 2;
$sheet->setCellValue("A$summaryRow", "Summary:");
$sheet->getStyle("A$summaryRow")->getFont()->setBold(true);

if ($result && $result->num_rows > 0) {
    $sheet->setCellValue("A" . ($summaryRow + 1), "Total Items: " . $itemCount);
    $sheet->setCellValue("A" . ($summaryRow + 2), "Total Beginning Inventory: " . number_format($totalBeginning));
    $sheet->setCellValue("A" . ($summaryRow + 3), "Total Stock In: " . number_format($totalReceived));
    $sheet->setCellValue("A" . ($summaryRow + 4), "Total Stock Out: " . number_format($totalDispatched));
    $sheet->setCellValue("A" . ($summaryRow + 5), "Total Returns: " . number_format($totalReturns));
    $sheet->setCellValue("A" . ($summaryRow + 6), "Total Ending Inventory: " . number_format($totalEnding));
}

// Set page setup for printing
$sheet->getPageSetup()
    ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
    ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
    ->setFitToWidth(1)
    ->setFitToHeight(0);

// Set print area
$lastRow = max($rowNum, $summaryRow + 6);
$sheet->getPageSetup()->setPrintArea('A1:I' . $lastRow);

// Filename
$fileName = "Inventory_Report_" . date('F j, Y', strtotime($fromDate)) . "_to_" . date('F j, Y', strtotime($toDate)) . ".xlsx";

// Output to browser
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename=\"$fileName\"");
header('Cache-Control: max-age=0');
header('Pragma: no-cache');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>