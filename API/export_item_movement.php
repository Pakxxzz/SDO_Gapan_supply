<?php
require '../vendor/autoload.php'; // PhpSpreadsheet autoloader
include "../API/db-connector.php";

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// Get filters - match the parameter names from item-movement.php
$dateFrom = $_GET['dateFrom'] ?? null;
$dateTo = $_GET['dateTo'] ?? null;
$movementType = $_GET['movement_type'] ?? null;
$checkerId = isset($_GET['warehouse-head']) ? intval($_GET['warehouse-head']) : null;
$search = $_GET['search'] ?? null;

// Build WHERE conditions
$whereClauses = ["i.ITEM_IS_ARCHIVED = 0", "imh.QUANTITY_PIECE != 0"];

if ($dateFrom) {
    $dateFromEscaped = $conn->real_escape_string($dateFrom);
    $whereClauses[] = "imh.MOVEMENT_DATE >= STR_TO_DATE('$dateFromEscaped', '%Y-%m-%dT%H:%i')";
}
if ($dateTo) {
    $dateToEscaped = $conn->real_escape_string($dateTo);
    $whereClauses[] = "imh.MOVEMENT_DATE <= STR_TO_DATE('$dateToEscaped', '%Y-%m-%dT%H:%i')";
}
if ($movementType) {
    $movementTypeEscaped = $conn->real_escape_string($movementType);
    $whereClauses[] = "imh.MOVEMENT_TYPE = '$movementTypeEscaped'";
}
if ($checkerId) {
    $whereClauses[] = "imh.CHECKER_ID = $checkerId";
}

if (!empty($search)) {
    $escapedSearch = $conn->real_escape_string($search);
    $whereClauses[] = "(i.ITEM_DESC LIKE '%$escapedSearch%' 
                       OR i.ITEM_CODE LIKE '%$escapedSearch%' 
                       OR imh.BATCH_NO LIKE '%$escapedSearch%' 
                       OR imh.DETAILS LIKE '%$escapedSearch%')";
}

$whereSQL = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// Get ALL results (no pagination)
$sql = "SELECT imh.*, i.ITEM_DESC, i.ITEM_CODE, i.ITEM_UNIT
        FROM item_movement_history imh
        JOIN item i ON imh.ITEM_ID = i.ITEM_ID
        $whereSQL
        ORDER BY imh.IMH_ID DESC";

$result = $conn->query($sql);

if (!$result) {
    die("Error in query: " . $conn->error);
}

// Create Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set report title and metadata
$reportTitle = "ITEM MOVEMENT REPORT";
$sheet->setCellValue('A1', $reportTitle);
$sheet->mergeCells('A1:G1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Report generation info
$sheet->setCellValue('A2', 'Generated on: ' . date('Y-m-d H:i:s'));
$sheet->getStyle('A2')->getFont()->setItalic(true);

// Filter information section
$filterInfoRow = 3;
$hasFilters = false;

if ($dateFrom || $dateTo || $movementType || $checkerId || !empty($search)) {
    $sheet->setCellValue('A' . $filterInfoRow, 'Applied Filters:');
    $sheet->getStyle('A' . $filterInfoRow)->getFont()->setBold(true);
    $filterInfoRow++;
    $hasFilters = true;
}

if ($dateFrom) {
    $sheet->setCellValue('A' . $filterInfoRow, '• From Date: ' . $dateFrom);
    $filterInfoRow++;
}
if ($dateTo) {
    $sheet->setCellValue('A' . $filterInfoRow, '• To Date: ' . $dateTo);
    $filterInfoRow++;
}
if ($movementType) {
    $sheet->setCellValue('A' . $filterInfoRow, '• Movement Type: ' . $movementType);
    $filterInfoRow++;
}
if ($checkerId) {
    // Get checker name if needed
    $checkerQuery = "SELECT CONCAT(USER_FNAME, ' ', USER_LNAME) as FULL_NAME FROM users WHERE USER_ID = $checkerId";
    $checkerResult = $conn->query($checkerQuery);
    $checkerName = ($checkerResult && $checkerResult->num_rows > 0) ? $checkerResult->fetch_assoc()['FULL_NAME'] : 'ID: ' . $checkerId;
    $sheet->setCellValue('A' . $filterInfoRow, '• Warehouse Head: ' . $checkerName);
    $filterInfoRow++;
}
if (!empty($search)) {
    $sheet->setCellValue('A' . $filterInfoRow, '• Search: ' . $search);
    $filterInfoRow++;
}

// Add empty row before headers
$headerRow = $hasFilters ? $filterInfoRow + 1 : 4;

// Header row - FIXED to match columns in the main view
$headers = ["Batch Number", "SKU Code", "Item Description", "Movement Type", "Quantity", "Remarks", "Date"];
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
$sheet->getStyle('A' . $headerRow . ':G' . $headerRow)->applyFromArray($headerStyle);

// Data rows
$rowNum = $headerRow + 1;
$totalRecords = 0;
$totalQuantity = 0;

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $movementType = $row['MOVEMENT_TYPE'];
        $quantity = (int) $row['QUANTITY_PIECE'];

        // Apply negative if movement type is Delivery or Disposed (matching the main view logic)
        if ($movementType === "Delivery" || $movementType === "Disposed") {
            $displayQuantity = -abs($quantity);
        } else {
            $displayQuantity = $quantity;
        }

        $totalRecords++;
        $totalQuantity += $displayQuantity;

        $sheet->setCellValue("A$rowNum", $row['BATCH_NO']);
        $sheet->setCellValue("B$rowNum", $row['ITEM_CODE']);
        $sheet->setCellValue("C$rowNum", $row['ITEM_DESC']);
        $sheet->setCellValue("D$rowNum", $movementType);
        $sheet->setCellValue("E$rowNum", $displayQuantity);
        $sheet->setCellValue("F$rowNum", $row['DETAILS']); // Remarks column
        $sheet->setCellValue("G$rowNum", date('M j, Y \a\t g:i a', strtotime($row['MOVEMENT_DATE'])));
        $rowNum++;
    }

    // Add summary row
    $summaryRow = $rowNum;
    $sheet->setCellValue("A$summaryRow", "SUMMARY");
    $sheet->mergeCells("A$summaryRow:D$summaryRow");
    $sheet->setCellValue("E$summaryRow", $totalQuantity);
    $sheet->setCellValue("F$summaryRow", "Total Records: " . $totalRecords);

    // Style summary row
    $summaryStyle = [
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
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ]
    ];
    $sheet->getStyle("A$summaryRow:G$summaryRow")->applyFromArray($summaryStyle);
    $sheet->getStyle("A$summaryRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Apply borders to data range
    $dataRange = 'A' . $headerRow . ':G' . $summaryRow;
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
    // Define the data range (from first header to the last summary/data row)
// 1. Define the actual full range of your table (A to G)
    $lastDataRow = $rowNum;
    $fullTableRange = 'A' . $headerRow . ':G' . $lastDataRow;

    // 2. Set EVERYTHING to Center-Center first (The "Center in all corners" look)
    $sheet->getStyle($fullTableRange)->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER);

    // 3. Override Columns C (Description) and F (Remarks) to be LEFT-aligned 
// but keep them VERTICALLY centered. (Long text looks messy centered)
    $sheet->getStyle('C' . ($headerRow + 1) . ':C' . $lastDataRow)
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

    $sheet->getStyle('F' . ($headerRow + 1) . ':F' . $lastDataRow)
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

} else {
    // No data message
    $sheet->setCellValue("A$rowNum", "No item movements found for the selected filters.");
    $sheet->mergeCells("A$rowNum:G$rowNum");
    $sheet->getStyle("A$rowNum")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A$rowNum")->getFont()->setItalic(true);
    $rowNum++;
}

// Add final summary info
$finalSummaryRow = $rowNum + 1;
if ($result && $result->num_rows > 0) {
    $sheet->setCellValue("A$finalSummaryRow", "Report Summary:");
    $sheet->getStyle("A$finalSummaryRow")->getFont()->setBold(true);

    $sheet->setCellValue("A" . ($finalSummaryRow + 1), "Total Records: " . $totalRecords);
    $sheet->setCellValue("A" . ($finalSummaryRow + 2), "Net Quantity Movement: " . number_format($totalQuantity));
}

// Auto-size columns with optimal widths
$sheet->getColumnDimension('A')->setWidth(20); // Batch Number
$sheet->getColumnDimension('B')->setWidth(15); // SKU Code
$sheet->getColumnDimension('C')->setWidth(40); // Item Description
$sheet->getColumnDimension('D')->setWidth(15); // Movement Type
$sheet->getColumnDimension('E')->setWidth(15); // Quantity
$sheet->getColumnDimension('F')->setWidth(30); // Remarks
$sheet->getColumnDimension('G')->setWidth(20); // Date

// Wrap text for description and remarks columns
$sheet->getStyle('C:C')->getAlignment()->setWrapText(true);
$sheet->getStyle('F:F')->getAlignment()->setWrapText(true);

// Center align specific columns
$sheet->getStyle('D:D')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('E:E')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('G:G')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Set page setup for printing
$sheet->getPageSetup()
    ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
    ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
    ->setFitToWidth(1)
    ->setFitToHeight(0);

// Set print area
$sheet->getPageSetup()->setPrintArea('A1:G' . $rowNum);

// Filename with filter info
$fileName = "Item_Movement_Report_" . date('Y-m-d');

if (!empty($movementType))
    $fileName .= "_" . strtolower($movementType);
if (!empty($search))
    $fileName .= "_search";
$fileName .= ".xlsx";

// Clean filename (remove special characters)
$fileName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $fileName);

// Output
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename=\"$fileName\"");
header('Cache-Control: max-age=0');
header('Pragma: no-cache');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;