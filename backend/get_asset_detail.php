<?php
require_once "../includes/db_connect.php";

$id = (int)$_POST['id'];

$result = $mysqli->query("
SELECT * FROM asset_inventory WHERE no='$id' LIMIT 1
");

$row = $result->fetch_assoc();

echo "
<div class='row g-3'>

<div class='col-md-6'>
<label class='fw-bold'>Part Number</label>
<div>".$row['part_number']."</div>
</div>

<div class='col-md-6'>
<label class='fw-bold'>Serial Number</label>
<div>".$row['serial_number']."</div>
</div>

<div class='col-md-6'>
<label class='fw-bold'>Brand</label>
<div>".$row['brand']."</div>
</div>

<div class='col-md-6'>
<label class='fw-bold'>Location</label>
<div>".$row['location']."</div>
</div>

<div class='col-md-6'>
<label class='fw-bold'>Date Received</label>
<div>".$row['date_received']."</div>
</div>

<div class='col-12'>
<label class='fw-bold'>Description</label>
<div>".$row['description']."</div>
</div>

</div>
";
?>