<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";

$username = $_SESSION['username'] ?? 'Unknown';
$role = $_SESSION['role'] ?? 'Unknown';
$accountType = $_SESSION['account_type'] ?? 'Unknown';

$ip = $_SERVER['REMOTE_ADDR'];
$time = date("Y-m-d H:i:s");

$description = "User [$username] logged out.
Role: $role
Account Type: $accountType
IP Address: $ip
Time: $time";

logActivity(
    $mysqli,
    $username,
    $role,
    "LOGOUT",
    $description
);

session_destroy();

header("Location: index.html");
exit();
?>