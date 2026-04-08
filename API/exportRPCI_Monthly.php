<?php
// admin/exportRPCI_Monthly.php
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

include "../API/db-connector.php";

$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

$snapshot_month = $month + 1;
$snapshot_year = $year;
if ($snapshot_month > 12) {
    $snapshot_month = 1;
    $snapshot_year = $year + 1;
}
$snapshot_date = sprintf("%04d-%02d-01", $snapshot_year, $snapshot_month);
$monthName = date("F", mktime(0, 0, 0, $month, 10));
$lastDayOfMonth = date("t", mktime(0, 0, 0, $month, 1, $year));

// Fetch Data
$sql = "SELECT 
            i.ITEM_CODE,
            i.ITEM_DESC,
            i.ITEM_UNIT,
            b.HISTORICAL_UNIT_COST as ITEM_COST,
            b.SYSTEM_QUANTITY as PHYSICAL_COUNT
        FROM baseline_inventory b
        JOIN item i ON b.ITEM_ID = i.ITEM_ID
        WHERE b.DATE_SNAPSHOT = ?
        ORDER BY i.ITEM_CODE ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $snapshot_date);
$stmt->execute();
$result = $stmt->get_result();

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$spreadsheet->getDefaultStyle()->getFont()->setName('Times New Roman')->setSize(11);

// --- 1. Top Header Section ---
$sheet->setCellValue('J1', 'Appendix 66');
$sheet->getStyle('J1')->getFont()->setItalic(true)->setSize(12);

$sheet->mergeCells('A2:J2');
$sheet->setCellValue('A2', 'REPORT ON THE PHYSICAL COUNT OF INVENTORIES');
$sheet->getStyle('A2')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A3:J3');
$sheet->setCellValue('A3', 'Office Supplies Inventory');
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A4:J4');
$sheet->setCellValue('A4', "as at $monthName $lastDayOfMonth, $year");
$sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A5', 'Fund Cluster : 101101');
$sheet->getStyle('A5')->getFont()->setBold(true);

$sheet->mergeCells('A6:J6');
$sheet->setCellValue('A6', 'For which Christopher G. Paguio, AO IV-Supply Officer, Schools Division Office of Gapan City, is accountable, having assumed such accountability on April 28, 2025.');
$sheet->getStyle('A6')->getAlignment()->setWrapText(true);

// --- 2. Table Header Section ---
$headers = [
    'A8' => 'Article',
    'B8' => 'Description',
    'C8' => 'Stock Number',
    'D8' => 'Unit of Measure',
    'E8' => 'Unit Value',
    'F8' => 'Balance Per Card',
    'G8' => 'On Hand Per Count',
    'H8' => 'Shortage/Overage',
    'I8' => 'TOTAL',
    'J8' => 'Remarks'
];

foreach ($headers as $cell => $val) {
    $sheet->setCellValue($cell, $val);
}

// Sub-headers for Quantity/Value
$sheet->setCellValue('F9', '(Quantity)')->getStyle('F9')->getFont()->setBold(false)->setSize(10);
$sheet->setCellValue('G9', '(Quantity)')->getStyle('G9')->getFont()->setBold(false)->setSize(10);
$sheet->setCellValue('H9', 'Quantity')->getStyle('H9')->getFont()->setBold(false)->setSize(10);
$sheet->setCellValue('I9', 'Value')->getStyle('I9')->getFont()->setBold(false)->setSize(10);

$styleHeader = [
    'font' => ['bold' => true, 'size' => 10],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];

// Merge the first 5 columns vertically so they span both header rows (8 and 9)
foreach (range('A', 'E') as $col) {
    $sheet->mergeCells("{$col}8:{$col}9");
}
$sheet->mergeCells("J8:J9"); // Remarks also spans two rows

$sheet->getStyle('A8:J9')->applyFromArray($styleHeader);
$sheet->getStyle('A8:J9')->applyFromArray($styleHeader);

// --- 3. Populate Data ---
$rowNum = 10;
$totalInventoryValue = 0;
$sheet->setCellValue('A' . $rowNum, 'Office Supplies');

while ($row = $result->fetch_assoc()) {
    $qty = $row['PHYSICAL_COUNT'];
    $unitCost = $row['ITEM_COST'];
    $totalItemValue = $qty * $unitCost;
    $totalInventoryValue += $totalItemValue;


    $sheet->setCellValue('B' . $rowNum, $row['ITEM_DESC']);
    $sheet->setCellValue('C' . $rowNum, $row['ITEM_CODE']);
    $sheet->setCellValue('D' . $rowNum, $row['ITEM_UNIT']);
    $sheet->setCellValue('E' . $rowNum, '₱' . number_format($unitCost, 2));
    $sheet->setCellValue('F' . $rowNum, $qty); // Balance Per Card
    $sheet->setCellValue('G' . $rowNum, $qty); // On Hand Per Count
    $sheet->setCellValue('H' . $rowNum, 0);   // Shortage
    $sheet->setCellValue('I' . $rowNum, '₱' . number_format($totalItemValue, 2));
    $sheet->setCellValue('J' . $rowNum, 'in good condition');

    $sheet->getStyle("A$rowNum:J$rowNum")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("A$rowNum:J$rowNum")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("E$rowNum")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle("I$rowNum")->getNumberFormat()->setFormatCode('#,##0.00');
    $rowNum++;
}

// --- 4. Total Row ---
$sheet->mergeCells("A$rowNum:H$rowNum");
$sheet->setCellValue("A$rowNum", "TOTAL");
$sheet->setCellValue("I$rowNum", $totalInventoryValue); // Fixed variable name
$sheet->getStyle("A$rowNum:J$rowNum")->getFont()->setBold(true);
$sheet->getStyle("A$rowNum:J$rowNum")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("I$rowNum")->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle("A$rowNum")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

// --- Signatories Footer ---
$rowNum += 2;
// Row 1: Titles
$sheet->setCellValue('A' . $rowNum, 'Certified Correct by:');
$sheet->setCellValue('D' . $rowNum, 'Approved by:');
$sheet->setCellValue('I' . $rowNum, 'Verified by:');
$sheet->getStyle("A$rowNum:J$rowNum")->getFont()->setBold(true);

$rowNum += 1;
// Row 2: Names
$sheet->setCellValue('B' . $rowNum, 'CHRISTOPHER G. PAGUIO');
$sheet->mergeCells('E' . $rowNum . ':H' . $rowNum);
$sheet->setCellValue('E' . $rowNum, 'ENRIQUE E. ANGELES JR., PhD, CESO VI');
$sheet->mergeCells('I' . $rowNum . ':J' . $rowNum);
$sheet->setCellValue('I' . $rowNum, '___________________');

// APPLY STYLES INDIVIDUALLY TO AVOID THE COORDINATE ERROR
$signatoryCells = ['B' . $rowNum, 'E' . $rowNum, 'I' . $rowNum];

foreach ($signatoryCells as $cell) {
    $sheet->getStyle($cell)->getFont()->setBold(true)->setUnderline(true);
    $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

$rowNum++;
// 1. Set the values and merge cells
$sheet->setCellValue('B' . $rowNum, 'Signature over Printed Name of Inventory Committee Chair and Members');

$sheet->mergeCells('E' . $rowNum . ':H' . $rowNum);
$sheet->setCellValue('E' . $rowNum, 'Signature over Printed Name of Head of Agency/Entity or Authorized Representative');

$sheet->mergeCells('I' . $rowNum . ':J' . $rowNum);
$sheet->setCellValue('I' . $rowNum, 'Signature over Printed Name of COA Representative');

// 2. Apply Styles (Font Size, Wrap Text, and Vertical Alignment)
$footerStyle = [
    'font' => ['size' => 10],
    'alignment' => [
        'wrapText' => true,
        'vertical' => Alignment::VERTICAL_TOP,
        'horizontal' => Alignment::HORIZONTAL_CENTER
    ]
];

$sheet->getStyle("B$rowNum")->applyFromArray($footerStyle);
$sheet->getStyle("E$rowNum:H$rowNum")->applyFromArray($footerStyle);
$sheet->getStyle("I$rowNum:J$rowNum")->applyFromArray($footerStyle);

// 3. Set Row Height to Auto
$sheet->getRowDimension($rowNum)->setRowHeight(-1);

// --- 5. Column Widths & Page Setup ---
$widths = ['A' => 15, 'B' => 40, 'C' => 15, 'D' => 12, 'E' => 12, 'F' => 12, 'G' => 12, 'H' => 12, 'I' => 15, 'J' => 20];
foreach ($widths as $col => $width) {
    $sheet->getColumnDimension($col)->setWidth($width);
}

$sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
$sheet->getPageSetup()->setFitToWidth(1);
$sheet->getPageSetup()->setFitToHeight(0);

// Output
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="RPCI_Report_' . $monthName . '_' . $year . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;