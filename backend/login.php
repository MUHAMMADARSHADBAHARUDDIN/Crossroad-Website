<?php
session_start();
require_once '../includes/db_connect.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"]);
    $password = trim($_POST["password"]);

    /* ---------------------------
       1. CHECK SYSTEM ADMIN
    --------------------------- */

    $stmt = $mysqli->prepare("SELECT password FROM system_admin WHERE username=?");
    $stmt->bind_param("s",$username);
    $stmt->execute();
    $stmt->store_result();

    if($stmt->num_rows === 1){

        $stmt->bind_result($db_password);
        $stmt->fetch();

        if($password === $db_password){

            $_SESSION["username"] = $username;
            $_SESSION["role"] = "system_admin";

            header("Location: ../frontend/system_admin_dashboard.html");
            exit();
        }
    }

    $stmt->close();

    /* ---------------------------
       2. CHECK ADMINISTRATOR
    --------------------------- */

    $stmt = $mysqli->prepare("SELECT password FROM administrator WHERE username=?");
    $stmt->bind_param("s",$username);
    $stmt->execute();
    $stmt->store_result();

    if($stmt->num_rows === 1){

        $stmt->bind_result($db_password);
        $stmt->fetch();

        if($password === $db_password){

            $_SESSION["username"] = $username;
            $_SESSION["role"] = "administrator";

            header("Location: ../frontend/admin_dashboard.html");
            exit();
        }
    }

    $stmt->close();

    /* ---------------------------
       3. CHECK NORMAL USER
    --------------------------- */

    $stmt = $mysqli->prepare("SELECT password FROM user WHERE username=?");
    $stmt->bind_param("s",$username);
    $stmt->execute();
    $stmt->store_result();

    if($stmt->num_rows === 1){

        $stmt->bind_result($db_password);
        $stmt->fetch();

        if($password === $db_password){

            $_SESSION["username"] = $username;
            $_SESSION["role"] = "user";

            header("Location: ../frontend/user_dashboard.html");
            exit();
        }
    }

    $stmt->close();

    /* ---------------------------
       LOGIN FAILED
    --------------------------- */

    echo "Invalid username or password";

}
?>