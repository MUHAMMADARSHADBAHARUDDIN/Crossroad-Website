<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/activity_log.php";

if(!isset($_SESSION['username'])){
    die("No session");
}

$username = $_SESSION['username'] ?? "";
$role = $_SESSION['role'] ?? "";

$file_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if($file_id <= 0){
    die("Invalid file");
}

/* =========================
   GET FILE DATA + CONTRACT OWNER
========================= */
$stmt = $mysqli->prepare("
    SELECT
        cf.id,
        cf.contract_id,
        cf.file_name,
        cf.uploaded_by,
        pi.created_by,
        pi.project_name,
        pi.contract_no
    FROM contract_files cf
    LEFT JOIN project_inventory pi ON pi.no = cf.contract_id
    WHERE cf.id = ?
    LIMIT 1
");

if(!$stmt){
    die("SQL Error: " . $mysqli->error);
}

$stmt->bind_param("i", $file_id);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows <= 0){
    die("File not found");
}

$row = $result->fetch_assoc();
$stmt->close();

$contract_id = $row['contract_id'];
$file_name = basename($row['file_name']);
$created_by = $row['created_by'] ?? "";

/* =========================
   PERMISSION CHECK
   Do not change existing permission system.
========================= */
$canDelete = false;

if(function_exists("hasContractDeleteAccess")){
    $canDelete = hasContractDeleteAccess($mysqli, $created_by);
}else{
    $canDelete = in_array($role, [
        "Administrator",
        "System Admin",
        "User (Project Coordinator)"
    ]);
}

if(!$canDelete){
    die("Access denied");
}

/* =========================
   FILE PATH
========================= */
$filePathContracts = "../uploads/contracts/" . $file_name;
$filePathOld = "../uploads/" . $file_name;

/* =========================
   DELETE DATABASE RECORD FIRST
========================= */
$deleteStmt = $mysqli->prepare("
    DELETE FROM contract_files
    WHERE id = ?
");

if(!$deleteStmt){
    die("SQL Error: " . $mysqli->error);
}

$deleteStmt->bind_param("i", $file_id);

if(!$deleteStmt->execute()){
    die("Delete failed: " . $mysqli->error);
}

$deleteStmt->close();

/* =========================
   DELETE PHYSICAL FILE
========================= */
if(file_exists($filePathContracts)){
    unlink($filePathContracts);
}
elseif(file_exists($filePathOld)){
    unlink($filePathOld);
}

/* =========================
   ACTIVITY LOG
========================= */
$displayFileName = preg_replace('/^\d+_/', '', $file_name);

if(function_exists("logActivity")){
    $description = "User [$username] deleted contract document.
Contract ID: $contract_id
Project Name: " . ($row['project_name'] ?? '-') . "
Contract No: " . ($row['contract_no'] ?? '-') . "
File Name: $displayFileName
IP Address: " . ($_SERVER['REMOTE_ADDR'] ?? '-') . "
Time: " . date("Y-m-d H:i:s");

    logActivity($mysqli, $username, $role, "DELETE CONTRACT FILE", $description);
}

echo "success";
exit();
?>