<?php
// Include database connection
include("../includes/db_connect.php");

// Admin details
$username = "Arshad";           // replace with your admin username
$password = "1111";              // current plain password
$email    = "ars466928@gmail.com";  // admin email

// Hash the password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Check if email exists for this user
$stmt = $mysqli->prepare("SELECT username FROM administrator WHERE username=?");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->store_result();

if($stmt->num_rows === 1){
    // User exists, update password AND email
    $stmt->close();
    $update = $mysqli->prepare("UPDATE administrator SET password=?, email=? WHERE username=?");
    $update->bind_param("sss", $hashed_password, $email, $username);

    if($update->execute()){
        echo "Admin password AND email updated successfully!";
    } else {
        echo "Error updating admin: ".$mysqli->error;
    }
    $update->close();
}else{
    // User does not exist, insert new admin with hashed password
    $stmt->close();
    $insert = $mysqli->prepare("INSERT INTO administrator (username, password, email) VALUES (?, ?, ?)");
    $insert->bind_param("sss", $username, $hashed_password, $email);

    if($insert->execute()){
        echo "Admin created successfully with hashed password!";
    } else {
        echo "Error creating admin: ".$mysqli->error;
    }
    $insert->close();
}

// Close connection
$mysqli->close();
?>