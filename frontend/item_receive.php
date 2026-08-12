<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/receiving_schema.php";
if(!isset($_SESSION['username'])){ header("Location:index.html"); exit; }
if(!hasPermission($mysqli, "receiving_view")){ die("Access denied"); }
ensureReceivingSchema($mysqli);
$canAdd = hasPermission($mysqli, "receiving_add");
$rows = $mysqli->query("SELECT * FROM receiving_records ORDER BY received_date DESC,id DESC");
function irE($value){ return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html><head>
<title>Item Receive</title>
<link rel="icon" type="image/png" href="../image/logo.png">
<link rel="stylesheet" href="style.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<style>.ir-card{height:auto!important;display:block!important;cursor:default!important;border:1px solid #d9dee7!important}#irTable{border:1px solid #d9dee7}#irTable th{background:#f3f5f8;padding:12px;border-bottom:2px solid #d4dae2}#irTable td{padding:12px;border-bottom:1px solid #e1e5eb;vertical-align:middle}.modal-content{border-radius:12px}.image-preview-close{position:absolute;top:-18px;right:-18px;z-index:5;width:42px;height:42px;border:3px solid #fff;border-radius:50%;display:flex;align-items:center;justify-content:center;padding:0;background:#dc3545;color:#fff;font-size:30px;font-weight:700;line-height:1;box-shadow:0 4px 14px rgba(0,0,0,.38);cursor:pointer}.image-preview-close:hover{background:#b02a37;transform:scale(1.05)}@media(max-width:767px){.image-preview-close{top:-14px;right:0}}</style>
</head><body>
<?php include "layout/header.php"; include "layout/sidebar.php"; ?>
<main class="main">
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2>Item Receive</h2><div class="text-muted">Track received items before they are manually entered into inventory.</div></div><div class="d-flex gap-2"><button class="btn btn-outline-dark" data-bs-toggle="modal" data-bs-target="#reportModal"><i class="fa fa-file-pdf"></i> Create Report</button><?php if($canAdd): ?><a href="receive_item.php" class="btn btn-warning"><i class="fa fa-plus"></i> Receive Item</a><?php endif; ?></div></div>
<?php if(isset($_GET['saved'])): ?><div class="alert alert-success">Item received successfully.</div><?php endif; ?>
<div class="card ir-card"><div class="card-body"><div class="table-responsive"><table id="irTable" class="table table-hover"><thead><tr><th>Date</th><th>Received By</th><th>Item</th><th>Part / Serial</th><th>Qty</th><th>Rack</th><th>Attachment</th><th>Remark</th></tr></thead><tbody>
<?php while($row = $rows->fetch_assoc()): ?>
<?php
$attachmentUrl = $row['attachment_file_name'] ? "../uploads/item_receive/" . rawurlencode($row['attachment_file_name']) : "";
$attachmentExtension = strtolower(pathinfo((string)$row['attachment_file_name'], PATHINFO_EXTENSION));
$isImageAttachment = in_array($attachmentExtension, ['png','jpg','jpeg','gif','webp'], true);
?>
<tr><td><?= irE(date('d/m/Y',strtotime($row['received_date']))) ?></td><td><?= irE($row['received_by']) ?></td><td><strong><?= irE($row['item_name']) ?></strong><div class="small text-muted"><?= irE($row['item_type']) ?></div></td><td><?= irE($row['part_number'] ?: '-') ?><div class="small text-muted"><?= irE($row['serial_number'] ?: '-') ?></div></td><td><?= (int)$row['quantity'] ?></td><td><?= irE($row['rack_location']) ?></td><td><?php if($attachmentUrl): ?><?php if($isImageAttachment): ?><a href="#" class="image-attachment-link" data-image-src="<?= irE($attachmentUrl) ?>"><i class="fa fa-paperclip"></i> <?= irE($row['attachment_original_name']) ?></a><?php else: ?><a target="_blank" href="<?= irE($attachmentUrl) ?>"><i class="fa fa-paperclip"></i> <?= irE($row['attachment_original_name']) ?></a><?php endif; ?><?php else: ?>-<?php endif; ?></td><td><?= irE($row['remark'] ?: '-') ?></td></tr><?php endwhile; ?>
</tbody></table></div></div></div></main>

<div class="modal fade" id="reportModal"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header bg-dark text-white"><h5 class="modal-title">Report Range</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-2"><?php foreach(['today'=>'Today','7days'=>'7 Day','30days'=>'30 Day','monthly'=>'Monthly','yearly'=>'Yearly'] as $value=>$label): ?><div class="col-6"><a target="_blank" href="item_receive_report.php?range=<?= $value ?>" class="btn btn-outline-dark w-100"><?= $label ?></a></div><?php endforeach; ?><div class="col-6"><button type="button" class="btn btn-outline-dark w-100" id="itemCustomButton">Custom</button></div></div></div></div></div></div>
<div class="modal fade" id="customReportModal"><div class="modal-dialog modal-dialog-centered"><form action="item_receive_report.php" method="get" target="_blank" class="modal-content"><div class="modal-header bg-dark text-white"><h5 class="modal-title">Custom Date Range</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="range" value="custom"><div class="mb-3"><label class="form-label">Start Date</label><input type="date" name="from" class="form-control" required></div><div><label class="form-label">End Date</label><input type="date" name="to" class="form-control" required></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-warning"><i class="fa fa-file-pdf"></i> Generate PDF</button></div></form></div></div></div>
<div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-xl"><div class="modal-content bg-transparent border-0 shadow-none"><div class="modal-body p-0 position-relative text-center"><button type="button" class="image-preview-close" data-bs-dismiss="modal" aria-label="Close image preview">&times;</button><img id="attachmentPreviewImage" src="" alt="Attachment preview" class="img-fluid rounded shadow" style="max-height:88vh;object-fit:contain;background:#fff;"></div></div></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$('#irTable').DataTable({pageLength:10,order:[[0,'desc']]});
document.getElementById('itemCustomButton').addEventListener('click', function(){
    const first = bootstrap.Modal.getOrCreateInstance(document.getElementById('reportModal'));
    const element = document.getElementById('reportModal');
    element.addEventListener('hidden.bs.modal', function handler(){ element.removeEventListener('hidden.bs.modal', handler); bootstrap.Modal.getOrCreateInstance(document.getElementById('customReportModal')).show(); });
    first.hide();
});
document.addEventListener('click', function(event){
    const link = event.target.closest('.image-attachment-link');
    if(!link){ return; }
    event.preventDefault();
    document.getElementById('attachmentPreviewImage').src = link.dataset.imageSrc;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('imagePreviewModal')).show();
});
document.getElementById('imagePreviewModal').addEventListener('hidden.bs.modal', function(){ document.getElementById('attachmentPreviewImage').src = ''; });
</script>
<?php include "layout/footer.php"; ?>
</body></html>
