<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/contract_task_documents.php";

if(!isset($_SESSION['username'])){
    die("No session");
}

$documentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$document = contractTaskDocumentFetchDocument($mysqli, $documentId);

if(!$document){
    die("Document not found");
}

$createdBy = $document['created_by'] ?? "";

if(!hasContractTaskDocumentDownloadAccess($mysqli, $createdBy)){
    die("Access denied");
}

$filePath = contractTaskDocumentDiskPath($document['file_name'] ?? "");

if(!is_file($filePath)){
    die("File not found");
}

$displayName = contractTaskDocumentDisplayName($document);

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
