<?php
session_start();

include("../includes/db_connect.php");
require_once "../includes/activity_log.php";

$username = $_GET['username'];
$role = $_GET['role'];

if($role == "user"){

    $stmt = $mysqli->prepare("DELETE FROM user WHERE username=?");

}
else{

    $stmt = $mysqli->prepare("DELETE FROM system_admin WHERE username=?");

}

$stmt->bind_param("s",$username);

if($stmt->execute()){

    // 🔵 LOG ACTIVITY
    logActivity(
        $mysqli,
        $_SESSION['username'],
        $_SESSION['role'],
        "DELETE USER",
        "Deleted user: $username"
    );

}

header("Location: ../frontend/manage_users.php");
exit();
?>