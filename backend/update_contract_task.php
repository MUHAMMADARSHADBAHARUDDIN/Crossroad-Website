<?php
require_once "../includes/security.php";
startSecureSession();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/activity_log.php";

header("Content-Type: text/plain; charset=UTF-8");

if(!isset($_SESSION['username'])){
    exit("No session");
}

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
$taskStartDate = trim($_POST['task_start_date'] ?? "");
$taskEndDate = trim($_POST['task_end_date'] ?? "");

if($id <= 0){ exit("Invalid task."); }
if($taskText === ""){ exit("Task cannot be empty."); }
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
$dateSelect = $hasTaskDates ? ", task_start_date, task_end_date" : "";

$taskStmt = $mysqli->prepare("
    SELECT contract_id, `$textColumn` AS old_task_text $dateSelect
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
$contractStmt = $mysqli->prepare("SELECT created_by, project_name, contract_no FROM project_inventory WHERE no = ? LIMIT 1");
if(!$contractStmt){ exit("SQL Error: " . $mysqli->error); }
$contractStmt->bind_param("i", $contractId);
$contractStmt->execute();
$contract = $contractStmt->get_result()->fetch_assoc();
if(!$contract){ exit("Contract not found."); }

if(!hasContractTaskEditAccess($mysqli, $contract['created_by'] ?? "")){
    exit("Access denied. You do not have Task Edit permission.");
}

if(!$hasTaskDates && $taskStartDate !== ""){
    exit("Please run upgrade_pm_stockout.sql first before assigning task dates.");
}

if($hasTaskDates){
    $startValue = $taskStartDate !== "" ? $taskStartDate : null;
    $endValue = $taskEndDate !== "" ? $taskEndDate : null;
    $stmt = $mysqli->prepare("
        UPDATE contract_tasks
        SET `$textColumn` = ?, task_start_date = ?, task_end_date = ?
        WHERE `$idColumn` = ?
    ");
    if(!$stmt){ exit("SQL Error: " . $mysqli->error); }
    $stmt->bind_param("sssi", $taskText, $startValue, $endValue, $id);
}else{
    $stmt = $mysqli->prepare("UPDATE contract_tasks SET `$textColumn` = ? WHERE `$idColumn` = ?");
    if(!$stmt){ exit("SQL Error: " . $mysqli->error); }
    $stmt->bind_param("si", $taskText, $id);
}

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

$description = "User [$username] edited a contract task.\n"
    . "Contract ID: $contractId\n"
    . "Contract No: " . ($contract['contract_no'] ?? "") . "\n"
    . "Project Name: " . ($contract['project_name'] ?? "") . "\n"
    . "Task ID: $id\n\n"
    . "OLD DATA:\n- Task: " . ($task['old_task_text'] ?? "") . "\n- Task Date: $oldDateText\n\n"
    . "NEW DATA:\n- Task: $taskText\n- Task Date: $newDateText\n\n"
    . "IP Address: $ip\nTime: $time";

logActivity($mysqli, $username, $role, "EDIT CONTRACT TASK", $description);
exit("success");
?>
