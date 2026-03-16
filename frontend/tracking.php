<?php
global $mysqli;
session_start();
require_once "../includes/db_connect.php";

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

if($_SESSION['role'] !== "Administrator"){
    die("Access Denied");
}

$result = $mysqli->query("
SELECT * FROM activity_logs
ORDER BY log_time DESC
");
?>

<!DOCTYPE html>
<html>
<head>

<title>Activity Tracking</title>

<link rel="icon" href="../image/logo.png">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

</head>

<body>

<div class="container mt-4">

<h3>System Activity Logs</h3>

<table id="logsTable" class="table table-striped">

<thead>
<tr>
<th>Username</th>
<th>Role</th>
<th>Action</th>
<th>Description</th>
<th>Date & Time</th>
</tr>
</thead>

<tbody>

<?php while($row = $result->fetch_assoc()): ?>

<tr>

<td><?= $row['username'] ?></td>
<td><?= $row['role'] ?></td>
<td><?= $row['action_type'] ?></td>
<td><?= $row['description'] ?></td>
<td><?= $row['log_time'] ?></td>

</tr>

<?php endwhile; ?>

</tbody>

</table>

</div>

<script src="https://code.jquery.com/jquery-3.7.1.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

<script>
$(document).ready(function(){
    $('#logsTable').DataTable();
});
</script>

</body>
</html>