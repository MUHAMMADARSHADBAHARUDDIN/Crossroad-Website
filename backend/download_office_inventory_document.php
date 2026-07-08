<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/inventory_report_schema.php";
require_once "../includes/office_inventory_documents.php";

if(!isset($_SESSION['username'])){
    die("No session");
}

if(!hasPermission($mysqli, "office_inventory_document_download")){
    die("Access denied");
}

ensureInventoryReportSchema($mysqli);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if($id <= 0){
    die("Invalid request");
}

$stmt = $mysqli->prepare("
    SELECT document_file_name, document_original_name
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

$filePath = officeInventoryDocumentDiskPath($row['document_file_name']);

if(!is_file($filePath)){
    die("File not found");
}

$displayName = officeInventoryDocumentDisplayName($row);

while(ob_get_level() > 0){
    ob_end_clean();
}

header("Content-Description: File Transfer");
header("Content-Type: application/octet-stream");
header("Content-Disposition: attachment; filename=\"" . addslashes($displayName) . "\"");
header("Content-Length: " . filesize($filePath));
header("Cache-Control: must-revalidate");
header("Pragma: public");
header("Expires: 0");

readfile($filePath);
exit();
?>
