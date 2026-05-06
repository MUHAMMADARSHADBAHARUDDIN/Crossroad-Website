<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username'])){
    exit("No session");
}

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

$contractId = isset($_POST['contract_id']) ? (int)$_POST['contract_id'] : 0;
$taskText = trim($_POST['task_text'] ?? "");

if($contractId <= 0){
    exit("Invalid contract.");
}

if($taskText === ""){
    exit("Task cannot be empty.");
}

if(!addTaskTableExists($mysqli, "contract_tasks")){
    exit("contract_tasks table not found.");
}

if(!addTaskColumnExists($mysqli, "contract_tasks", "contract_id")){
    exit("contract_id column not found.");
}

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

/* ✅ FIXED: use Task Add permission */
if(!hasContractTaskAddAccess($mysqli, $createdBy)){
    exit("Access denied. You do not have Task Add permission.");
}

if(addTaskColumnExists($mysqli, "contract_tasks", "task_text")){
    $textColumn = "task_text";
}
elseif(addTaskColumnExists($mysqli, "contract_tasks", "task_name")){
    $textColumn = "task_name";
}
elseif(addTaskColumnExists($mysqli, "contract_tasks", "title")){
    $textColumn = "title";
}
elseif(addTaskColumnExists($mysqli, "contract_tasks", "description")){
    $textColumn = "description";
}
else{
    exit("Task text column not found.");
}

$columns = ["contract_id", $textColumn];
$placeholders = ["?", "?"];
$types = "is";
$params = [$contractId, $taskText];

if(addTaskColumnExists($mysqli, "contract_tasks", "is_completed")){
    $columns[] = "is_completed";
    $placeholders[] = "?";
    $types .= "i";
    $params[] = 0;
}
elseif(addTaskColumnExists($mysqli, "contract_tasks", "completed")){
    $columns[] = "completed";
    $placeholders[] = "?";
    $types .= "i";
    $params[] = 0;
}
elseif(addTaskColumnExists($mysqli, "contract_tasks", "status")){
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

$sql = "
    INSERT INTO contract_tasks (`" . implode("`, `", $columns) . "`)
    VALUES (" . implode(", ", $placeholders) . ")
";

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

if($stmt->execute()){
    exit("success");
}

exit("Failed to add task.");