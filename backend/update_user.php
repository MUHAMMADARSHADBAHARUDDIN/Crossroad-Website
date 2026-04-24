<?php
global $mysqli;
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
        $adminUser = $_SESSION['username'];
        $adminRole = $_SESSION['role'];

        $ip = $_SERVER['REMOTE_ADDR'];
        $time = date("Y-m-d H:i:s");

        $description = "Admin [$adminUser] updated user password.
        Target Username: $username
        Target Role: $role
        IP Address: $ip
        Time: $time";

        logActivity(
            $mysqli,
            $adminUser,
            $adminRole,
            "UPDATE USER PASSWORD",
            $description
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