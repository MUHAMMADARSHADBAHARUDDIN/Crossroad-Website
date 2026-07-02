<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";
require_once "../includes/inventory_report_schema.php";

header("Content-Type: text/plain");

if(!isset($_SESSION['username'])){
    exit("No session");
}

if(!hasPermission($mysqli, "inventory_delete")){
    exit("Access denied");
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if($id <= 0){
    exit("Invalid ID");
}

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "UNKNOWN";
ensureInventoryReportSchema($mysqli);

$stmt = $mysqli->prepare("
SELECT *
FROM server_inventory
WHERE no = ?
LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if(!$row){
    exit("Server not found");
}

$mysqli->begin_transaction();

$deleteComponentStmt = $mysqli->prepare("
DELETE FROM server_components
WHERE server_inventory_id = ?
");

if(!$deleteComponentStmt){
    $mysqli->rollback();
    exit("SQL Error: " . $mysqli->error);
}

$deleteComponentStmt->bind_param("i", $id);

if(!$deleteComponentStmt->execute()){
    $mysqli->rollback();
    exit("Component cleanup failed: " . $deleteComponentStmt->error);
}

$deleteStmt = $mysqli->prepare("
DELETE FROM server_inventory
WHERE no = ?
");
$deleteStmt->bind_param("i", $id);

if($deleteStmt->execute()){
    $mysqli->commit();

    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $description = "User [$username] deleted server directly.
Server Name: {$row['server_name']}
Machine Type: {$row['machine_type']}
Serial Number: {$row['serial_number']}
Location: {$row['location']}
Received By: {$row['received_by']}
Deleted Without Stock Out: YES
IP Address: $ip
Time: $time";

    logActivity(
        $mysqli,
        $username,
        $role,
        "DELETE SERVER",
        $description
    );

    echo "success";
    exit();
}

$mysqli->rollback();
echo "Delete failed: " . $mysqli->error;
?>
