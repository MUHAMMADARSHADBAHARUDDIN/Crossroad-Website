<?php
require_once __DIR__ . "/env.php";

$host = trim((string)(getenv("CROSSROAD_DB_HOST") ?: "127.0.0.1"));
$user = trim((string)(getenv("CROSSROAD_DB_USER") ?: "crossroad_app"));
$password = (string)(getenv("CROSSROAD_DB_PASS") ?: "StrongPassword123!");
$database = trim((string)(getenv("CROSSROAD_DB_NAME") ?: "crossroad_solutions_inventory_management"));
$port = (int)(getenv("CROSSROAD_DB_PORT") ?: 3306);

$mysqli = new mysqli($host, $user, $password, $database, $port);

if ($mysqli->connect_error) {
    die("Database connection failed: " . $mysqli->connect_error);
}

$mysqli->set_charset("utf8mb4");
?>
