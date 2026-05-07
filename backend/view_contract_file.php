<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username'])){
    die("No session");
}

if(!hasContractViewAccess($mysqli)){
    die("Access denied");
}

$file_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if($file_id <= 0){
    die("Invalid file");
}

function viewerEscape($value){
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$stmt = $mysqli->prepare("
    SELECT file_name
    FROM contract_files
    WHERE id = ?
    LIMIT 1
");

if(!$stmt){
    die("SQL Error: " . $mysqli->error);
}

$stmt->bind_param("i", $file_id);
$stmt->execute();
$result = $stmt->get_result();

if($result->num_rows <= 0){
    die("File not found in database");
}

$row = $result->fetch_assoc();
$stmt->close();

$storedFileName = basename($row['file_name']);
$displayFileName = preg_replace('/^\d+_/', '', $storedFileName);

$filePathContracts = "../uploads/contracts/" . $storedFileName;
$filePathOld = "../uploads/" . $storedFileName;

if(file_exists($filePathContracts)){
    $filePath = $filePathContracts;
}
elseif(file_exists($filePathOld)){
    $filePath = $filePathOld;
}
else{
    die("File not found in folder");
}

$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

$downloadUrl = "download_contract_file.php?id=" . $file_id;

$previewHtml = "";

if($extension === "pdf"){
    $fileData = base64_encode(file_get_contents($filePath));

    $previewHtml = "
        <iframe
            src='data:application/pdf;base64,$fileData'
            class='viewer-frame'>
        </iframe>
    ";
}
elseif(in_array($extension, ["jpg", "jpeg", "png", "gif", "webp"])){
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

    $previewHtml = "
        <div class='image-wrap'>
            <img src='data:$mime;base64,$fileData' alt='Preview'>
        </div>
    ";
}
elseif(in_array($extension, ["txt", "csv"])){
    $textContent = file_get_contents($filePath);

    $previewHtml = "
        <pre class='text-preview'>" . viewerEscape($textContent) . "</pre>
    ";
}
else{
    $previewHtml = "
        <div class='unsupported-box'>
            <i class='fa fa-file-circle-question'></i>
            <h4>Preview not available for this file type</h4>
            <p>
                This file is <b>." . viewerEscape($extension) . "</b>.
                Browser can normally preview PDF, image, TXT and CSV files only.
            </p>
            <p>
                For Word or Excel files, please use the Download button.
            </p>
            <a href='" . viewerEscape($downloadUrl) . "' class='btn btn-warning'>
                <i class='fa fa-download'></i> Download File
            </a>
        </div>
    ";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>View Document - <?= viewerEscape($displayFileName) ?></title>

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
    height:calc(100vh - 70px);
    padding:16px;
}

.viewer-card{
    background:white;
    border-radius:10px;
    box-shadow:0 3px 12px rgba(0,0,0,0.12);
    height:100%;
    overflow:hidden;
}

.viewer-frame{
    width:100%;
    height:100%;
    border:0;
}

.image-wrap{
    height:100%;
    overflow:auto;
    display:flex;
    justify-content:center;
    align-items:flex-start;
    padding:20px;
    background:#fff;
}

.image-wrap img{
    max-width:100%;
    height:auto;
    border-radius:8px;
}

.text-preview{
    height:100%;
    margin:0;
    padding:20px;
    overflow:auto;
    white-space:pre-wrap;
    font-size:14px;
    background:white;
}

.unsupported-box{
    height:100%;
    display:flex;
    flex-direction:column;
    justify-content:center;
    align-items:center;
    text-align:center;
    padding:30px;
}

.unsupported-box i{
    font-size:52px;
    color:#ffc107;
    margin-bottom:15px;
}

@media(max-width: 768px){
    .viewer-body{
        height:calc(100vh - 115px);
        padding:10px;
    }

    .viewer-header{
        align-items:flex-start;
    }
}
</style>
</head>

<body>

<div class="viewer-header">
    <p class="viewer-title">
        <i class="fa fa-eye"></i>
        <?= viewerEscape($displayFileName) ?>
    </p>

    <div class="viewer-actions">
        <a href="<?= viewerEscape($downloadUrl) ?>" class="btn btn-sm btn-warning">
            <i class="fa fa-download"></i> Download
        </a>

        <button onclick="window.close()" class="btn btn-sm btn-light">
            <i class="fa fa-xmark"></i> Close
        </button>
    </div>
</div>

<div class="viewer-body">
    <div class="viewer-card">
        <?= $previewHtml ?>
    </div>
</div>

</body>
</html>