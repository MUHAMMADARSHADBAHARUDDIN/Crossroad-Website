<?php
session_start();
require_once "../includes/db_connect.php";

$id = $_POST['id'];

// 🔥 FIX: always define this safely
$stockout_remark = isset($_POST['remark']) ? $_POST['remark'] : '';

$username = $_SESSION['username'];
$role = $_SESSION['role'];

$allowedRoles = ["Administrator", "System Admin", "User (Technical)"];

if(!in_array($role, $allowedRoles)){
    die("Access denied");
}

// GET DATA FIRST
$check = $mysqli->query("SELECT * FROM server_inventory WHERE no='$id'");
$row = $check->fetch_assoc();

if(!$row){
    die("Record not found");
}

// INSERT INTO STOCK OUT HISTORY
$mysqli->query("
INSERT INTO server_stockout_history
(server_name, machine_type, serial_number, location, status, remark, tester, stock_out_by)
VALUES
(
    '".$row['server_name']."',
    '".$row['machine_type']."',
    '".$row['serial_number']."',
    '".$row['location']."',
    '".$row['status']."',
    '$stockout_remark',
    '".$row['tester']."',
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
    'Stock Out Server',
    'Stocked out server: ".$row['server_name']." | Serial: ".$row['serial_number']."'
)
");

// DELETE FROM INVENTORY
$mysqli->query("DELETE FROM server_inventory WHERE no='$id'");

echo "success";
?>