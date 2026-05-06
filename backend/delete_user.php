<?php
session_start();

include("../includes/db_connect.php");
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username']) || !isset($_SESSION['role'])){
    exit("No session");
}

if(!hasPermission($mysqli, "users_delete")){
    exit("Access denied");
}

if(!isset($_GET['username']) || !isset($_GET['account_type'])){
    exit("Invalid request");
}

$username = trim($_GET['username']);
$accountType = trim($_GET['account_type']);

$adminUser = $_SESSION['username'];
$adminRole = $_SESSION['role'];

if(!in_array($accountType, ["user", "administrator"], true)){
    exit("Invalid account type");
}

if($username === $adminUser && $accountType === "administrator"){
    exit("You cannot delete your own administrator account.");
}

if($accountType === "administrator"){

    $checkStmt = $mysqli->prepare("
        SELECT username, email, role
        FROM administrator
        WHERE username = ?
        LIMIT 1
    ");

} else {

    $checkStmt = $mysqli->prepare("
        SELECT username, email, role
        FROM user
        WHERE username = ?
        LIMIT 1
    ");
}

if(!$checkStmt){
    exit("Prepare failed: " . $mysqli->error);
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

if(!$deleteStmt){
    exit("Prepare failed: " . $mysqli->error);
}

$deleteStmt->bind_param("s", $username);

if($deleteStmt->execute()){

    /*
    |--------------------------------------------------------------------------
    | DELETE USER PERMISSIONS TOO
    |--------------------------------------------------------------------------
    */
    $deletePerm = $mysqli->prepare("
        DELETE FROM user_permissions
        WHERE username = ?
        AND account_type = ?
    ");

    if($deletePerm){
        $deletePerm->bind_param("ss", $username, $accountType);
        $deletePerm->execute();
    }

    /*
    Clean any leftover permission with same username, just in case account type changed before.
    */
    $deletePermAll = $mysqli->prepare("
        DELETE FROM user_permissions
        WHERE username = ?
    ");

    if($deletePermAll){
        $deletePermAll->bind_param("s", $username);
        $deletePermAll->execute();
    }

    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    $deletedRole = $userData['role'] ?? "-";
    $deletedEmail = $userData['email'] ?? "-";

    $description = "Admin [$adminUser] deleted user account.
Deleted Username: $username
Account Type: $accountType
Role/Title: $deletedRole
User Email: $deletedEmail
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