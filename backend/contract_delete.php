<?php

session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";

$id = $_GET['id'];

$role = $_SESSION['role'];
$username = $_SESSION['username'];

/* GET CONTRACT DATA */
$result = $mysqli->query("SELECT contract_name, created_by FROM project_inventory WHERE no=$id");
$data = $result->fetch_assoc();

$contract_name = $data['contract_name'];
$owner = $data['created_by'];

/* PERMISSION CHECK */
if(
    $role == "Administrator" ||
    $role == "User (Project Coordinator)" ||
    (
        ($role == "User (Technical)" || $role == "User (Project Manager)")
        && $username == $owner
    )
){
    $mysqli->query("DELETE FROM project_inventory WHERE no=$id");

    logActivity(
        $mysqli,
        $_SESSION['username'],
        $_SESSION['role'],
        "DELETE CONTRACT",
        "Deleted contract: $contract_name"
    );
}

header("Location: ../frontend/contracts.php");
exit();
?>