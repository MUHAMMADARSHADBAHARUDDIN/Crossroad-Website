<?php
session_start();
require_once "../includes/db_connect.php";

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

$role = $_SESSION['role'];
$username = $_SESSION['username'];

// Only allow certain roles
if(!in_array($role, ["Administrator","System Admin","User (Technical)"])){
    die("Access denied");
}

$search = "";
if(isset($_GET['search'])){
    $search = $mysqli->real_escape_string($_GET['search']);
}

// Fetch stock out history
$sql = "
SELECT *
FROM stock_out_history
WHERE part_number LIKE '%$search%'
ORDER BY stock_out_date DESC
";

$result = $mysqli->query($sql);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Stock Out History</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">
    <h2 class="mb-4">Stock Out History</h2>

    <form method="GET" class="mb-3">
        <div class="input-group">
            <input type="text" name="search" class="form-control" placeholder="Search by Part Number..." value="<?php echo $search ?>">
            <button class="btn btn-warning"><i class="fa fa-search"></i></button>
        </div>
    </form>

    <table class="table table-striped">
    <thead>
        <tr>
            <th>Part Number</th>
            <th>Serial Number</th>
            <th>Location</th>
            <th>Remark</th>
            <th>Stocked Out By</th>
            <th>Date</th>
        </tr>
    </thead>
    <tbody>
        <?php while($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?php echo $row['part_number']; ?></td>
                <td><?php echo $row['serial_number']; ?></td>
                <td><?php echo $row['location']; ?></td>
                <td><?php echo $row['remark']; ?></td>
                <td><?php echo $row['stock_out_by']; ?></td>
                <td><?php echo $row['stock_out_date']; ?></td>
            </tr>
        <?php endwhile; ?>
    </tbody>
    </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>