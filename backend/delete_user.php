<?php

include("../includes/db_connect.php");

$username = $_GET['username'];
$role = $_GET['role'];

if($role == "user"){

$stmt = $mysqli->prepare("DELETE FROM user WHERE username=?");

}
else{

$stmt = $mysqli->prepare("DELETE FROM system_admin WHERE username=?");

}

$stmt->bind_param("s",$username);
$stmt->execute();

header("Location: ../frontend/manage_users.php");

?>