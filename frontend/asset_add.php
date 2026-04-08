<?php
session_start();
require_once "../includes/db_connect.php";

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

$role = $_SESSION['role'];
$username = $_SESSION['username'];

if(!in_array($role, ["Administrator","System Admin","User (Technical)"])){
    header("Location: ../frontend/asset_inventory.php");
    exit();
}

$error = "";

if(isset($_POST['add'])){

    $part = trim($_POST['part']);
    $serial = trim($_POST['serial']);
    $brand = trim($_POST['brand']);
    $desc = trim($_POST['desc']);
    $type = trim($_POST['type']);
    $location = trim($_POST['location']);
    $remark = trim($_POST['remark']);

    // ✅ VALIDATION
    if($part == "" || $serial == ""){
        $error = "Part Number and Serial Number are required!";
    } else {

        // ✅ CHECK DUPLICATE SERIAL
        $check = $mysqli->query("SELECT * FROM asset_inventory WHERE serial_number='$serial'");
        if($check->num_rows > 0){
            $error = "Serial Number already exists!";
        } else {

            // ✅ INSERT
            $mysqli->query("
            INSERT INTO asset_inventory
            (part_number,serial_number,brand,description,type,location,remark,created_by)
            VALUES
            ('$part','$serial','$brand','$desc','$type','$location','$remark','$username')
            ");

            // ✅ ACTIVITY LOG (PLACE HERE AFTER VARIABLES ARE DEFINED)
            $mysqli->query("
            INSERT INTO activity_logs
            (username, role, action_type, description)
            VALUES
            (
                '$username',
                '$role',
                'Stock In',
                'Added asset: Part Number $part, Serial Number $serial, Brand $brand, Type $type, Location $location, Remark $remark'
            )
            ");

            header("Location: ../frontend/asset_inventory.php");
            exit();
        }
    }
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

    <h2 class="mb-4">Stock Receive</h2>

    <div class="">

        <?php if($error != ""): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">

            <div class="row">

                <div class="col-md-6 mb-3">
                    <label>Part Number *</label>
                    <input type="text" name="part" class="form-control" required>
                </div>

                <div class="col-md-6 mb-3">
                    <label>Serial Number *</label>
                    <input type="text" name="serial" class="form-control" required>
                </div>

                <div class="col-md-6 mb-3">
                    <label>Brand</label>
                    <input type="text" name="brand" class="form-control">
                </div>

                <div class="col-md-6 mb-3">
                    <label>Type</label>
                    <input type="text" name="type" class="form-control">
                </div>

                <div class="col-md-6 mb-3">
                    <label>Location</label>
                    <input type="text" name="location" class="form-control">
                </div>

                <div class="col-12 mb-3">
                    <label>Description</label>
                    <input type="text" name="desc" class="form-control">
                </div>

                <div class="col-12 mb-3">
                    <label>Remark (Asset Info)</label>
                    <textarea name="remark" class="form-control"></textarea>
                </div>

            </div>

           <button class="btn btn-warning" name="add">
                <i class="fa fa-plus"></i> Add Asset
            </button>

            <a href="../frontend/asset_inventory.php" class="btn btn-secondary">
                Cancel
            </a>

        </form>

    </div>

</div>

<?php include "layout/footer.php"; ?>

</body>
</html>