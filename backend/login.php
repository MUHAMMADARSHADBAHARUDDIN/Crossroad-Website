<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";

// Only process POST requests
if($_SERVER["REQUEST_METHOD"] === "POST"){

    $email = trim($_POST["email"]);
    $password = trim($_POST["password"]);

    $tables = [
        "system_admin" => "System Admin",
        "administrator" => "Administrator",
        "user" => null
    ];

    foreach($tables as $table => $role_name){

        if($table === "user"){
            $stmt = $mysqli->prepare("SELECT username, password, role FROM $table WHERE email=?");
        } else {
            $stmt = $mysqli->prepare("SELECT username, password FROM $table WHERE email=?");
        }

        if(!$stmt){
            die("SQL Error: " . $mysqli->error);
        }

        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if($stmt->num_rows === 1){

            if($table === "user"){
                $stmt->bind_result($db_username, $db_password, $role);
            } else {
                $stmt->bind_result($db_username, $db_password);
                $role = $role_name;
            }

            $stmt->fetch();

            if(password_verify($password, $db_password)){

                $_SESSION['username'] = $db_username;
                $_SESSION['email'] = $email;
                $_SESSION['role'] = $role;

                $ip = $_SERVER['REMOTE_ADDR'];
                $time = date("Y-m-d H:i:s");

                $description = "User [$db_username] logged in successfully.
Email: $email
Role: $role
IP Address: $ip
Time: $time";

                logActivity($mysqli, $db_username, $role, "LOGIN SUCCESS", $description);

                header("Location: ../frontend/dashboard.php");
                exit();
            }
        }

        $stmt->close();
    }

    // FAILED LOGIN LOG
    $ip = $_SERVER['REMOTE_ADDR'];
    $time = date("Y-m-d H:i:s");

    logActivity(
        $mysqli,
        $email,
        "UNKNOWN",
        "LOGIN FAILED",
        "Failed login attempt.
Email: $email
IP Address: $ip
Time: $time"
    );

    echo "<script>alert('Invalid email or password'); window.location.href='../frontend/index.html';</script>";
}
?>