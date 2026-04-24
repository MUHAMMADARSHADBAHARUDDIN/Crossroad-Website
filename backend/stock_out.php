<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";

header('Content-Type: text/plain');

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$remark = isset($_POST['remark']) ? $mysqli->real_escape_string($_POST['remark']) : "";

$username = $_SESSION['username'] ?? '';
$role = $_SESSION['role'] ?? '';

if(!$id){
    exit("error");
}

// GET DATA FIRST
$check = $mysqli->query("SELECT * FROM asset_inventory WHERE no=$id");
$row = $check->fetch_assoc();

if(!$row){
    exit("error");
}

$allowedRoles = ["Administrator", "System Admin", "User (Technical)"];

if(!in_array($role, $allowedRoles)){
    exit("access_denied");
}

// INSERT INTO HISTORY
$mysqli->query("
INSERT INTO stock_out_history
(part_number, serial_number, location, remark, stock_out_by)
VALUES
(
    '".$row['part_number']."',
    '".$row['serial_number']."',
    '".$row['location']."',
    '$remark',
    '$username'
)
");

// ACTIVITY LOG
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

// DELETE FROM INVENTORY
$mysqli->query("DELETE FROM asset_inventory WHERE no=$id");

echo "success";
exit;
?>