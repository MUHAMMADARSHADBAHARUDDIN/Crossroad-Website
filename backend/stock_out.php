<?php
session_start();
require_once "../includes/db_connect.php";

$id = $_POST['id'];
$remark = $_POST['remark'];
$username = $_SESSION['username'];
$role = $_SESSION['role'];

// GET DATA FIRST
$check = $mysqli->query("SELECT * FROM asset_inventory WHERE no=$id");
$row = $check->fetch_assoc();

// PERMISSION
if($role == "User (Technical)" && $row['created_by'] != $username){
    die("Access denied");
}

// INSERT INTO HISTORY (IMPORTANT)
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
// INSERT INTO ACTIVITY LOG
$mysqli->query("
INSERT INTO activity_logs
(username, role, action_type, description)
VALUES
(
    '$username',
    '$role',
    'Stock Out',
    'Stocked out asset: Part Number ".$row['part_number'].", Serial Number ".$row['serial_number'].", Location ".$row['location'].", Remark: $remark'
)
");
// DELETE FROM INVENTORY
$mysqli->query("DELETE FROM asset_inventory WHERE no=$id");

echo "success";
?>