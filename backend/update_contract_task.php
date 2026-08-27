<?php
require_once "../includes/security.php";
startSecureSession();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/activity_log.php";
require_once "../includes/contract_task_schema.php";
require_once "../includes/contract_notifications.php";

header("Content-Type: text/plain; charset=UTF-8");

if(!isset($_SESSION['username'])){
    exit("No session");
}

ensureContractTaskCompletionSchema($mysqli);

function updateTaskTableExists($mysqli, $tableName){
    $tableName = $mysqli->real_escape_string($tableName);
    $result = $mysqli->query("SHOW TABLES LIKE '$tableName'");
    return ($result && $result->num_rows > 0);
}

function updateTaskColumnExists($mysqli, $tableName, $columnName){
    $tableName = str_replace("`", "", $tableName);
    $columnName = $mysqli->real_escape_string($columnName);
    $result = $mysqli->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
    return ($result && $result->num_rows > 0);
}

function updateTaskValidDate($value){
    if($value === ""){
        return true;
    }
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$taskText = trim($_POST['task_text'] ?? "");
$taskType = trim((string)($_POST['task_type'] ?? ""));
$remark = trim((string)($_POST['remark'] ?? ""));
$notificationEmail = trim((string)($_POST['notification_email'] ?? ""));
$taskStartDate = trim($_POST['task_start_date'] ?? "");
$taskEndDate = trim($_POST['task_end_date'] ?? "");
$claimAmountRaw = trim((string)($_POST['claim_amount'] ?? ""));
$invoice = trim((string)($_POST['invoice'] ?? ""));
$claimAmount = null;

if($id <= 0){ exit("Invalid task."); }
if($taskText === ""){ exit("Task cannot be empty."); }
$allowedTaskTypes = ["preventive", "kickoff", "training", "meeting", "corrective", "claim", "other"];
if(!in_array($taskType, $allowedTaskTypes, true)){
    if(stripos($taskText, "Claim -") === 0 || strcasecmp($taskText, "Claim") === 0){
        $taskType = "claim";
    }elseif(stripos($taskText, "Preventive Maintenance") === 0){
        $taskType = "preventive";
    }elseif(strcasecmp($taskText, "Kickoff") === 0){
        $taskType = "kickoff";
    }elseif(strcasecmp($taskText, "Training") === 0){
        $taskType = "training";
    }elseif(strcasecmp($taskText, "Meeting") === 0){
        $taskType = "meeting";
    }elseif(stripos($taskText, "Corrective Maintenance") === 0){
        $taskType = "corrective";
    }else{
        $taskType = "other";
    }
}

$isClaimTask = $taskType === "claim";
if($isClaimTask && $invoice === ""){ exit("Please enter the invoice."); }
if(!$isClaimTask){
    $claimAmountRaw = "";
    $claimAmount = null;
    $invoice = "";
}
if($claimAmountRaw !== ""){
    $claimAmountRaw = str_replace([",", " "], "", $claimAmountRaw);

    if(!is_numeric($claimAmountRaw) || (float)$claimAmountRaw < 0){
        exit("Claim amount must be a positive number.");
    }

    $claimAmount = round((float)$claimAmountRaw, 2);
}
if(!updateTaskValidDate($taskStartDate) || !updateTaskValidDate($taskEndDate)){ exit("Invalid task date."); }
if($taskStartDate === "" && $taskEndDate !== ""){ exit("Please select a task start date first."); }
if($taskStartDate !== "" && $taskEndDate === ""){ $taskEndDate = $taskStartDate; }
if($taskStartDate !== "" && $taskEndDate < $taskStartDate){ exit("Task end date cannot be before the start date."); }

if(!updateTaskTableExists($mysqli, "contract_tasks")){ exit("contract_tasks table not found."); }
$idColumn = updateTaskColumnExists($mysqli, "contract_tasks", "id") ? "id" : "no";
if(!updateTaskColumnExists($mysqli, "contract_tasks", $idColumn)){ exit("Task ID column not found."); }
if(!updateTaskColumnExists($mysqli, "contract_tasks", "contract_id")){ exit("contract_id column not found."); }

if(updateTaskColumnExists($mysqli, "contract_tasks", "task_text")){
    $textColumn = "task_text";
}elseif(updateTaskColumnExists($mysqli, "contract_tasks", "task_name")){
    $textColumn = "task_name";
}elseif(updateTaskColumnExists($mysqli, "contract_tasks", "title")){
    $textColumn = "title";
}elseif(updateTaskColumnExists($mysqli, "contract_tasks", "description")){
    $textColumn = "description";
}else{
    exit("Task text column not found.");
}

$hasTaskDates = updateTaskColumnExists($mysqli, "contract_tasks", "task_start_date")
    && updateTaskColumnExists($mysqli, "contract_tasks", "task_end_date");
$hasClaimAmount = updateTaskColumnExists($mysqli, "contract_tasks", "claim_amount");
$hasInvoice = updateTaskColumnExists($mysqli, "contract_tasks", "invoice");
$hasTaskType = updateTaskColumnExists($mysqli, "contract_tasks", "task_type");
$hasRemark = updateTaskColumnExists($mysqli, "contract_tasks", "remark");
$hasNotificationEmail = updateTaskColumnExists($mysqli, "contract_tasks", "notification_email");
$dateSelect = $hasTaskDates ? ", task_start_date, task_end_date" : "";
$claimSelect = $hasClaimAmount ? ", claim_amount" : "";
$invoiceSelect = $hasInvoice ? ", invoice" : ", NULL AS invoice";
$taskTypeSelect = $hasTaskType ? ", task_type" : ", NULL AS task_type";
$remarkSelect = $hasRemark ? ", remark" : ", NULL AS remark";
$notificationEmailSelect = $hasNotificationEmail ? ", notification_email" : ", NULL AS notification_email";

$taskStmt = $mysqli->prepare("
    SELECT contract_id, `$textColumn` AS old_task_text $dateSelect $claimSelect $invoiceSelect $taskTypeSelect $remarkSelect $notificationEmailSelect
    FROM contract_tasks
    WHERE `$idColumn` = ?
    LIMIT 1
");
if(!$taskStmt){ exit("SQL Error: " . $mysqli->error); }
$taskStmt->bind_param("i", $id);
$taskStmt->execute();
$task = $taskStmt->get_result()->fetch_assoc();
if(!$task){ exit("Task not found."); }

$contractId = (int)$task['contract_id'];
$contractStmt = $mysqli->prepare("SELECT created_by, project_name, project_code, contract_no, contract_start, contract_end FROM project_inventory WHERE no = ? LIMIT 1");
if(!$contractStmt){ exit("SQL Error: " . $mysqli->error); }
$contractStmt->bind_param("i", $contractId);
$contractStmt->execute();
$contract = $contractStmt->get_result()->fetch_assoc();
if(!$contract){ exit("Contract not found."); }

if(!hasContractTaskEditAccess($mysqli, $contract['created_by'] ?? "")){
    exit("Access denied. You do not have Task Edit permission.");
}

$canViewClaim = hasContractClaimViewAccess($mysqli);
if(!$canViewClaim && $claimAmountRaw !== ""){
    exit("Access denied. You do not have View Claim permission.");
}

if(!$hasTaskDates && $taskStartDate !== ""){
    exit("Please run upgrade_pm_stockout.sql first before assigning task dates.");
}

$setParts = ["`$textColumn` = ?"];
$types = "s";
$params = [$taskText];

if($hasTaskType){
    $setParts[] = "task_type = ?";
    $types .= "s";
    $params[] = $taskType;
}

if($hasRemark){
    $setParts[] = "remark = ?";
    $types .= "s";
    $params[] = $remark !== "" ? $remark : null;
}

if($hasNotificationEmail){
    $setParts[] = "notification_email = ?";
    $types .= "s";
    $params[] = $notificationEmail !== "" ? $notificationEmail : null;
}

if($hasTaskDates){
    $startValue = $taskStartDate !== "" ? $taskStartDate : null;
    $endValue = $taskEndDate !== "" ? $taskEndDate : null;
    $setParts[] = "task_start_date = ?";
    $setParts[] = "task_end_date = ?";
    $types .= "ss";
    $params[] = $startValue;
    $params[] = $endValue;
}

if($hasClaimAmount && $canViewClaim){
    $setParts[] = "claim_amount = ?";
    $types .= "d";
    $params[] = $claimAmount;
} elseif($claimAmount !== null){
    exit("claim_amount column not found.");
}

if($hasInvoice){
    $setParts[] = "invoice = ?";
    $types .= "s";
    $params[] = $invoice !== "" ? $invoice : null;
} elseif($invoice !== ""){
    exit("invoice column not found.");
}

$types .= "i";
$params[] = $id;

$stmt = $mysqli->prepare("
    UPDATE contract_tasks
    SET " . implode(", ", $setParts) . "
    WHERE `$idColumn` = ?
");
if(!$stmt){ exit("SQL Error: " . $mysqli->error); }

$refs = [];
foreach($params as $key => $value){
    $refs[$key] = &$params[$key];
}
array_unshift($refs, $types);
call_user_func_array([$stmt, 'bind_param'], $refs);

if(!$stmt->execute()){
    exit("Failed to update task: " . $stmt->error);
}

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "UNKNOWN";
$ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
$time = date("Y-m-d H:i:s");
$oldStart = $task['task_start_date'] ?? "";
$oldEnd = $task['task_end_date'] ?? "";
$oldDateText = $oldStart === "" ? "Not Assigned" : ($oldStart === $oldEnd ? $oldStart : "$oldStart to $oldEnd");
$newDateText = $taskStartDate === "" ? "Not Assigned" : ($taskStartDate === $taskEndDate ? $taskStartDate : "$taskStartDate to $taskEndDate");
$oldClaimText = "Unchanged";
$newClaimText = "Unchanged";
$oldInvoiceText = isset($task['invoice']) && trim((string)$task['invoice']) !== "" ? $task['invoice'] : "Not Assigned";
$newInvoiceText = $invoice === "" ? "Not Assigned" : $invoice;
$oldRemarkText = isset($task['remark']) && trim((string)$task['remark']) !== "" ? $task['remark'] : "Not Assigned";
$newRemarkText = $remark === "" ? "Not Assigned" : $remark;

if($canViewClaim){
    $oldClaimText = isset($task['claim_amount']) && $task['claim_amount'] !== null && $task['claim_amount'] !== ""
        ? number_format((float)$task['claim_amount'], 2)
        : "Not Assigned";
    $newClaimText = $claimAmount === null ? "Not Assigned" : number_format($claimAmount, 2);
}

$description = "User [$username] edited a contract task.\n"
    . "Contract ID: $contractId\n"
    . "Contract No: " . ($contract['contract_no'] ?? "") . "\n"
    . "Project Name: " . ($contract['project_name'] ?? "") . "\n"
    . "Task ID: $id\n\n"
    . "OLD DATA:\n- Task: " . ($task['old_task_text'] ?? "") . "\n- Remark: $oldRemarkText\n- Task Date: $oldDateText\n- Claim Amount: $oldClaimText\n- Invoice: $oldInvoiceText\n\n"
    . "NEW DATA:\n- Checklist Type: $taskType\n- Task: $taskText\n- Remark: $newRemarkText\n- Task Date: $newDateText\n- Claim Amount: $newClaimText\n- Invoice: $newInvoiceText\n\n"
    . "IP Address: $ip\nTime: $time";

logActivity($mysqli, $username, $role, "EDIT CONTRACT TASK", $description);
crossroadSendContractNotification($notificationEmail, "A project task was updated", $contract, [
    "task_text" => $taskText,
    "task_start_date" => $taskStartDate,
    "task_end_date" => $taskEndDate,
    "remark" => $remark
]);
exit("success");
?>
