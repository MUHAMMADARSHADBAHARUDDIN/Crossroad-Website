<?php
$DB_HOST = 'localhost';
$DB_PORT = 3306;
$DB_NAME = 'crossroad_solutions_inventory_management';
$DB_USER = 'root';
$DB_PASS = ''; // default XAMPP password is empty

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);

if ($mysqli->connect_errno) {
    die("Database connection failed: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error);
}

$mysqli->set_charset("utf8mb4");
?> 
