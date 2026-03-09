<?php
// includes/db_connect.php
// Update these values with the credentials from Hostinger
$DB_HOST = 'localhost';               // common on Hostinger, but confirm in panel
$DB_NAME = 'u770115470_crossroad';      // your provided DB name
$DB_USER = 'u770115470_User';        // <- replace
$DB_PASS = 'Arsh@d5312';        // <- replace

// Create mysqli connection
$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

// Check connection
if ($mysqli->connect_errno) {
    // In production, do not echo internals. Log and show generic message.
    error_log("DB connect error: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error);
    die("Database connection failed. Please check configuration.");
}
$mysqli->set_charset("utf8mb4");
