<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";

// Only process POST requests
if($_SERVER["REQUEST_METHOD"] === "POST"){

    $email = trim($_POST["email"]);
    $password = trim($_POST["password"]);

    // Tables to check: system_admin, administrator, user
    $tables = [
        "system_admin" => "System Admin",
        "administrator" => "Administrator",
        "user" => null // role comes from DB
    ];

    $login_success = false;

    foreach($tables as $table => $role_name){

        // Prepare SQL depending on table
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

            // Verify password
            if(password_verify($password, $db_password)){
                // Set session variables
                $_SESSION['username'] = $db_username;
                $_SESSION['email'] = $email;
                $_SESSION['role'] = $role;

                // Log activity
                logActivity($mysqli, $db_username, $role, "LOGIN", "User logged in");

                // Redirect to dashboard
                header("Location: ../frontend/dashboard.php");
                exit();
            }
        }

        $stmt->close();
    }

    // If login failed
    echo "<script>alert('Invalid email or password'); window.location.href='../frontend/index.html';</script>";
}
?>