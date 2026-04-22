<?php
session_start();
require_once "../includes/db_connect.php";

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
if(file_exists($file_path)){
    unlink($file_path);
}

// DELETE DB RECORD
$stmt = $mysqli->prepare("DELETE FROM contract_files WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();

header("Location: ../frontend/contracts.php");
exit();
?>