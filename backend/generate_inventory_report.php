<?php
ob_start();
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";
require_once "../includes/inventory_report_schema.php";
require_once "../includes/fpdf/fpdf.php";

if(!isset($_SESSION['username'])){
    die("No session");
}

if(!hasPermission($mysqli, "inventory_report")){
    die("Access denied");
}

ensureInventoryReportSchema($mysqli);

$type = strtolower(trim($_GET['type'] ?? 'asset'));

if(!in_array($type, ["asset", "server"], true)){
    die("Invalid report type");
}

$movement = strtolower(trim($_GET['movement'] ?? 'all'));

if(!in_array($movement, ["stock_in", "stock_out", "all"], true)){
    $movement = "all";
}

function reportPdfText($value){
    $value = (string)($value ?? "");
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $converted = @iconv("UTF-8", "windows-1252//TRANSLIT", $value);

    if($converted !== false){
        return $converted;
    }

    return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $value);
}

function reportValue($value, $fallback = "-"){
    $value = trim((string)($value ?? ""));
    return $value === "" ? $fallback : $value;
}

function reportQuantity($value){
    $quantity = (int)$value;
    return $quantity > 0 ? $quantity : 1;
}

function reportFormatDateTime($value){
    $value = trim((string)($value ?? ""));

    if($value === "" || $value === "0000-00-00" || $value === "0000-00-00 00:00:00"){
        return "-";
    }

    $timestamp = strtotime($value);

    if($timestamp === false){
        return $value;
    }

    return date("d F Y, h:i A", $timestamp);
}

function reportFormatDateOnly($value){
    $timestamp = strtotime((string)$value);

    if($timestamp === false){
        return reportValue($value);
    }

    return date("d F Y", $timestamp);
}

function reportMonthLabel($value){
    $timestamp = strtotime((string)$value);

    if($timestamp === false){
        return "Unknown Month";
    }

    return date("F Y", $timestamp);
}

function reportGroupByMonth($rows, $dateField){
    $groups = [];

    foreach($rows as $row){
        $timestamp = strtotime((string)($row[$dateField] ?? ""));
        $key = $timestamp === false ? "unknown" : date("Y-m", $timestamp);
        $label = $timestamp === false ? "Unknown Month" : date("F Y", $timestamp);

        if(!isset($groups[$key])){
            $groups[$key] = [
                "label" => $label,
                "rows" => []
            ];
        }

        $groups[$key]["rows"][] = $row;
    }

    return $groups;
}

function reportFetchRows($mysqli, $sql, $start, $end){
    $stmt = $mysqli->prepare($sql);

    if(!$stmt){
        die("SQL Error: " . $mysqli->error);
    }

    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();

    $result = $stmt->get_result();
    $rows = [];

    while($row = $result->fetch_assoc()){
        $rows[] = $row;
    }

    return $rows;
}

function reportMonthlyTotals($stockInRows, $stockInDateField, $stockOutRows, $stockOutDateField){
    $totals = [];

    foreach($stockInRows as $row){
        $timestamp = strtotime((string)($row[$stockInDateField] ?? ""));
        $key = $timestamp === false ? "unknown" : date("Y-m", $timestamp);
        $label = $timestamp === false ? "Unknown Month" : date("F Y", $timestamp);

        if(!isset($totals[$key])){
            $totals[$key] = [
                "label" => $label,
                "stock_in" => 0,
                "stock_out" => 0
            ];
        }

        $totals[$key]["stock_in"] += reportQuantity($row["quantity"] ?? 1);
    }

    foreach($stockOutRows as $row){
        $timestamp = strtotime((string)($row[$stockOutDateField] ?? ""));
        $key = $timestamp === false ? "unknown" : date("Y-m", $timestamp);
        $label = $timestamp === false ? "Unknown Month" : date("F Y", $timestamp);

        if(!isset($totals[$key])){
            $totals[$key] = [
                "label" => $label,
                "stock_in" => 0,
                "stock_out" => 0
            ];
        }

        $totals[$key]["stock_out"] += reportQuantity($row["quantity"] ?? 1);
    }

    ksort($totals);
    return $totals;
}

function reportParseDateOnly($value){
    $value = trim((string)($value ?? ""));

    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)){
        return null;
    }

    $parts = explode("-", $value);

    if(!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])){
        return null;
    }

    return sprintf("%04d-%02d-%02d", (int)$parts[0], (int)$parts[1], (int)$parts[2]);
}

function reportFindFirstMovementDate($mysqli, $type){
    if($type === "asset"){
        $sql = "
            SELECT MIN(movement_date) AS first_date
            FROM (
                SELECT MIN(stock_in_date) AS movement_date FROM asset_stockin_history
                UNION ALL
                SELECT MIN(stock_out_date) AS movement_date FROM stock_out_history
            ) movement_dates
        ";
    } else {
        $sql = "
            SELECT MIN(movement_date) AS first_date
            FROM (
                SELECT MIN(stock_in_date) AS movement_date FROM server_stockin_history
                UNION ALL
                SELECT MIN(stock_out_date) AS movement_date FROM server_stockout_history
            ) movement_dates
        ";
    }

    $result = $mysqli->query($sql);

    if(!$result){
        return null;
    }

    $row = $result->fetch_assoc();
    $timestamp = strtotime((string)($row["first_date"] ?? ""));

    if($timestamp === false){
        return null;
    }

    return date("Y-m-d", $timestamp);
}

function reportResolvePeriod($mysqli, $type){
    $period = strtolower(trim((string)($_GET["period"] ?? $_GET["range"] ?? "monthly")));
    $period = str_replace([" ", "_", "-"], "", $period);

    if($period === "7days"){
        $period = "7day";
    }

    if($period === "30days"){
        $period = "30day";
    }

    if($period === "montly"){
        $period = "monthly";
    }

    $today = date("Y-m-d");
    $tomorrow = date("Y-m-d 00:00:00", strtotime($today . " +1 day"));

    if($period === "today"){
        return [
            "code" => "today",
            "label" => "Today",
            "start" => $today . " 00:00:00",
            "end" => $tomorrow
        ];
    }

    if($period === "7day"){
        $startDate = date("Y-m-d", strtotime($today . " -6 days"));

        return [
            "code" => "7day",
            "label" => "Past 7 Days",
            "start" => $startDate . " 00:00:00",
            "end" => $tomorrow
        ];
    }

    if($period === "30day"){
        $startDate = date("Y-m-d", strtotime($today . " -29 days"));

        return [
            "code" => "30day",
            "label" => "Past 30 Days",
            "start" => $startDate . " 00:00:00",
            "end" => $tomorrow
        ];
    }

    if($period === "yearly"){
        $firstDate = reportFindFirstMovementDate($mysqli, $type);

        if($firstDate === null){
            $firstDate = date("Y") . "-01-01";
        }

        return [
            "code" => "yearly",
            "label" => "Yearly Report",
            "start" => $firstDate . " 00:00:00",
            "end" => $tomorrow
        ];
    }

    if($period === "custom"){
        $startDate = reportParseDateOnly($_GET["start_date"] ?? $_GET["start"] ?? "");
        $endDate = reportParseDateOnly($_GET["end_date"] ?? $_GET["end"] ?? "");

        if($startDate === null || $endDate === null || strtotime($endDate) < strtotime($startDate)){
            die("Invalid custom date range");
        }

        return [
            "code" => "custom",
            "label" => "Custom Report",
            "start" => $startDate . " 00:00:00",
            "end" => date("Y-m-d 00:00:00", strtotime($endDate . " +1 day"))
        ];
    }

    $year = isset($_GET["year"]) ? (int)$_GET["year"] : (int)date("Y");

    if($year < 2000 || $year > 2100){
        $year = (int)date("Y");
    }

    return [
        "code" => "monthly",
        "label" => "Monthly Report for " . $year,
        "start" => sprintf("%04d-01-01 00:00:00", $year),
        "end" => sprintf("%04d-01-01 00:00:00", $year + 1)
    ];
}

$reportPeriod = reportResolvePeriod($mysqli, $type);
$periodStart = $reportPeriod["start"];
$periodEnd = $reportPeriod["end"];
$periodLabel = $reportPeriod["label"];

class InventoryReportPDF extends FPDF {
    function Footer(){
        $this->SetY(-12);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 8, reportPdfText('Page ' . $this->PageNo() . ' of {nb}'), 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }

    function CheckPageBreak($height){
        if($this->GetY() + $height > $this->PageBreakTrigger){
            $this->AddPage($this->CurOrientation);
        }
    }

    function NbLines($width, $text){
        $cw = &$this->CurrentFont['cw'];

        if($width == 0){
            $width = $this->w - $this->rMargin - $this->x;
        }

        $widthMax = ($width - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $text = str_replace("\r", '', reportPdfText($text));
        $length = strlen($text);

        if($length > 0 && $text[$length - 1] == "\n"){
            $length--;
        }

        $sep = -1;
        $i = 0;
        $j = 0;
        $lineLength = 0;
        $lineCount = 1;

        while($i < $length){
            $char = $text[$i];

            if($char == "\n"){
                $i++;
                $sep = -1;
                $j = $i;
                $lineLength = 0;
                $lineCount++;
                continue;
            }

            if($char == ' '){
                $sep = $i;
            }

            $lineLength += $cw[$char] ?? 0;

            if($lineLength > $widthMax){
                if($sep == -1){
                    if($i == $j){
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }

                $sep = -1;
                $j = $i;
                $lineLength = 0;
                $lineCount++;
            } else {
                $i++;
            }
        }

        return $lineCount;
    }

    function Row($data, $widths, $lineHeight = 5.2, $fill = false){
        $lineCount = 0;

        for($i = 0; $i < count($data); $i++){
            $lineCount = max($lineCount, $this->NbLines($widths[$i], (string)$data[$i]));
        }

        $height = $lineHeight * $lineCount;
        $this->CheckPageBreak($height);

        for($i = 0; $i < count($data); $i++){
            $width = $widths[$i];
            $x = $this->GetX();
            $y = $this->GetY();

            if($fill){
                $this->Rect($x, $y, $width, $height, 'DF');
            } else {
                $this->Rect($x, $y, $width, $height);
            }

            $this->MultiCell($width, $lineHeight, reportPdfText($data[$i]), 0, 'L', false);
            $this->SetXY($x + $width, $y);
        }

        $this->Ln($height);
    }

    function SectionTitle($title){
        $this->Ln(3);
        $this->SetFillColor(33, 37, 41);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 8, reportPdfText($title), 0, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(2);
    }

    function MonthHeading($title){
        $this->Ln(2);
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(33, 37, 41);
        $this->Cell(0, 7, reportPdfText($title), 0, 1, 'L');
        $this->SetTextColor(0, 0, 0);
    }

    function Paragraph($text){
        $this->SetFont('Arial', '', 10);
        $this->MultiCell(0, 7.5, reportPdfText($text), 0, 'J');
        $this->Ln(1.5);
    }

    function DetailSentence($text){
        $this->SetFont('Arial', '', 9.5);
        $this->MultiCell(0, 7.2, reportPdfText($text), 0, 'J');
        $this->Ln(0.5);
    }

    function TableHeader($headers, $widths){
        $this->SetFillColor(255, 193, 7);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'B', 7.5);
        $this->Row($headers, $widths, 5.4, true);
        $this->SetFont('Arial', '', 7.5);
    }
}

function reportRenderEmpty($pdf, $message){
    $pdf->SetFont('Arial', 'I', 10);
    $pdf->MultiCell(0, 7.5, reportPdfText($message), 0, 'J');
    $pdf->Ln(2);
}

function reportRenderAssetStockIn($pdf, $rows){
    $pdf->SectionTitle("Stock In Table");

    if(empty($rows)){
        reportRenderEmpty($pdf, "No asset stock in records were found for this reporting period.");
        return;
    }

    $groups = reportGroupByMonth($rows, "stock_in_date");
    $widths = [38, 38, 42, 40, 14, 40, 55];

    foreach($groups as $group){
        $pdf->MonthHeading("Stock In - " . $group["label"]);
        $pdf->TableHeader(["Date & Time", "Stock In By", "Part Number", "Serial Number", "Qty", "Received By", "Location"], $widths);

        foreach($group["rows"] as $row){
            $pdf->Row([
                reportFormatDateTime($row["stock_in_date"] ?? ""),
                reportValue($row["stock_in_by"] ?? ""),
                reportValue($row["part_number"] ?? ""),
                reportValue($row["serial_number"] ?? ""),
                (string)reportQuantity($row["quantity"] ?? 1),
                reportValue($row["received_by"] ?? ""),
                reportValue($row["location"] ?? "")
            ], $widths, 5.2);
        }
    }
}

function reportRenderAssetStockOut($pdf, $rows){
    $pdf->SectionTitle("Stock Out Table");

    if(empty($rows)){
        reportRenderEmpty($pdf, "No asset stock out records were found for this reporting period.");
        return;
    }

    $groups = reportGroupByMonth($rows, "stock_out_date");
    $widths = [38, 38, 42, 40, 14, 40, 55];

    foreach($groups as $group){
        $pdf->MonthHeading("Stock Out - " . $group["label"]);
        $pdf->TableHeader(["Date & Time", "Stock Out By", "Part Number", "Serial Number", "Qty", "Location", "Remark"], $widths);

        foreach($group["rows"] as $row){
            $pdf->Row([
                reportFormatDateTime($row["stock_out_date"] ?? ""),
                reportValue($row["stock_out_by"] ?? ""),
                reportValue($row["part_number"] ?? ""),
                reportValue($row["serial_number"] ?? ""),
                (string)reportQuantity($row["quantity"] ?? 1),
                reportValue($row["location"] ?? ""),
                reportValue($row["remark"] ?? "")
            ], $widths, 5.2);
        }
    }
}

function reportRenderServerStockIn($pdf, $rows){
    $pdf->SectionTitle("Stock In Table");

    if(empty($rows)){
        reportRenderEmpty($pdf, "No server stock in records were found for this reporting period.");
        return;
    }

    $groups = reportGroupByMonth($rows, "stock_in_date");
    $widths = [38, 36, 45, 36, 40, 12, 38, 22];

    foreach($groups as $group){
        $pdf->MonthHeading("Stock In - " . $group["label"]);
        $pdf->TableHeader(["Date & Time", "Stock In By", "Server Name", "Machine Type", "Serial Number", "Qty", "Received By", "Status"], $widths);

        foreach($group["rows"] as $row){
            $pdf->Row([
                reportFormatDateTime($row["stock_in_date"] ?? ""),
                reportValue($row["stock_in_by"] ?? ""),
                reportValue($row["server_name"] ?? ""),
                reportValue($row["machine_type"] ?? ""),
                reportValue($row["serial_number"] ?? ""),
                (string)reportQuantity($row["quantity"] ?? 1),
                reportValue($row["received_by"] ?? ""),
                reportValue($row["status"] ?? "")
            ], $widths, 5.2);
        }
    }
}

function reportRenderServerStockOut($pdf, $rows){
    $pdf->SectionTitle("Stock Out Table");

    if(empty($rows)){
        reportRenderEmpty($pdf, "No server stock out records were found for this reporting period.");
        return;
    }

    $groups = reportGroupByMonth($rows, "stock_out_date");
    $widths = [38, 36, 45, 36, 40, 12, 22, 38];

    foreach($groups as $group){
        $pdf->MonthHeading("Stock Out - " . $group["label"]);
        $pdf->TableHeader(["Date & Time", "Stock Out By", "Server Name", "Machine Type", "Serial Number", "Qty", "Status", "Remark"], $widths);

        foreach($group["rows"] as $row){
            $pdf->Row([
                reportFormatDateTime($row["stock_out_date"] ?? ""),
                reportValue($row["stock_out_by"] ?? ""),
                reportValue($row["server_name"] ?? ""),
                reportValue($row["machine_type"] ?? ""),
                reportValue($row["serial_number"] ?? ""),
                (string)reportQuantity($row["quantity"] ?? 1),
                reportValue($row["status"] ?? ""),
                reportValue($row["remark"] ?? "")
            ], $widths, 5.2);
        }
    }
}

function reportRenderDateRange($pdf, $periodLabel, $periodStart, $periodEnd){
    $pdf->SectionTitle("Report Date Range");
    $pdf->Paragraph(
        "Report type: " . $periodLabel
        . "\nDate range: " . reportFormatDateOnly($periodStart)
        . " to " . reportFormatDateOnly(date("Y-m-d H:i:s", strtotime($periodEnd . " -1 second")))
        . ". Generated on " . date("d F Y, h:i A") . "."
    );
}

function reportRenderMovementTotals($pdf, $stockInRows, $stockOutRows, $movement){
    $pdf->SectionTitle("Monthly Movement Totals");

    $totals = reportMonthlyTotals($stockInRows, "stock_in_date", $stockOutRows, "stock_out_date");

    if(empty($totals)){
        $emptyText = $movement === "stock_in"
            ? "No stock in movement was found for this reporting period."
            : ($movement === "stock_out"
                ? "No stock out movement was found for this reporting period."
                : "No stock in or stock out movement was found for this reporting period.");

        reportRenderEmpty($pdf, $emptyText);
        return;
    }

    $widths = $movement === "all" ? [100, 60, 60] : [120, 70];
    $grandStockIn = 0;
    $grandStockOut = 0;

    if($movement === "stock_in"){
        $pdf->TableHeader(["Month", "Total Stock In"], $widths);
    }
    elseif($movement === "stock_out"){
        $pdf->TableHeader(["Month", "Total Stock Out"], $widths);
    }
    else{
        $pdf->TableHeader(["Month", "Total Stock In", "Total Stock Out"], $widths);
    }

    foreach($totals as $row){
        $grandStockIn += (int)$row["stock_in"];
        $grandStockOut += (int)$row["stock_out"];

        if($movement === "stock_in"){
            $pdf->Row([$row["label"], (string)$row["stock_in"]], $widths, 5.6);
        }
        elseif($movement === "stock_out"){
            $pdf->Row([$row["label"], (string)$row["stock_out"]], $widths, 5.6);
        }
        else{
            $pdf->Row([
                $row["label"],
                (string)$row["stock_in"],
                (string)$row["stock_out"]
            ], $widths, 5.6);
        }
    }

    $pdf->SetFillColor(233, 236, 239);
    $pdf->SetFont('Arial', 'B', 8);

    if($movement === "stock_in"){
        $pdf->Row(["Grand Total", (string)$grandStockIn], $widths, 5.8, true);
    }
    elseif($movement === "stock_out"){
        $pdf->Row(["Grand Total", (string)$grandStockOut], $widths, 5.8, true);
    }
    else{
        $pdf->Row([
            "Grand Total",
            (string)$grandStockIn,
            (string)$grandStockOut
        ], $widths, 5.8, true);
    }
}

if($type === "asset"){
    $stockInRows = reportFetchRows(
        $mysqli,
        "
            SELECT id, stock_in_date, stock_in_by, received_by, part_number, serial_number, brand, description, location, quantity
            FROM asset_stockin_history
            WHERE stock_in_date >= ?
              AND stock_in_date < ?
            ORDER BY stock_in_date ASC, id ASC
        ",
        $periodStart,
        $periodEnd
    );

    $stockOutRows = reportFetchRows(
        $mysqli,
        "
            SELECT id, stock_out_date, stock_out_by, part_number, serial_number, location, remark, COALESCE(quantity, 1) AS quantity
            FROM stock_out_history
            WHERE stock_out_date >= ?
              AND stock_out_date < ?
            ORDER BY stock_out_date ASC, id ASC
        ",
        $periodStart,
        $periodEnd
    );

    if($movement === "stock_in"){
        $stockOutRows = [];
        $reportName = "Asset Stock In Report";
        $inventoryLabel = "asset stock in";
    }
    elseif($movement === "stock_out"){
        $stockInRows = [];
        $reportName = "Asset Stock Out Report";
        $inventoryLabel = "asset stock out";
    }
    else{
        $reportName = "Asset Inventory Movement Report";
        $inventoryLabel = "asset inventory";
    }
} else {
    $stockInRows = reportFetchRows(
        $mysqli,
        "
            SELECT id, stock_in_date, stock_in_by, received_by, server_name, brand, machine_type, serial_number, location, status, remark, date_testing, tester, quantity
            FROM server_stockin_history
            WHERE stock_in_date >= ?
              AND stock_in_date < ?
            ORDER BY stock_in_date ASC, id ASC
        ",
        $periodStart,
        $periodEnd
    );

    $stockOutRows = reportFetchRows(
        $mysqli,
        "
            SELECT id, stock_out_date, stock_out_by, server_name, machine_type, serial_number, location, status, remark, tester, COALESCE(quantity, 1) AS quantity
            FROM server_stockout_history
            WHERE stock_out_date >= ?
              AND stock_out_date < ?
            ORDER BY stock_out_date ASC, id ASC
        ",
        $periodStart,
        $periodEnd
    );

    if($movement === "stock_in"){
        $stockOutRows = [];
        $reportName = "Server Stock In Report";
        $inventoryLabel = "server stock in";
    }
    elseif($movement === "stock_out"){
        $stockInRows = [];
        $reportName = "Server Stock Out Report";
        $inventoryLabel = "server stock out";
    }
    else{
        $reportName = "Server Inventory Movement Report";
        $inventoryLabel = "server inventory";
    }
}

$pdf = new InventoryReportPDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();

$pdf->SetFont('Arial', 'B', 18);
$pdf->Cell(0, 10, reportPdfText($reportName), 0, 1, 'C');
$pdf->Ln(10);

$logoPath = realpath(__DIR__ . "/../image/logo.png");

if($logoPath && file_exists($logoPath)){
    $logoWidth = 38;
    $logoX = ($pdf->GetPageWidth() - $logoWidth) / 2;
    $pdf->Image($logoPath, $logoX, $pdf->GetY(), $logoWidth);
    $pdf->Ln(36);
} else {
    $pdf->Ln(14);
}

$pdf->Ln(4);
$pdf->SectionTitle("Introduction");

if($type === "asset"){
    $movementPhrase = $movement === "stock_in"
        ? "new stock in records"
        : ($movement === "stock_out" ? "stock out records" : "new stock in records from stock out records");

    $pdf->Paragraph(
        "Asset inventory is the record used to monitor company parts, equipment, and related items that are received into stock or removed from stock. "
        . "This report explains the asset inventory movement for the selected reporting period (" . $periodLabel . ") by showing " . $movementPhrase . ". "
        . "Each monthly table shows only the items recorded during that month so the latest stock in and stock out movement can be reviewed clearly without listing unrelated inventory records."
    );
} else {
    $movementPhrase = $movement === "stock_in"
        ? "new stock in records"
        : ($movement === "stock_out" ? "stock out records" : "new stock in records from stock out records");

    $pdf->Paragraph(
        "Server inventory is the record used to monitor company server assets, machine types, serial numbers, status, and related movement activities. "
        . "This report explains the server inventory movement for the selected reporting period (" . $periodLabel . ") by showing " . $movementPhrase . ". "
        . "Each monthly table shows only the servers recorded during that month so the latest stock in and stock out movement can be reviewed clearly without listing unrelated inventory records."
    );
}

reportRenderDateRange($pdf, $periodLabel, $periodStart, $periodEnd);
reportRenderMovementTotals($pdf, $stockInRows, $stockOutRows, $movement);

if($type === "asset"){
    if($movement !== "stock_out"){
        reportRenderAssetStockIn($pdf, $stockInRows);
    }

    if($movement !== "stock_in"){
        reportRenderAssetStockOut($pdf, $stockOutRows);
    }
} else {
    if($movement !== "stock_out"){
        reportRenderServerStockIn($pdf, $stockInRows);
    }

    if($movement !== "stock_in"){
        reportRenderServerStockOut($pdf, $stockOutRows);
    }
}

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "Unknown";
$ip = $_SERVER['REMOTE_ADDR'] ?? "CLI";
$time = date("Y-m-d H:i:s");
$actionLabel = $type === "asset" ? "GENERATE ASSET REPORT" : "GENERATE SERVER REPORT";
$description = "User [$username] generated " . $inventoryLabel . " movement report.
Report Period: $periodLabel
Movement: $movement
Date Range: " . reportFormatDateOnly($periodStart) . " to " . reportFormatDateOnly(date("Y-m-d H:i:s", strtotime($periodEnd . " -1 second"))) . "
IP Address: $ip
Time: $time";

if(PHP_SAPI !== "cli"){
    logActivity($mysqli, $username, $role, $actionLabel, $description);
}

$filenamePrefix = $type === "asset" ? "asset" : "server";
$filenameMovement = $movement === "stock_in" ? "stock_in" : ($movement === "stock_out" ? "stock_out" : "inventory_movement");
$filename = $filenamePrefix . "_" . $filenameMovement . "_report.pdf";

while(ob_get_level() > 0){
    ob_end_clean();
}

$pdf->Output('I', $filename);
exit();
