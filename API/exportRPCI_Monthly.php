<?php
// admin/exportRPCI_Monthly.php
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

include "../API/db-connector.php";

$month = isset($_GET['month']) ? intval($_GET['month']) : date('m') - 1;
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

// Calculate snapshot date (first day of next month)
$snapshot_month = $month + 1;
$snapshot_year = $year;
if ($snapshot_month > 12) {
    $snapshot_month = 1;
    $snapshot_year = $year + 1;
}
$snapshot_date = sprintf("%04d-%02d-01", $snapshot_year, $snapshot_month);
$monthName = strtoupper(date("F", mktime(0, 0, 0, $month, 10)));

// Fetch physical count data using snapshot date - ONLY item details and quantity
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

// Check if data exists
if ($result->num_rows === 0) {
    die("No physical count data found for $monthName $year");
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$spreadsheet->getDefaultStyle()->getFont()->setName('Times New Roman')->setSize(10);
$sheet->getPageSetup()->setHorizontalCentered(true);
// $sheet->getPageSetup()->setVerticalCentered(true);

// Set Footer
$sheet->getHeaderFooter()->setOddFooter(
    '&LReport of Physical Count of Inventories (RPCI)' .
    '&CPage &P'
);

// --- Header Section ---
$sheet->mergeCells('A1:D1');
$sheet->setCellValue('A1', 'Republic of the Philippines');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A2:D2');
$sheet->setCellValue('A2', 'Department of Education');
$sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A3:D3');
$sheet->setCellValue('A3', 'SCHOOLS DIVISION OFFICE - GAPAN CITY');
$sheet->getStyle('A3')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A4:D4');
$sheet->setCellValue('A4', 'REPORT OF PHYSICAL COUNT OF INVENTORIES (RPCI)');
$sheet->getStyle('A4')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A5:D5');
$sheet->setCellValue('A5', "As of " . date('F d, Y', strtotime($snapshot_date)));
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A6:D6');
$sheet->setCellValue('A6', "For the Month of: " . date('F Y', mktime(0, 0, 0, $month, 10)));
$sheet->getStyle('A6')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Empty row
$sheet->setCellValue('A7', '');

// --- Main Table Headers - ONLY 4 columns ---
$headers = [
    'A8' => 'Stock No.',
    'B8' => 'Item Description',
    'C8' => 'Unit',
    'D8' => 'Quantity'
];

foreach ($headers as $cell => $value) {
    $sheet->setCellValue($cell, $value);
}

// Style Headers
$headerStyle = [
    'font' => ['bold' => true, 'size' => 10],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    'fill' => [
        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'E2E8F0']
    ]
];
$sheet->getStyle('A8:D8')->applyFromArray($headerStyle);

// --- Populate Data ---
$rowNum = 9;
$total_quantity = 0;

while ($row = $result->fetch_assoc()) {
    $quantity = $row['PHYSICAL_COUNT'];
    $total_quantity += $quantity;
    
    $sheet->setCellValue('A' . $rowNum, $row['ITEM_CODE']);
    $sheet->setCellValue('B' . $rowNum, $row['ITEM_DESC']);
    $sheet->setCellValue('C' . $rowNum, $row['ITEM_UNIT']);
    $sheet->setCellValue('D' . $rowNum, $quantity);
    
    // Apply borders and alignment
    $sheet->getStyle("A$rowNum:D$rowNum")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("A$rowNum")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("C$rowNum:D$rowNum")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("B$rowNum")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    
    $rowNum++;
}

// --- Totals Row ---
$sheet->setCellValue('A' . $rowNum, 'TOTAL:');
$sheet->mergeCells('A' . $rowNum . ':C' . $rowNum);
$sheet->setCellValue('D' . $rowNum, $total_quantity);

$sheet->getStyle('A' . $rowNum . ':D' . $rowNum)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle('A' . $rowNum)->getFont()->setBold(true);
$sheet->getStyle('D' . $rowNum)->getFont()->setBold(true);

$rowNum += 2;

// --- Signatories Section ---
$signRow = $rowNum;

// Left: Prepared by
// $sheet->mergeCells('A' . $signRow . ':B' . $signRow);
// $sheet->setCellValue('A' . $signRow, 'Prepared by:');
// $sheet->getStyle('A' . $signRow)->getFont()->setBold(true);

// $sheet->mergeCells('A' . ($signRow + 2) . ':B' . ($signRow + 2));
// $sheet->setCellValue('A' . ($signRow + 2), '____________________________________');
// $sheet->getStyle('A' . ($signRow + 2))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// $sheet->mergeCells('A' . ($signRow + 3) . ':B' . ($signRow + 3));
// $sheet->setCellValue('A' . ($signRow + 3), 'Supply Officer/Inventory Custodian');
// $sheet->getStyle('A' . ($signRow + 3))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
// $sheet->getStyle('A' . ($signRow + 3))->getFont()->setItalic(true);

// // Right: Certified Correct
// $sheet->mergeCells('C' . $signRow . ':D' . $signRow);
// $sheet->setCellValue('C' . $signRow, 'Certified Correct:');
// $sheet->getStyle('C' . $signRow)->getFont()->setBold(true);

// $sheet->mergeCells('C' . ($signRow + 2) . ':D' . ($signRow + 2));
// $sheet->setCellValue('C' . ($signRow + 2), '____________________________________');
// $sheet->getStyle('C' . ($signRow + 2))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// $sheet->mergeCells('C' . ($signRow + 3) . ':D' . ($signRow + 3));
// $sheet->setCellValue('C' . ($signRow + 3), 'Accountant/Designated Representative');
// $sheet->getStyle('C' . ($signRow + 3))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
// $sheet->getStyle('C' . ($signRow + 3))->getFont()->setItalic(true);

// $rowNum += 6;

// // --- Noted by ---
// $sheet->mergeCells('B' . $rowNum . ':C' . $rowNum);
// $sheet->setCellValue('B' . $rowNum, 'Noted by:');
// $sheet->getStyle('B' . $rowNum)->getFont()->setBold(true);
// $sheet->getStyle('B' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// $sheet->mergeCells('B' . ($rowNum + 2) . ':C' . ($rowNum + 2));
// $sheet->setCellValue('B' . ($rowNum + 2), '____________________________________');
// $sheet->getStyle('B' . ($rowNum + 2))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// $sheet->mergeCells('B' . ($rowNum + 3) . ':C' . ($rowNum + 3));
// $sheet->setCellValue('B' . ($rowNum + 3), 'Schools Division Superintendent');
// $sheet->getStyle('B' . ($rowNum + 3))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
// $sheet->getStyle('B' . ($rowNum + 3))->getFont()->setItalic(true);

// --- Column Widths ---
$sheet->getColumnDimension('A')->setWidth(15); // Stock No
$sheet->getColumnDimension('B')->setWidth(60); // Item Description (wider)
$sheet->getColumnDimension('C')->setWidth(15); // Unit
$sheet->getColumnDimension('D')->setWidth(width: 20); // Quantity

// --- Page Setup ---
$sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
$sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
$sheet->getPageSetup()->setFitToWidth(1);
$sheet->getPageSetup()->setFitToHeight(0);

// Repeat headers on each page
$sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(8, 8);

// --- Output ---
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="RPCI_' . $monthName . '_' . $year . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;