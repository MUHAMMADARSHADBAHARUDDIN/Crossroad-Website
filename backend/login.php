<?php
session_start();
require_once '../includes/db_connect.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"]);
    $password = trim($_POST["password"]);

    // Define user roles and corresponding redirect pages
    $roles = [
        "system_admin" => "../frontend/system_admin_dashboard.html",
        "administrator" => "../frontend/admin_dashboard.html",
        "user" => "../frontend/user_dashboard.html"
    ];

    $loggedIn = false;

    foreach ($roles as $table => $redirectPage) {

        $stmt = $mysqli->prepare("SELECT password FROM $table WHERE username=?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($db_password);
            $stmt->fetch();

            // Plain password comparison (change to password_verify if hashed)
            if ($password === $db_password) {
                $_SESSION["username"] = $username;
                $_SESSION["role"] = $table;
                $stmt->close();
                header("Location: $redirectPage");
                exit();
            }
        }
        $stmt->close();
    }

    // If we reach here, login failed
    echo "<script>alert('Invalid username or password'); window.location.href='../frontend/index.html';</script>";
}
?>