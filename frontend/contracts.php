<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("location: index.html");
    exit();
}

require_once "../includes/db_connect.php";

$role = $_SESSION['role'];
$username = $_SESSION['username'];

/* SEARCH */

$search = "";
if(isset($_GET['search'])){
    $search = $mysqli->real_escape_string($_GET['search']);
}


/* QUERY */

$sql = "SELECT * FROM project_inventory
WHERE Name LIKE '%$search%'
OR contract_name LIKE '%$search%'";

$result = $mysqli->query($sql);



?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Contracts</title>

    <link rel="icon" href="../image/logo.png">

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

    <h2 style="margin-bottom:20px;">Contracts</h2>

    <!-- SEARCH -->

    <form method="GET" class="mb-3">

        <div class="input-group">

            <input type="text"
                   name="search"
                   class="form-control"
                   placeholder="Search contracts..."
                   value="<?php echo $search ?>">

            <button class="btn btn-warning">

                <i class="fa fa-search"></i>

            </button>

        </div>

    </form>

    <!-- ADD BUTTON -->

    <?php if($role == "Administrator" || $role == "System Admin"): ?>

        <a href="contract_add.php" class="btn btn-warning mb-3">

            <i class="fa fa-plus"></i> Add Contract

        </a>

    <?php endif; ?>

    <!-- TABLE -->

    <div class="card shadow-sm">
        <div class="card-body p-0">

            <div class="table-responsive">

        <table id="contractsTable" class="table table-striped">



            <thead>

            <tr>

                <th>Organization</th>
                <th>Contract</th>
                <th>Code</th>
                <th>Start</th>
                <th>End</th>
                <th>Location</th>
                <th>PIC</th>
                <th>Support</th>
                <th>Preventive</th>
                <th>Partner</th>
                <th>Partner PIC</th>
                <th>Remarks</th>
                <th>Actions</th>

            </tr>

            </thead>

            <tbody>

            <?php if($result && $result->num_rows > 0): ?>

                <?php while($row = $result->fetch_assoc()): ?>

                    <tr>

                        <td><?php echo $row['name']; ?></td>
                        <td><?php echo $row['contract_name']; ?></td>
                        <td><?php echo $row['contract_code']; ?></td>
                        <td><?php echo $row['contract_start']; ?></td>
                        <td><?php echo $row['contract_end']; ?></td>
                        <td><?php echo $row['location']; ?></td>
                        <td><?php echo $row['pic']; ?></td>
                        <td><?php echo $row['support_coverage']; ?></td>
                        <td><?php echo $row['preventive_management']; ?></td>
                        <td><?php echo $row['partner']; ?></td>
                        <td><?php echo $row['partner_pic']; ?></td>
                        <td><?php echo $row['remark']; ?></td>

                        <td>

                            <?php if($role == "Administrator" || $role == "System Admin"): ?>

                                <div class="d-flex gap-2">

                                    <a href="contract_edit.php?id=<?php echo $row['no']; ?>"
                                       class="btn btn-sm btn-primary">
                                        <i class="fa fa-pen"></i>
                                    </a>

                                    <a href="../backend/contract_delete.php?id=<?php echo $row['no']; ?>"
                                       class="btn btn-sm btn-danger"
                                       onclick="return confirm('Delete this contract?')">
                                        <i class="fa fa-trash"></i>
                                    </a>

                                </div>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endwhile; ?>

            <?php else: ?>

                <tr>
                    <td colspan="13" class="text-center text-muted">
                        No contracts found
                    </td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>
            </div>
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

        $('#contractsTable').DataTable({

            dom: 'Bfrtip',

            buttons: [

                {
                    extend: 'excel',
                    text: '<i class="fa fa-file-excel"></i> Excel'
                },

                {
                    extend: 'pdf',
                    text: '<i class="fa fa-file-pdf"></i> PDF',

                    orientation: 'landscape',
                    pageSize: 'A4',

                    customize: function(doc){

                        doc.styles.tableHeader.alignment = 'left';

                        doc.content[1].table.widths =
                            Array(doc.content[1].table.body[0].length + 1).join('*').split('');

                    }
                }

            ]

        });

    });

</script>

</body>
</html>