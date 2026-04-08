<?php
session_start();
require_once "../includes/db_connect.php";

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
INSERT INTO activity_logs
(username, role, action_type, description)
VALUES
(
    '$username',
    '$role',
    'Delete Asset',
    'Deleted asset: Part Number ".$row['part_number'].", Serial Number ".$row['serial_number'].", Location ".$row['location']."'
)
");
$mysqli->query("DELETE FROM asset_inventory WHERE no=$id");

echo "success";
?>