<?php
global $mysqli;
session_start();

require_once "../includes/db_connect.php";

$role = $_SESSION['role'];

if($role != "Administrator" && $role != "System Admin"){
    header("Location: asset_inventory.php");
    exit();
}

if(isset($_POST['add'])){

    $part = $_POST['part'];
    $serial = $_POST['serial'];
    $brand = $_POST['brand'];
    $desc = $_POST['desc'];
    $interface = $_POST['interface'];
    $qty = $_POST['qty'];
    $type = $_POST['type'];
    $location = $_POST['location'];
    $remark = $_POST['remark'];

    $sql = "INSERT INTO asset_inventory
(part_number,serial_number,brand,description,interface,quantity,type,location,remark)
VALUES
('$part','$serial','$brand','$desc','$interface','$qty','$type','$location','$remark')";

    $mysqli->query($sql);

    header("Location: asset_inventory.php");
}
?>

<!DOCTYPE html>
<html>
<head>

    <title>Add Asset</title>

    <link rel="stylesheet" href="style.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">

    <h2>Add Asset</h2>

    <div class="card p-4">

        <form method="POST">

            <input name="part" class="form-control mb-3" placeholder="Part Number">

            <input name="serial" class="form-control mb-3" placeholder="Serial Number">

            <input name="brand" class="form-control mb-3" placeholder="Brand">

            <input name="desc" class="form-control mb-3" placeholder="Description">

            <input name="interface" class="form-control mb-3" placeholder="Interface">

            <input name="qty" class="form-control mb-3" placeholder="Quantity">

            <input name="type" class="form-control mb-3" placeholder="Type">

            <input name="location" class="form-control mb-3" placeholder="Location">

            <textarea name="remark" class="form-control mb-3" placeholder="Remark"></textarea>

            <button class="btn btn-warning" name="add">
                Add Asset
            </button>

            <a href="asset_inventory.php" class="btn btn-secondary">
                Cancel
            </a>

        </form>

    </div>

</div>

<?php include "layout/footer.php"; ?>

</body>
</html>
