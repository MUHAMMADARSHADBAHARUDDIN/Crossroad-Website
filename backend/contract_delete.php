<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username'])){
    exit("No session");
}

if(!isset($_GET['id'])){
    exit("Invalid request");
}

$id = intval($_GET['id']);

$role = $_SESSION['role'] ?? "UNKNOWN";
$username = $_SESSION['username'];

/* GET CONTRACT DATA */
$stmt = $mysqli->prepare("
    SELECT project_name, project_owner, project_manager, account_manager, created_by
    FROM project_inventory
    WHERE no = ?
");

if(!$stmt){
    exit("Prepare failed: " . $mysqli->error);
}

$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();

if(!$data){
    exit("Contract not found");
}

/* OWNER */
$created_by = $data['created_by'];
$project_name = $data['project_name'];
$project_owner = $data['project_owner'];
$project_manager = $data['project_manager'] ?? '';
$account_manager = $data['account_manager'] ?? '';

if(!hasContractDeleteAccess($mysqli, $created_by)){
    exit("Access denied");
}

/* DELETE TASKS FIRST */
$deleteTaskStmt = $mysqli->prepare("
    DELETE FROM contract_tasks
    WHERE contract_id = ?
");

if($deleteTaskStmt){
    $deleteTaskStmt->bind_param("i", $id);
    $deleteTaskStmt->execute();
}

/* DELETE CONTRACT */
$deleteStmt = $mysqli->prepare("DELETE FROM project_inventory WHERE no = ?");

if(!$deleteStmt){
    exit("Prepare failed: " . $mysqli->error);
}

$deleteStmt->bind_param("i", $id);

if($deleteStmt->execute()){

    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $description = "User [$username] deleted contract.
Project Name: $project_name
Project Owner: $project_owner
Project Manager: $project_manager
Account Manager: $account_manager
Contract ID: $id
Related checklist tasks were also deleted.
IP Address: $ip
Time: $time";

    logActivity(
        $mysqli,
        $username,
        $role,
        "DELETE CONTRACT",
        $description
    );

    header("Location: ../frontend/contracts.php");
    exit();

} else {
    echo "Delete failed: " . $mysqli->error;
}
?>