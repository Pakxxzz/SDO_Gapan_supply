<?php
// admin/exportRIS-excel.php
session_start();
include "../API/db-connector.php";
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

if (!isset($_SESSION['user_id']) || !isset($_GET['ris_no'])) {
    die("Invalid request");
}

$ris_no = $_GET['ris_no'];

$query = "SELECT 
            so.*, 
            i.ITEM_CODE, 
            i.ITEM_DESC, 
            i.ITEM_UNIT, 
            o.OFF_CODE, 
            o.OFF_NAME,
            CONCAT(u.USER_FNAME, ' ', u.USER_LNAME) as REQUESTED_BY,
            so.CREATED_AT,
            COALESCE((
                SELECT SUM(RETURN_QUANTITY) 
                FROM item_return 
                WHERE RIS_NO = so.SO_RIS_NO 
                AND ITEM_ID = so.ITEM_ID
            ), 0) as RETURNED_QUANTITY
          FROM stock_out so
          JOIN item i ON so.ITEM_ID = i.ITEM_ID
          JOIN office o ON so.OFF_ID = o.OFF_ID
          JOIN users u ON so.CREATED_BY = u.USER_ID
          WHERE so.SO_RIS_NO = ?
          ORDER BY so.SO_ID ASC";

$stmt = $conn->prepare($query);
$stmt->bind_param("s", $ris_no);
$stmt->execute();
$result = $stmt->get_result();
$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}

if (empty($items))
    die("No records found");

$office_code = $items[0]['OFF_CODE'];
$requested_by = $items[0]['REQUESTED_BY'];
$created_at = date('F j, Y', strtotime($items[0]['CREATED_AT']));

// Template Identity Constants
$issued_by = "Christopher G. Paguio";
$designation = "AO-IV/Supply Officer";
$division = "GAPAN CITY";
$fund_cluster = "101101";
$entity_name = "DEPED DIVISION OF GAPAN CITY";

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('RIS');

// --- 1. MANDATORY A4 SINGLE PAGE SETUP ---
$sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)
    ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
    ->setFitToPage(true)
    ->setFitToWidth(1)
    ->setFitToHeight(1);

$sheet->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.3)->setRight(0.3);

// Column Widths
$sheet->getColumnDimension('A')->setWidth(12);
$sheet->getColumnDimension('B')->setWidth(10);
$sheet->getColumnDimension('C')->setWidth(35);
$sheet->getColumnDimension('D')->setWidth(10);
$sheet->getColumnDimension('E')->setWidth(10);
$sheet->getColumnDimension('F')->setWidth(10);
$sheet->getColumnDimension('G')->setWidth(12);
$sheet->getColumnDimension('H')->setWidth(20);

// --- 2. HEADER SECTION ---
// Appendix 63 moved to Row 2 to facilitate inclusion in the outer border
$sheet->setCellValue('H2', 'Appendix 63');
$sheet->getStyle('H2')->getFont()->setItalic(true)->setSize(11);
$sheet->getStyle('H2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

$sheet->mergeCells('A4:H4');
$sheet->setCellValue('A4', 'REQUISITION AND ISSUE SLIP');
$sheet->getStyle('A4')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Row 6: Entity Name and Fund Cluster
$sheet->mergeCells('A6:D6');
$sheet->setCellValue('A6', " Entity Name :   " . $entity_name);
$sheet->getStyle('A6')->getFont()->setBold(true);

$sheet->mergeCells('E6:H6');
$sheet->setCellValue('E6', " Fund Cluster :   " . $fund_cluster);
$sheet->getStyle('E6')->getFont()->setBold(true);

// Row 8: Division and Responsibility Center Code
$sheet->mergeCells('A7:H7');
$sheet->mergeCells('A42:H42');

$sheet->mergeCells('A8:D8');
$sheet->setCellValue('A8', " Division :   " . $division);
$sheet->getStyle('A8')->getFont()->setBold(true);

$sheet->mergeCells('E8:H8');
$sheet->setCellValue('E8', " Responsibility Center Code : ________________");
$sheet->getStyle('E8')->getFont()->setBold(true);

// Row 9: Office and RIS No
$sheet->mergeCells('A9:D9');
$sheet->setCellValue('A9', " Office :   " . $office_code);
$sheet->getStyle('A9')->getFont()->setBold(true);

$sheet->mergeCells('E9:H9');
$sheet->setCellValue('E9', " RIS No. :   " . $ris_no);
$sheet->getStyle('E9')->getFont()->setBold(true);

// Individual cell borders for the top info block
// $sheet->getStyle('A6:H9')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

// --- 3. TABLE DEFINITION ---
$sheet->mergeCells('A10:D10');
$sheet->setCellValue('A10', 'Requisition');
$sheet->mergeCells('E10:F10');
$sheet->setCellValue('E10', 'Stock Available?');
$sheet->mergeCells('G10:H10');
$sheet->setCellValue('G10', 'Issue');

$cols = ['A11' => 'Stock No.', 'B11' => 'Unit', 'C11' => 'Description', 'D11' => 'Quantity', 'E11' => 'Yes', 'F11' => 'No', 'G11' => 'Quantity', 'H11' => 'Remarks'];
foreach ($cols as $cell => $val)
    $sheet->setCellValue($cell, $val);

$sheet->getStyle('A10:H11')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A10:H11')->getFont()->setBold(true)->setSize(10);
$sheet->getStyle('A11:H11')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
// Center the page content
$sheet->getPageSetup()->setHorizontalCentered(true);
$sheet->getPageSetup()->setVerticalCentered(true);
// --- 4. DATA PROCESSING ---
$row = 12;
$maxDataRows = 25;
$total_returns = 0;
foreach ($items as $index => $item) {
    if ($index >= $maxDataRows)
        break;
    
    $original_qty = $item['SO_QUANTITY'];
    $returned_qty = $item['RETURNED_QUANTITY'];
    $net_issued = $original_qty - $returned_qty;
    $total_returns += $returned_qty;
    
    $availableYes = $net_issued > 0 ? '✓' : '';
    $availableNo = $net_issued <= 0 ? '✓' : '';

    $sheet->setCellValue('A' . $row, $item['ITEM_CODE']);
    $sheet->setCellValue('B' . $row, $item['ITEM_UNIT']);
    $sheet->setCellValue('C' . $row, $item['ITEM_DESC']);
    $sheet->setCellValue('D' . $row, $item['SO_QUANTITY']);
    $sheet->setCellValue('E' . $row, $availableYes);
    $sheet->setCellValue('F' . $row, $availableNo);
    $sheet->setCellValue('G' . $row, $net_issued);
    $sheet->setCellValue('H' . $row, $item['SO_REMARKS']);

    $sheet->getStyle("A$row:H$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    // $sheet->getStyle("D$row:H$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("C$row")->getAlignment()->setWrapText(true);
    $row++;
}

$tableRow = 12;
// Fill blank rows to maintain document height
while ($tableRow <= 39) {
    $sheet->getStyle("A$tableRow:H$tableRow")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $tableRow++;
}

$rowBorders = 10;
while ($rowBorders <= 39) {
    $sheet->getStyle("E$rowBorders")->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THICK);
    $sheet->getStyle("F$rowBorders")->getBorders()->getRight()->setBorderStyle(Border::BORDER_THICK);
    $rowBorders++;
}

$footerRow = 43;
while ($footerRow <= 47) {
    $sheet->getStyle("A$footerRow:H$footerRow")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $footerRow++;
}

// --- 5. FOOTER & SIGNATURES ---
$row = 40;
$sheet->mergeCells("A$row:H$row");
$sheet->setCellValue("A$row", " Purpose/s: For Office Purposes");
$sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$sheet->getRowDimension($row)->setRowHeight(25);
$row++;

// Empty divider row matching the gap in photo
$sheet->mergeCells("A$row:H$row");
$sheet->getRowDimension($row)->setRowHeight(15);
$row++;

$sigStart = 43;
$sheet->getRowDimension($sigStart)->setRowHeight(25);

// Header Labels
$sheet->mergeCells("B$sigStart:C$sigStart");
$sheet->setCellValue("B$sigStart", "Requested by:");
$sheet->mergeCells("D$sigStart:E$sigStart");
$sheet->setCellValue("D$sigStart", "Approved by:");
$sheet->mergeCells("F$sigStart:G$sigStart");
$sheet->setCellValue("F$sigStart", "Issued by:");
$sheet->setCellValue("H$sigStart", "Received by:");

// Row Labels in Column A
$sheet->setCellValue("A" . ($sigStart + 1), "Signature :");
$sheet->setCellValue("A" . ($sigStart + 2), "Printed Name :");
$sheet->setCellValue("A" . ($sigStart + 3), "Designation :");
$sheet->setCellValue("A" . ($sigStart + 4), "Date :");

// Structure Merging for signature fields
$actorCols = ['B', 'D', 'F'];
foreach ($actorCols as $col) {
    $next = chr(ord($col) + 1);
    for ($i = 1; $i <= 4; $i++) {
        $sheet->mergeCells($col . ($sigStart + $i) . ":" . $next . ($sigStart + $i));
    }
}

// Map Data
// $sheet->setCellValue("B" . ($sigStart + 2), $requested_by);
$sheet->setCellValue("F" . ($sigStart + 2), $issued_by);
$sheet->setCellValue("F" . ($sigStart + 3), $designation);
$sheet->setCellValue("B" . ($sigStart + 4), $created_at);
$sheet->setCellValue("H" . ($sigStart + 4), $created_at);

// --- 6. FINAL STYLING & OUTER BORDER ---
$lastRow = $sigStart + 4;

// Apply thin borders to all internal components first
$sheet->getStyle("A10:H10")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THICK);
$sheet->getStyle("E8")->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("E9")->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("A8:H8")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("A43:H47")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("A39:H39")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THICK);
$sheet->getStyle("A43:H43")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THICK);
$sheet->getStyle("A40:H41")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

// Applying the OUTER BORDER encompassing everything from Appendix to Footer
$sheet->getStyle("A1:H$lastRow")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THICK);

// Typography and Alignment
$sheet->getStyle("A$sigStart:H$lastRow")->getFont()->setBold(true)->setSize(10);
$sheet->getStyle("A$sigStart:A$lastRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("B$sigStart:H$lastRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Global Font
$spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $ris_no . '.xlsx"');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;