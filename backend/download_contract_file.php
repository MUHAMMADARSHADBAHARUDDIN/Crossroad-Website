<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username'])){
    die("No session");
}

if(function_exists('hasContractDownloadAccess')){
    if(!hasContractDownloadAccess($mysqli)){
        die("Access denied");
    }
}else{
    if(!hasContractViewAccess($mysqli)){
        die("Access denied");
    }
}

$file_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if($file_id <= 0){
    die("Invalid file");
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

if(ob_get_length()){
    ob_end_clean();
}

header("Content-Description: File Transfer");
header("Content-Type: application/octet-stream");
header("Content-Disposition: attachment; filename=\"" . addslashes($displayFileName) . "\"");
header("Content-Length: " . filesize($filePath));
header("Cache-Control: must-revalidate");
header("Pragma: public");
header("Expires: 0");

readfile($filePath);
exit();
?>