<?php
session_start();
require_once "../includes/db_connect.php";

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
$mysqli->query("
INSERT INTO activity_logs
(username, role, action_type, description)
VALUES
(
    '$username',
    '$role',
    'Stock Out',
    'Stocked out asset: Part ".$row['part_number']." Serial ".$row['serial_number']." Location ".$row['location']." Remark: $remark'
)
");

// DELETE FROM INVENTORY
$mysqli->query("DELETE FROM asset_inventory WHERE no=$id");

echo "success";
exit;
?>