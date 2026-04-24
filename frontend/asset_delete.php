<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";

$id = $_POST['id'];
$username = $_SESSION['username'];
$role = $_SESSION['role'];

$check = $mysqli->query("SELECT * FROM asset_inventory WHERE no=$id");
$row = $check->fetch_assoc();

if($role == "User (Technical)" && $row['created_by'] != $username){
    die("Access denied");
}
// INSERT INTO ACTIVITY LOG
$mysqli->query("
$ip = $_SERVER['REMOTE_ADDR'];
$time = date("Y-m-d H:i:s");

$description = "User [$username] deleted asset.
Part Number: {$row['part_number']}
Serial Number: {$row['serial_number']}
Location: {$row['location']}
Deleted By Role: $role
IP Address: $ip
Time: $time";

logActivity(
    $mysqli,
    $username,
    $role,
    "DELETE ASSET",
    $description
);
$mysqli->query("DELETE FROM asset_inventory WHERE no=$id");

echo "success";
?>