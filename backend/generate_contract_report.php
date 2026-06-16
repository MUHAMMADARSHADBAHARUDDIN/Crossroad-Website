<?php
ob_start();
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";
require_once "../includes/contract_task_schema.php";
require_once "../includes/date_helpers.php";
require_once "../includes/fpdf/fpdf.php";

if(!isset($_SESSION['username'])){
    die("No session");
}

if(!hasContractViewAccess($mysqli)){
    die("Access denied");
}

ensureContractTaskCompletionSchema($mysqli);

$reportType = strtolower(trim((string)($_GET["report_type"] ?? $_GET["type"] ?? "all")));
$validReportTypes = ["all", "active", "pm", "project", "custom_range"];

if(!in_array($reportType, $validReportTypes, true)){
    die("Invalid report type");
}

function contractReportPdfText($value){
    $value = (string)($value ?? "");
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $converted = @iconv("UTF-8", "windows-1252//TRANSLIT", $value);

    if($converted !== false){
        return $converted;
    }

    return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $value);
}

function contractReportValue($value, $fallback = "-"){
    $value = trim((string)($value ?? ""));
    return $value === "" ? $fallback : $value;
}

function contractReportMoney($value){
    if($value === null || $value === ""){
        return "RM 0.00";
    }

    return "RM " . number_format((float)$value, 2);
}

function contractReportFormatDate($value){
    $value = trim((string)($value ?? ""));

    if($value === "" || $value === "0000-00-00" || $value === "0000-00-00 00:00:00"){
        return "-";
    }

    $timestamp = strtotime($value);

    if($timestamp === false){
        return $value;
    }

    return date("d F Y", $timestamp);
}

function contractReportFormatDateTime($value){
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

function contractReportFormatDateRange($startDate, $endDate){
    $startDate = trim((string)($startDate ?? ""));
    $endDate = trim((string)($endDate ?? ""));

    if($startDate === "" || $startDate === "0000-00-00"){
        return "Date not assigned";
    }

    if($endDate === "" || $endDate === "0000-00-00" || $endDate === $startDate){
        return contractReportFormatDate($startDate);
    }

    return contractReportFormatDate($startDate) . " to " . contractReportFormatDate($endDate);
}

function contractReportParseDateOnly($value){
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

function contractReportTableExists($mysqli, $tableName){
    $tableName = $mysqli->real_escape_string($tableName);
    $result = $mysqli->query("SHOW TABLES LIKE '$tableName'");
    return ($result && $result->num_rows > 0);
}

function contractReportColumnExists($mysqli, $tableName, $columnName){
    $tableName = str_replace("`", "", $tableName);
    $columnName = $mysqli->real_escape_string($columnName);
    $result = $mysqli->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
    return ($result && $result->num_rows > 0);
}

function contractReportBindParams($stmt, $types, $params){
    if($types === "" || empty($params)){
        return;
    }

    $refs = [];

    foreach($params as $key => $value){
        $refs[$key] = &$params[$key];
    }

    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function contractReportFetchRows($mysqli, $sql, $types = "", $params = []){
    $stmt = $mysqli->prepare($sql);

    if(!$stmt){
        die("SQL Error: " . $mysqli->error);
    }

    contractReportBindParams($stmt, $types, $params);
    $stmt->execute();

    $result = $stmt->get_result();
    $rows = [];

    while($row = $result->fetch_assoc()){
        $rows[] = $row;
    }

    return $rows;
}

function contractReportDateCondition($alias = "pi"){
    $contractStartSql = appSqlDateValue("`$alias`.`contract_start`");
    $contractEndSql = appSqlDateValue("`$alias`.`contract_end`");

    return "
        (
            (
                $contractStartSql IS NULL
                AND $contractEndSql IS NULL
            )
            OR (
                COALESCE($contractStartSql, $contractEndSql) < ?
                AND COALESCE($contractEndSql, $contractStartSql) >= ?
            )
        )
    ";
}

function contractReportFindFirstContractDate($mysqli){
    $contractStartSql = appSqlDateValue("contract_start");
    $contractEndSql = appSqlDateValue("contract_end");

    $result = $mysqli->query("
        SELECT MIN(report_date) AS first_date
        FROM (
            SELECT MIN($contractStartSql) AS report_date FROM project_inventory
            UNION ALL
            SELECT MIN($contractEndSql) AS report_date FROM project_inventory
        ) report_dates
    ");

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

function contractReportResolvePeriod($mysqli){
    $period = strtolower(trim((string)($_GET["period"] ?? $_GET["range"] ?? "monthly")));
    $period = str_replace([" ", "_", "-"], "", $period);

    if($period === "7days"){
        $period = "7day";
    }

    if($period === "30days"){
        $period = "30day";
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
        $firstDate = contractReportFindFirstContractDate($mysqli);

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
        $startDate = contractReportParseDateOnly($_GET["start_date"] ?? $_GET["start"] ?? "");
        $endDate = contractReportParseDateOnly($_GET["end_date"] ?? $_GET["end"] ?? "");

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

function contractReportStatusCase(){
    $contractEndSql = appSqlDateValue("pi.contract_end");

    return "
        CASE
            WHEN $contractEndSql IS NOT NULL
                 AND $contractEndSql < CURDATE()
            THEN 'Closed'
            WHEN $contractEndSql IS NOT NULL
                 AND $contractEndSql BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            THEN 'Expiring Soon'
            ELSE 'Active'
        END
    ";
}

function contractReportFetchContracts($mysqli, $reportType, $periodStart, $periodEnd, $projectId){
    $hasProjectManager = contractReportColumnExists($mysqli, "project_inventory", "project_manager");
    $hasAccountManager = contractReportColumnExists($mysqli, "project_inventory", "account_manager");

    $projectManagerSelect = $hasProjectManager ? "pi.project_manager" : "'' AS project_manager";
    $accountManagerSelect = $hasAccountManager ? "pi.account_manager" : "'' AS account_manager";
    $statusCase = contractReportStatusCase();
    $contractStartSql = appSqlDateValue("pi.contract_start");
    $contractEndSql = appSqlDateValue("pi.contract_end");

    $whereParts = [contractReportDateCondition("pi")];
    $types = "ss";
    $params = [$periodEnd, $periodStart];

    if($reportType === "active"){
        $whereParts[] = "
            $contractStartSql IS NOT NULL
            AND $contractStartSql <= CURDATE()
            AND $contractEndSql IS NOT NULL
            AND $contractEndSql >= CURDATE()
        ";
    }

    if($reportType === "project"){
        if($projectId <= 0){
            die("Please choose a project.");
        }

        $whereParts[] = "pi.no = ?";
        $types .= "i";
        $params[] = $projectId;
    }

    $whereSql = "WHERE " . implode(" AND ", $whereParts);

    return contractReportFetchRows(
        $mysqli,
        "
            SELECT
                pi.no,
                pi.year_awarded,
                pi.project_name,
                pi.project_owner,
                $projectManagerSelect,
                $accountManagerSelect,
                pi.end_user,
                pi.contract_no,
                pi.service,
                pi.po_date,
                pi.contract_start,
                pi.contract_end,
                pi.amount,
                pi.created_by,
                $statusCase AS auto_status
            FROM project_inventory pi
            $whereSql
            ORDER BY pi.project_name ASC, pi.no ASC
        ",
        $types,
        $params
    );
}

function contractReportFetchPmRows($mysqli, $periodStart, $periodEnd){
    if(!contractReportTableExists($mysqli, "contract_tasks")){
        return [];
    }

    if(!contractReportColumnExists($mysqli, "contract_tasks", "contract_id")){
        return [];
    }

    $idColumn = contractReportColumnExists($mysqli, "contract_tasks", "id") ? "id" : "no";

    if(!contractReportColumnExists($mysqli, "contract_tasks", $idColumn)){
        return [];
    }

    if(contractReportColumnExists($mysqli, "contract_tasks", "task_text")){
        $textColumn = "task_text";
    } elseif(contractReportColumnExists($mysqli, "contract_tasks", "task_name")){
        $textColumn = "task_name";
    } elseif(contractReportColumnExists($mysqli, "contract_tasks", "title")){
        $textColumn = "title";
    } elseif(contractReportColumnExists($mysqli, "contract_tasks", "description")){
        $textColumn = "description";
    } else {
        return [];
    }

    if(contractReportColumnExists($mysqli, "contract_tasks", "is_completed")){
        $completeSql = "CASE WHEN ct.is_completed = 1 THEN 1 ELSE 0 END AS is_done";
    } elseif(contractReportColumnExists($mysqli, "contract_tasks", "completed")){
        $completeSql = "CASE WHEN ct.completed = 1 THEN 1 ELSE 0 END AS is_done";
    } elseif(contractReportColumnExists($mysqli, "contract_tasks", "is_done")){
        $completeSql = "CASE WHEN ct.is_done = 1 THEN 1 ELSE 0 END AS is_done";
    } elseif(contractReportColumnExists($mysqli, "contract_tasks", "status")){
        $completeSql = "CASE WHEN LOWER(ct.status) IN ('completed','complete','done') THEN 1 ELSE 0 END AS is_done";
    } else {
        $completeSql = "0 AS is_done";
    }

    $hasTaskDates = contractReportColumnExists($mysqli, "contract_tasks", "task_start_date")
        && contractReportColumnExists($mysqli, "contract_tasks", "task_end_date");

    $dateSelect = $hasTaskDates
        ? "ct.task_start_date, ct.task_end_date"
        : "NULL AS task_start_date, NULL AS task_end_date";

    $createdBySelect = contractReportColumnExists($mysqli, "contract_tasks", "created_by")
        ? "ct.created_by"
        : "'' AS created_by";

    $completedBySelect = contractReportColumnExists($mysqli, "contract_tasks", "completed_by")
        ? "ct.completed_by"
        : "'' AS completed_by";

    $completedAtSelect = contractReportColumnExists($mysqli, "contract_tasks", "completed_at")
        ? "ct.completed_at"
        : "NULL AS completed_at";

    $whereParts = [
        "(LOWER(ct.`$textColumn`) LIKE '%pm%' OR LOWER(ct.`$textColumn`) LIKE '%preventive%')"
    ];

    $types = "";
    $params = [];

    if($hasTaskDates){
        $taskStartSql = appSqlDateValue("ct.task_start_date");
        $taskEndSql = appSqlDateValue("ct.task_end_date");

        $whereParts[] = "
            (
                (
                    $taskStartSql IS NOT NULL
                    AND COALESCE($taskEndSql, $taskStartSql) >= ?
                    AND $taskStartSql < ?
                )
                OR (
                    $taskStartSql IS NULL
                    AND " . contractReportDateCondition("pi") . "
                )
            )
        ";
        $types .= "ssss";
        $params[] = $periodStart;
        $params[] = $periodEnd;
        $params[] = $periodEnd;
        $params[] = $periodStart;
    } else {
        $whereParts[] = contractReportDateCondition("pi");
        $types .= "ss";
        $params[] = $periodEnd;
        $params[] = $periodStart;
    }

    $whereSql = "WHERE " . implode(" AND ", $whereParts);
    $statusCase = contractReportStatusCase();
    $projectManagerSelect = contractReportColumnExists($mysqli, "project_inventory", "project_manager")
        ? "pi.project_manager"
        : "'' AS project_manager";

    return contractReportFetchRows(
        $mysqli,
        "
            SELECT
                ct.`$idColumn` AS task_id,
                ct.`$textColumn` AS task_text,
                $dateSelect,
                $createdBySelect,
                $completedBySelect,
                $completedAtSelect,
                $completeSql,
                pi.no AS contract_id,
                pi.project_name,
                pi.contract_no,
                pi.project_owner,
                $projectManagerSelect,
                pi.contract_start,
                pi.contract_end,
                $statusCase AS auto_status
            FROM contract_tasks ct
            INNER JOIN project_inventory pi ON pi.no = ct.contract_id
            $whereSql
            ORDER BY pi.project_name ASC, ct.`$idColumn` ASC
        ",
        $types,
        $params
    );
}

class ContractReportPDF extends FPDF {
    function Footer(){
        $this->SetY(-12);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 8, contractReportPdfText('Page ' . $this->PageNo() . ' of {nb}'), 0, 0, 'C');
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
        $text = str_replace("\r", '', contractReportPdfText($text));
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

            $this->MultiCell($width, $lineHeight, contractReportPdfText($data[$i]), 0, 'L', false);
            $this->SetXY($x + $width, $y);
        }

        $this->Ln($height);
    }

    function SectionTitle($title){
        $this->Ln(3);
        $this->SetFillColor(33, 37, 41);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 8, contractReportPdfText($title), 0, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(2);
    }

    function Paragraph($text){
        $this->SetFont('Arial', '', 10);
        $this->MultiCell(0, 7.5, contractReportPdfText($text), 0, 'J');
        $this->Ln(1.5);
    }

    function TableHeader($headers, $widths){
        $this->SetFillColor(255, 193, 7);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'B', 7.4);
        $this->Row($headers, $widths, 5.4, true);
        $this->SetFont('Arial', '', 7.4);
    }
}

function contractReportRenderDateRange($pdf, $periodLabel, $periodStart, $periodEnd){
    $pdf->SectionTitle("Report Date Range");
    $pdf->Paragraph(
        "Report type: " . $periodLabel
        . "\nDate range: " . contractReportFormatDate($periodStart)
        . " to " . contractReportFormatDate(date("Y-m-d H:i:s", strtotime($periodEnd . " -1 second")))
        . ". Generated on " . date("d F Y, h:i A") . "."
    );
}

function contractReportRenderContractSummary($pdf, $rows){
    $total = count($rows);
    $active = 0;
    $expiring = 0;
    $closed = 0;
    $amount = 0;

    foreach($rows as $row){
        $status = $row["auto_status"] ?? "";

        if($status === "Active"){
            $active++;
        } elseif($status === "Expiring Soon"){
            $expiring++;
        } elseif($status === "Closed"){
            $closed++;
        }

        $amount += (float)($row["amount"] ?? 0);
    }

    $pdf->SectionTitle("Contract Summary");
    $pdf->SetFont('Arial', '', 10);
    $pdf->MultiCell(0, 7.5, contractReportPdfText(
        "Total contract records in this report: $total\n"
        . "Active: $active\n"
        . "Expiring soon: $expiring\n"
        . "Closed: $closed\n"
        . "Total amount: " . contractReportMoney($amount)
    ));
    $pdf->Ln(1.5);
}

function contractReportRenderPmSummary($pdf, $rows){
    $total = count($rows);
    $completed = 0;
    $contracts = [];

    foreach($rows as $row){
        if((int)($row["is_done"] ?? 0) === 1){
            $completed++;
        }

        $contracts[(int)($row["contract_id"] ?? 0)] = true;
    }

    $pending = $total - $completed;

    $pdf->SectionTitle("PM Summary");
    $pdf->SetFont('Arial', '', 10);
    $pdf->MultiCell(0, 7.5, contractReportPdfText(
        "Total PM tasks in this report: $total\n"
        . "Completed PM tasks: $completed\n"
        . "Pending PM tasks: $pending\n"
        . "Contracts with PM tasks: " . count($contracts)
    ));
    $pdf->Ln(1.5);
}

function contractReportRenderContractTable($pdf, $rows){
    $pdf->SectionTitle("Contract Table");

    if(empty($rows)){
        $pdf->SetFont('Arial', 'I', 10);
        $pdf->MultiCell(0, 7.5, contractReportPdfText("No contract records were found for this report."));
        return;
    }

    $widths = [11, 50, 29, 29, 31, 22, 22, 22, 51];
    $pdf->TableHeader(["No", "Project", "Owner", "Project Manager", "Contract No", "Start", "End", "Status", "Amount"], $widths);

    foreach($rows as $row){
        $pdf->Row([
            (string)($row["no"] ?? ""),
            contractReportValue($row["project_name"] ?? ""),
            contractReportValue($row["project_owner"] ?? ""),
            contractReportValue($row["project_manager"] ?? ""),
            contractReportValue($row["contract_no"] ?? ""),
            contractReportFormatDate($row["contract_start"] ?? ""),
            contractReportFormatDate($row["contract_end"] ?? ""),
            contractReportValue($row["auto_status"] ?? ""),
            contractReportMoney($row["amount"] ?? 0)
        ], $widths, 5.1);
    }
}

function contractReportRenderPmTable($pdf, $rows){
    $pdf->SectionTitle("PM Task Table");

    if(empty($rows)){
        $pdf->SetFont('Arial', 'I', 10);
        $pdf->MultiCell(0, 7.5, contractReportPdfText("No PM checklist tasks were found for this report."));
        return;
    }

    $widths = [10, 44, 30, 47, 38, 21, 36, 41];
    $pdf->TableHeader(["No", "Project", "Contract No", "PM Task", "Schedule", "Status", "Created By", "Ticked By / Time"], $widths);

    foreach($rows as $row){
        $status = (int)($row["is_done"] ?? 0) === 1 ? "Completed" : "Pending";
        $completedBy = contractReportValue($row["completed_by"] ?? "");
        $completedAt = contractReportFormatDateTime($row["completed_at"] ?? "");
        $tickText = $completedBy;

        if($completedAt !== "-"){
            $tickText .= "\n" . $completedAt;
        }

        $pdf->Row([
            (string)($row["task_id"] ?? ""),
            contractReportValue($row["project_name"] ?? ""),
            contractReportValue($row["contract_no"] ?? ""),
            contractReportValue($row["task_text"] ?? ""),
            contractReportFormatDateRange($row["task_start_date"] ?? "", $row["task_end_date"] ?? ""),
            $status,
            contractReportValue($row["created_by"] ?? ""),
            $tickText
        ], $widths, 5.1);
    }
}

$projectId = isset($_GET["project_id"]) ? (int)$_GET["project_id"] : 0;
$reportPeriod = contractReportResolvePeriod($mysqli);
$periodStart = $reportPeriod["start"];
$periodEnd = $reportPeriod["end"];
$periodLabel = $reportPeriod["label"];

$reportTypeLabels = [
    "all" => "All Total Contract Report",
    "active" => "Active Contract Report",
    "pm" => "PM Only Based on Contract Report",
    "project" => "Specific Project Contract Report",
    "custom_range" => "Custom Contract Range Report"
];

$reportName = $reportTypeLabels[$reportType];
$contractRows = [];
$pmRows = [];

if($reportType === "pm"){
    $pmRows = contractReportFetchPmRows($mysqli, $periodStart, $periodEnd);
} else {
    $contractRows = contractReportFetchContracts(
        $mysqli,
        $reportType === "custom_range" ? "all" : $reportType,
        $periodStart,
        $periodEnd,
        $projectId
    );

    if($reportType === "project" && !empty($contractRows)){
        $reportName = "Project Contract Report - " . contractReportValue($contractRows[0]["project_name"] ?? "");
    }
}

$pdf = new ContractReportPDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();

$pdf->SetFont('Arial', 'B', 18);
$pdf->Cell(0, 10, contractReportPdfText($reportName), 0, 1, 'C');
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

if($reportType === "pm"){
    $pdf->Paragraph(
        "This report lists Preventive Management checklist tasks that are attached to contract records for the selected reporting period. "
        . "It separates completed and pending PM work so the project team can review the current PM status by contract."
    );
} else {
    $pdf->Paragraph(
        "This report summarizes contract records for the selected reporting period. "
        . "It includes project ownership, contract number, contract dates, current contract status, and contract amount so the contract list can be reviewed in a printable format."
    );
}

contractReportRenderDateRange($pdf, $periodLabel, $periodStart, $periodEnd);

if($reportType === "pm"){
    contractReportRenderPmSummary($pdf, $pmRows);
    contractReportRenderPmTable($pdf, $pmRows);
} else {
    contractReportRenderContractSummary($pdf, $contractRows);
    contractReportRenderContractTable($pdf, $contractRows);
}

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "Unknown";
$ip = $_SERVER['REMOTE_ADDR'] ?? "CLI";
$time = date("Y-m-d H:i:s");
$description = "User [$username] generated a contract report.
Report Type: $reportName
Report Period: $periodLabel
Date Range: " . contractReportFormatDate($periodStart) . " to " . contractReportFormatDate(date("Y-m-d H:i:s", strtotime($periodEnd . " -1 second"))) . "
IP Address: $ip
Time: $time";

if(PHP_SAPI !== "cli"){
    logActivity($mysqli, $username, $role, "GENERATE CONTRACT REPORT", $description);
}

$filename = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $reportName));
$filename = trim($filename, "_") . ".pdf";

while(ob_get_level() > 0){
    ob_end_clean();
}

$pdf->Output('I', $filename);
exit();
?>
