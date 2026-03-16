<?php
session_start();
include("../includes/db_connect.php");
require_once "../includes/activity_log.php";

// Only allow administrators
if (!isset($_SESSION['role']) || $_SESSION['role'] !== "Administrator") {
    die("Access denied.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"]);
    $password = trim($_POST["password"]);
    $role = trim($_POST["role"]);

    if (empty($username) || empty($password) || empty($role)) {
        die("All fields are required.");
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $table = ($role === "system_admin") ? "system_admin" : "user";

    $stmt = $mysqli->prepare("UPDATE $table SET password=? WHERE username=?");
    $stmt->bind_param("ss", $hashed_password, $username);

    if ($stmt->execute()) {

        // 🔵 LOG ACTIVITY
        logActivity(
            $mysqli,
            $_SESSION['username'],
            $_SESSION['role'],
            "UPDATE USER",
            "Updated password for user: $username"
        );

        echo "<script>
                alert('User updated successfully');
                window.location.href='../frontend/manage_users.php';
              </script>";
    } else {

        echo "<script>
                alert('Error: " . $mysqli->error . "');
                window.location.href='../frontend/manage_users.php';
              </script>";
    }

    $stmt->close();
    $mysqli->close();
}
?>