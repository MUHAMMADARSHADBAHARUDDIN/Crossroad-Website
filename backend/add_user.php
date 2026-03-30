<?php
session_start();

include("../includes/db_connect.php");
require_once "../includes/activity_log.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== "Administrator") {
    die("Access denied.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"]);
    $email = trim($_POST["email"]);
    $password = trim($_POST["password"]);
    $role = trim($_POST["role"]);

    // PASSWORD VALIDATION
    if(strlen($password) < 8){
        die("Password must be at least 8 characters long.");
    }

    if(!preg_match('/[A-Z]/', $password)){
        die("Password must include at least one uppercase letter.");
    }

    if(!preg_match('/[\W]/', $password)){
        die("Password must include at least one symbol.");
    }

    // HASH PASSWORD
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    if($role == "system_admin"){

        $stmt = $mysqli->prepare("INSERT INTO system_admin (username, email, password) VALUES (?,?,?)");
        $stmt->bind_param("sss",$username,$email,$hashed_password);

    }
    else{

        $stmt = $mysqli->prepare("INSERT INTO user (username, email, password, role) VALUES (?,?,?,?)");
        $stmt->bind_param("ssss",$username,$email,$hashed_password,$role);

    }

    if($stmt->execute()){

        logActivity(
            $mysqli,
            $_SESSION['username'],
            $_SESSION['role'],
            "ADD USER",
            "Created user: $username ($role)"
        );

        echo "<script>
        alert('User added successfully');
        window.location.href='../frontend/manage_users.php';
        </script>";

    } else {
        echo "Error: " . $mysqli->error;
    }

}
?>