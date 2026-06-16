<?php
$host = "127.0.0.1";
$user = "crossroad_app";
$password = "StrongPassword123!";
$database = "crossroad_solutions_inventory_management";
$port = 3306;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $mysqli = new mysqli($host, $user, $password, $database, $port);
    $mysqli->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>