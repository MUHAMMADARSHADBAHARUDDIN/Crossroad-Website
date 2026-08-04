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

$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$task = contractTaskDocumentFetchTask($mysqli, $taskId);

if(!$task){
    exit("Task not found.");
}

$createdBy = $task['created_by'] ?? "";

if(!hasContractTaskDocumentUploadAccess($mysqli, $createdBy)){
    exit("Access denied. You do not have checklist document upload permission.");
}

if(!isset($_FILES['file'])){
    exit("Please choose a document.");
}

$file = $_FILES['file'];
$uploadError = contractTaskDocumentValidateUpload($file);

if($uploadError !== ""){
    exit($uploadError);
}

$originalName = basename($file['name']);
$storedName = contractTaskDocumentStoredFileName($originalName);
$uploadDir = contractTaskDocumentEnsureUploadDir();
$targetPath = $uploadDir . "/" . $storedName;

if(!move_uploaded_file($file['tmp_name'], $targetPath)){
    exit("Failed to move uploaded file.");
}

$contractId = (int)$task['contract_id'];
$uploadedBy = $_SESSION['username'];

$stmt = $mysqli->prepare("
    INSERT INTO contract_task_documents (contract_id, task_id, file_name, original_file_name, uploaded_by)
    VALUES (?, ?, ?, ?, ?)
");

if(!$stmt){
    @unlink($targetPath);
    exit("SQL Error: " . $mysqli->error);
}

$stmt->bind_param("iisss", $contractId, $taskId, $storedName, $originalName, $uploadedBy);

if(!$stmt->execute()){
    @unlink($targetPath);
    exit("Failed to save document: " . $stmt->error);
}

$role = $_SESSION['role'] ?? "UNKNOWN";
$ip = $_SERVER['REMOTE_ADDR'] ?? "Unknown";
$time = date("Y-m-d H:i:s");

$description = "User [$uploadedBy] uploaded a checklist document.
Contract ID: $contractId
Contract No: " . ($task['contract_no'] ?? "") . "
Project Name: " . ($task['project_name'] ?? "") . "
Task ID: $taskId
Task: " . ($task['task_text'] ?? "") . "
File Name: $originalName
File Size: " . (int)$file['size'] . " bytes
IP Address: $ip
Time: $time";

logActivity($mysqli, $uploadedBy, $role, "UPLOAD CONTRACT TASK DOCUMENT", $description);

exit("success");
?>
