<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/activity_log.php";

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

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$taskText = trim($_POST['task_text'] ?? "");

if($id <= 0){
    exit("Invalid task.");
}

if($taskText === ""){
    exit("Task cannot be empty.");
}

if(!updateTaskTableExists($mysqli, "contract_tasks")){
    exit("contract_tasks table not found.");
}

$idColumn = updateTaskColumnExists($mysqli, "contract_tasks", "id") ? "id" : "no";

if(!updateTaskColumnExists($mysqli, "contract_tasks", $idColumn)){
    exit("Task ID column not found.");
}

if(!updateTaskColumnExists($mysqli, "contract_tasks", "contract_id")){
    exit("contract_id column not found.");
}

if(updateTaskColumnExists($mysqli, "contract_tasks", "task_text")){
    $textColumn = "task_text";
}
elseif(updateTaskColumnExists($mysqli, "contract_tasks", "task_name")){
    $textColumn = "task_name";
}
elseif(updateTaskColumnExists($mysqli, "contract_tasks", "title")){
    $textColumn = "title";
}
elseif(updateTaskColumnExists($mysqli, "contract_tasks", "description")){
    $textColumn = "description";
}
else{
    exit("Task text column not found.");
}

$taskStmt = $mysqli->prepare("
    SELECT contract_id, `$textColumn` AS old_task_text
    FROM contract_tasks
    WHERE `$idColumn` = ?
    LIMIT 1
");

if(!$taskStmt){
    exit("SQL Error: " . $mysqli->error);
}

$taskStmt->bind_param("i", $id);
$taskStmt->execute();
$taskResult = $taskStmt->get_result();

if($taskResult->num_rows <= 0){
    exit("Task not found.");
}

$task = $taskResult->fetch_assoc();
$contractId = (int)$task['contract_id'];
$oldTaskText = $task['old_task_text'] ?? "";

$contractStmt = $mysqli->prepare("
    SELECT created_by, project_name, contract_no
    FROM project_inventory
    WHERE no = ?
    LIMIT 1
");

if(!$contractStmt){
    exit("SQL Error: " . $mysqli->error);
}

$contractStmt->bind_param("i", $contractId);
$contractStmt->execute();
$contractResult = $contractStmt->get_result();

if($contractResult->num_rows <= 0){
    exit("Contract not found.");
}

$contract = $contractResult->fetch_assoc();
$createdBy = $contract['created_by'] ?? "";
$projectName = $contract['project_name'] ?? "";
$contractNo = $contract['contract_no'] ?? "";

if(!hasContractTaskEditAccess($mysqli, $createdBy)){
    exit("Access denied. You do not have Task Edit permission.");
}

$stmt = $mysqli->prepare("
    UPDATE contract_tasks
    SET `$textColumn` = ?
    WHERE `$idColumn` = ?
");

if(!$stmt){
    exit("SQL Error: " . $mysqli->error);
}

$stmt->bind_param("si", $taskText, $id);

if($stmt->execute()){

    $username = $_SESSION['username'];
    $role = $_SESSION['role'] ?? "UNKNOWN";
    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $description = "User [$username] updated a contract task.
Contract ID: $contractId
Contract No: $contractNo
Project Name: $projectName
Task ID: $id

OLD DATA:
- Task: $oldTaskText

NEW DATA:
- Task: $taskText

IP Address: $ip
Time: $time";

    logActivity(
        $mysqli,
        $username,
        $role,
        "UPDATE CONTRACT TASK",
        $description
    );

    exit("success");
}

exit("Failed to update task.");