<?php
require_once __DIR__ . "/env.php";

$host = getenv("CROSSROAD_DB_HOST") ?: "127.0.0.1";
$user = getenv("CROSSROAD_DB_USER") ?: "root";
$password = getenv("CROSSROAD_DB_PASS") ?: "";
$database = getenv("CROSSROAD_DB_NAME") ?: "crossroad_solutions_inventory_management";
$port = (int)(getenv("CROSSROAD_DB_PORT") ?: 3306);

$mysqli = new mysqli($host, $user, $password, $database, $port);

if ($mysqli->connect_error) {
    die("Database connection failed: " . $mysqli->connect_error);
}

$mysqli->set_charset("utf8mb4");
?>
