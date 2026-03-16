<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";

$username = $_SESSION['username'];
$role = $_SESSION['role'];

logActivity($mysqli, $username, $role, "LOGOUT", "User logged out");

session_destroy();

header("Location: index.html");
exit();
?>