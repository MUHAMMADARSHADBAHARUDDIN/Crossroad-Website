<?php
require_once "../includes/security.php";
startSecureSession();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/activity_log.php";
require_once "../includes/contract_task_schema.php";

if(!isset($_SESSION['username'])){
    exit("No session");
}

ensureContractTaskCompletionSchema($mysqli);

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

function toggleTaskBindParams($stmt, $types, $params){
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

if(toggleTaskColumnExists($mysqli, "contract_tasks", "task_text")){
    $textColumn = "task_text";
}
elseif(toggleTaskColumnExists($mysqli, "contract_tasks", "task_name")){
    $textColumn = "task_name";
}
elseif(toggleTaskColumnExists($mysqli, "contract_tasks", "title")){
    $textColumn = "title";
}
elseif(toggleTaskColumnExists($mysqli, "contract_tasks", "description")){
    $textColumn = "description";
}
else{
    $textColumn = "";
}

if(toggleTaskColumnExists($mysqli, "contract_tasks", "is_completed")){
    $oldStatusSql = "CASE WHEN is_completed = 1 THEN 'Completed' ELSE 'Pending' END AS old_status";
}
elseif(toggleTaskColumnExists($mysqli, "contract_tasks", "completed")){
    $oldStatusSql = "CASE WHEN completed = 1 THEN 'Completed' ELSE 'Pending' END AS old_status";
}
elseif(toggleTaskColumnExists($mysqli, "contract_tasks", "status")){
    $oldStatusSql = "status AS old_status";
}
else{
    $oldStatusSql = "'Pending' AS old_status";
}

$taskTextSelect = $textColumn !== "" ? "`$textColumn` AS task_text" : "'' AS task_text";
$taskDateSelect = toggleTaskColumnExists($mysqli, "contract_tasks", "task_start_date")
    ? ", task_start_date"
    : ", NULL AS task_start_date";
$completedBySelect = toggleTaskColumnExists($mysqli, "contract_tasks", "completed_by")
    ? ", completed_by"
    : ", '' AS completed_by";
$completedAtSelect = toggleTaskColumnExists($mysqli, "contract_tasks", "completed_at")
    ? ", completed_at"
    : ", NULL AS completed_at";

$taskStmt = $mysqli->prepare("
    SELECT contract_id, $taskTextSelect, $oldStatusSql $taskDateSelect $completedBySelect $completedAtSelect
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
$oldStatus = $task['old_status'] ?? "Pending";

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

$newStatus = $isCompleted === 1 ? "Completed" : "Pending";
$assignments = [];
$types = "";
$params = [];

if(toggleTaskColumnExists($mysqli, "contract_tasks", "is_completed")){
    $value = $isCompleted === 1 ? 1 : 0;
    $assignments[] = "is_completed = ?";
    $types .= "i";
    $params[] = $value;
}
elseif(toggleTaskColumnExists($mysqli, "contract_tasks", "completed")){
    $value = $isCompleted === 1 ? 1 : 0;
    $assignments[] = "completed = ?";
    $types .= "i";
    $params[] = $value;
}
elseif(toggleTaskColumnExists($mysqli, "contract_tasks", "status")){
    $value = $isCompleted === 1 ? "Completed" : "Pending";
    $assignments[] = "status = ?";
    $types .= "s";
    $params[] = $value;
}
else{
    exit("No completion column found. Add is_completed column.");
}

if(toggleTaskColumnExists($mysqli, "contract_tasks", "completed_by")){
    $completedByValue = $isCompleted === 1 ? ($_SESSION['username'] ?? "") : null;
    $assignments[] = "completed_by = ?";
    $types .= "s";
    $params[] = $completedByValue;
}

if(toggleTaskColumnExists($mysqli, "contract_tasks", "completed_at")){
    $assignments[] = $isCompleted === 1
        ? "completed_at = NOW()"
        : "completed_at = NULL";
}

$types .= "i";
$params[] = $id;

$stmt = $mysqli->prepare("
    UPDATE contract_tasks
    SET " . implode(", ", $assignments) . "
    WHERE `$idColumn` = ?
");

if(!$stmt){
    exit("SQL Error: " . $mysqli->error);
}

toggleTaskBindParams($stmt, $types, $params);

if($stmt->execute()){

    $username = $_SESSION['username'];
    $role = $_SESSION['role'] ?? "UNKNOWN";
    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");
    $taskStartDate = (string)($task['task_start_date'] ?? "");
    $hasAssignedDate = $taskStartDate !== "" && $taskStartDate !== "0000-00-00";
    $completionNote = $isCompleted === 1
        ? ($hasAssignedDate ? "Assigned task date kept unchanged." : "Completion date/time was taken from the tick time.")
        : "Completion metadata cleared.";

    $actionType = $isCompleted === 1
        ? "COMPLETE CONTRACT TASK"
        : "CANCEL COMPLETE CONTRACT TASK";

    $actionText = $isCompleted === 1
        ? "completed a contract task"
        : "cancelled completion of a contract task";

    $description = "User [$username] $actionText.
Contract ID: $contractId
Contract No: $contractNo
Project Name: $projectName
Task ID: $id
Task: $taskText

OLD DATA:
- Status: $oldStatus

NEW DATA:
- Status: $newStatus
- Ticked By: " . ($isCompleted === 1 ? $username : "-") . "
- Completion Note: $completionNote

IP Address: $ip
Time: $time";

    logActivity(
        $mysqli,
        $username,
        $role,
        $actionType,
        $description
    );

    exit("success");
}

exit("Failed to update task.");
