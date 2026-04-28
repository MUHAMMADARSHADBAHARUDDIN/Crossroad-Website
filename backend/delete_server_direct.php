<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

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

$deleteStmt = $mysqli->prepare("
DELETE FROM server_inventory
WHERE no = ?
");
$deleteStmt->bind_param("i", $id);

if($deleteStmt->execute()){

    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $description = "User [$username] deleted server directly.
Server Name: {$row['server_name']}
Machine Type: {$row['machine_type']}
Serial Number: {$row['serial_number']}
Location: {$row['location']}
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

echo "Delete failed: " . $mysqli->error;
?>