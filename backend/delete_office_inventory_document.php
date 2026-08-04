<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";
require_once "../includes/inventory_report_schema.php";
require_once "../includes/office_inventory_documents.php";

if(!isset($_SESSION['username'])){
    die("No session");
}

if(!hasPermission($mysqli, "office_inventory_document_delete")){
    die("Access denied");
}

ensureInventoryReportSchema($mysqli);

$username = $_SESSION['username'] ?? "";
$role = $_SESSION['role'] ?? "UNKNOWN";
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if($id <= 0){
    die("Invalid request");
}

$stmt = $mysqli->prepare("
    SELECT owner, serial_number, brand, model, document_file_name, document_original_name
    FROM laptop_inventory
    WHERE id = ?
    LIMIT 1
");

if(!$stmt){
    die("SQL Error: " . $mysqli->error);
}

$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if(!$row || trim((string)($row['document_file_name'] ?? "")) === ""){
    die("Document not found");
}

$documentFileName = trim((string)$row['document_file_name']);
$documentPath = officeInventoryDocumentDiskPath($documentFileName);
$displayName = officeInventoryDocumentDisplayName($row);

$updateStmt = $mysqli->prepare("
    UPDATE laptop_inventory
    SET document_file_name = NULL,
        document_original_name = NULL,
        document_uploaded_by = NULL,
        document_uploaded_at = NULL
    WHERE id = ?
    LIMIT 1
");

if(!$updateStmt){
    die("SQL Error: " . $mysqli->error);
}

$updateStmt->bind_param("i", $id);

if(!$updateStmt->execute()){
    die("Delete failed: " . $updateStmt->error);
}

if(is_file($documentPath)){
    unlink($documentPath);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
$time = date("Y-m-d H:i:s");

$description = "User [$username] deleted office inventory document.
Owner: " . ($row['owner'] ?? '-') . "
Serial Number: " . ($row['serial_number'] ?? '-') . "
Brand: " . ($row['brand'] ?? '-') . "
Model: " . ($row['model'] ?? '-') . "
Document: $displayName
IP Address: $ip
Time: $time";

logActivity($mysqli, $username, $role, "DELETE OFFICE INVENTORY DOCUMENT", $description);

echo "success";
exit();
?>
