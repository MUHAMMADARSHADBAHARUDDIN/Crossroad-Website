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

/*
    ✅ SAME STYLE AS ASSET INVENTORY
    - No page refresh while searching
    - DataTables pagination
    - Comma search supported
    - No left/right scrollbar
*/
$stmt = $mysqli->prepare("
SELECT *
FROM stock_out_history
ORDER BY stock_out_date DESC
");

if(!$stmt){
    die("SQL Error: " . $mysqli->error);
}

$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Asset Stock Out History</title>

    <link rel="stylesheet" href="style.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

    <script src="https://code.jquery.com/jquery-3.7.1.js"></script>

<style>
html, body{
    overflow-x:hidden !important;
}

.main{
    overflow-x:hidden !important;
    max-width:100%;
}

.table-responsive{
    overflow-x:hidden !important;
    width:100%;
}

#stockOutTable{
    width:100% !important;
    table-layout:fixed;
}

#stockOutTable th,
#stockOutTable td{
    white-space:normal !important;
    word-break:break-word;
    overflow-wrap:anywhere;
    vertical-align:middle;
}

#stockOutTable tbody tr:hover{
    background:#fff3cd !important;
}

#stockOutTable_wrapper{
    width:100%;
    overflow-x:hidden !important;
}

#stockOutTable_wrapper .row{
    margin-left:0 !important;
    margin-right:0 !important;
}

#stockOutTable_wrapper .dataTables_length{
    padding-left:0;
}

#stockOutTable_wrapper .dataTables_info{
    padding-top:0;
    font-size:14px;
    color:#6c757d;
}

#stockOutTable_wrapper .dataTables_paginate{
    padding-top:0;
}

#stockOutTable_wrapper .page-item .page-link{
    border-radius:8px;
    margin:0 3px;
    border:none;
}

#stockOutTable_wrapper .page-item.active .page-link{
    background-color:#ffc107;
    color:#000;
}

.stockout-search-hint{
    font-size:13px;
    color:#6c757d;
    margin-top:-8px;
    margin-bottom:15px;
}

@media(max-width:768px){
    #stockOutTable_wrapper .stockout-bottom-row{
        gap:10px;
    }

    #stockOutTable_wrapper .dataTables_info,
    #stockOutTable_wrapper .dataTables_paginate{
        text-align:left !important;
        justify-content:flex-start !important;
    }
}
</style>

</head>
<body>

<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main">

    <h2 class="mb-4">Asset Stock Out History</h2>

    <form method="GET" class="mb-2" onsubmit="return false;">
        <div class="input-group">
            <input
                type="text"
                id="liveStockOutSearch"
                name="search"
                class="form-control"
                placeholder="Search by Part Number / Serial / Remark... Example: dell, arshad"
                value="<?php echo htmlspecialchars($search); ?>"
                autocomplete="off"
            >

            <button type="button" class="btn btn-warning">
                <i class="fa fa-search"></i>
            </button>
        </div>
    </form>
    <div class="table-responsive">

    <table class="table table-striped table-hover" id="stockOutTable">
    <thead>
        <tr>
            <th>Part Number</th>
            <th>Serial Number</th>
            <th>Remark</th>
            <th>Stocked Out By</th>
            <th>Date</th>
            <th>Action</th>
        </tr>
    </thead>

    <tbody>
        <?php if($result && $result->num_rows > 0): ?>
            <?php while($row = $result->fetch_assoc()): ?>

                <?php
                $rawDate = $row['stock_out_date'] ?? '';

                if(!empty($rawDate) && strtotime($rawDate)){
                    $date = date("d/m/Y", strtotime($rawDate));
                    $time = date("H:i:s", strtotime($rawDate));
                } else {
                    $date = "-";
                    $time = "";
                }

                $searchText = strtolower(
                    ($row['part_number'] ?? '') . ' ' .
                    ($row['serial_number'] ?? '') . ' ' .
                    ($row['location'] ?? '') . ' ' .
                    ($row['remark'] ?? '') . ' ' .
                    ($row['stock_out_by'] ?? '') . ' ' .
                    ($row['stock_out_date'] ?? '') . ' ' .
                    $date . ' ' .
                    $time
                );
                ?>

                <tr data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8'); ?>">
                    <td><?= htmlspecialchars($row['part_number'] ?? ''); ?></td>
                    <td><?= htmlspecialchars($row['serial_number'] ?? ''); ?></td>
                    <td><?= htmlspecialchars($row['remark'] ?? ''); ?></td>
                    <td><?= htmlspecialchars($row['stock_out_by'] ?? ''); ?></td>

                    <td>
                        <?= htmlspecialchars($date); ?><br>
                        <small class="text-muted"><?= htmlspecialchars($time); ?></small>
                    </td>

                    <td>
                    <?php if($canDelete): ?>
                        <button
                            class="btn btn-sm btn-danger"
                            onclick="deleteStockOutHistory(<?= (int)$row['id']; ?>)">
                            <i class="fa fa-trash"></i>
                        </button>
                    <?php else: ?>
                        <span class="badge bg-secondary">View Only</span>
                    <?php endif; ?>
                    </td>
                </tr>

            <?php endwhile; ?>
        <?php endif; ?>
    </tbody>
    </table>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

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
function deleteStockOutHistory(id){
    if(!confirm("Delete this stock out history record?")){
        return;
    }

    fetch("../backend/delete_stockout_history.php", {
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
let stockOutTable;

/*
    ✅ DATATABLE PAGINATION + LIVE SEARCH
    - No left/right scrollbar
    - Info shows bottom left
    - Page number shows bottom right
    - Comma search supported
*/
$.fn.dataTable.ext.search.push(function(settings, data, dataIndex){
    if(settings.nTable.id !== "stockOutTable"){
        return true;
    }

    const input = document.getElementById("liveStockOutSearch");
    const keyword = input ? input.value.toLowerCase().trim() : "";

    if(keyword === ""){
        return true;
    }

    const terms = keyword
        .split(",")
        .map(term => term.trim())
        .filter(term => term !== "");

    const rowNode = settings.aoData[dataIndex].nTr;
    const searchText = rowNode ? (rowNode.getAttribute("data-search") || "") : "";

    for(let i = 0; i < terms.length; i++){
        if(!searchText.includes(terms[i])){
            return false;
        }
    }

    return true;
});

$(document).ready(function(){

    stockOutTable = $("#stockOutTable").DataTable({
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        ordering: true,
        searching: true,
        autoWidth: false,
        scrollX: false,
        order: [[4, "desc"]],
        dom:
            "<'row mb-2 align-items-center'<'col-md-6'l>>" +
            "rt" +
            "<'row mt-3 align-items-center stockout-bottom-row'<'col-md-6'i><'col-md-6 d-flex justify-content-end'p>>",
        language: {
            zeroRecords: "No records found",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ stock out records"
        },
        columnDefs: [
            { width: "18%", targets: 0 },
            { width: "18%", targets: 1 },
            { width: "25%", targets: 2 },
            { width: "17%", targets: 3 },
            { width: "14%", targets: 4 },
            { width: "8%", targets: 5, orderable: false }
        ]
    });

    $("#liveStockOutSearch").on("input", function(){
        stockOutTable.draw();

        let keyword = this.value.trim();
        let newUrl = "stock_out.php";

        if(keyword !== ""){
            newUrl += "?search=" + encodeURIComponent(keyword);
        }

        if(window.history.replaceState){
            window.history.replaceState({}, document.title, newUrl);
        }
    });

    stockOutTable.draw();
});
</script>

</body>
</html>