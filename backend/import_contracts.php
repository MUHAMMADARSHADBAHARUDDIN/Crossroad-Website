<?php
require_once "../includes/security.php";
startSecureSession();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/activity_log.php";
require_once "../includes/contract_schema.php";
require_once "../includes/contract_task_schema.php";
require_once "../includes/xlsx_reader.php";

if(!isset($_SESSION['username'])){
    header("Location: ../frontend/index.html");
    exit();
}

if(!hasContractAddAccess($mysqli)){
    http_response_code(403);
    exit("Access denied.");
}

function contractImportFinish($status, $message){
    $_SESSION['contract_import_result'] = ["status" => $status, "message" => $message];
    header("Location: ../frontend/contracts.php");
    exit();
}

function contractImportNormalizeKey($value){
    $value = strtoupper(trim((string)$value));
    return preg_replace('/[^A-Z0-9]+/', '', $value);
}

function contractImportValue($row, $excelColumn){
    return trim((string)($row[crossroadXlsxColumnIndex($excelColumn)] ?? ""));
}

function contractImportAddPmTask($mysqli, $contractId, $taskNumber, $taskDate, $scheduleLabel, $createdBy){
    if(!$taskDate || !contractTaskSchemaTableExists($mysqli, "contract_tasks")){
        return false;
    }

    $textColumn = "";
    foreach(["task_text", "task_name", "title", "description"] as $candidate){
        if(contractTaskSchemaColumnExists($mysqli, "contract_tasks", $candidate)){
            $textColumn = $candidate;
            break;
        }
    }

    if($textColumn === ""
        || !contractTaskSchemaColumnExists($mysqli, "contract_tasks", "task_start_date")
        || !contractTaskSchemaColumnExists($mysqli, "contract_tasks", "task_end_date")){
        return false;
    }

    $taskText = "Preventive Maintenance " . $taskNumber;
    $hasTaskType = contractTaskSchemaColumnExists($mysqli, "contract_tasks", "task_type");
    $duplicateSql = "SELECT 1 FROM contract_tasks WHERE contract_id = ? AND task_start_date = ? AND (LOWER(TRIM(`$textColumn`)) = LOWER(TRIM(?))";
    if($hasTaskType){
        $duplicateSql .= " OR LOWER(TRIM(COALESCE(task_type, ''))) IN ('preventive', 'preventive maintenance', 'preventive_maintenance')";
    }
    $duplicateSql .= ") LIMIT 1";
    $duplicateStmt = $mysqli->prepare($duplicateSql);
    if($duplicateStmt){
        $duplicateStmt->bind_param("iss", $contractId, $taskDate, $taskText);
        $duplicateStmt->execute();
        $duplicateResult = $duplicateStmt->get_result();
        if($duplicateResult && $duplicateResult->num_rows > 0){
            return false;
        }
    }

    $columns = ["contract_id", $textColumn, "task_start_date", "task_end_date"];
    $values = [$contractId, $taskText, $taskDate, $taskDate];
    $types = "isss";

    if(contractTaskSchemaColumnExists($mysqli, "contract_tasks", "is_completed")){
        $columns[] = "is_completed";
        $values[] = 0;
        $types .= "i";
    }
    if(contractTaskSchemaColumnExists($mysqli, "contract_tasks", "created_by")){
        $columns[] = "created_by";
        $values[] = $createdBy;
        $types .= "s";
    }
    if($hasTaskType){
        $columns[] = "task_type";
        $values[] = "preventive";
        $types .= "s";
    }
    if(contractTaskSchemaColumnExists($mysqli, "contract_tasks", "remark")){
        $columns[] = "remark";
        $values[] = "Imported from Project List: " . $scheduleLabel;
        $types .= "s";
    }

    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $quotedColumns = '`' . implode('`,`', $columns) . '`';
    $insertStmt = $mysqli->prepare("INSERT INTO contract_tasks ($quotedColumns) VALUES ($placeholders)");
    if(!$insertStmt){ return false; }

    $refs = [];
    foreach($values as $key => $value){ $refs[$key] = &$values[$key]; }
    array_unshift($refs, $types);
    call_user_func_array([$insertStmt, 'bind_param'], $refs);
    return $insertStmt->execute();
}

if($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['project_file'])){
    contractImportFinish("danger", "Please choose an Excel workbook to import.");
}

$file = $_FILES['project_file'];
if(($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK){
    contractImportFinish("danger", "The Excel workbook could not be uploaded.");
}

$originalName = (string)($file['name'] ?? "");
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if($extension !== 'xlsx'){
    contractImportFinish("danger", "Only .xlsx Excel workbooks are supported.");
}
if((int)($file['size'] ?? 0) > 25 * 1024 * 1024){
    contractImportFinish("danger", "The workbook is too large. Maximum upload size is 25MB.");
}

ensureContractProjectSchema($mysqli);
ensureContractTaskCompletionSchema($mysqli);

try{
    $rows = crossroadXlsxReadSheet($file['tmp_name'], 'Project List');
}catch(Throwable $error){
    contractImportFinish("danger", $error->getMessage());
}

$existingContractNos = [];
$existingProjectCodes = [];
$existingFallbacks = [];
$existingResult = $mysqli->query("SELECT no, project_code, contract_no, project_name, year_awarded, contract_start FROM project_inventory");
while($existingResult && $existing = $existingResult->fetch_assoc()){
    $contractKey = contractImportNormalizeKey($existing['contract_no'] ?? "");
    $projectCodeKey = contractImportNormalizeKey($existing['project_code'] ?? "");
    $contractId = (int)$existing['no'];
    if($contractKey !== ""){ $existingContractNos[$contractKey] = $contractId; }
    if($projectCodeKey !== ""){ $existingProjectCodes[$projectCodeKey] = $contractId; }
    $fallback = contractImportNormalizeKey($existing['project_name'] ?? "") . '|' . trim((string)($existing['year_awarded'] ?? "")) . '|' . trim((string)($existing['contract_start'] ?? ""));
    $existingFallbacks[$fallback] = $contractId;
}

$maxResult = $mysqli->query("SELECT COALESCE(MAX(no), 0) AS max_no FROM project_inventory");
$nextNo = (int)(($maxResult ? $maxResult->fetch_assoc()['max_no'] : 0) ?? 0) + 1;
$inserted = 0;
$skipped = 0;
$invalid = 0;
$pmTasksAdded = 0;
$invalidPmDates = 0;
$createdBy = $_SESSION['username'];

foreach($rows as $rowNumber => $row){
    if($rowNumber <= 6){ continue; }

    $projectName = contractImportValue($row, 'F');
    $contractNo = contractImportValue($row, 'C');
    $rawProjectCode = contractImportValue($row, 'B');
    if($projectName === "" && $contractNo === "" && $rawProjectCode === ""){
        continue;
    }

    $yearAwardedRaw = contractImportValue($row, 'D');
    $yearAwarded = is_numeric($yearAwardedRaw) ? (int)$yearAwardedRaw : null;
    $contractStart = crossroadXlsxExcelDate(contractImportValue($row, 'L'));
    $contractEnd = crossroadXlsxExcelDate(contractImportValue($row, 'M'));
    $contractKey = contractImportNormalizeKey($contractNo);
    $projectCodeKey = contractImportNormalizeKey($rawProjectCode);
    $fallback = contractImportNormalizeKey($projectName) . '|' . ($yearAwarded ?? "") . '|' . ($contractStart ?? "");

    $contractId = null;
    if($contractKey !== "" && isset($existingContractNos[$contractKey])){
        $contractId = $existingContractNos[$contractKey];
    }elseif($projectCodeKey !== "" && isset($existingProjectCodes[$projectCodeKey])){
        $contractId = $existingProjectCodes[$projectCodeKey];
    }elseif(isset($existingFallbacks[$fallback])){
        $contractId = $existingFallbacks[$fallback];
    }

    $pmExcelColumns = ["R", "S", "T", "U", "V", "W", "X", "Y", "Z", "AA", "AB", "AC", "AD", "AE", "AF", "AG"];
    $pmSchedule = [];
    foreach($pmExcelColumns as $offset => $excelColumn){
        $rawPmDate = contractImportValue($row, $excelColumn);
        if($rawPmDate === "" || preg_match('/^(?:N\/?A|-)$/i', $rawPmDate)){
            continue;
        }
        $pmDate = crossroadXlsxExcelDate($rawPmDate);
        if(!$pmDate){
            $invalidPmDates++;
            continue;
        }
        $year = 2026 + intdiv($offset, 4);
        $quarter = ($offset % 4) + 1;
        $pmSchedule[] = ["number" => $offset + 1, "date" => $pmDate, "label" => "$year Q$quarter"];
    }

    if($contractId !== null){
        $skipped++;
    }else{
        $projectOwner = contractImportValue($row, 'H');
        $projectManager = contractImportValue($row, 'G');
        $endUser = contractImportValue($row, 'I');
        $service = contractImportValue($row, 'J');
        $poDate = crossroadXlsxExcelDate(contractImportValue($row, 'K'));
        $status = contractImportValue($row, 'E');
        $amountRaw = str_replace([',', 'RM', ' '], '', contractImportValue($row, 'N'));
        $amount = is_numeric($amountRaw) ? round((float)$amountRaw, 2) : 0;
        $paymentTerm = contractImportValue($row, 'O');
        $projectRemark = contractImportValue($row, 'P');
        $noOfPmRaw = contractImportValue($row, 'Q');
        $noOfPm = is_numeric($noOfPmRaw) ? (float)$noOfPmRaw : null;

        $projectCode = contractProjectCodeNormalize($rawProjectCode);
        if($projectCode === "" || contractProjectCodeIsPlaceholder($projectCode)){
            $projectCode = contractProjectCodeGenerate($mysqli, $projectName, $endUser, $projectOwner, $contractNo);
        }
        if($projectCode === null || contractProjectCodeExists($mysqli, $projectCode)){
            $invalid++;
            continue;
        }

        $columns = ["no", "project_code", "year_awarded", "project_name", "project_owner", "project_manager", "account_manager", "end_user", "contract_no", "service", "po_date", "contract_start", "contract_end", "status", "amount", "payment_term", "no_of_pm", "project_remark"];
        $values = [$nextNo, $projectCode, $yearAwarded, $projectName, $projectOwner, $projectManager, "", $endUser, $contractNo, $service, $poDate, $contractStart, $contractEnd, $status, $amount, $paymentTerm, $noOfPm, $projectRemark];
        $types = "isisssssssssssdsds";

        $pmColumns = [];
        foreach(range(1, 4) as $pmYear){
            foreach(range(1, 4) as $pmQuarter){
                $pmColumns[] = "pm_y{$pmYear}_q{$pmQuarter}";
            }
        }
        foreach($pmColumns as $offset => $pmColumn){
            $columns[] = $pmColumn;
            $values[] = contractImportValue($row, $pmExcelColumns[$offset]);
            $types .= "s";
        }
        $columns[] = "created_by";
        $values[] = $createdBy;
        $types .= "s";

        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $quotedColumns = '`' . implode('`,`', $columns) . '`';
        $stmt = $mysqli->prepare("INSERT INTO project_inventory ($quotedColumns) VALUES ($placeholders)");
        if(!$stmt){ $invalid++; continue; }

        $refs = [];
        foreach($values as $key => $value){ $refs[$key] = &$values[$key]; }
        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);

        if(!$stmt->execute()){
            $invalid++;
            continue;
        }

        $contractId = $nextNo;
        $nextNo++;
        $inserted++;
        if($contractKey !== ""){ $existingContractNos[$contractKey] = $contractId; }
        $existingProjectCodes[contractImportNormalizeKey($projectCode)] = $contractId;
        $existingFallbacks[$fallback] = $contractId;
    }

    foreach($pmSchedule as $pmItem){
        if(contractImportAddPmTask($mysqli, $contractId, $pmItem['number'], $pmItem['date'], $pmItem['label'], $createdBy)){
            $pmTasksAdded++;
        }
    }
}

$description = "User [$createdBy] imported contracts from workbook [$originalName].\nInserted: $inserted\nExisting skipped: $skipped\nPM checklist tasks added: $pmTasksAdded\nInvalid PM dates skipped: $invalidPmDates\nInvalid rows skipped: $invalid\nSheet: Project List";
logActivity($mysqli, $createdBy, $_SESSION['role'] ?? 'UNKNOWN', 'IMPORT CONTRACTS', $description);

$message = "$inserted new contract" . ($inserted === 1 ? " was" : "s were") . " imported from Project List. $skipped existing contract" . ($skipped === 1 ? " was" : "s were") . " left unchanged.";
$message .= " $pmTasksAdded preventive maintenance checklist task" . ($pmTasksAdded === 1 ? " was" : "s were") . " added from the Excel dates.";
if($invalid > 0){ $message .= " $invalid row" . ($invalid === 1 ? " was" : "s were") . " skipped because required data was invalid or conflicted."; }
if($invalidPmDates > 0){ $message .= " $invalidPmDates PM value" . ($invalidPmDates === 1 ? " was" : "s were") . " skipped because it was not a valid date."; }
contractImportFinish(($invalid > 0 || $invalidPmDates > 0) ? "warning" : "success", $message);
?>
