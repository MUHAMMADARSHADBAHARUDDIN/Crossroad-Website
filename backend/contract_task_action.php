<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username'])){
    exit("No session");
}

if(!hasContractViewAccess($mysqli)){
    exit("Access denied");
}

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "UNKNOWN";

function taskEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function canManageContractTask($mysqli){
    if(function_exists("hasContractTaskAccess")){
        return hasContractTaskAccess($mysqli);
    }

    return (
        hasPermission($mysqli, "contracts_full") ||
        hasPermission($mysqli, "contracts_task")
    );
}

$canTask = canManageContractTask($mysqli);

$action = $_POST['action'] ?? "";

if($action === ""){
    exit("Invalid action");
}

/* =========================
   LIST TASKS
========================= */
if($action === "list"){

    $contract_id = isset($_POST['contract_id']) ? (int)$_POST['contract_id'] : 0;

    if($contract_id <= 0){
        exit("<div class='text-danger'>Invalid contract.</div>");
    }

    $stmt = $mysqli->prepare("
        SELECT id, task_text, is_done, created_by, created_at
        FROM contract_tasks
        WHERE contract_id = ?
        ORDER BY id ASC
    ");

    if(!$stmt){
        exit("<div class='text-danger'>SQL Error: " . taskEscape($mysqli->error) . "</div>");
    }

    $stmt->bind_param("i", $contract_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if(!$result || $result->num_rows === 0){
        echo "
        <div class='alert alert-light border mb-0'>
            <i class='fa fa-circle-info'></i> No task available.
        </div>
        ";
        exit();
    }

    echo "<div class='task-list'>";

    while($row = $result->fetch_assoc()){

        $checked = ((int)$row['is_done'] === 1) ? "checked" : "";
        $doneClass = ((int)$row['is_done'] === 1) ? "task-done" : "";
        $disabled = $canTask ? "" : "disabled";

        echo "
        <div class='task-item $doneClass'>
            <div class='task-left'>
                <input
                    type='checkbox'
                    class='form-check-input'
                    onchange='toggleContractTask(" . (int)$row['id'] . ", this.checked)'
                    $checked
                    $disabled
                >

                <div>
                    <div class='task-text'>" . taskEscape($row['task_text']) . "</div>
                    <small class='text-muted'>
                        Added by " . taskEscape($row['created_by']) . "
                    </small>
                </div>
            </div>
        ";

        if($canTask){
            echo "
            <button
                type='button'
                class='btn btn-sm btn-outline-danger'
                onclick='deleteContractTask(" . (int)$row['id'] . ")'>
                <i class='fa fa-trash'></i>
            </button>
            ";
        }

        echo "</div>";
    }

    echo "</div>";
    exit();
}

/* From here, task permission is required */
if(!$canTask){
    exit("Access denied");
}

/* =========================
   ADD TASK
========================= */
if($action === "add"){

    $contract_id = isset($_POST['contract_id']) ? (int)$_POST['contract_id'] : 0;
    $task_text = trim($_POST['task_text'] ?? "");

    if($contract_id <= 0){
        exit("Invalid contract.");
    }

    if($task_text === ""){
        exit("Task cannot be empty.");
    }

    $checkStmt = $mysqli->prepare("
        SELECT no, project_name
        FROM project_inventory
        WHERE no = ?
        LIMIT 1
    ");

    if(!$checkStmt){
        exit("SQL Error: " . $mysqli->error);
    }

    $checkStmt->bind_param("i", $contract_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $contractData = $checkResult->fetch_assoc();

    if(!$contractData){
        exit("Contract not found.");
    }

    $stmt = $mysqli->prepare("
        INSERT INTO contract_tasks
        (contract_id, task_text, is_done, created_by)
        VALUES (?, ?, 0, ?)
    ");

    if(!$stmt){
        exit("SQL Error: " . $mysqli->error);
    }

    $stmt->bind_param("iss", $contract_id, $task_text, $username);

    if(!$stmt->execute()){
        exit("Insert failed: " . $stmt->error);
    }

    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $description = "User [$username] added contract task.
Contract ID: $contract_id
Project Name: {$contractData['project_name']}
Task: $task_text
IP Address: $ip
Time: $time";

    logActivity($mysqli, $username, $role, "ADD CONTRACT TASK", $description);

    exit("success");
}

/* =========================
   TOGGLE TASK
========================= */
if($action === "toggle"){

    $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
    $is_done = isset($_POST['is_done']) ? (int)$_POST['is_done'] : 0;

    $is_done = $is_done === 1 ? 1 : 0;

    if($task_id <= 0){
        exit("Invalid task.");
    }

    $checkStmt = $mysqli->prepare("
        SELECT ct.id, ct.task_text, ct.contract_id, pi.project_name
        FROM contract_tasks ct
        LEFT JOIN project_inventory pi ON pi.no = ct.contract_id
        WHERE ct.id = ?
        LIMIT 1
    ");

    if(!$checkStmt){
        exit("SQL Error: " . $mysqli->error);
    }

    $checkStmt->bind_param("i", $task_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $taskData = $checkResult->fetch_assoc();

    if(!$taskData){
        exit("Task not found.");
    }

    $stmt = $mysqli->prepare("
        UPDATE contract_tasks
        SET is_done = ?
        WHERE id = ?
    ");

    if(!$stmt){
        exit("SQL Error: " . $mysqli->error);
    }

    $stmt->bind_param("ii", $is_done, $task_id);

    if(!$stmt->execute()){
        exit("Update failed: " . $stmt->error);
    }

    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");
    $statusText = $is_done ? "Completed" : "Not Completed";

    $description = "User [$username] updated contract task status.
Contract ID: {$taskData['contract_id']}
Project Name: {$taskData['project_name']}
Task: {$taskData['task_text']}
Status: $statusText
IP Address: $ip
Time: $time";

    logActivity($mysqli, $username, $role, "UPDATE CONTRACT TASK", $description);

    exit("success");
}

/* =========================
   DELETE TASK
========================= */
if($action === "delete"){

    $task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;

    if($task_id <= 0){
        exit("Invalid task.");
    }

    $checkStmt = $mysqli->prepare("
        SELECT ct.id, ct.task_text, ct.contract_id, pi.project_name
        FROM contract_tasks ct
        LEFT JOIN project_inventory pi ON pi.no = ct.contract_id
        WHERE ct.id = ?
        LIMIT 1
    ");

    if(!$checkStmt){
        exit("SQL Error: " . $mysqli->error);
    }

    $checkStmt->bind_param("i", $task_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $taskData = $checkResult->fetch_assoc();

    if(!$taskData){
        exit("Task not found.");
    }

    $stmt = $mysqli->prepare("
        DELETE FROM contract_tasks
        WHERE id = ?
    ");

    if(!$stmt){
        exit("SQL Error: " . $mysqli->error);
    }

    $stmt->bind_param("i", $task_id);

    if(!$stmt->execute()){
        exit("Delete failed: " . $stmt->error);
    }

    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $description = "User [$username] deleted contract task.
Contract ID: {$taskData['contract_id']}
Project Name: {$taskData['project_name']}
Deleted Task: {$taskData['task_text']}
IP Address: $ip
Time: $time";

    logActivity($mysqli, $username, $role, "DELETE CONTRACT TASK", $description);

    exit("success");
}

exit("Invalid action");
?>