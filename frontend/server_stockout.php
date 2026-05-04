<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/search_helper.php";

if(!isset($_SESSION['username'])){
    exit("No session");
}

if(!hasPermission($mysqli, "inventory_view")){
    die("Access denied");
}

$role = $_SESSION['role'] ?? "UNKNOWN";
$username = $_SESSION['username'];
$canDelete = hasPermission($mysqli, "inventory_delete");

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
        "serial_number",
        "location",
        "status",
        "remark",
        "tester",
        "stock_out_by",
        "stock_out_date"
    ],
    $params,
    $types
);

$stmt = $mysqli->prepare("
SELECT *
FROM server_stockout_history
$whereSql
ORDER BY stock_out_date DESC
");

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

    <form method="GET" class="mb-3" onsubmit="return false;">
        <div class="input-group">
            <input
                type="text"
                id="liveServerStockOutSearch"
                name="search"
                class="form-control"
                placeholder="Search by Server Name / Serial / Status..."
                value="<?php echo htmlspecialchars($search); ?>"
                autocomplete="off"
            >

            <button type="button" class="btn btn-warning">
                <i class="fa fa-search"></i>
            </button>
        </div>
    </form>

    <table class="table table-striped table-hover" id="serverStockOutTable">
    <thead>
        <tr>
            <th>Server Name</th>
            <th>Machine Type</th>
            <th>Serial Number</th>
            <th>Status</th>
            <th>Remark (Stock Out)</th>
            <th>Tester</th>
            <th>Stocked Out By</th>
            <th>Date</th>
            <th>Action</th>
        </tr>
    </thead>

    <tbody>

    <?php if($result && $result->num_rows > 0): ?>
        <?php while($row = $result->fetch_assoc()): ?>
        <tr
        data-search="<?=
        htmlspecialchars(
            strtolower(
                ($row['server_name'] ?? '') . ' ' .
                ($row['machine_type'] ?? '') . ' ' .
                ($row['serial_number'] ?? '') . ' ' .
                ($row['status'] ?? '') . ' ' .
                ($row['remark'] ?? '') . ' ' .
                ($row['tester'] ?? '') . ' ' .
                ($row['stock_out_by'] ?? '') . ' ' .
                ($row['stock_out_date'] ?? '')
            ),
            ENT_QUOTES,
            'UTF-8'
        );
        ?>"
        >
            <td><?php echo htmlspecialchars($row['server_name'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($row['machine_type'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($row['serial_number'] ?? ''); ?></td>
            <?php
            $statusColor = ($row['status'] == 'Okay') ? 'success' : 'danger';
            ?>
            <td>
                <span class="badge bg-<?php echo $statusColor; ?>">
                    <?php echo htmlspecialchars($row['status'] ?? ''); ?>
                </span>
            </td>

            <td><?php echo htmlspecialchars($row['remark'] ?: '-'); ?></td>
            <td><?php echo htmlspecialchars($row['tester'] ?: '-'); ?></td>
            <td><?php echo htmlspecialchars($row['stock_out_by'] ?? ''); ?></td>
             <?php
                $date = date("d/m/Y", strtotime($row['stock_out_date']));
                $time = date("H:i:s", strtotime($row['stock_out_date']));
             ?>

            <td>
                <?= $date ?><br>
                <small class="text-muted"><?= $time ?></small>
            </td>
            <td>
            <?php if($canDelete): ?>
                <button
                    class="btn btn-sm btn-danger"
                    onclick="deleteServerStockOutHistory(<?= (int)$row['id']; ?>)">
                    <i class="fa fa-trash"></i>
                </button>
            <?php else: ?>
                <span class="badge bg-secondary">View Only</span>
            <?php endif; ?>
            </td>
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

<script>
function deleteServerStockOutHistory(id){
    if(!confirm("Delete this server stock out history record?")){
        return;
    }

    fetch("../backend/delete_server_stockout_history.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: "id=" + encodeURIComponent(id)
    })
    .then(response => response.text())
    .then(data => {
        if(data.trim() === "success"){
            location.reload();
        }else{
            alert(data);
        }
    });
}
</script>

<script>
const liveServerStockOutSearch = document.getElementById("liveServerStockOutSearch");
const clearServerStockOutSearch = document.getElementById("clearServerStockOutSearch");

function filterServerStockOutTable(){
    const keyword = liveServerStockOutSearch.value.toLowerCase().trim();
    const rows = document.querySelectorAll("#serverStockOutTable tbody tr[data-search]");

    rows.forEach(row => {
        const text = row.dataset.search || "";
        row.style.display = text.includes(keyword) ? "" : "none";
    });
}

liveServerStockOutSearch.addEventListener("input", filterServerStockOutTable);

clearServerStockOutSearch.addEventListener("click", function(){
    liveServerStockOutSearch.value = "";
    filterServerStockOutTable();

    if(window.location.search){
        window.location.href = "server_stockout.php";
    }
});
</script>

</body>
</html>