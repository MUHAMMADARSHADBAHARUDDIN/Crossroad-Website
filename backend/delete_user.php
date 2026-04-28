<?php
session_start();

include("../includes/db_connect.php");
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username']) || !isset($_SESSION['role'])){
    exit("No session");
}

if(($_SESSION['role'] ?? '') !== "Administrator"){
    exit("Access denied");
}

if(!hasPermission($mysqli, "users_delete")){
    exit("Access denied");
}

if(!isset($_GET['username']) || !isset($_GET['account_type'])){
    exit("Invalid request");
}

$username = $_GET['username'];
$accountType = $_GET['account_type'];

$adminUser = $_SESSION['username'];
$adminRole = $_SESSION['role'];

if($username === $adminUser && $accountType === "administrator"){
    exit("You cannot delete your own administrator account.");
}

if($accountType === "administrator"){

    $checkStmt = $mysqli->prepare("
        SELECT username, email
        FROM administrator
        WHERE username = ?
        LIMIT 1
    ");

} elseif($accountType === "user"){

    $checkStmt = $mysqli->prepare("
        SELECT username, email
        FROM user
        WHERE username = ?
        LIMIT 1
    ");

} else {
    exit("Invalid account type");
}

$checkStmt->bind_param("s", $username);
$checkStmt->execute();
$result = $checkStmt->get_result();
$userData = $result->fetch_assoc();

if(!$userData){
    exit("User not found");
}

if($accountType === "administrator"){

    $deleteStmt = $mysqli->prepare("
        DELETE FROM administrator
        WHERE username = ?
    ");

} else {

    $deleteStmt = $mysqli->prepare("
        DELETE FROM user
        WHERE username = ?
    ");
}

$deleteStmt->bind_param("s", $username);

if($deleteStmt->execute()){

    $deletePerm = $mysqli->prepare("
        DELETE FROM user_permissions
        WHERE username = ?
    ");
    $deletePerm->bind_param("s", $username);
    $deletePerm->execute();

    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $description = "Admin [$adminUser] deleted user account.
Deleted Username: $username
Account Type: $accountType
User Email: {$userData['email']}
IP Address: $ip
Time: $time";

    logActivity(
        $mysqli,
        $adminUser,
        $adminRole,
        "DELETE USER",
        $description
    );

} else {
    echo "Delete failed: " . $mysqli->error;
    exit();
}

header("Location: ../frontend/manage_users.php");
exit();
?>