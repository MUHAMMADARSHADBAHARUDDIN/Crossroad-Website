<?php
session_start();
require_once "../includes/db_connect.php";

$part = $_POST['part'];
$username = $_SESSION['username'];
$role = $_SESSION['role'];

$result = $mysqli->query("
SELECT no, serial_number, location, created_by
FROM asset_inventory
WHERE part_number='$part'
");

echo "
<table class='table table-bordered table-hover'>
<thead class='table-dark'>
<tr>
    <th>Serial Number</th>
    <th>Location</th>
    <th>Action</th>
</tr>
</thead>
<tbody>
";

while($row = $result->fetch_assoc()){

    $canClick = (
        $role == "Administrator" ||
        $role == "System Admin" ||
        ($role == "User (Technical)" && $row['created_by'] == $username)
    );

    echo "<tr>";

    echo "<td>".$row['serial_number']."</td>";
    echo "<td>".$row['location']."</td>";
$canClick = (
    $role == "Administrator" ||
    $role == "System Admin" ||
    ($role == "User (Technical)" && $row['created_by'] == $username)
);
    if($canClick){
        echo "
        <td>
            <button class='btn btn-sm btn-danger'
                onclick='openRemarkModal(".$row['no'].", \"".$row['serial_number']."\")'>
                <i class='fa fa-arrow-up'></i> Stock Out
            </button>
        </td>";
    }else{
        echo "<td><span class='badge bg-secondary'>View Only</span></td>";
    }

    echo "</tr>";
}

echo "</tbody></table>";
?>