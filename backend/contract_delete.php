<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";

if(!isset($_SESSION['username'])){
    exit("No session");
}

$id = intval($_GET['id']);
$role = $_SESSION['role'];
$username = $_SESSION['username'];

/* GET CONTRACT OWNER */
$stmt = $mysqli->prepare("SELECT project_name, project_owner FROM project_inventory WHERE no = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$data = $res->fetch_assoc();

if(!$data){
    exit("Contract not found");
}

$project_name = $data['project_name'];
$owner = $data['project_owner'];

/* PERMISSION CHECK */
$allowed =
    $role === "Administrator" ||
    $role === "User (Project Coordinator)" ||
    ($role === "User (Project Manager)" && $username === $owner);

if(!$allowed){
    exit("Access denied");
}

/* DELETE */
$stmt = $mysqli->prepare("DELETE FROM project_inventory WHERE no = ?");
$stmt->bind_param("i", $id);
$stmt->execute();

/* LOG */
logActivity(
    $mysqli,
    $username,
    $role,
    "DELETE CONTRACT",
    "Deleted contract: $project_name"
);

header("Location: ../frontend/contracts.php");
exit();
?>