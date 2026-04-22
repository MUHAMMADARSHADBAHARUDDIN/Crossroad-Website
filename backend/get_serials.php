<?php
session_start();
require_once "../includes/db_connect.php";

$part = $_POST['part'];
$username = $_SESSION['username'];
$role = $_SESSION['role'];

$result = $mysqli->query("
SELECT no, serial_number, location, brand, description, date_received
FROM asset_inventory
WHERE part_number='$part'
ORDER BY date_received DESC
");

echo "
<table class='table table-sm table-hover align-middle'>
<thead class='table-dark'>
<tr>
    <th>Serial</th>
    <th>Brand</th>
    <th>Location</th>
    <th>Date</th>
    <th style='width:120px;'>Action</th>
</tr>
</thead>
<tbody>
";

while($row = $result->fetch_assoc()){

    $canClick = (
        $role == "Administrator" ||
        $role == "System Admin" ||
        ($role == "User (Technical)")
    );

    echo "<tr style='cursor:pointer;' onclick='viewDetail(".$row['no'].")'>";;

echo "<td>".$row['serial_number']."</td>";
echo "<td>".$row['brand']."</td>";
echo "<td>".$row['location']."</td>";
echo "<td>".$row['date_received']."</td>";
$canClick = (
    $role == "Administrator" ||
    $role == "System Admin" ||
    ($role == "User (Technical)")
);
    if($canClick){
        echo "
        <td>
            <a href='asset_edit.php?id=".$row['no']."'
               class='btn btn-sm btn-primary'
               onclick='event.stopPropagation();'>
               <i class='fa fa-pen'></i>
            </a>

            <button class='btn btn-sm btn-danger'
                onclick='event.stopPropagation(); openRemarkModal(".$row['no'].", \"".$row['serial_number']."\")'>
                <i class='fa fa-arrow-up'></i>
            </button>
        </td>";
    }else{
        echo "<td><span class='badge bg-secondary'>View Only</span></td>";
    }

    echo "</tr>";
}

echo "</tbody></table>";
?>