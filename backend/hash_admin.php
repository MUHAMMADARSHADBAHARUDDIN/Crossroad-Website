<?php
global $mysqli;
include("../includes/db_connect.php");

// Admin username
$username = "Boss Frank"; // replace with your admin username
$password = "1111";  // current plain password

// Hash the password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Update DB
$stmt = $mysqli->prepare("UPDATE administrator SET password=? WHERE username=?");
$stmt->bind_param("ss", $hashed_password, $username);

if($stmt->execute()){
    echo "Admin password hashed successfully!";
} else {
    echo "Error: ".$mysqli->error;
}

$stmt->close();
$mysqli->close();
?>