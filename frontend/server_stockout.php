<?php
session_start();
require_once "../includes/db_connect.php";

if(!isset($_SESSION['username'])){
    exit("No session");
}

$role = $_SESSION['role'];
$username = $_SESSION['username'];

// ✅ VIEW ONLY CONTROL (NO MORE ACCESS DENIED)
$canEdit = in_array($role, ["Administrator","System Admin","User (Technical)"]);

// SEARCH
$search = "";
if(isset($_GET['search'])){
    $search = $mysqli->real_escape_string($_GET['search']);
}

// ✅ FETCH SERVER STOCK OUT HISTORY
$sql = "
SELECT *
FROM server_stockout_history
WHERE server_name LIKE '%$search%'
   OR serial_number LIKE '%$search%'
ORDER BY stock_out_date DESC
";

$result = $mysqli->query($sql);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Server Stock Out History</title>

    <link rel="stylesheet" href="style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">

    <h2 class="mb-4">Server Stock Out History</h2>

    <!-- SEARCH -->
    <form method="GET" class="mb-3">
        <div class="input-group">
            <input type="text" name="search" class="form-control"
                   placeholder="Search by Server Name / Serial..."
                   value="<?php echo $search ?>">
            <button class="btn btn-warning">
                <i class="fa fa-search"></i>
            </button>
        </div>
    </form>

    <!-- TABLE -->
    <table class="table table-striped">
    <thead>
        <tr>
            <th>Server Name</th>
            <th>Machine Type</th>
            <th>Serial Number</th>
            <th>Location</th>
            <th>Status</th>
            <th>Remark (Stock Out)</th>
            <th>Tester</th>
            <th>Stocked Out By</th>
            <th>Date</th>
        </tr>
    </thead>

    <tbody>

    <?php if($result && $result->num_rows > 0): ?>
        <?php while($row = $result->fetch_assoc()): ?>
        <tr>
            <td><?php echo $row['server_name']; ?></td>
            <td><?php echo $row['machine_type']; ?></td>
            <td><?php echo $row['serial_number']; ?></td>
            <td><?php echo $row['location']; ?></td>

            <?php
            $statusColor = ($row['status'] == 'Okay') ? 'success' : 'danger';
            ?>
            <td>
                <span class="badge bg-<?php echo $statusColor; ?>">
                    <?php echo $row['status']; ?>
                </span>
            </td>

            <td><?php echo $row['remark'] ?: '-'; ?></td>
            <td><?php echo $row['tester'] ?: '-'; ?></td>
            <td><?php echo $row['stock_out_by']; ?></td>
            <td><?php echo $row['stock_out_date']; ?></td>
        </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr>
            <td colspan="9" class="text-center">No records found</td>
        </tr>
    <?php endif; ?>

    </tbody>
    </table>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
function toggleSidebar(){
    const sidebar = document.getElementById("sidebar");
    const main = document.querySelector(".main");
    const btn = document.getElementById("menuBtn");

    sidebar.classList.toggle("collapsed");
    main.classList.toggle("expanded");
    btn.classList.toggle("active");
}
</script>

</body>
</html>