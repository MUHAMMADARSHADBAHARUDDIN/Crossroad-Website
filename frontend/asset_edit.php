<?php
session_start();
require_once "../includes/db_connect.php";

if(!isset($_SESSION['username'])){
    header("Location: ../frontend/index.html");
    exit();
}

// ✅ FIX 1: Define session variables
$role = $_SESSION['role'];
$username = $_SESSION['username'];

// ✅ FIX 2: Secure GET
$part = $mysqli->real_escape_string($_GET['id']);

// ✅ GET DATA
$result = $mysqli->query("
SELECT * FROM asset_inventory
WHERE part_number='$part'
LIMIT 1
");

if(!$result){
    die("Query Error: " . $mysqli->error);
}

$row = $result->fetch_assoc();

if(!$row){
    die("No data found");
}

// 🔒 Security
// ✅ ROLE CONTROL
$canEdit = false;

if($role == "Administrator" || $role == "System Admin"){
    $canEdit = true;
}

if($role == "User (Technical)" && $row['created_by'] == $username){
    $canEdit = true;
}

// ✅ UPDATE

if(isset($_POST['update'])){

    // Escape POST data
    $new_part = $mysqli->real_escape_string($_POST['part_number']);
    $new_serial = $mysqli->real_escape_string($_POST['serial_number']);
    $brand = $mysqli->real_escape_string($_POST['brand']);
    $description = $mysqli->real_escape_string($_POST['description']);
    $type = $mysqli->real_escape_string($_POST['type']);
    $location = $mysqli->real_escape_string($_POST['location']);
    $remark = $mysqli->real_escape_string($_POST['remark']);

    // UPDATE query using original part number to locate the row
    $update = $mysqli->query("
        UPDATE asset_inventory SET
            part_number='$new_part',
            serial_number='$new_serial',
            brand='$brand',
            description='$description',
            type='$type',
            location='$location',
            remark='$remark'
        WHERE part_number='$part' AND serial_number='".$row['serial_number']."'
    ");

    if(!$update){
        die("Update Error: " . $mysqli->error);
    }

    // Redirect after update
    header("Location: ../frontend/asset_inventory.php");
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

<div class="main">

    <h2 class="mb-4">Edit Asset</h2>

    <div class="">

        <form method="POST">

            <div class="row">

               <div class="col-md-6 mb-3">
                   <label>Part Number *</label>
                   <input type="text" name="part_number" class="form-control"
                          value="<?php echo $row['part_number']; ?>" required>
               </div>

               <div class="col-md-6 mb-3">
                   <label>Serial Number *</label>
                   <input type="text" name="serial_number" class="form-control"
                          value="<?php echo $row['serial_number']; ?>" required>
               </div>

                <div class="col-md-6 mb-3">
                    <label>Brand</label>
                    <input type="text" name="brand" class="form-control"
                           value="<?php echo $row['brand']; ?>">
                </div>

                <div class="col-md-6 mb-3">
                    <label>Type</label>
                    <input type="text" name="type" class="form-control"
                           value="<?php echo $row['type']; ?>">
                </div>

                <div class="col-md-6 mb-3">
                    <label>Location</label>
                    <input type="text" name="location" class="form-control"
                           value="<?php echo $row['location']; ?>">
                </div>

                <div class="col-12 mb-3">
                    <label>Description</label>
                    <input type="text" name="description" class="form-control"
                           value="<?php echo $row['description']; ?>">
                </div>

                <div class="col-12 mb-3">
                    <label>Remark</label>
                    <textarea name="remark" class="form-control"><?php echo $row['remark']; ?></textarea>
                </div>

            </div>

            <button class="btn btn-warning" name="update">
                Update Asset
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