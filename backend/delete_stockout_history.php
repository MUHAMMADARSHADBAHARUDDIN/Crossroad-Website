<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

header("Content-Type: text/plain; charset=UTF-8");

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

$stmt = $mysqli->prepare("SELECT * FROM stock_out_history WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if(!$row){
    exit("Record not found");
}

$mysqli->begin_transaction();

try{
    $tableCheck = $mysqli->query("SHOW TABLES LIKE 'stockout_additional_information'");
    if($tableCheck && $tableCheck->num_rows > 0){
        $noteDelete = $mysqli->prepare("
            DELETE FROM stockout_additional_information
            WHERE stockout_type = 'asset' AND stockout_id = ?
        ");
        $noteDelete->bind_param("i", $id);
        if(!$noteDelete->execute()){
            throw new Exception($noteDelete->error);
        }
    }

    $deleteStmt = $mysqli->prepare("DELETE FROM stock_out_history WHERE id = ?");
    $deleteStmt->bind_param("i", $id);
    if(!$deleteStmt->execute()){
        throw new Exception($deleteStmt->error);
    }

    $mysqli->commit();

    $ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
    $time = date("Y-m-d H:i:s");
    $description = "User [$username] deleted asset stock out history.\n"
        . "Part Number: {$row['part_number']}\n"
        . "Serial Number: {$row['serial_number']}\n"
        . "Stock Out By: {$row['stock_out_by']}\n"
        . "History ID: $id\n"
        . "IP Address: $ip\n"
        . "Time: $time";

    logActivity($mysqli, $username, $role, "DELETE STOCK OUT HISTORY", $description);
    exit("success");
}catch(Throwable $e){
    $mysqli->rollback();
    exit("Delete failed: " . $e->getMessage());
}
?>
