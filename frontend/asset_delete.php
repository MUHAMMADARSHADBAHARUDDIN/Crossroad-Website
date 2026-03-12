<?php

global $mysqli;
require_once "../includes/db_connect.php";

$id = $_GET['id'];

$mysqli->query("DELETE FROM asset_inventory WHERE no=$id");

header("Location: ../frontend/asset_inventory.php");

?>
