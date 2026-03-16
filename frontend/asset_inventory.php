<?php
session_start();

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

require_once "../includes/db_connect.php";

$role = $_SESSION['role'];

$search = "";

if(isset($_GET['search'])){
    $search = $mysqli->real_escape_string($_GET['search']);
}

$sql = "SELECT * FROM asset_inventory
WHERE part_number LIKE '%$search%'
OR serial_number LIKE '%$search%'";

$result = $mysqli->query($sql);
?>

<!DOCTYPE html>
<html>
<head>

    <title>Asset Inventory</title>

    <link rel="stylesheet" href="style.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">

</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main" id="main">

    <h2 class="mb-4">Asset Inventory</h2>

    <form method="GET" class="mb-3">

        <div class="input-group">

            <input type="text"
                   name="search"
                   class="form-control"
                   placeholder="Search asset..."
                   value="<?php echo $search ?>">

            <button class="btn btn-warning">
                <i class="fa fa-search"></i>
            </button>

        </div>

    </form>

    <?php if($role == "Administrator" || $role == "System Admin"): ?>

        <a href="asset_add.php" class="btn btn-warning mb-3">
            <i class="fa fa-plus"></i> Add Asset
        </a>

    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-0">

            <div class="table-responsive" style="overflow-x:auto;">

                <table id="assetTable" class="table table-striped">

                    <thead>

                    <tr>

                        <th>Part Number</th>
                        <th>Serial Number</th>
                        <th>Brand</th>
                        <th>Description</th>
                        <th>Interface</th>
                        <th>Quantity</th>
                        <th>Type</th>
                        <th>Location</th>
                        <th>Remark</th>
                        <th>Actions</th>

                    </tr>

                    </thead>

                    <tbody>

                    <?php if($result->num_rows > 0): ?>

                        <?php while($row = $result->fetch_assoc()): ?>

                            <tr>

                                <td><?php echo $row['part_number']; ?></td>
                                <td><?php echo $row['serial_number']; ?></td>
                                <td><?php echo $row['brand']; ?></td>
                                <td><?php echo $row['description']; ?></td>
                                <td><?php echo $row['interface']; ?></td>
                                <td><?php echo $row['quantity']; ?></td>
                                <td><?php echo $row['type']; ?></td>
                                <td><?php echo $row['location']; ?></td>
                                <td><?php echo $row['remark']; ?></td>

                                <td>

                                    <?php if($role == "Administrator" || $role == "System Admin"): ?>

                                        <div class="d-flex gap-2">

                                            <a href="asset_edit.php?id=<?php echo $row['no']; ?>"
                                               class="btn btn-sm btn-primary">
                                                <i class="fa fa-pen"></i>
                                            </a>

                                            <a href="../backend/asset_delete.php?id=<?php echo $row['no']; ?>"
                                               class="btn btn-sm btn-danger"
                                               onclick="return confirm('Delete this asset?')">
                                                <i class="fa fa-trash"></i>
                                            </a>

                                        </div>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="10" class="text-center text-muted">
                                No assets found
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>
        </div>
    </div>

</div>

<?php include "layout/footer.php"; ?>

<script src="https://code.jquery.com/jquery-3.7.1.js"></script>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

<script>

    $(document).ready(function(){

        $('#assetTable').DataTable({

            pageLength: 10,

            dom: 'Bfrtip',

            buttons: [
                'excel',
                'pdf',
                'print'
            ]

        });

    });

</script>

</body>
</html>
