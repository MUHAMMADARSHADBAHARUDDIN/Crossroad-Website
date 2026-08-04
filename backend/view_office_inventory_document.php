<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/inventory_report_schema.php";
require_once "../includes/office_inventory_documents.php";

if(!isset($_SESSION['username'])){
    die("No session");
}

if(!hasPermission($mysqli, "office_inventory_document_view")){
    die("Access denied");
}

ensureInventoryReportSchema($mysqli);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if($id <= 0){
    die("Invalid request");
}

function officeDocumentViewerEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
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
$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$downloadUrl = "download_office_inventory_document.php?id=" . $id;
$canDownload = hasPermission($mysqli, "office_inventory_document_download");
$previewHtml = "";

if($extension === "pdf"){
    $fileData = base64_encode(file_get_contents($filePath));
    $previewHtml = "<iframe src='data:application/pdf;base64,$fileData' class='viewer-frame'></iframe>";
}
elseif(in_array($extension, ["jpg", "jpeg", "png"], true)){
    $mime = $extension === "png" ? "image/png" : "image/jpeg";
    $fileData = base64_encode(file_get_contents($filePath));
    $previewHtml = "<div class='image-wrap'><img src='data:$mime;base64,$fileData' alt='Preview'></div>";
}
elseif(in_array($extension, ["txt", "csv"], true)){
    $previewHtml = "<pre class='text-preview'>" . officeDocumentViewerEscape(file_get_contents($filePath)) . "</pre>";
}
else{
    $downloadButton = "";

    if($canDownload){
        $downloadButton = "
            <a href='" . officeDocumentViewerEscape($downloadUrl) . "' class='btn btn-warning'>
                <i class='fa fa-download'></i> Download File
            </a>
        ";
    }

    $previewHtml = "
        <div class='unsupported-box'>
            <i class='fa fa-file-circle-question'></i>
            <h4>Preview not available for this file type</h4>
            <p>This file is <b>." . officeDocumentViewerEscape($extension) . "</b>. Please download it to open.</p>
            $downloadButton
        </div>
    ";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>View Office Document - <?= officeDocumentViewerEscape($displayName) ?></title>
<link rel="icon" type="image/png" href="../image/logo.png">
<link rel="shortcut icon" type="image/png" href="../image/logo.png">
<link rel="apple-touch-icon" href="../image/logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
html, body{
    min-height:100%;
    margin:0;
    background:#f4f6f9;
}

.viewer-header{
    position:sticky;
    top:0;
    z-index:10;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    padding:14px 18px;
    background:#111827;
    color:#fff;
}

.viewer-title{
    margin:0;
    font-weight:700;
    word-break:break-word;
}

.viewer-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}

.viewer-body{
    padding:18px;
}

.viewer-card{
    background:#fff;
    border:1px solid #dee2e6;
    border-radius:10px;
    min-height:calc(100vh - 112px);
    overflow:hidden;
}

.viewer-frame{
    width:100%;
    height:calc(100vh - 132px);
    border:0;
}

.image-wrap{
    display:flex;
    align-items:center;
    justify-content:center;
    min-height:calc(100vh - 132px);
    padding:16px;
}

.image-wrap img{
    max-width:100%;
    max-height:calc(100vh - 164px);
}

.text-preview{
    min-height:calc(100vh - 132px);
    margin:0;
    padding:18px;
    white-space:pre-wrap;
}

.unsupported-box{
    min-height:calc(100vh - 132px);
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:10px;
    text-align:center;
    padding:24px;
}

.unsupported-box i{
    font-size:44px;
    color:#6c757d;
}

@media(max-width:768px){
    .viewer-body{
        padding:10px;
    }

    .viewer-actions .btn{
        width:100%;
    }
}
</style>
</head>
<body>

<div class="viewer-header">
    <p class="viewer-title">
        <i class="fa fa-file"></i>
        <?= officeDocumentViewerEscape($displayName) ?>
    </p>
    <?php if($canDownload): ?>
        <div class="viewer-actions">
            <a href="<?= officeDocumentViewerEscape($downloadUrl) ?>" class="btn btn-sm btn-warning">
                <i class="fa fa-download"></i> Download
            </a>
        </div>
    <?php endif; ?>
</div>

<div class="viewer-body">
    <div class="viewer-card">
        <?= $previewHtml ?>
    </div>
</div>

</body>
</html>
