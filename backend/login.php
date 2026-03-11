<?php
session_start();
include("../includes/db_connect.php");

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"]);
    $password = trim($_POST["password"]);

    // Tables with roles
    $roles = [
        "system_admin" => "System Admin",
        "administrator" => "Administrator",
        "user" => "User"
    ];

    foreach ($roles as $table => $roleName) {

        $stmt = $mysqli->prepare("SELECT password FROM $table WHERE username = ?");
        if (!$stmt) {
            die("SQL error: " . $mysqli->error);
        }

        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($db_password);
            $stmt->fetch();

            // Verify hashed password
            if (password_verify($password, $db_password)) {
                $_SESSION["username"] = $username;
                $_SESSION["role"] = $roleName;

                header("Location: ../frontend/home.php");
                exit();
            }
        }

        $stmt->close();
    }

    // Login failed
    echo "<script>
            alert('Invalid username or password');
            window.location.href='../frontend/index.html';
          </script>";
}
?>