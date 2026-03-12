<?php

global $mysqli;
require_once "../includes/db_connect.php";

$id = $_GET['id'];

$mysqli->query("DELETE FROM project_inventory WHERE No=$id");

header("Location: ../frontend/contracts.php");

?>
