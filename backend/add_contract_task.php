<?php
require_once "../includes/security.php";
startSecureSession();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/activity_log.php";
require_once "../includes/contract_task_documents.php";
require_once "../includes/contract_notifications.php";

header("Content-Type: text/plain; charset=UTF-8");

if(!isset($_SESSION['username'])){
    exit("No session");
}

ensureContractTaskCompletionSchema($mysqli);

function addTaskTableExists($mysqli, $tableName){
    $tableName = $mysqli->real_escape_string($tableName);
    $result = $mysqli->query("SHOW TABLES LIKE '$tableName'");
    return ($result && $result->num_rows > 0);
}

function addTaskColumnExists($mysqli, $tableName, $columnName){
    $tableName = str_replace("`", "", $tableName);
    $columnName = $mysqli->real_escape_string($columnName);
    $result = $mysqli->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
    return ($result && $result->num_rows > 0);
}

function validTaskDate($value){
    if($value === ""){
        return true;
    }
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

$contractId = isset($_POST['contract_id']) ? (int)$_POST['contract_id'] : 0;
$taskText = trim($_POST['task_text'] ?? "");
$taskType = trim($_POST['task_type'] ?? "");
$remark = trim((string)($_POST['remark'] ?? ""));
$notificationEmail = trim((string)($_POST['notification_email'] ?? ""));
$taskStartDate = trim($_POST['task_start_date'] ?? "");
$taskEndDate = trim($_POST['task_end_date'] ?? "");
$claimAmountRaw = trim((string)($_POST['claim_amount'] ?? ""));
$invoice = trim((string)($_POST['invoice'] ?? ""));
$claimAmount = null;

if($contractId <= 0){
    exit("Invalid contract.");
}

if($taskText === ""){
    exit("Task cannot be empty.");
}

$allowedTaskTypes = ["preventive", "kickoff", "training", "meeting", "corrective", "claim", "other"];
if(!in_array($taskType, $allowedTaskTypes, true)){
    exit("Invalid checklist type.");
}

if($taskType !== "claim"){
    $claimAmountRaw = "";
    $invoice = "";
}

if($taskType === "claim" && $invoice === ""){
    exit("Please enter the invoice.");
}

if($claimAmountRaw !== ""){
    $claimAmountRaw = str_replace([",", " "], "", $claimAmountRaw);

    if(!is_numeric($claimAmountRaw) || (float)$claimAmountRaw < 0){
        exit("Claim amount must be a positive number.");
    }

    $claimAmount = round((float)$claimAmountRaw, 2);
}

if(!validTaskDate($taskStartDate) || !validTaskDate($taskEndDate)){
    exit("Invalid task date.");
}

if($taskStartDate === "" && $taskEndDate !== ""){
    exit("Please select a task start date first.");
}

if($taskStartDate !== "" && $taskEndDate === ""){
    $taskEndDate = $taskStartDate;
}

if($taskStartDate !== "" && $taskEndDate < $taskStartDate){
    exit("Task end date cannot be before the start date.");
}

if(!addTaskTableExists($mysqli, "contract_tasks")){
    exit("contract_tasks table not found.");
}

if(!addTaskColumnExists($mysqli, "contract_tasks", "contract_id")){
    exit("contract_id column not found.");
}

$contractStmt = $mysqli->prepare("
    SELECT created_by, project_name, project_code, contract_no, contract_start, contract_end
    FROM project_inventory
    WHERE no = ?
    LIMIT 1
");

if(!$contractStmt){
    exit("SQL Error: " . $mysqli->error);
}

$contractStmt->bind_param("i", $contractId);
$contractStmt->execute();
$contract = $contractStmt->get_result()->fetch_assoc();

if(!$contract){
    exit("Contract not found.");
}

$createdBy = $contract['created_by'] ?? "";
if(!hasContractTaskAddAccess($mysqli, $createdBy)){
    exit("Access denied. You do not have Task Add permission.");
}

$canViewClaim = hasContractClaimViewAccess($mysqli);
if(!$canViewClaim && $claimAmountRaw !== ""){
    exit("Access denied. You do not have View Claim permission.");
}

$hasDocumentUpload = isset($_FILES['task_document']) && ($_FILES['task_document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

if($hasDocumentUpload && !hasContractTaskDocumentUploadAccess($mysqli, $createdBy)){
    exit("Access denied. You do not have checklist document upload permission.");
}

if($hasDocumentUpload){
    $uploadError = contractTaskDocumentValidateUpload($_FILES['task_document']);

    if($uploadError !== ""){
        exit($uploadError);
    }
}

if(addTaskColumnExists($mysqli, "contract_tasks", "task_text")){
    $textColumn = "task_text";
}elseif(addTaskColumnExists($mysqli, "contract_tasks", "task_name")){
    $textColumn = "task_name";
}elseif(addTaskColumnExists($mysqli, "contract_tasks", "title")){
    $textColumn = "title";
}elseif(addTaskColumnExists($mysqli, "contract_tasks", "description")){
    $textColumn = "description";
}else{
    exit("Task text column not found.");
}

$columns = ["contract_id", $textColumn];
$placeholders = ["?", "?"];
$types = "is";
$params = [$contractId, $taskText];

$hasTaskDates = addTaskColumnExists($mysqli, "contract_tasks", "task_start_date")
    && addTaskColumnExists($mysqli, "contract_tasks", "task_end_date");

if(!$hasTaskDates && $taskStartDate !== ""){
    exit("Please run upgrade_pm_stockout.sql first before assigning task dates.");
}

if($hasTaskDates){
    $startValue = $taskStartDate !== "" ? $taskStartDate : null;
    $endValue = $taskEndDate !== "" ? $taskEndDate : null;
    $columns[] = "task_start_date";
    $columns[] = "task_end_date";
    $placeholders[] = "?";
    $placeholders[] = "?";
    $types .= "ss";
    $params[] = $startValue;
    $params[] = $endValue;
}

if(addTaskColumnExists($mysqli, "contract_tasks", "is_completed")){
    $columns[] = "is_completed";
    $placeholders[] = "?";
    $types .= "i";
    $params[] = 0;
}elseif(addTaskColumnExists($mysqli, "contract_tasks", "completed")){
    $columns[] = "completed";
    $placeholders[] = "?";
    $types .= "i";
    $params[] = 0;
}elseif(addTaskColumnExists($mysqli, "contract_tasks", "status")){
    $columns[] = "status";
    $placeholders[] = "?";
    $types .= "s";
    $params[] = "Pending";
}

if(addTaskColumnExists($mysqli, "contract_tasks", "created_by")){
    $columns[] = "created_by";
    $placeholders[] = "?";
    $types .= "s";
    $params[] = $_SESSION['username'];
}

if(addTaskColumnExists($mysqli, "contract_tasks", "task_type")){
    $columns[] = "task_type";
    $placeholders[] = "?";
    $types .= "s";
    $params[] = $taskType;
}

if(addTaskColumnExists($mysqli, "contract_tasks", "remark")){
    $columns[] = "remark";
    $placeholders[] = "?";
    $types .= "s";
    $params[] = $remark !== "" ? $remark : null;
}

if(addTaskColumnExists($mysqli, "contract_tasks", "notification_email")){
    $columns[] = "notification_email";
    $placeholders[] = "?";
    $types .= "s";
    $params[] = $notificationEmail !== "" ? $notificationEmail : null;
}

if(addTaskColumnExists($mysqli, "contract_tasks", "claim_amount") && $canViewClaim){
    $columns[] = "claim_amount";
    $placeholders[] = "?";
    $types .= "d";
    $params[] = $claimAmount;
} elseif($claimAmount !== null){
    exit("claim_amount column not found.");
}

if(addTaskColumnExists($mysqli, "contract_tasks", "invoice")){
    $columns[] = "invoice";
    $placeholders[] = "?";
    $types .= "s";
    $params[] = $invoice !== "" ? $invoice : null;
} elseif($invoice !== ""){
    exit("invoice column not found.");
}

$sql = "INSERT INTO contract_tasks (`" . implode("`, `", $columns) . "`) VALUES (" . implode(", ", $placeholders) . ")";
$stmt = $mysqli->prepare($sql);

if(!$stmt){
    exit("SQL Error: " . $mysqli->error);
}

$refs = [];
foreach($params as $key => $value){
    $refs[$key] = &$params[$key];
}
array_unshift($refs, $types);
call_user_func_array([$stmt, 'bind_param'], $refs);

if(!$stmt->execute()){
    exit("Failed to add task: " . $stmt->error);
}

$newTaskId = $stmt->insert_id;
$uploadedDocumentName = "";

if($hasDocumentUpload){
    ensureContractTaskDocumentSchema($mysqli);

    $file = $_FILES['task_document'];
    $originalName = basename($file['name']);
    $storedName = contractTaskDocumentStoredFileName($originalName);
    $uploadDir = contractTaskDocumentEnsureUploadDir();
    $targetPath = $uploadDir . "/" . $storedName;

    if(!move_uploaded_file($file['tmp_name'], $targetPath)){
        exit("Failed to move uploaded file.");
    }

    $docStmt = $mysqli->prepare("
        INSERT INTO contract_task_documents (contract_id, task_id, file_name, original_file_name, uploaded_by)
        VALUES (?, ?, ?, ?, ?)
    ");

    if(!$docStmt){
        @unlink($targetPath);
        exit("SQL Error: " . $mysqli->error);
    }

    $uploadedBy = $_SESSION['username'];
    $docStmt->bind_param("iisss", $contractId, $newTaskId, $storedName, $originalName, $uploadedBy);

    if(!$docStmt->execute()){
        @unlink($targetPath);
        exit("Failed to save checklist document: " . $docStmt->error);
    }

    $uploadedDocumentName = $originalName;
}

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "UNKNOWN";
$ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
$time = date("Y-m-d H:i:s");
$dateText = $taskStartDate === "" ? "Not Assigned" : ($taskStartDate === $taskEndDate ? $taskStartDate : "$taskStartDate to $taskEndDate");

$description = "User [$username] added a contract task.\n"
    . "Contract ID: $contractId\n"
    . "Contract No: " . ($contract['contract_no'] ?? "") . "\n"
    . "Project Name: " . ($contract['project_name'] ?? "") . "\n"
    . "Task ID: $newTaskId\n"
    . "Checklist Type: $taskType\n"
    . "Task: $taskText\n"
    . "Remark: " . ($remark === "" ? "Not Assigned" : $remark) . "\n"
    . "Task Date: $dateText\n"
    . "Claim Amount: " . ($claimAmount === null ? "Not Assigned" : number_format($claimAmount, 2)) . "\n"
    . "Invoice: " . ($invoice === "" ? "Not Assigned" : $invoice) . "\n"
    . ($uploadedDocumentName !== "" ? "Attached Document: $uploadedDocumentName\n" : "")
    . "Status: Pending\n"
    . "IP Address: $ip\n"
    . "Time: $time";

logActivity($mysqli, $username, $role, "ADD CONTRACT TASK", $description);
crossroadSendContractNotification($notificationEmail, "A project task was added", $contract, [
    "task_text" => $taskText,
    "task_start_date" => $taskStartDate,
    "task_end_date" => $taskEndDate,
    "remark" => $remark
]);
exit("success");
?>
