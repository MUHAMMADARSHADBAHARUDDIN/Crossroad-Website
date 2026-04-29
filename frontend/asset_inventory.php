<?php
global $mysqli;
session_start();

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";

if(!hasPermission($mysqli, "inventory_view")){
    die("Access denied");
}

$role = $_SESSION['role'] ?? "UNKNOWN";
$username = $_SESSION['username'];

$canAdd = hasPermission($mysqli, "inventory_add");

$search = "";

if(isset($_GET['search'])){
    $search = trim($_GET['search']);
}

$searchLike = "%" . $search . "%";

$stmt = $mysqli->prepare("
SELECT
    part_number,
    brand,
    description,
    GROUP_CONCAT(serial_number SEPARATOR ' ') AS serial_numbers,
    MAX(date_received) AS date_received,
    COUNT(*) AS total_qty,
    MIN(created_by) AS created_by
FROM asset_inventory
WHERE
    part_number LIKE ? OR
    brand LIKE ? OR
    description LIKE ? OR
    serial_number LIKE ?
GROUP BY part_number, brand, description
ORDER BY part_number ASC
");

if(!$stmt){
    die("SQL Error: " . $mysqli->error);
}

$stmt->bind_param("ssss", $searchLike, $searchLike, $searchLike, $searchLike);
$stmt->execute();
$result = $stmt->get_result();
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

<form method="GET" class="mb-3" onsubmit="return false;">
    <div class="input-group">
        <input
            type="text"
            id="liveAssetSearch"
            name="search"
            class="form-control"
            placeholder="Search asset..."
            value="<?php echo htmlspecialchars($search); ?>"
            autocomplete="off"
        >

        <button type="button" class="btn btn-warning">
            <i class="fa fa-search"></i>
        </button>
    </div>
</form>

<?php if($canAdd): ?>
<a href="asset_add.php" class="btn btn-warning mb-3">
    <i class="fa fa-plus"></i> Stock Receive
</a>
<?php endif; ?>

<table class="table table-striped table-hover" id="assetInventoryTable">
<thead>
<tr>
    <th>Part Number</th>
    <th>Brand</th>
    <th>Description</th>
    <th>Quantity</th>
</tr>
</thead>

<tbody>

<?php while($row = $result->fetch_assoc()): ?>

<tr
data-search="<?=
htmlspecialchars(
    strtolower(
        ($row['part_number'] ?? '') . ' ' .
        ($row['brand'] ?? '') . ' ' .
        ($row['description'] ?? '') . ' ' .
        ($row['serial_numbers'] ?? '') . ' ' .
        ($row['total_qty'] ?? '')
    ),
    ENT_QUOTES,
    'UTF-8'
);
?>"
onclick="viewSerial(<?= htmlspecialchars(json_encode($row['part_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)"
style="cursor:pointer;"
>

<td><?php echo htmlspecialchars($row['part_number'] ?? ''); ?></td>
<td><?php echo htmlspecialchars($row['brand'] ?? ''); ?></td>
<td><?php echo htmlspecialchars($row['description'] ?? ''); ?></td>
<td><?php echo htmlspecialchars($row['total_qty'] ?? '0'); ?></td>

</tr>

<?php endwhile; ?>

</tbody>
</table>

</div>

<div class="modal fade" id="serialModal">
<div class="modal-dialog modal-lg">
    <div class="modal-content">

      <div class="modal-header">
        <h5>Serial Numbers</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body" id="serialContent"></div>

    </div>
  </div>
</div>

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

<div class="modal fade" id="detailModal">
  <div class="modal-dialog">
    <div class="modal-content">

      <div class="modal-header">
        <h5>Asset Details</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body" id="detailContent"></div>

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
    }, function(data){
        if(data.trim() === "success"){
            location.reload();
        }else{
            alert(data);
        }
    });
}

function deleteAssetDirect(id, serial){
    if(!confirm("Delete asset serial " + serial + " permanently?\n\nThis will NOT go to Stock Out History.")){
        return;
    }

    $.post("../backend/delete_asset_direct.php",
    {
        id: id
    }, function(data){
        if(data.trim() === "success"){
            location.reload();
        }else{
            alert(data);
        }
    });
}

let deleteId = 0;

function confirmDelete(id, serial){
    deleteId = id;
    document.getElementById("confirmText").innerHTML =
        "Stock out serial: <b>" + serial + "</b>?";

    var modal = new bootstrap.Modal(document.getElementById('confirmModal'));
    modal.show();
}

function deleteSerial(){
    $.post("../frontend/asset_delete.php", {id: deleteId}, function(data){
        if(data.trim() === "success"){
            location.reload();
        }else{
            alert(data);
        }
    });
}

function viewDetail(id){
    $.post("../backend/get_asset_detail.php",{id:id},function(data){
        $("#detailContent").html(data);

        var modal = new bootstrap.Modal(document.getElementById('detailModal'));
        modal.show();
    });
}
</script>

<script>
const liveAssetSearch = document.getElementById("liveAssetSearch");
const clearAssetSearch = document.getElementById("clearAssetSearch");

function filterAssetTable(){
    const keyword = liveAssetSearch.value.toLowerCase().trim();
    const rows = document.querySelectorAll("#assetInventoryTable tbody tr[data-search]");

    rows.forEach(row => {
        const text = row.dataset.search || "";
        row.style.display = text.includes(keyword) ? "" : "none";
    });
}

liveAssetSearch.addEventListener("input", filterAssetTable);

clearAssetSearch.addEventListener("click", function(){
    liveAssetSearch.value = "";
    filterAssetTable();

    if(window.location.search){
        window.location.href = "asset_inventory.php";
    }
});
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