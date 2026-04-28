<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

header('Content-Type: text/plain');

if(!isset($_SESSION['username'])){
    exit("No session");
}

if(!hasPermission($mysqli, "inventory_stockout")){
    exit("access_denied");
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$remark = isset($_POST['remark']) ? trim($_POST['remark']) : "";

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "UNKNOWN";

if(!$id){
    exit("error");
}

$stmt = $mysqli->prepare("
SELECT *
FROM asset_inventory
WHERE no = ?
LIMIT 1
");

$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if(!$row){
    exit("error");
}

$insertStmt = $mysqli->prepare("
INSERT INTO stock_out_history
(part_number, serial_number, location, remark, stock_out_by)
VALUES (?, ?, ?, ?, ?)
");

$insertStmt->bind_param(
    "sssss",
    $row['part_number'],
    $row['serial_number'],
    $row['location'],
    $remark,
    $username
);

$insertStmt->execute();

$ip = $_SERVER['REMOTE_ADDR'];
$time = date("Y-m-d H:i:s");

$description = "User [$username] performed STOCK OUT on asset.
Part Number: {$row['part_number']}
Serial Number: {$row['serial_number']}
Location: {$row['location']}
Remark: $remark
IP Address: $ip
Time: $time";

logActivity(
    $mysqli,
    $username,
    $role,
    "STOCK OUT ASSET",
    $description
);

$deleteStmt = $mysqli->prepare("
DELETE FROM asset_inventory
WHERE no = ?
");

$deleteStmt->bind_param("i", $id);
$deleteStmt->execute();

echo "success";
exit;
?>