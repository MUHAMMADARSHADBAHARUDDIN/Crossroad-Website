<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

require_once "../includes/db_connect.php";

$role = $_SESSION['role'];

if($role != "Administrator" && $role != "System Admin"){
    header("Location: asset_inventory.php");
    exit();
}

$id = $_GET['id'];

/* GET ASSET DATA */

$result = $mysqli->query("SELECT * FROM asset_inventory WHERE no=$id");
$row = $result->fetch_assoc();

/* UPDATE ASSET */

if(isset($_POST['update'])){

    $part = $_POST['part_number'];
    $serial = $_POST['serial_number'];
    $brand = $_POST['brand'];
    $description = $_POST['description'];
    $interface = $_POST['interface'];
    $quantity = $_POST['quantity'];
    $type = $_POST['type'];
    $location = $_POST['location'];
    $remark = $_POST['remark'];

    $sql = "UPDATE asset_inventory SET
    part_number='$part',
    serial_number='$serial',
    brand='$brand',
    description='$description',
    interface='$interface',
    quantity='$quantity',
    type='$type',
    location='$location',
    remark='$remark'
    WHERE no=$id";

    $mysqli->query($sql);

    header("Location: asset_inventory.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>

    <title>Edit Asset</title>

    <link rel="stylesheet" href="style.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main" id="main">

    <h2 class="mb-4">Edit Asset</h2>

    <div class="card p-4 shadow-sm">

        <form method="POST">

            <div class="mb-3">
                <label>Part Number</label>
                <input type="text" name="part_number" class="form-control"
                       value="<?php echo $row['part_number']; ?>" required>
            </div>

            <div class="mb-3">
                <label>Serial Number</label>
                <input type="text" name="serial_number" class="form-control"
                       value="<?php echo $row['serial_number']; ?>">
            </div>

            <div class="mb-3">
                <label>Brand</label>
                <input type="text" name="brand" class="form-control"
                       value="<?php echo $row['brand']; ?>">
            </div>

            <div class="mb-3">
                <label>Description</label>
                <input type="text" name="description" class="form-control"
                       value="<?php echo $row['description']; ?>">
            </div>

            <div class="mb-3">
                <label>Interface</label>
                <input type="text" name="interface" class="form-control"
                       value="<?php echo $row['interface']; ?>">
            </div>

            <div class="mb-3">
                <label>Quantity</label>
                <input type="number" name="quantity" class="form-control"
                       value="<?php echo $row['quantity']; ?>">
            </div>

            <div class="mb-3">
                <label>Type</label>
                <input type="text" name="type" class="form-control"
                       value="<?php echo $row['type']; ?>">
            </div>

            <div class="mb-3">
                <label>Location</label>
                <input type="text" name="location" class="form-control"
                       value="<?php echo $row['location']; ?>">
            </div>

            <div class="mb-3">
                <label>Remark</label>
                <textarea name="remark" class="form-control"><?php echo $row['remark']; ?></textarea>
            </div>

            <button type="submit" name="update" class="btn btn-warning">
                Update Asset
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
