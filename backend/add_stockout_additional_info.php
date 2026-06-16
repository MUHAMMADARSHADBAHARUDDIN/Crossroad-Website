<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

header("Content-Type: text/plain; charset=UTF-8");

if(!isset($_SESSION['username'])){
    exit("No session");
}

if(!hasPermission($mysqli, "inventory_stockout_add_info")){
    exit("Access denied. You do not have permission to add stock out information.");
}

$stockoutType = trim($_POST['stockout_type'] ?? "");
$stockoutId = isset($_POST['stockout_id']) ? (int)$_POST['stockout_id'] : 0;
$additionalInformation = trim($_POST['additional_information'] ?? "");

if(!in_array($stockoutType, ["asset", "server"], true)){
    exit("Invalid stock out type.");
}

if($stockoutId <= 0){
    exit("Invalid stock out record.");
}

if($additionalInformation === ""){
    exit("Please enter additional information.");
}

if(function_exists('mb_strlen') && mb_strlen($additionalInformation) > 5000){
    exit("Additional information cannot exceed 5000 characters.");
}
elseif(!function_exists('mb_strlen') && strlen($additionalInformation) > 5000){
    exit("Additional information cannot exceed 5000 characters.");
}

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "UNKNOWN";

if($stockoutType === "asset"){
    $recordStmt = $mysqli->prepare("
        SELECT part_number, serial_number
        FROM stock_out_history
        WHERE id = ?
        LIMIT 1
    ");
}else{
    $recordStmt = $mysqli->prepare("
        SELECT server_name, serial_number
        FROM server_stockout_history
        WHERE id = ?
        LIMIT 1
    ");
}

if(!$recordStmt){
    exit("SQL Error: " . $mysqli->error);
}

$recordStmt->bind_param("i", $stockoutId);
$recordStmt->execute();
$recordResult = $recordStmt->get_result();
$record = $recordResult->fetch_assoc();

if(!$record){
    exit("Stock out record not found.");
}

$insertStmt = $mysqli->prepare("
    INSERT INTO stockout_additional_information
    (stockout_type, stockout_id, additional_information, added_by)
    VALUES (?, ?, ?, ?)
");

if(!$insertStmt){
    exit("Please run upgrade_pm_stockout.sql first. SQL Error: " . $mysqli->error);
}

$insertStmt->bind_param("siss", $stockoutType, $stockoutId, $additionalInformation, $username);

if(!$insertStmt->execute()){
    exit("Failed to save additional information: " . $insertStmt->error);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
$time = date("Y-m-d H:i:s");

if($stockoutType === "asset"){
    $itemDetails = "Part Number: " . ($record['part_number'] ?? "") . "\nSerial Number: " . ($record['serial_number'] ?? "");
    $actionType = "ADD ASSET STOCK OUT INFO";
}else{
    $itemDetails = "Server Name: " . ($record['server_name'] ?? "") . "\nSerial Number: " . ($record['serial_number'] ?? "");
    $actionType = "ADD SERVER STOCK OUT INFO";
}

$description = "User [$username] added additional information to a $stockoutType stock out record.\n"
    . "Stock Out History ID: $stockoutId\n"
    . $itemDetails . "\n"
    . "Additional Information: $additionalInformation\n"
    . "IP Address: $ip\n"
    . "Time: $time";

logActivity($mysqli, $username, $role, $actionType, $description);

echo "success";
?>
