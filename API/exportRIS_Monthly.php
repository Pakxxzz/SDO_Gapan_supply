<?php
// admin/exportRIS_Monthly.php
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

include "../API/db-connector.php";

$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$monthName = strtoupper(date("F", mktime(0, 0, 0, $month, 10)));

// 1. Fetch Main Data
$sql = "SELECT 
            stock_out.SO_RIS_NO, 
            office.OFF_CODE, 
            item.ITEM_CODE, 
            item.ITEM_DESC, 
            item.ITEM_UNIT, 
            stock_out.SO_QUANTITY,
            stock_out.SO_REMARKS,
            stock_out.ITEM_ID,
            stock_out.SO_UNIT_COST,
            COALESCE((
                SELECT SUM(RETURN_QUANTITY) 
                FROM item_return 
                WHERE RIS_NO = stock_out.SO_RIS_NO 
                AND ITEM_ID = stock_out.ITEM_ID
            ), 0) as RETURNED_QUANTITY
        FROM stock_out
        JOIN item ON stock_out.ITEM_ID = item.ITEM_ID
        JOIN office ON stock_out.OFF_ID = office.OFF_ID
        WHERE MONTH(stock_out.CREATED_AT) = ? AND YEAR(stock_out.CREATED_AT) = ?
        ORDER BY stock_out.SO_RIS_NO ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $month, $year);
$stmt->execute();
$result = $stmt->get_result();

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$spreadsheet->getDefaultStyle()->getFont()->setName('Times New Roman');
// Center the page content
use PhpOffice\PhpSpreadsheet\Worksheet\HeaderFooter;

// Set Footer
$sheet->getHeaderFooter()->setOddFooter(
    '&LAppendix 64 - Report of Supplies and Materials Issued' .
    '&CPage &P'
);

// --- Header Setup ---
$sheet->setCellValue('H1', 'Appendix 64')->getStyle('H1')->getFont()->setItalic(true)->setSize(18);
$sheet->mergeCells('A3:I3');
$sheet->setCellValue('A3', 'REPORT OF SUPPLIES AND MATERIALS ISSUED');
$sheet->getStyle('A3')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A4', 'Entity Name: Department of Education, SDO Gapan City');
$sheet->setCellValue('G4', 'Serial No. : _______________________');
$sheet->setCellValue('A5', 'Fund Cluster: __________________________________');
$sheet->setCellValue('G5', "Date : $monthName $year");
$sheet->getStyle('A4:G5')->getFont()->setBold(true)->setSize(11);

// --- Main Table Headers ---
$sheet->setCellValue('A7', 'To be filled up by the Supply and/or Property Division/Unit')->getStyle('A7')->getFont()->setItalic(true);
$sheet->mergeCells('A7:F7');
$sheet->setCellValue('G7', 'To be filled up by the Accounting Division/Unit')->getStyle('G7')->getFont()->setItalic(true);
$sheet->mergeCells('G7:H7');



$headers = ['RIS No.', 'Responsibility Center Code', 'Stock No.', 'Item', 'Unit', 'Quantity Issued', 'Unit Cost', 'Amount'];
$sheet->fromArray($headers, NULL, 'A8');

$styleHeader = [
    'font' => ['bold' => true, 'size' => 11],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];
$sheet->getStyle('A7:H8')->applyFromArray($styleHeader);

// --- Populate Main Table & Prepare Recapitulation Data ---
$rowNum = 9;
$recapData = []; // Array to store totals per item

while ($row = $result->fetch_assoc()) {
    $original_qty = $row['SO_QUANTITY'];
    $returned_qty = $row['RETURNED_QUANTITY'];
    $net_issued = $original_qty - $returned_qty;
    
    // Skip if net_issued is zero or negative
    if ($net_issued <= 0) {
        continue;
    }

    $sheet->setCellValue('A' . $rowNum, $row['SO_RIS_NO']);
    $sheet->setCellValue('B' . $rowNum, '01');
    $sheet->setCellValue('C' . $rowNum, $row['ITEM_CODE']);
    $sheet->setCellValue('D' . $rowNum, $row['ITEM_DESC']);
    $sheet->setCellValue('E' . $rowNum, $row['ITEM_UNIT']);
    $sheet->setCellValue('F' . $rowNum, $net_issued);
    $sheet->setCellValue('G' . $rowNum, isset($row['SO_UNIT_COST']) ? $row['SO_UNIT_COST'] : '0'); // Unit Cost
    $sheet->setCellValue('H' . $rowNum, isset($row['SO_UNIT_COST']) ? $row['SO_UNIT_COST'] * $net_issued : 0); // Amount

    $sheet->getStyle("A$rowNum:H$rowNum")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("A$rowNum:H$rowNum")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A$rowNum")->getFont()->setBold(true)->setSize(11);

    // Grouping for Recapitulation
    $itemCode = $row['ITEM_CODE'];
    if (!isset($recapData[$itemCode])) {
        $recapData[$itemCode] = [
            'desc' => $row['ITEM_DESC'],
            'unit_cost' => $row['SO_UNIT_COST'],
            'qty' => 0,
            'original_qty' => 0,
            'returned_qty' => 0
        ];
    }
    $recapData[$itemCode]['qty'] += $net_issued;
    $recapData[$itemCode]['original_qty'] += $original_qty;
    $recapData[$itemCode]['returned_qty'] += $returned_qty;

    $rowNum++;
}

// --- Recapitulation Section ---

$rowNum += 1;
$recapStart = $rowNum;
$sheet->getStyle('A' . ($rowNum - 1) . ':H' . ($rowNum - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("F$rowNum:H$rowNum")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

// Section Title
$sheet->mergeCells('B' . $rowNum . ':C' . $rowNum)->setCellValue('B' . $rowNum, 'Recapitulation:');
$sheet->getStyle("B$rowNum:C$rowNum")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("B$rowNum:C$rowNum")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("A$rowNum")->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN);

$sheet->mergeCells('F' . $rowNum . ':H' . $rowNum)->setCellValue('F' . $rowNum, 'Recapitulation:');
$sheet->getStyle("F$rowNum:H$rowNum")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("F$rowNum:H$rowNum")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle('B' . $rowNum)->getFont()->setBold(true)->setUnderline(true);
$sheet->getStyle('F' . $rowNum)->getFont()->setBold(true)->setUnderline(true);
$rowNum++;

// Recap Headers - Adjusted to match the template columns
// Stock No (B), Qty (C), Unit Cost (E), Total Cost (F), UACS Code (G)
$recapHeaders = ['Stock No.', 'Quantity', "", "", 'Unit Cost', 'Total Cost', 'UACS Object Code'];
$sheet->fromArray($recapHeaders, NULL, 'B' . $rowNum);

// Style Recap Headers
$styleRecapHeader = [
    'font' => ['bold' => true, 'size' => 11],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
];
$sheet->getStyle("B$rowNum:C$rowNum")->applyFromArray($styleRecapHeader);
$sheet->getStyle("F$rowNum:H$rowNum")->applyFromArray($styleRecapHeader);
$sheet->getStyle("A$rowNum")->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN);
$rowNum++;

ksort($recapData);

// Populate Recap Rows
foreach ($recapData as $code => $data) {
    $sheet->setCellValue('B' . $rowNum, $code);
    $sheet->setCellValue('C' . $rowNum, $data['qty']);
    $sheet->setCellValue('D' . $rowNum, ''); // Unit Cost (Blank)
    $sheet->setCellValue('E' . $rowNum, ''); // Unit Cost (Blank)
    $sheet->setCellValue('F' . $rowNum, '₱' . isset($data['unit_cost']) ? $data['unit_cost'] : '0'); // Unit Cost (Blank)
    $sheet->setCellValue('G' . $rowNum, '₱' . isset($data['unit_cost']) ? $data['unit_cost'] * $data['qty'] : 0); // Total Cost (Blank)
    $sheet->setCellValue('H' . $rowNum, ''); // UACS Object Code (Blank)

    // Apply borders to each row
    $sheet->getStyle("A$rowNum")->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("B$rowNum:C$rowNum")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("F$rowNum:H$rowNum")->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("B$rowNum:H$rowNum")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $rowNum++;
}

// --- Signatories & Certification ---
// Move down a bit after the recap table
$rowNum += 1;
$certRow = $rowNum;
$sheet->mergeCells("B" . ($certRow - 1) . ":C" . ($certRow - 1))->getStyle("B" . ($certRow - 1) . ":C" . ($certRow - 1))->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
$sheet->mergeCells("F" . ($certRow - 1) . ":H" . ($certRow - 1))->getStyle("F" . ($certRow - 1) . ":H" . ($certRow - 1))->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
$sheet->mergeCells("A" . ($certRow - 1))->getStyle("A" . ($certRow - 1))->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN);
// Left Side: Certification
$sheet->mergeCells("A$certRow:E$certRow")->getStyle("A$certRow:E$certRow")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
$sheet->setCellValue('A' . $certRow, 'I hereby certify to the correctness of the above information.')->getStyle("E$certRow")->getBorders()->getRight()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("A$certRow")->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("E" . ($certRow + 1))->getBorders()->getRight()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("A" . ($certRow + 1))->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("E" . ($certRow + 2))->getBorders()->getRight()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("A" . ($certRow + 2))->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("E" . ($certRow + 3))->getBorders()->getRight()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("A" . ($certRow + 3))->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN);
// 1. First line (The underline)
$sheet->mergeCells("A" . ($certRow + 4) . ":E" . ($certRow + 4))
    ->setCellValue('A' . ($certRow + 4), '____________________________________');

$sheet->getStyle("A" . ($certRow + 4) . ":E" . ($certRow + 4))->applyFromArray([
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ],
    'borders' => [
        'right' => [
            'borderStyle' => Border::BORDER_THIN,
        ],
        'left' => [
            'borderStyle' => Border::BORDER_THIN,
        ],
    ],
]);

// 2. Second line (The title text)
$sheet->mergeCells("A" . ($certRow + 5) . ":E" . ($certRow + 5))
    ->setCellValue('A' . ($certRow + 5), 'Signature over Printed Name of Supply and/or Property Custodian');

$sheet->getStyle("A" . ($certRow + 5) . ":E" . ($certRow + 5))->applyFromArray([
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ],
    'borders' => [
        'right' => [
            'borderStyle' => Border::BORDER_THIN,
        ],
        'left' => [
            'borderStyle' => Border::BORDER_THIN,
        ],
    ],

]);
$sheet->getStyle("A" . ($certRow + 5) . ":E" . ($certRow + 5))->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("B" . ($certRow + 4) . ":B" . ($certRow + 5))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Right Side: Posted By
$sheet->mergeCells("F$certRow:H$certRow")->getStyle("F$certRow:H$certRow")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
$sheet->setCellValue('F' . $certRow, 'Posted by:')->getStyle("H$certRow")->getBorders()->getRight()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("H" . ($certRow + 1))->getBorders()->getRight()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("H" . ($certRow + 2))->getBorders()->getRight()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("H" . ($certRow + 3))->getBorders()->getRight()->setBorderStyle(Border::BORDER_THIN);
// 1. First line (The underline)
$sheet->mergeCells("F" . ($certRow + 4) . ":H" . ($certRow + 4))
    ->setCellValue('F' . ($certRow + 4), '____________________________________');

$sheet->getStyle("F" . ($certRow + 4) . ":H" . ($certRow + 4))->applyFromArray([
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ],
    'borders' => [
        'right' => [
            'borderStyle' => Border::BORDER_THIN,
        ],
    ],
]);

// 2. Second line (The title text)
$sheet->mergeCells("F" . ($certRow + 5) . ":H" . ($certRow + 5))
    ->setCellValue('F' . ($certRow + 5), 'Signature over Printed Name of Designated Accounting Staff');

$sheet->getStyle("F" . ($certRow + 5) . ":H" . ($certRow + 5))->applyFromArray([
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ],
    'borders' => [
        'right' => [
            'borderStyle' => Border::BORDER_THIN,
        ],
    ],
]);
$sheet->getStyle("F" . ($certRow + 5) . ":H" . ($certRow + 5))->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
$sheet->getStyle("F" . ($certRow + 4) . ":H" . ($certRow + 5))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// --- Final Page Formatting ---
// Set Column Widths to match your template
$sheet->getColumnDimension('A')->setWidth(18); // RIS No
$sheet->getColumnDimension('B')->setWidth(15); // Resp Center / Stock No
$sheet->getColumnDimension('C')->setWidth(15); // Qty
$sheet->getColumnDimension('D')->setWidth(45); // Item Desc / Unit Cost
$sheet->getColumnDimension('F')->setWidth(15); // quantity
$sheet->getColumnDimension('G')->setWidth(15); // Unit Cost 
$sheet->getColumnDimension('H')->setWidth(25); //  Amount

$sheet->getPageSetup()->setHorizontalCentered(true);
$sheet->getPageSetup()->setVerticalCentered(true);

// Landscape and Fit to Page
$sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
$sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_LEGAL);
$sheet->getPageSetup()->setFitToWidth(1);
$sheet->getPageSetup()->setFitToHeight(0);

// --- Output ---
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="RSMI_REPORT_' . $monthName . '_' . $year . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;