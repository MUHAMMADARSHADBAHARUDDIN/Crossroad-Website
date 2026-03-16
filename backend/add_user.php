<?php
session_start();

include("../includes/db_connect.php");
require_once "../includes/activity_log.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== "Administrator") {
    die("Access denied.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"]);
    $password = trim($_POST["password"]);
    $role = trim($_POST["role"]);

    $hashed_password = password_hash($password,PASSWORD_DEFAULT);

    if($role == "system_admin"){

        $stmt = $mysqli->prepare("INSERT INTO system_admin (username,password) VALUES (?,?)");
        $stmt->bind_param("ss",$username,$hashed_password);

    }
    else{

        $stmt = $mysqli->prepare("INSERT INTO user (username,password,role) VALUES (?,?,?)");
        $stmt->bind_param("sss",$username,$hashed_password,$role);

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

    }

}
?>