<?php
session_start();
include("../includes/db_connect.php");

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

    // Hash the new password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Decide table
    $table = ($role === "system_admin") ? "system_admin" : "user";

    // Update password
    $stmt = $mysqli->prepare("UPDATE $table SET password=? WHERE username=?");
    $stmt->bind_param("ss", $hashed_password, $username);

    if ($stmt->execute()) {
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