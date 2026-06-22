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

if(!hasContractTaskDocumentViewAccess($mysqli, $createdBy)){
    die("Access denied");
}

$canDownload = hasContractTaskDocumentDownloadAccess($mysqli, $createdBy);

$filePath = contractTaskDocumentDiskPath($document['file_name'] ?? "");

if(!is_file($filePath)){
    die("File not found");
}

$displayName = contractTaskDocumentDisplayName($document);
$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$downloadUrl = "download_contract_task_document.php?id=" . (int)$documentId;
$previewHtml = "";

if($extension === "pdf"){
    $fileData = base64_encode(file_get_contents($filePath));
    $previewHtml = "<iframe src='data:application/pdf;base64,$fileData' class='viewer-frame'></iframe>";
}
elseif(in_array($extension, ["jpg", "jpeg", "png", "gif", "webp"], true)){
    $mime = "image/jpeg";

    if($extension === "png"){
        $mime = "image/png";
    }
    elseif($extension === "gif"){
        $mime = "image/gif";
    }
    elseif($extension === "webp"){
        $mime = "image/webp";
    }

    $fileData = base64_encode(file_get_contents($filePath));
    $previewHtml = "<div class='image-wrap'><img src='data:$mime;base64,$fileData' alt='Preview'></div>";
}
elseif(in_array($extension, ["txt", "csv"], true)){
    $previewHtml = "<pre class='text-preview'>" . contractTaskDocumentEscape(file_get_contents($filePath)) . "</pre>";
}
else{
    $downloadAction = $canDownload
        ? "
            <a href='" . contractTaskDocumentEscape($downloadUrl) . "' class='btn btn-warning'>
                <i class='fa fa-download'></i> Download File
            </a>
        "
        : "<p class='text-muted mb-0'>Download permission is required to save this file.</p>";

    $previewHtml = "
        <div class='unsupported-box'>
            <i class='fa fa-file-circle-question'></i>
            <h4>Preview not available for this file type</h4>
            <p>This file is <b>." . contractTaskDocumentEscape($extension) . "</b>.</p>
            $downloadAction
        </div>
    ";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Checklist Document - <?= contractTaskDocumentEscape($displayName) ?></title>

<link rel="icon" type="image/png" href="../image/logo.png">
<link rel="shortcut icon" type="image/png" href="../image/logo.png">
<link rel="apple-touch-icon" href="../image/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
html,
body{
    height:100%;
    margin:0;
    background:#f5f6fa;
    font-family:Arial, sans-serif;
}

.viewer-header{
    background:#1f2937;
    color:white;
    padding:14px 20px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
}

.viewer-title{
    font-size:16px;
    font-weight:600;
    margin:0;
    overflow-wrap:anywhere;
}

.viewer-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.viewer-body{
    height:calc(100% - 66px);
    padding:16px;
}

.viewer-frame{
    width:100%;
    height:100%;
    border:0;
    background:white;
    border-radius:10px;
    box-shadow:0 4px 14px rgba(0,0,0,0.08);
}

.image-wrap{
    height:100%;
    display:flex;
    align-items:center;
    justify-content:center;
}

.image-wrap img{
    max-width:100%;
    max-height:100%;
    background:white;
    border-radius:10px;
    box-shadow:0 4px 14px rgba(0,0,0,0.08);
}

.text-preview{
    height:100%;
    background:white;
    border-radius:10px;
    padding:16px;
    overflow:auto;
    box-shadow:0 4px 14px rgba(0,0,0,0.08);
}

.unsupported-box{
    max-width:560px;
    margin:70px auto;
    background:white;
    border-radius:12px;
    padding:28px;
    text-align:center;
    box-shadow:0 4px 14px rgba(0,0,0,0.08);
}

.unsupported-box i{
    font-size:42px;
    color:#ffc107;
    margin-bottom:14px;
}
</style>
</head>

<body>
<div class="viewer-header">
    <h1 class="viewer-title">
        <i class="fa fa-paperclip"></i>
        <?= contractTaskDocumentEscape($displayName) ?>
    </h1>
    <div class="viewer-actions">
        <?php if($canDownload): ?>
            <a href="<?= contractTaskDocumentEscape($downloadUrl) ?>" class="btn btn-warning btn-sm">
                <i class="fa fa-download"></i> Download
            </a>
        <?php endif; ?>
        <button type="button" class="btn btn-light btn-sm" onclick="window.close()">
            <i class="fa fa-xmark"></i> Close
        </button>
    </div>
</div>

<div class="viewer-body">
    <?= $previewHtml ?>
</div>
</body>
</html>
