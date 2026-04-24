<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php"; // ✅ ADD THIS

$role = $_SESSION['role'];
$username = $_SESSION['username'];
$id = intval($_GET['id']);

function canManageContract($role, $username, $owner){
    return (
        $role === "Administrator" ||
        $role === "User (Project Coordinator)" ||
        ($role === "User (Project Manager)" && $username === $owner)
    );
}

// GET FILE INFO
$stmt = $mysqli->prepare("SELECT file_name, uploaded_by FROM contract_files WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$file = $result->fetch_assoc();

if(!$file){
    die("File not found");
}

// PERMISSION CHECK
if(!canManageContract($role, $username, $file['uploaded_by'])){
    die("Access denied");
}

// DELETE FILE FROM SERVER
$file_path = "../uploads/" . $file['file_name'];
$fileDeleted = false;

if(file_exists($file_path)){
    $fileDeleted = unlink($file_path);
}

// DELETE DB RECORD
$deleteStmt = $mysqli->prepare("DELETE FROM contract_files WHERE id=?");

if($deleteStmt){
    $deleteStmt->bind_param("i", $id);

    if($deleteStmt->execute()){

        $ip = $_SERVER['REMOTE_ADDR'];
        $time = date("Y-m-d H:i:s");

        $description = "User [$username] deleted contract file.
File Name: {$file['file_name']}
Uploaded By: {$file['uploaded_by']}
File Deleted From Server: " . ($fileDeleted ? "YES" : "NO") . "
File ID: $id
IP Address: $ip
Time: $time";

        // ✅ LOG ONLY AFTER SUCCESS
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