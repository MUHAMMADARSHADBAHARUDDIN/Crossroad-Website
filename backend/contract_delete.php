<?php

session_start();
require_once "../includes/db_connect.php";

$id = $_GET['id'];

$role = $_SESSION['role'];
$username = $_SESSION['username'];

$result = $mysqli->query("SELECT created_by FROM project_inventory WHERE no=$id");
$row = $result->fetch_assoc();

$owner = $row['created_by'];

if(
    $role == "Administrator" ||
    $role == "User (Project Coordinator)" ||
    (
        ($role == "User (Technical)" || $role == "User (Project Manager)")
        && $username == $owner
    )
){
    $mysqli->query("DELETE FROM project_inventory WHERE no=$id");
}

header("Location: ../frontend/contracts.php");
?>