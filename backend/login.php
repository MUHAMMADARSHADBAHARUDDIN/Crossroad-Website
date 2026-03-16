<?php
global $mysqli;
session_start();

include("../includes/db_connect.php");
require_once "../includes/activity_log.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"]);
    $password = trim($_POST["password"]);

    /* ---------- SYSTEM ADMIN ---------- */

    $stmt = $mysqli->prepare("SELECT password FROM system_admin WHERE username=?");
    $stmt->bind_param("s",$username);
    $stmt->execute();
    $stmt->store_result();

    if($stmt->num_rows == 1){

        $stmt->bind_result($db_password);
        $stmt->fetch();

        if(password_verify($password,$db_password)){

            $_SESSION['username']=$username;
            $_SESSION['role']="System Admin";

            logActivity($mysqli,$username,"System Admin","LOGIN","User logged in");

            header("Location: ../frontend/home.php");
            exit();
        }
    }

    $stmt->close();

    /* ---------- ADMINISTRATOR ---------- */

    $stmt = $mysqli->prepare("SELECT password FROM administrator WHERE username=?");
    $stmt->bind_param("s",$username);
    $stmt->execute();
    $stmt->store_result();

    if($stmt->num_rows == 1){

        $stmt->bind_result($db_password);
        $stmt->fetch();

        if(password_verify($password,$db_password)){

            $_SESSION['username']=$username;
            $_SESSION['role']="Administrator";

            logActivity($mysqli,$username,"Administrator","LOGIN","User logged in");

            header("Location: ../frontend/home.php");
            exit();
        }
    }

    $stmt->close();

    /* ---------- USER TABLE ---------- */

    $stmt = $mysqli->prepare("SELECT password,role FROM user WHERE username=?");
    $stmt->bind_param("s",$username);
    $stmt->execute();
    $stmt->store_result();

    if($stmt->num_rows == 1){

        $stmt->bind_result($db_password,$role);
        $stmt->fetch();

        if(password_verify($password,$db_password)){

            $_SESSION['username']=$username;
            $_SESSION['role']=$role;

            logActivity($mysqli,$username,$role,"LOGIN","User logged in");

            header("Location: ../frontend/home.php");
            exit();
        }
    }

    $stmt->close();

    echo "<script>
            alert('Invalid username or password');
            window.location.href='../frontend/index.html';
          </script>";
}
?>