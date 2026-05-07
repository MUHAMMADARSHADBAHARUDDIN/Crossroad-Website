<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/activity_log.php";

if(!isset($_SESSION['username'])){
    exit("No session");
}

function deleteTaskTableExists($mysqli, $tableName){
    $tableName = $mysqli->real_escape_string($tableName);
    $result = $mysqli->query("SHOW TABLES LIKE '$tableName'");
    return ($result && $result->num_rows > 0);
}

function deleteTaskColumnExists($mysqli, $tableName, $columnName){
    $tableName = str_replace("`", "", $tableName);
    $columnName = $mysqli->real_escape_string($columnName);

    $result = $mysqli->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
    return ($result && $result->num_rows > 0);
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if($id <= 0){
    exit("Invalid task.");
}

if(!deleteTaskTableExists($mysqli, "contract_tasks")){
    exit("contract_tasks table not found.");
}

$idColumn = deleteTaskColumnExists($mysqli, "contract_tasks", "id") ? "id" : "no";

if(!deleteTaskColumnExists($mysqli, "contract_tasks", $idColumn)){
    exit("Task ID column not found.");
}

if(!deleteTaskColumnExists($mysqli, "contract_tasks", "contract_id")){
    exit("contract_id column not found.");
}

if(deleteTaskColumnExists($mysqli, "contract_tasks", "task_text")){
    $textColumn = "task_text";
}
elseif(deleteTaskColumnExists($mysqli, "contract_tasks", "task_name")){
    $textColumn = "task_name";
}
elseif(deleteTaskColumnExists($mysqli, "contract_tasks", "title")){
    $textColumn = "title";
}
elseif(deleteTaskColumnExists($mysqli, "contract_tasks", "description")){
    $textColumn = "description";
}
else{
    $textColumn = "";
}

if(deleteTaskColumnExists($mysqli, "contract_tasks", "is_completed")){
    $statusSql = "CASE WHEN is_completed = 1 THEN 'Completed' ELSE 'Pending' END AS task_status";
}
elseif(deleteTaskColumnExists($mysqli, "contract_tasks", "completed")){
    $statusSql = "CASE WHEN completed = 1 THEN 'Completed' ELSE 'Pending' END AS task_status";
}
elseif(deleteTaskColumnExists($mysqli, "contract_tasks", "status")){
    $statusSql = "status AS task_status";
}
else{
    $statusSql = "'Pending' AS task_status";
}

$taskTextSelect = $textColumn !== "" ? "`$textColumn` AS task_text" : "'' AS task_text";

$taskStmt = $mysqli->prepare("
    SELECT contract_id, $taskTextSelect, $statusSql
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
$taskText = $task['task_text'] ?? "";
$taskStatus = $task['task_status'] ?? "Pending";

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

if(!hasContractTaskDeleteAccess($mysqli, $createdBy)){
    exit("Access denied. You do not have Task Delete permission.");
}

$stmt = $mysqli->prepare("
    DELETE FROM contract_tasks
    WHERE `$idColumn` = ?
");

if(!$stmt){
    exit("SQL Error: " . $mysqli->error);
}

$stmt->bind_param("i", $id);

if($stmt->execute()){

    $username = $_SESSION['username'];
    $role = $_SESSION['role'] ?? "UNKNOWN";
    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $description = "User [$username] deleted a contract task.
Contract ID: $contractId
Contract No: $contractNo
Project Name: $projectName
Task ID: $id
Task: $taskText
Status: $taskStatus
IP Address: $ip
Time: $time";

    logActivity(
        $mysqli,
        $username,
        $role,
        "DELETE CONTRACT TASK",
        $description
    );

    exit("success");
}

exit("Failed to delete task.");