<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

header('Content-Type: text/plain');

if(!isset($_SESSION['username'])){
    exit("No session");
}

if(!hasPermission($mysqli, "inventory_delete")){
    exit("access_denied");
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
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

if(!$stmt){
    exit("Prepare failed: " . $mysqli->error);
}

$stmt->bind_param("i", $id);
$stmt->execute();

$check = $stmt->get_result();
$row = $check->fetch_assoc();

if(!$row){
    exit("Asset not found");
}

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

$deleteStmt = $mysqli->prepare("
    DELETE FROM asset_inventory
    WHERE no = ?
");

if(!$deleteStmt){
    exit("Prepare failed: " . $mysqli->error);
}

$deleteStmt->bind_param("i", $id);

if($deleteStmt->execute()){
    echo "success";
} else {
    echo "Delete failed: " . $mysqli->error;
}
?>