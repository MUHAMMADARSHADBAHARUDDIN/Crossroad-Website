<?php
require_once "../includes/db_connect.php";

$id = $_POST['id'];

$result = $mysqli->query("SELECT * FROM server_inventory WHERE no='$id'");
$row = $result->fetch_assoc();

echo "
<table class='table table-bordered'>
<tr><th>Serial</th><td>{$row['serial_number']}</td></tr>
<tr><th>Server Name</th><td>{$row['server_name']}</td></tr>
<tr><th>Machine Type</th><td>{$row['machine_type']}</td></tr>
<tr><th>Brand</th><td>{$row['brand']}</td></tr>
<tr><th>Location</th><td>{$row['location']}</td></tr>
<tr><th>Status</th><td>{$row['status']}</td></tr>
<tr><th>Remark</th><td>{$row['remark']}</td></tr>
<tr><th>Date Testing</th><td>{$row['date_testing']}</td></tr>
<tr><th>Tester</th><td>{$row['tester']}</td></tr>
</table>
";
?>