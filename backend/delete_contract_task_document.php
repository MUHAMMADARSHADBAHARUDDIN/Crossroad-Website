<?php
require_once "../includes/security.php";
startSecureSession();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/activity_log.php";
require_once "../includes/contract_task_documents.php";

header("Content-Type: text/plain; charset=UTF-8");

if(!isset($_SESSION['username'])){
    exit("No session");
}

$documentId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$document = contractTaskDocumentFetchDocument($mysqli, $documentId);

if(!$document){
    exit("Document not found.");
}

$createdBy = $document['created_by'] ?? "";

if(!hasContractTaskDocumentDeleteAccess($mysqli, $createdBy)){
    exit("Access denied. You do not have checklist document delete permission.");
}

$stmt = $mysqli->prepare("
    DELETE FROM contract_task_documents
    WHERE id = ?
");

if(!$stmt){
    exit("SQL Error: " . $mysqli->error);
}

$stmt->bind_param("i", $documentId);

if(!$stmt->execute()){
    exit("Delete failed: " . $stmt->error);
}

$filePath = contractTaskDocumentDiskPath($document['file_name'] ?? "");

if(is_file($filePath)){
    @unlink($filePath);
}

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? "UNKNOWN";
$ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
$time = date("Y-m-d H:i:s");
$displayName = contractTaskDocumentDisplayName($document);

$description = "User [$username] deleted a checklist document.
Contract ID: " . ($document['contract_id'] ?? "") . "
Contract No: " . ($document['contract_no'] ?? "") . "
Project Name: " . ($document['project_name'] ?? "") . "
Task ID: " . ($document['task_id'] ?? "") . "
File Name: $displayName
IP Address: $ip
Time: $time";

logActivity($mysqli, $username, $role, "DELETE CONTRACT TASK DOCUMENT", $description);

exit("success");
?>
