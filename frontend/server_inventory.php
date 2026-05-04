<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/search_helper.php";

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

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

$params = [];
$types = "";

$whereSql = buildCommaSearchWhere(
    $search,
    [
        "server_name",
        "machine_type",
        "brand",
        "serial_number",
        "location",
        "status",
        "tester",
        "date_testing"
    ],
    $params,
    $types
);

$sql = "
SELECT
    server_name,
    machine_type,
    brand,
    COUNT(*) AS total_qty,
    SUM(CASE WHEN status = 'Okay' THEN 1 ELSE 0 END) AS ok_qty,
    SUM(CASE WHEN status = 'Faulty' THEN 1 ELSE 0 END) AS faulty_qty
FROM server_inventory
$whereSql
GROUP BY server_name, machine_type, brand
ORDER BY server_name ASC
";

$stmt = $mysqli->prepare($sql);

if(!$stmt){
    die("SQL Error: " . $mysqli->error);
}

if(!empty($params)){
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
<title>Server Inventory</title>

<link rel="stylesheet" href="style.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.js"></script>
</head>

<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">

<h2 class="mb-4">Server Inventory</h2>

<form method="GET" class="mb-3" onsubmit="return false;">
    <div class="input-group">
        <input
            type="text"
            id="liveServerSearch"
            name="search"
            class="form-control"
            placeholder="Search server..."
            value="<?= htmlspecialchars($search) ?>"
            autocomplete="off"
        >

        <button type="button" class="btn btn-warning">
            <i class="fa fa-search"></i>
        </button>

    </div>
</form>

<?php if($canAdd): ?>
<a href="server_add.php" class="btn btn-warning mb-3">
    <i class="fa fa-plus"></i> Add Server
</a>
<?php endif; ?>

<table class="table table-striped table-hover" id="serverInventoryTable">
<thead>
<tr>
    <th>Server Name</th>
    <th>Machine Type</th>
    <th>Brand</th>
    <th>Status</th>
    <th>Quantity</th>
</tr>
</thead>

<tbody>

<?php while($row = $result->fetch_assoc()): ?>

<?php
$statusSearchText = "";

if(($row['ok_qty'] ?? 0) > 0){
    $statusSearchText .= " okay";
}

if(($row['faulty_qty'] ?? 0) > 0){
    $statusSearchText .= " faulty";
}
?>

<tr
data-search="<?=
htmlspecialchars(
    strtolower(
        ($row['server_name'] ?? '') . ' ' .
        ($row['machine_type'] ?? '') . ' ' .
        ($row['brand'] ?? '') . ' ' .
        ($row['serial_numbers'] ?? '') . ' ' .
        $statusSearchText . ' ' .
        ($row['total_qty'] ?? '')
    ),
    ENT_QUOTES,
    'UTF-8'
);
?>"
onclick="viewServer(<?= htmlspecialchars(json_encode($row['server_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>, <?= htmlspecialchars(json_encode($row['machine_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)"
style="cursor:pointer;"
>

<td><?= htmlspecialchars($row['server_name'] ?? ''); ?></td>
<td><?= htmlspecialchars($row['machine_type'] ?? ''); ?></td>
<td><?= htmlspecialchars($row['brand'] ?? ''); ?></td>
<td>
    <?php if($row['ok_qty'] > 0): ?>
        <span class="badge bg-success"><?= htmlspecialchars($row['ok_qty']) ?> Okay</span>
    <?php endif; ?>

    <?php if($row['faulty_qty'] > 0): ?>
        <span class="badge bg-danger"><?= htmlspecialchars($row['faulty_qty']) ?> Faulty</span>
    <?php endif; ?>
</td>
<td><?= htmlspecialchars($row['total_qty']); ?></td>

</tr>

<?php endwhile; ?>

</tbody>
</table>

</div>

<div class="modal fade" id="serialModal">
<div class="modal-dialog modal-xl">
<div class="modal-content">

<div class="modal-header">
<h5>Server Serial List</h5>
<button class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body" id="serialContent"></div>

</div>
</div>
</div>

<div class="modal fade" id="serverRemarkModal">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">Server Stock Out</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <p id="serverSelectedSerial"></p>

        <textarea id="serverRemarkInput" class="form-control"
            placeholder="Enter stock out reason..."></textarea>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger" onclick="submitServerStockOut()">Confirm</button>
      </div>

    </div>
  </div>
</div>

<div class="modal fade" id="serverDetailModal">
  <div class="modal-dialog">
    <div class="modal-content">

      <div class="modal-header">
        <h5>Server Details</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body" id="serverDetailContent"></div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
function viewServer(name, type){
    $.post("../backend/get_server_serials.php",
    {name:name, type:type},
    function(data){
        $("#serialContent").html(data);
        new bootstrap.Modal(document.getElementById('serialModal')).show();
    });
}

let serverSelectedId = 0;

function openServerRemarkModal(id, serial){
    serverSelectedId = id;

    document.getElementById("serverSelectedSerial").innerHTML =
        "Serial: <b>" + serial + "</b>";

    document.getElementById("serverRemarkInput").value = "";

    var modal = new bootstrap.Modal(document.getElementById('serverRemarkModal'));
    modal.show();
}

function submitServerStockOut(){
    let remark = document.getElementById("serverRemarkInput").value;

    $.post("../backend/server_stock_out.php",
    {
        id: serverSelectedId,
        remark: remark
    }, function(data){
        if(data.trim() == "success"){
            location.reload();
        }else{
            alert(data);
        }
    });
}

function deleteServerDirect(id, serial){
    if(!confirm("Delete server serial " + serial + " permanently?\n\nThis will NOT go to Server Stock Out History.")){
        return;
    }

    $.post("../backend/delete_server_direct.php",
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

function viewServerDetail(id){
    $.post("../backend/get_server_detail.php",{id:id},function(data){
        $("#serverDetailContent").html(data);
        new bootstrap.Modal(document.getElementById('serverDetailModal')).show();
    });
}
</script>

<script>
const liveServerSearch = document.getElementById("liveServerSearch");
const clearServerSearch = document.getElementById("clearServerSearch");

function filterServerTable(){
    const keyword = liveServerSearch.value.toLowerCase().trim();
    const rows = document.querySelectorAll("#serverInventoryTable tbody tr[data-search]");

    rows.forEach(row => {
        const text = row.dataset.search || "";
        row.style.display = text.includes(keyword) ? "" : "none";
    });
}

liveServerSearch.addEventListener("input", filterServerTable);

clearServerSearch.addEventListener("click", function(){
    liveServerSearch.value = "";
    filterServerTable();

    if(window.location.search){
        window.location.href = "server_inventory.php";
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