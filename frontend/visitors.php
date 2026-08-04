<?php
require_once '../includes/security.php';
startSecureSession(false);
if(!isset($_SESSION['username'])){ header('Location: index.html'); exit; }
require_once '../includes/db_connect.php';
require_once '../includes/permissions.php';
require_once '../includes/visitor_schema.php';
if(!hasPermission($mysqli, 'visitor_view')){ http_response_code(403); die('Access denied.'); }
ensureVisitorSchema($mysqli);
$canDelete = hasPermission($mysqli, 'visitor_delete');
$canReport = hasPermission($mysqli, 'visitor_report');
$result = $mysqli->query('SELECT id, name, phone, unit_number, company, person_to_meet, purpose, visit_time FROM visitors ORDER BY visit_time DESC, id DESC');
include 'layout/header.php';
?>
<style>
.visitor-card{background:#fff;border-radius:14px;box-shadow:0 5px 18px rgba(0,0,0,.08);padding:24px}.visitor-heading{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:20px}.visitor-heading h2{margin:0}.visitor-heading p{margin:5px 0 0;color:#6c757d}.visitor-actions{display:flex;gap:9px;flex-wrap:wrap}.bulk-bar{display:flex;align-items:center;gap:12px;margin-bottom:14px}.table-scroll{overflow-x:auto}.visitor-table{width:100%;border-collapse:collapse}.visitor-table th,.visitor-table td{padding:12px 10px;border-bottom:1px solid #e8edf2;text-align:left;vertical-align:top}.visitor-table th{background:#f6f8fa;white-space:nowrap}.visitor-table td:nth-child(3),.visitor-table td:nth-child(7){min-width:160px}.visitor-table input[type=checkbox]{width:18px;height:18px}.empty{text-align:center!important;color:#6c757d;padding:40px!important}.custom-range{display:none}.custom-range.show{display:flex}#reportModal{z-index:1210}.modal-backdrop{z-index:1200!important}@media(max-width:700px){.visitor-card{padding:18px}.visitor-heading{align-items:flex-start;flex-direction:column}.custom-range.show{display:block}}
</style>
<?php include 'layout/sidebar.php'; ?>
<main class="main" id="main"><section class="visitor-card">
    <div class="visitor-heading"><div><h2>Visitor Records</h2><p>Visitor check-ins, newest first.</p></div><div class="visitor-actions"><a class="btn btn-outline-primary" href="../visitor/" target="_blank">Open form</a><a class="btn btn-primary" href="../visitor/qr.php" target="_blank"><i class="fa fa-qrcode"></i> QR code</a><?php if($canReport): ?><button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#reportModal"><i class="fa fa-file-pdf"></i> Report</button><?php endif; ?></div></div>
    <form id="deleteForm" method="post" action="../backend/delete_visitors.php" onsubmit="return confirmVisitorDelete()">
        <?= csrfTokenField() ?>
        <?php if($canDelete): ?><div class="bulk-bar"><button class="btn btn-danger btn-sm" type="submit"><i class="fa fa-trash"></i> Delete selected</button><span id="selectedCount" class="text-muted">0 selected</span></div><?php endif; ?>
        <div class="table-scroll"><table class="visitor-table"><thead><tr><?php if($canDelete): ?><th><input type="checkbox" id="selectAll" aria-label="Select all visitors"></th><?php endif; ?><th>No.</th><th>Name</th><th>Phone</th><th>Unit</th><th>Company</th><th>Person to Meet</th><th>Purpose</th><th>Time</th></tr></thead><tbody>
        <?php if($result && $result->num_rows): $no=1; while($row=$result->fetch_assoc()): ?><tr><?php if($canDelete): ?><td><input class="visitor-check" type="checkbox" name="visitor_ids[]" value="<?= (int)$row['id'] ?>" aria-label="Select <?= htmlspecialchars($row['name']) ?>"></td><?php endif; ?><td><?= $no++ ?></td><td><?= htmlspecialchars($row['name']) ?></td><td><?= htmlspecialchars($row['phone']) ?></td><td><?= htmlspecialchars($row['unit_number']) ?></td><td><?= htmlspecialchars($row['company']) ?></td><td><?= htmlspecialchars($row['person_to_meet']) ?></td><td><?= nl2br(htmlspecialchars($row['purpose'])) ?></td><td><?= htmlspecialchars(date('d M Y, h:i A', strtotime($row['visit_time']))) ?></td></tr><?php endwhile; else: ?><tr><td class="empty" colspan="<?= $canDelete ? 9 : 8 ?>">No visitor check-ins yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </form>
</section></main>

<?php if($canReport): ?><div class="modal fade" id="reportModal" tabindex="-1" aria-labelledby="reportModalLabel" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form action="../backend/generate_visitor_report.php" method="get" target="_blank"><div class="modal-header"><h5 class="modal-title" id="reportModalLabel">Generate Visitor Report</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><label class="form-label" for="reportPeriod">Report period</label><select class="form-select" id="reportPeriod" name="period" required><option value="today">Today</option><option value="7day">Last 7 days</option><option value="30day">Last 30 days</option><option value="monthly">This month</option><option value="yearly">This year</option><option value="custom">Custom range</option></select><div class="custom-range gap-3 mt-3" id="customRange"><div class="flex-fill"><label class="form-label" for="startDate">Start date</label><input class="form-control" type="date" id="startDate" name="start_date"></div><div class="flex-fill"><label class="form-label" for="endDate">End date</label><input class="form-control" type="date" id="endDate" name="end_date"></div></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success"><i class="fa fa-file-pdf"></i> Generate PDF</button></div></form></div></div></div><?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
    const all = document.getElementById('selectAll');
    const checks = Array.from(document.querySelectorAll('.visitor-check'));
    const count = document.getElementById('selectedCount');
    function updateCount(){ const selected=checks.filter(c=>c.checked).length; if(count) count.textContent=selected+' selected'; if(all){all.checked=checks.length>0&&selected===checks.length;all.indeterminate=selected>0&&selected<checks.length;} }
    if(all) all.addEventListener('change',function(){checks.forEach(c=>c.checked=all.checked);updateCount();});
    checks.forEach(c=>c.addEventListener('change',updateCount));
    window.confirmVisitorDelete=function(){ const selected=checks.filter(c=>c.checked).length; if(!selected){alert('Please select at least one visitor record.');return false;} return confirm('Delete '+selected+' selected visitor record'+(selected===1?'':'s')+'? This cannot be undone.'); };
    const period=document.getElementById('reportPeriod'), range=document.getElementById('customRange'), start=document.getElementById('startDate'), end=document.getElementById('endDate');
    if(period) period.addEventListener('change',function(){const custom=period.value==='custom';range.classList.toggle('show',custom);start.required=custom;end.required=custom;});
})();
</script>
<?php include 'layout/footer.php'; ?>
