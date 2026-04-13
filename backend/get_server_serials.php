<?php
session_start();
require_once "../includes/db_connect.php";

$name = $_POST['name'];
$type = $_POST['type'];

$username = $_SESSION['username'];
$role = $_SESSION['role'];

$result = $mysqli->query("
SELECT no, serial_number, location
FROM server_inventory
WHERE server_name='$name' AND machine_type='$type'
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
        ($role == "User (Technical)")
    );

    echo "<tr>";

    echo "<td>".$row['serial_number']."</td>";
    echo "<td>".$row['location']."</td>";

    if($canClick){
        echo "
        <td>
            <a href='server_edit.php?id=".$row['no']."'
               class='btn btn-sm btn-primary'>
               <i class='fa fa-pen'></i>
            </a>

            <button class='btn btn-sm btn-danger'
               onclick='openServerRemarkModal(".$row['no'].", \"".$row['serial_number']."\")'>
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