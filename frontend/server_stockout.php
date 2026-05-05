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
    ✅ SAME STYLE AS SERVER INVENTORY
    - No page refresh while searching
    - DataTables pagination
    - Comma search supported
    - No left/right scrollbar
    - Info bottom left, page number bottom right
*/
$stmt = $mysqli->prepare("
SELECT *
FROM server_stockout_history
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
    <title>Server Stock Out History</title>

    <link rel="stylesheet" href="style.css">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

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

    #serverStockOutTable{
        width:100% !important;
        table-layout:fixed;
    }

    #serverStockOutTable th,
    #serverStockOutTable td{
        white-space:normal !important;
        word-break:break-word;
        overflow-wrap:anywhere;
        vertical-align:middle;
        font-size:13px;
    }

    #serverStockOutTable tbody tr:hover{
        background:#fff3cd !important;
    }

    #serverStockOutTable_wrapper{
        width:100%;
        overflow-x:hidden !important;
    }

    #serverStockOutTable_wrapper .row{
        margin-left:0 !important;
        margin-right:0 !important;
    }

    #serverStockOutTable_wrapper .dataTables_length{
        padding-left:0;
    }

    #serverStockOutTable_wrapper .dataTables_info{
        padding-top:0;
        font-size:14px;
        color:#6c757d;
    }

    #serverStockOutTable_wrapper .dataTables_paginate{
        padding-top:0;
    }

    #serverStockOutTable_wrapper .page-item .page-link{
        border-radius:8px;
        margin:0 3px;
        border:none;
    }

    #serverStockOutTable_wrapper .page-item.active .page-link{
        background-color:#ffc107;
        color:#000;
    }

    .server-stockout-search-hint{
        font-size:13px;
        color:#6c757d;
        margin-top:-8px;
        margin-bottom:15px;
    }

    @media(max-width:768px){
        #serverStockOutTable_wrapper .server-stockout-bottom-row{
            gap:10px;
        }

        #serverStockOutTable_wrapper .dataTables_info,
        #serverStockOutTable_wrapper .dataTables_paginate{
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

    <h2 class="mb-4">Server Stock Out History</h2>

    <form method="GET" class="mb-2" onsubmit="return false;">
        <div class="input-group">
            <input
                type="text"
                id="liveServerStockOutSearch"
                name="search"
                class="form-control"
                placeholder="Search by Server Name / Serial / Status... Example: IBM, Okay"
                value="<?php echo htmlspecialchars($search); ?>"
                autocomplete="off"
            >

            <button type="button" class="btn btn-warning">
                <i class="fa fa-search"></i>
            </button>
        </div>
    </form>

    <div class="table-responsive">

    <table class="table table-striped table-hover" id="serverStockOutTable">
    <thead>
        <tr>
            <th>Server Name</th>
            <th>Machine Type</th>
            <th>Serial Number</th>
            <th>Status</th>
            <th>Remark</th>
            <th>Tester</th>
            <th>Stocked Out By</th>
            <th>Date</th>
            <th>Action</th>
        </tr>
    </thead>

    <tbody>

    <?php while($row = $result->fetch_assoc()): ?>

        <?php
        $date = "";
        $time = "";

        if(!empty($row['stock_out_date'])){
            $date = date("d/m/Y", strtotime($row['stock_out_date']));
            $time = date("H:i:s", strtotime($row['stock_out_date']));
        }

        $statusColor = (($row['status'] ?? '') == 'Okay') ? 'success' : 'danger';

        $searchText = strtolower(
            ($row['server_name'] ?? '') . ' ' .
            ($row['machine_type'] ?? '') . ' ' .
            ($row['serial_number'] ?? '') . ' ' .
            ($row['location'] ?? '') . ' ' .
            ($row['status'] ?? '') . ' ' .
            ($row['remark'] ?? '') . ' ' .
            ($row['tester'] ?? '') . ' ' .
            ($row['stock_out_by'] ?? '') . ' ' .
            ($row['stock_out_date'] ?? '') . ' ' .
            $date . ' ' .
            $time
        );
        ?>

        <tr
        data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8'); ?>"
        >
            <td><?php echo htmlspecialchars($row['server_name'] ?? ''); ?></td>

            <td><?php echo htmlspecialchars($row['machine_type'] ?? ''); ?></td>

            <td><?php echo htmlspecialchars($row['serial_number'] ?? ''); ?></td>

            <td>
                <span class="badge bg-<?php echo $statusColor; ?>">
                    <?php echo htmlspecialchars($row['status'] ?? ''); ?>
                </span>
            </td>

            <td><?php echo htmlspecialchars($row['remark'] ?: '-'); ?></td>

            <td><?php echo htmlspecialchars($row['tester'] ?: '-'); ?></td>

            <td><?php echo htmlspecialchars($row['stock_out_by'] ?? ''); ?></td>

            <td>
                <?= htmlspecialchars($date) ?><br>
                <small class="text-muted"><?= htmlspecialchars($time) ?></small>
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

    </tbody>
    </table>

    </div>

</div>

<script src="https://code.jquery.com/jquery-3.7.1.js"></script>

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
let serverStockOutTable;

/*
    ✅ DATATABLE PAGINATION + LIVE SEARCH
    - No page refresh
    - No left/right scrollbar
    - Info bottom left
    - Page numbers bottom right
    - Comma search supported
*/
$.fn.dataTable.ext.search.push(function(settings, data, dataIndex){
    if(settings.nTable.id !== "serverStockOutTable"){
        return true;
    }

    const input = document.getElementById("liveServerStockOutSearch");
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

    serverStockOutTable = $("#serverStockOutTable").DataTable({
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        ordering: true,
        searching: true,
        autoWidth: false,
        scrollX: false,
        order: [[7, "desc"]],
        dom:
            "<'row mb-2 align-items-center'<'col-md-6'l>>" +
            "rt" +
            "<'row mt-3 align-items-center server-stockout-bottom-row'<'col-md-6'i><'col-md-6 d-flex justify-content-end'p>>",
        language: {
            zeroRecords: "No records found",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ server stock out records"
        },
        columnDefs: [
            { width: "15%", targets: 0 },
            { width: "13%", targets: 1 },
            { width: "14%", targets: 2 },
            { width: "8%", targets: 3 },
            { width: "16%", targets: 4 },
            { width: "10%", targets: 5 },
            { width: "10%", targets: 6 },
            { width: "9%", targets: 7 },
            {
                width: "5%",
                targets: 8,
                orderable: false,
                searchable: false
            }
        ]
    });

    $("#liveServerStockOutSearch").on("input", function(){
        serverStockOutTable.draw();

        let keyword = this.value.trim();
        let newUrl = "server_stockout.php";

        if(keyword !== ""){
            newUrl += "?search=" + encodeURIComponent(keyword);
        }

        if(window.history.replaceState){
            window.history.replaceState({}, document.title, newUrl);
        }
    });

    serverStockOutTable.draw();
});
</script>

</body>
</html>