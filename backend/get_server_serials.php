<?php
session_start();
require_once "../includes/db_connect.php";

$name = $_POST['name'];
$type = $_POST['type'];

$username = $_SESSION['username'];
$role = $_SESSION['role'];

$result = $mysqli->query("
SELECT no, serial_number, location, status, remark, date_testing, tester
FROM server_inventory
WHERE server_name='$name' AND machine_type='$type'
ORDER BY date_testing DESC
");

echo "
<table class='table table-sm table-hover align-middle'>
<thead class='table-dark'>
<tr>
    <th>Serial</th>
    <th>Location</th>
    <th>Status</th>
    <th>Date Tested</th>
    <th>Tester</th>
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

    $statusColor = ($row['status'] == 'Okay') ? 'success' : 'danger';

    echo "<tr style='cursor:pointer;' onclick='viewServerDetail(".$row['no'].")'>";

    echo "<td>".$row['serial_number']."</td>";
    echo "<td>".$row['location']."</td>";
    echo "<td><span class='badge bg-$statusColor'>".$row['status']."</span></td>";
    echo "<td>".$row['date_testing']."</td>";
    echo "<td>".$row['tester']."</td>";

    if($canClick){
        echo "
        <td>
            <a href='server_edit.php?id=".$row['no']."'
               class='btn btn-sm btn-primary'
               onclick='event.stopPropagation();'>
               <i class='fa fa-pen'></i>
            </a>

            <button class='btn btn-sm btn-danger'
               onclick='event.stopPropagation(); openServerRemarkModal(".$row['no'].", \"".$row['serial_number']."\")'>
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