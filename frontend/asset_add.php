<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";
require_once "../includes/inventory_report_schema.php";
require_once "../includes/date_helpers.php";

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

$role = $_SESSION['role'] ?? "UNKNOWN";
$username = $_SESSION['username'];
ensureInventoryReportSchema($mysqli);

if(!hasPermission($mysqli, "inventory_add")){
    header("Location: ../frontend/asset_inventory.php");
    exit();
}

$error = "";

if(isset($_POST['add'])){

    $part = trim($_POST['part']);
    $serial = trim($_POST['serial']);
    $brand = trim($_POST['brand']);
    $desc = trim($_POST['desc']);
    $location = trim($_POST['location']);
    $date = appNormalizeDateInput($_POST['date_received'] ?? "");
    $receivedBy = $username;

    if($part == "" || $serial == ""){
        $error = "Part Number and Serial Number are required!";
    } else {

        $checkStmt = $mysqli->prepare("
            SELECT no
            FROM asset_inventory
            WHERE serial_number = ?
            LIMIT 1
        ");
        $checkStmt->bind_param("s", $serial);
        $checkStmt->execute();
        $check = $checkStmt->get_result();

        if($check->num_rows > 0){
            $error = "Serial Number already exists!";
        } else {

            $stmt = $mysqli->prepare("
                INSERT INTO asset_inventory
                (part_number, serial_number, brand, description, location, date_received, created_by, received_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if(!$stmt){
                die("SQL Error: " . $mysqli->error);
            }

            $stmt->bind_param(
                "ssssssss",
                $part,
                $serial,
                $brand,
                $desc,
                $location,
                $date,
                $username,
                $receivedBy
            );

            if($stmt->execute()){
                $assetInventoryId = $stmt->insert_id;
                $quantity = 1;

                $historyStmt = $mysqli->prepare("
                    INSERT INTO asset_stockin_history
                    (asset_inventory_id, part_number, serial_number, brand, description, quantity, location, stock_in_by, received_by, date_received, stock_in_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");

                if($historyStmt){
                    $historyStmt->bind_param(
                        "issssissss",
                        $assetInventoryId,
                        $part,
                        $serial,
                        $brand,
                        $desc,
                        $quantity,
                        $location,
                        $username,
                        $receivedBy,
                        $date
                    );
                    $historyStmt->execute();
                }

                $ip = $_SERVER['REMOTE_ADDR'];
                $time = date("Y-m-d H:i:s");

                $description = "User [$username] added new asset (STOCK IN).
Part Number: $part
Serial Number: $serial
Brand: $brand
Description: $desc
Quantity: $quantity
Location: $location
Received By: $receivedBy
Date Received: $date
IP Address: $ip
Time: $time";

                logActivity(
                    $mysqli,
                    $username,
                    $role,
                    "STOCK IN ASSET",
                    $description
                );

                header("Location: ../frontend/asset_inventory.php");
                exit();

            } else {
                $error = "Insert failed: " . $mysqli->error;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Asset</title>

    <link rel="icon" type="image/png" href="../image/logo.png">
    <link rel="shortcut icon" type="image/png" href="../image/logo.png">
    <link rel="apple-touch-icon" href="../image/logo.png">
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
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
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
                    <label>Location</label>
                    <input type="text" name="location" class="form-control">
                </div>

                <div class="col-12 mb-3">
                    <label>Description</label>
                    <input type="text" name="desc" class="form-control">
                </div>

                <div class="col-12 mb-3">
                    <label>Date Received</label>
                    <input type="date" name="date_received" class="form-control">
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
