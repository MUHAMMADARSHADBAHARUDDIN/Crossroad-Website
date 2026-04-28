<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

header("Content-Type: text/plain");

if(!isset($_SESSION['username'])){
    exit("No session");
}

if(!hasPermission($mysqli, "inventory_stockout")){
    exit("Access denied");
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$stockout_remark = isset($_POST['remark']) ? trim($_POST['remark']) : '';

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "UNKNOWN";

if($id <= 0){
    exit("Invalid ID");
}

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
    exit("Record not found");
}

$insertStmt = $mysqli->prepare("
INSERT INTO server_stockout_history
(server_name, machine_type, serial_number, location, status, remark, tester, stock_out_by)
VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

$insertStmt->bind_param(
    "ssssssss",
    $row['server_name'],
    $row['machine_type'],
    $row['serial_number'],
    $row['location'],
    $row['status'],
    $stockout_remark,
    $row['tester'],
    $username
);

$insertStmt->execute();

$ip = $_SERVER['REMOTE_ADDR'];
$time = date("Y-m-d H:i:s");

$description = "User [$username] performed STOCK OUT on server.
Server Name: {$row['server_name']}
Machine Type: {$row['machine_type']}
Serial Number: {$row['serial_number']}
Location: {$row['location']}
Remark: $stockout_remark
IP Address: $ip
Time: $time";

logActivity(
    $mysqli,
    $username,
    $role,
    "STOCK OUT SERVER",
    $description
);

$deleteStmt = $mysqli->prepare("
DELETE FROM server_inventory
WHERE no = ?
");

$deleteStmt->bind_param("i", $id);
$deleteStmt->execute();

echo "success";
?>