<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

header("Content-Type: text/plain; charset=UTF-8");

if(!isset($_SESSION['username'])){
    exit("No session");
}

if(!hasStockOutEditAccess($mysqli)){
    exit("Access denied. You do not have permission to edit stock out history.");
}

function cleanPostValue($key){
    return trim((string)($_POST[$key] ?? ""));
}

function normalizeDateTimeForDatabase($value){
    $value = trim((string)$value);

    if($value === ""){
        return date("Y-m-d H:i:s");
    }

    $timestamp = strtotime($value);

    if(!$timestamp){
        return false;
    }

    return date("Y-m-d H:i:s", $timestamp);
}

$stockoutType = cleanPostValue('stockout_type');
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if(!in_array($stockoutType, ["asset", "server"], true)){
    exit("Invalid stock out type.");
}

if($id <= 0){
    exit("Invalid stock out history ID.");
}

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "UNKNOWN";
$ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
$time = date("Y-m-d H:i:s");

if($stockoutType === "asset"){
    $partNumber = cleanPostValue('part_number');
    $serialNumber = cleanPostValue('serial_number');
    $location = cleanPostValue('location');
    $remark = cleanPostValue('remark');
    $stockOutBy = cleanPostValue('stock_out_by');
    $stockOutDate = normalizeDateTimeForDatabase($_POST['stock_out_date'] ?? "");

    if($partNumber === ""){
        exit("Part number is required.");
    }

    if($serialNumber === ""){
        exit("Serial number is required.");
    }

    if($stockOutBy === ""){
        exit("Stocked out by is required.");
    }

    if($stockOutDate === false){
        exit("Invalid stock out date.");
    }

    $oldStmt = $mysqli->prepare("SELECT * FROM stock_out_history WHERE id = ? LIMIT 1");
    if(!$oldStmt){ exit("SQL Error: " . $mysqli->error); }
    $oldStmt->bind_param("i", $id);
    $oldStmt->execute();
    $oldData = $oldStmt->get_result()->fetch_assoc();

    if(!$oldData){
        exit("Asset stock out history not found.");
    }

    $stmt = $mysqli->prepare("
        UPDATE stock_out_history
        SET part_number = ?, serial_number = ?, location = ?, remark = ?, stock_out_by = ?, stock_out_date = ?
        WHERE id = ?
        LIMIT 1
    ");

    if(!$stmt){
        exit("SQL Error: " . $mysqli->error);
    }

    $stmt->bind_param("ssssssi", $partNumber, $serialNumber, $location, $remark, $stockOutBy, $stockOutDate, $id);

    if(!$stmt->execute()){
        exit("Update failed: " . $stmt->error);
    }

    $description = "User [$username] edited asset stock out history.\n"
        . "History ID: $id\n"
        . "Old Part Number: " . ($oldData['part_number'] ?? '') . "\n"
        . "New Part Number: $partNumber\n"
        . "Old Serial Number: " . ($oldData['serial_number'] ?? '') . "\n"
        . "New Serial Number: $serialNumber\n"
        . "Old Location: " . ($oldData['location'] ?? '') . "\n"
        . "New Location: $location\n"
        . "Old Remark: " . ($oldData['remark'] ?? '') . "\n"
        . "New Remark: $remark\n"
        . "Old Stocked Out By: " . ($oldData['stock_out_by'] ?? '') . "\n"
        . "New Stocked Out By: $stockOutBy\n"
        . "Old Date: " . ($oldData['stock_out_date'] ?? '') . "\n"
        . "New Date: $stockOutDate\n"
        . "IP Address: $ip\n"
        . "Time: $time";

    logActivity($mysqli, $username, $role, "EDIT ASSET STOCK OUT HISTORY", $description);
    echo "success";
    exit();
}

$serverName = cleanPostValue('server_name');
$machineType = cleanPostValue('machine_type');
$serialNumber = cleanPostValue('serial_number');
$location = cleanPostValue('location');
$status = cleanPostValue('status');
$remark = cleanPostValue('remark');
$tester = cleanPostValue('tester');
$stockOutBy = cleanPostValue('stock_out_by');
$stockOutDate = normalizeDateTimeForDatabase($_POST['stock_out_date'] ?? "");

if($serverName === ""){
    exit("Server name is required.");
}

if($machineType === ""){
    exit("Machine type is required.");
}

if($serialNumber === ""){
    exit("Serial number is required.");
}

if($stockOutBy === ""){
    exit("Stocked out by is required.");
}

if($stockOutDate === false){
    exit("Invalid stock out date.");
}

$oldStmt = $mysqli->prepare("SELECT * FROM server_stockout_history WHERE id = ? LIMIT 1");
if(!$oldStmt){ exit("SQL Error: " . $mysqli->error); }
$oldStmt->bind_param("i", $id);
$oldStmt->execute();
$oldData = $oldStmt->get_result()->fetch_assoc();

if(!$oldData){
    exit("Server stock out history not found.");
}

$stmt = $mysqli->prepare("
    UPDATE server_stockout_history
    SET server_name = ?, machine_type = ?, serial_number = ?, location = ?, status = ?, remark = ?, tester = ?, stock_out_by = ?, stock_out_date = ?
    WHERE id = ?
    LIMIT 1
");

if(!$stmt){
    exit("SQL Error: " . $mysqli->error);
}

$stmt->bind_param("sssssssssi", $serverName, $machineType, $serialNumber, $location, $status, $remark, $tester, $stockOutBy, $stockOutDate, $id);

if(!$stmt->execute()){
    exit("Update failed: " . $stmt->error);
}

$description = "User [$username] edited server stock out history.\n"
    . "History ID: $id\n"
    . "Old Server Name: " . ($oldData['server_name'] ?? '') . "\n"
    . "New Server Name: $serverName\n"
    . "Old Machine Type: " . ($oldData['machine_type'] ?? '') . "\n"
    . "New Machine Type: $machineType\n"
    . "Old Serial Number: " . ($oldData['serial_number'] ?? '') . "\n"
    . "New Serial Number: $serialNumber\n"
    . "Old Location: " . ($oldData['location'] ?? '') . "\n"
    . "New Location: $location\n"
    . "Old Status: " . ($oldData['status'] ?? '') . "\n"
    . "New Status: $status\n"
    . "Old Remark: " . ($oldData['remark'] ?? '') . "\n"
    . "New Remark: $remark\n"
    . "Old Tester: " . ($oldData['tester'] ?? '') . "\n"
    . "New Tester: $tester\n"
    . "Old Stocked Out By: " . ($oldData['stock_out_by'] ?? '') . "\n"
    . "New Stocked Out By: $stockOutBy\n"
    . "Old Date: " . ($oldData['stock_out_date'] ?? '') . "\n"
    . "New Date: $stockOutDate\n"
    . "IP Address: $ip\n"
    . "Time: $time";

logActivity($mysqli, $username, $role, "EDIT SERVER STOCK OUT HISTORY", $description);

echo "success";
?>
