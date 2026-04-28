<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username'])){
    exit("No session");
}

$role = $_SESSION['role'] ?? "UNKNOWN";
$username = $_SESSION['username'];

if(!isset($_GET['id'])){
    exit("Invalid request");
}

$id = intval($_GET['id']);

$stmt = $mysqli->prepare("
    SELECT
        cf.file_name,
        cf.uploaded_by,
        cf.contract_id,
        pi.created_by AS contract_created_by
    FROM contract_files cf
    LEFT JOIN project_inventory pi ON cf.contract_id = pi.no
    WHERE cf.id = ?
    LIMIT 1
");

if(!$stmt){
    die("Prepare failed: " . $mysqli->error);
}

$stmt->bind_param("i", $id);
$stmt->execute();

$result = $stmt->get_result();
$file = $result->fetch_assoc();

if(!$file){
    die("File not found");
}

$contractCreatedBy = $file['contract_created_by'] ?? "";

if(!hasContractDeleteAccess($mysqli, $contractCreatedBy)){
    die("Access denied");
}

$file_path = "../uploads/" . $file['file_name'];
$fileDeleted = false;

if(file_exists($file_path)){
    $fileDeleted = unlink($file_path);
}

$deleteStmt = $mysqli->prepare("
    DELETE FROM contract_files
    WHERE id = ?
");

if($deleteStmt){
    $deleteStmt->bind_param("i", $id);

    if($deleteStmt->execute()){

        $ip = $_SERVER['REMOTE_ADDR'];
        $time = date("Y-m-d H:i:s");

        $description = "User [$username] deleted contract file.
File Name: {$file['file_name']}
Uploaded By: {$file['uploaded_by']}
Contract ID: {$file['contract_id']}
Contract Created By: {$contractCreatedBy}
File Deleted From Server: " . ($fileDeleted ? "YES" : "NO") . "
File ID: $id
IP Address: $ip
Time: $time";

        logActivity(
            $mysqli,
            $username,
            $role,
            "DELETE FILE",
            $description
        );

        header("Location: ../frontend/contracts.php");
        exit();

    } else {
        echo "Delete failed: " . $mysqli->error;
    }

} else {
    echo "Prepare failed: " . $mysqli->error;
}

header("Location: ../frontend/contracts.php");
exit();
?>