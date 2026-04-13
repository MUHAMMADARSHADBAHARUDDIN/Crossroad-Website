<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

require_once "../includes/db_connect.php";

$role = $_SESSION['role'];
$username = $_SESSION['username'];

$search = "";

if(isset($_GET['search'])){
    $search = $mysqli->real_escape_string($_GET['search']);
}

$sql = "
SELECT
    part_number,
    brand,
    description,
    type,
    MAX(date_received) AS date_received,
    COUNT(*) AS total_qty,
    MIN(created_by) AS created_by
FROM asset_inventory
WHERE part_number LIKE '%$search%'
GROUP BY part_number
";

$result = $mysqli->query($sql);
?>

<!DOCTYPE html>
<html>
<head>
<title>Asset Inventory</title>

<link rel="stylesheet" href="style.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<script src="https://code.jquery.com/jquery-3.7.1.js"></script>
</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">

<h2 class="mb-4">Asset Inventory</h2>

<form method="GET" class="mb-3">
    <div class="input-group">
        <input type="text" name="search" class="form-control" placeholder="Search..." value="<?php echo $search ?>">
        <button class="btn btn-warning"><i class="fa fa-search"></i></button>
    </div>
</form>

<?php if($role == "Administrator" || $role == "System Admin" || $role == "User (Technical)"): ?>
<a href="asset_add.php" class="btn btn-warning mb-3">
    <i class="fa fa-plus"></i> Stock Receive
</a>
<?php endif; ?>

<table class="table table-striped">
<thead>
<tr>
    <th>Part Number</th>
    <th>Brand</th>
    <th>Description</th>
    <th>Quantity</th>
    <th>Type</th>
    <th>Date Received</th>
</tr>
</thead>

<tbody>

<?php while($row = $result->fetch_assoc()): ?>

<tr onclick="viewSerial('<?php echo $row['part_number']; ?>')" style="cursor:pointer;">

<td><?php echo $row['part_number']; ?></td>
<td><?php echo $row['brand']; ?></td>
<td><?php echo $row['description']; ?></td>
<td><?php echo $row['total_qty']; ?></td>
<td><?php echo $row['type']; ?></td>
<td><?php echo $row['date_received']; ?></td>
</tr>

<?php endwhile; ?>

</tbody>
</table>

</div>

<!-- SERIAL MODAL -->
<div class="modal fade" id="serialModal">
  <div class="modal-dialog">
    <div class="modal-content">

      <div class="modal-header">
        <h5>Serial Numbers</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body" id="serialContent"></div>

    </div>
  </div>
</div>

<!-- CONFIRM MODAL -->
<div class="modal fade" id="confirmModal">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">Confirm Stock Out</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body text-center">
        <p id="confirmText"></p>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger" onclick="deleteSerial()">Confirm</button>
      </div>

    </div>
  </div>
</div>
<!-- REMARK MODAL -->
<div class="modal fade" id="remarkModal">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">Stock Out Remark</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <p id="selectedSerial"></p>

        <textarea id="remarkInput" class="form-control"
            placeholder="Enter remark..."></textarea>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" onclick="submitStockOut()">Submit</button>
      </div>

    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
function viewSerial(part){
    $.post("../backend/get_serials.php",{part:part},function(data){
        $("#serialContent").html(data);
        var modal = new bootstrap.Modal(document.getElementById('serialModal'));
        modal.show();
    });
}
let selectedId = 0;

function openRemarkModal(id, serial){
    selectedId = id;

    document.getElementById("selectedSerial").innerHTML =
        "Serial: <b>" + serial + "</b>";

    document.getElementById("remarkInput").value = "";

    var modal = new bootstrap.Modal(document.getElementById('remarkModal'));
    modal.show();
}

function submitStockOut(){
    let remark = document.getElementById("remarkInput").value;

    $.post("../backend/stock_out.php",
    {
        id: selectedId,
        remark: remark
    }, function(){
        location.reload();
    });
}
// NEW FUNCTION
let deleteId = 0;

function confirmDelete(id, serial){
    deleteId = id;
    document.getElementById("confirmText").innerHTML =
        "Stock out serial: <b>" + serial + "</b>?";

    var modal = new bootstrap.Modal(document.getElementById('confirmModal'));
    modal.show();
}

function deleteSerial(){
    $.post("../frontend/asset_delete.php", {id: deleteId}, function(){
        location.reload();
    });
}
</script>
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