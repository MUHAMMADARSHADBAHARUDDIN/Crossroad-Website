<?php
$host = "127.0.0.1";
$user = "crossroad_app";
$password = "StrongPassword123!";
$database = "crossroad_solutions_inventory_management";
$port = 3306;

$mysqli = new mysqli($host, $user, $password, $database, $port);

if ($mysqli->connect_error) {
    die("Database connection failed: " . $mysqli->connect_error);
}

$mysqli->set_charset("utf8mb4");
?>
