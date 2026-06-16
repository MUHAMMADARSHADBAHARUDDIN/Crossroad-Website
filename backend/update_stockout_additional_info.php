<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

header("Content-Type: text/plain; charset=UTF-8");

if(!isset($_SESSION['username'])){
    exit("No session");
}

if(!hasStockOutAdditionalInfoEditAccess($mysqli)){
    exit("Access denied. You do not have permission to edit stock out additional information.");
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$additionalInformation = trim($_POST['additional_information'] ?? "");

if($id <= 0){
    exit("Invalid additional information ID.");
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

$tableCheck = $mysqli->query("SHOW TABLES LIKE 'stockout_additional_information'");
if(!$tableCheck || $tableCheck->num_rows === 0){
    exit("Additional information table does not exist.");
}

$stmt = $mysqli->prepare("
    SELECT id, stockout_type, stockout_id, additional_information, added_by, added_at
    FROM stockout_additional_information
    WHERE id = ?
    LIMIT 1
");

if(!$stmt){
    exit("SQL Error: " . $mysqli->error);
}

$stmt->bind_param("i", $id);
$stmt->execute();
$info = $stmt->get_result()->fetch_assoc();

if(!$info){
    exit("Additional information not found.");
}

$oldInformation = $info['additional_information'] ?? "";

$updateStmt = $mysqli->prepare("
    UPDATE stockout_additional_information
    SET additional_information = ?
    WHERE id = ?
    LIMIT 1
");

if(!$updateStmt){
    exit("SQL Error: " . $mysqli->error);
}

$updateStmt->bind_param("si", $additionalInformation, $id);

if(!$updateStmt->execute()){
    exit("Update failed: " . $updateStmt->error);
}

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "UNKNOWN";
$ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
$time = date("Y-m-d H:i:s");
$stockoutType = $info['stockout_type'];
$actionType = ($stockoutType === "server") ? "EDIT SERVER STOCK OUT INFO" : "EDIT ASSET STOCK OUT INFO";

$description = "User [$username] edited additional information for a $stockoutType stock out record.\n"
    . "Additional Information ID: {$info['id']}\n"
    . "Stock Out History ID: {$info['stockout_id']}\n"
    . "Original Added By: {$info['added_by']}\n"
    . "Original Added At: {$info['added_at']}\n"
    . "Old Information: $oldInformation\n"
    . "New Information: $additionalInformation\n"
    . "IP Address: $ip\n"
    . "Time: $time";

logActivity($mysqli, $username, $role, $actionType, $description);

echo "success";
?>
