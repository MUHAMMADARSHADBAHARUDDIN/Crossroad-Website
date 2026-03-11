<?php
session_start();
include("../includes/db_connect.php");

// Only allow administrators to add users
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

    // Hash the password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Decide table
    $table = ($role === "system_admin") ? "system_admin" : "user";

    // Insert into DB
    $stmt = $mysqli->prepare("INSERT INTO $table (username, password) VALUES (?, ?)");
    $stmt->bind_param("ss", $username, $hashed_password);

    if ($stmt->execute()) {
        echo "<script>
                alert('User added successfully');
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