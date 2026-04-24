<?php
session_start();

include("../includes/db_connect.php");
require_once "../includes/activity_log.php";

/* ✅ SESSION CHECK */
if(!isset($_SESSION['username']) || !isset($_SESSION['role'])){
    exit("No session");
}

/* ✅ ONLY ADMIN CAN DELETE USERS */
if($_SESSION['role'] !== "Administrator"){
    exit("Access denied");
}

/* ✅ VALIDATE INPUT */
if(!isset($_GET['username']) || !isset($_GET['role'])){
    exit("Invalid request");
}

$username = $_GET['username'];
$role = $_GET['role'];

$adminUser = $_SESSION['username'];
$adminRole = $_SESSION['role'];

/* ✅ CHECK USER EXISTS FIRST */
if($role == "user"){
    $checkStmt = $mysqli->prepare("SELECT username, email FROM user WHERE username=?");
} else {
    $checkStmt = $mysqli->prepare("SELECT username, email FROM system_admin WHERE username=?");
}

$checkStmt->bind_param("s", $username);
$checkStmt->execute();
$result = $checkStmt->get_result();
$userData = $result->fetch_assoc();

if(!$userData){
    exit("User not found");
}

/* ✅ DELETE */
if($role == "user"){
    $deleteStmt = $mysqli->prepare("DELETE FROM user WHERE username=?");
} else {
    $deleteStmt = $mysqli->prepare("DELETE FROM system_admin WHERE username=?");
}

if($deleteStmt){
    $deleteStmt->bind_param("s",$username);

    if($deleteStmt->execute()){

        $ip = $_SERVER['REMOTE_ADDR'];
        $time = date("Y-m-d H:i:s");

        $description = "Admin [$adminUser] deleted user.
Deleted Username: $username
User Role: $role
User Email: {$userData['email']}
IP Address: $ip
Time: $time";

        /* ✅ LOG ONLY AFTER SUCCESS */
        logActivity(
            $mysqli,
            $adminUser,
            $adminRole,
            "DELETE USER",
            $description
        );

    } else {
        echo "Delete failed: " . $mysqli->error;
    }

} else {
    echo "Prepare failed: " . $mysqli->error;
}

header("Location: ../frontend/manage_users.php");
exit();
?>