<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username'])){
    exit("No session");
}

function toggleTaskTableExists($mysqli, $tableName){
    $tableName = $mysqli->real_escape_string($tableName);
    $result = $mysqli->query("SHOW TABLES LIKE '$tableName'");
    return ($result && $result->num_rows > 0);
}

function toggleTaskColumnExists($mysqli, $tableName, $columnName){
    $tableName = str_replace("`", "", $tableName);
    $columnName = $mysqli->real_escape_string($columnName);

    $result = $mysqli->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
    return ($result && $result->num_rows > 0);
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$isCompleted = isset($_POST['is_completed']) ? (int)$_POST['is_completed'] : 0;

if($id <= 0){
    exit("Invalid task.");
}

if(!toggleTaskTableExists($mysqli, "contract_tasks")){
    exit("contract_tasks table not found.");
}

$idColumn = toggleTaskColumnExists($mysqli, "contract_tasks", "id") ? "id" : "no";

if(!toggleTaskColumnExists($mysqli, "contract_tasks", $idColumn)){
    exit("Task ID column not found.");
}

if(!toggleTaskColumnExists($mysqli, "contract_tasks", "contract_id")){
    exit("contract_id column not found.");
}

$taskStmt = $mysqli->prepare("
    SELECT contract_id
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

$contractStmt = $mysqli->prepare("
    SELECT created_by
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

/* ✅ FIXED: checkbox tick is considered Task Edit permission */
if(!hasContractTaskEditAccess($mysqli, $createdBy)){
    exit("Access denied. You do not have Task Edit permission.");
}

if(toggleTaskColumnExists($mysqli, "contract_tasks", "is_completed")){
    $value = $isCompleted === 1 ? 1 : 0;

    $stmt = $mysqli->prepare("
        UPDATE contract_tasks
        SET is_completed = ?
        WHERE `$idColumn` = ?
    ");

    if(!$stmt){
        exit("SQL Error: " . $mysqli->error);
    }

    $stmt->bind_param("ii", $value, $id);
}
elseif(toggleTaskColumnExists($mysqli, "contract_tasks", "completed")){
    $value = $isCompleted === 1 ? 1 : 0;

    $stmt = $mysqli->prepare("
        UPDATE contract_tasks
        SET completed = ?
        WHERE `$idColumn` = ?
    ");

    if(!$stmt){
        exit("SQL Error: " . $mysqli->error);
    }

    $stmt->bind_param("ii", $value, $id);
}
elseif(toggleTaskColumnExists($mysqli, "contract_tasks", "status")){
    $value = $isCompleted === 1 ? "Completed" : "Pending";

    $stmt = $mysqli->prepare("
        UPDATE contract_tasks
        SET status = ?
        WHERE `$idColumn` = ?
    ");

    if(!$stmt){
        exit("SQL Error: " . $mysqli->error);
    }

    $stmt->bind_param("si", $value, $id);
}
else{
    exit("No completion column found. Add is_completed column.");
}

if($stmt->execute()){
    exit("success");
}

exit("Failed to update task.");