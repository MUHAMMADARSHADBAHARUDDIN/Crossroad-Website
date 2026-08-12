<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/receiving_schema.php";
require_once "../includes/activity_log.php";
require_once "../includes/mailer.php";
require_once "../includes/part_request_pdf.php";

if(!isset($_SESSION['username'])){ header("Location:index.html"); exit; }
if(!hasPermission($mysqli, "part_request_view")){ die("Access denied"); }
ensurePartRequestSchema($mysqli);

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? 'UNKNOWN';
$canAdd = hasPermission($mysqli, 'part_request_add');
$error = '';
$fixedRecipient = 'fazdlan@crossroad.my';
$fixedCc = 'support@crossroad.my';

function pre($value){ return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }

function partRequestPostedItems(){
    $tickets = $_POST['ticket_number'] ?? [];
    $parts = $_POST['part_number'] ?? [];
    $descriptions = $_POST['description'] ?? [];
    if(!is_array($tickets) || !is_array($parts) || !is_array($descriptions)){ return []; }
    $items = [];
    $maximum = min(50, max(count($tickets), count($parts), count($descriptions)));
    for($index = 0; $index < $maximum; $index++){
        $ticket = trim((string)($tickets[$index] ?? ''));
        $part = trim((string)($parts[$index] ?? ''));
        $description = trim((string)($descriptions[$index] ?? ''));
        if($ticket === '' && $part === '' && $description === ''){ continue; }
        $items[] = [
            'item_number' => count($items) + 1,
            'ticket_number' => $ticket,
            'part_number' => $part,
            'description' => $description
        ];
    }
    return $items;
}

$formItems = $_SERVER['REQUEST_METHOD'] === 'POST' ? partRequestPostedItems() : [];
if(!$formItems){ $formItems = [['item_number'=>1,'ticket_number'=>'','part_number'=>'','description'=>'']]; }

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_request'])){
    if(!$canAdd){ $error = 'No permission to create requests.'; }
    else {
        $purpose = trim((string)($_POST['purpose'] ?? ''));
        $submittedItems = partRequestPostedItems();
        if($purpose === ''){ $error = 'Purpose is required.'; }
        elseif(!$submittedItems){ $error = 'Add at least one item.'; }
        else {
            foreach($submittedItems as $item){
                if($item['ticket_number'] === '' || $item['part_number'] === '' || $item['description'] === ''){
                    $error = 'Ticket Number, Part Number and Description are required for every item.';
                    break;
                }
            }
        }

        if($error === ''){
            $date = date('Y-m-d');
            $pending = 'Pending';
            $firstItem = $submittedItems[0];
            $mysqli->begin_transaction();
            try {
                $stmt = $mysqli->prepare("INSERT INTO part_requests(request_date,purpose,ticket_number,part_number,description,recipient_email,requested_by,email_status) VALUES(?,?,?,?,?,?,?,?)");
                $stmt->bind_param('ssssssss', $date, $purpose, $firstItem['ticket_number'], $firstItem['part_number'], $firstItem['description'], $fixedRecipient, $username, $pending);
                if(!$stmt->execute()){ throw new RuntimeException($stmt->error); }
                $id = (int)$stmt->insert_id;
                $requestId = 'REQ' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
                $updateId = $mysqli->prepare("UPDATE part_requests SET request_id=? WHERE id=?");
                $updateId->bind_param('si', $requestId, $id);
                if(!$updateId->execute()){ throw new RuntimeException($updateId->error); }

                $itemStmt = $mysqli->prepare("INSERT INTO part_request_items(part_request_id,item_number,ticket_number,part_number,description) VALUES(?,?,?,?,?)");
                foreach($submittedItems as $item){
                    $itemStmt->bind_param('iisss', $id, $item['item_number'], $item['ticket_number'], $item['part_number'], $item['description']);
                    if(!$itemStmt->execute()){ throw new RuntimeException($itemStmt->error); }
                }
                $mysqli->commit();

                $requestData = ['id'=>$id,'request_id'=>$requestId,'request_date'=>$date,'requested_by'=>$username,'purpose'=>$purpose];
                $temporaryPdf = tempnam(sys_get_temp_dir(), 'part_request_') . '.pdf';
                file_put_contents($temporaryPdf, buildPartRequestPdf($requestData, $submittedItems));

                $emailRows = '';
                foreach($submittedItems as $item){
                    $emailRows .= '<tr>'
                        . '<td style="border:1px solid #d7dce2;padding:9px;text-align:center;">' . (int)$item['item_number'] . '</td>'
                        . '<td style="border:1px solid #d7dce2;padding:9px;">' . pre($item['ticket_number']) . '</td>'
                        . '<td style="border:1px solid #d7dce2;padding:9px;">' . pre($item['part_number']) . '</td>'
                        . '<td style="border:1px solid #d7dce2;padding:9px;">' . nl2br(pre($item['description'])) . '</td>'
                        . '</tr>';
                }
                $emailBody = '<div style="font-family:Arial,sans-serif;color:#20252b;max-width:760px">'
                    . '<p>Dear Fazdlan,</p>'
                    . '<p>A new CSSB part request has been created. One PDF attachment is included with this email.</p>'
                    . '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;margin:18px 0">'
                    . '<tr><td style="border:1px solid #d7dce2;background:#f3f5f8;padding:9px;font-weight:bold;width:145px">Request ID</td><td style="border:1px solid #d7dce2;padding:9px">' . pre($requestId) . '</td></tr>'
                    . '<tr><td style="border:1px solid #d7dce2;background:#f3f5f8;padding:9px;font-weight:bold">Requested By</td><td style="border:1px solid #d7dce2;padding:9px">' . pre($username) . '</td></tr>'
                    . '<tr><td style="border:1px solid #d7dce2;background:#f3f5f8;padding:9px;font-weight:bold">Purpose</td><td style="border:1px solid #d7dce2;padding:9px">' . nl2br(pre($purpose)) . '</td></tr>'
                    . '</table>'
                    . '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%">'
                    . '<thead><tr style="background:#ffc107"><th style="border:1px solid #c99a00;padding:9px;width:44px">No.</th><th style="border:1px solid #c99a00;padding:9px">Ticket Number</th><th style="border:1px solid #c99a00;padding:9px">Part Number</th><th style="border:1px solid #c99a00;padding:9px">Description</th></tr></thead>'
                    . '<tbody>' . $emailRows . '</tbody></table>'
                    . '<p style="color:#6c757d;font-size:12px;margin-top:18px">This email was sent automatically by Crossroad System.</p></div>';

                try {
                    $mail = crossroadCreateMailer();
                    $mail->addAddress($fixedRecipient, 'Fazdlan');
                    $mail->addCC($fixedCc, 'CSSB Support');
                    $mail->isHTML(true);
                    $mail->Subject = 'Part Request ' . $requestId . ' - ' . count($submittedItems) . ' item(s)';
                    $mail->Body = $emailBody;
                    $mail->AltBody = "Part Request $requestId\nRequested by: $username\nPurpose: $purpose\nItems: " . count($submittedItems) . "\nA PDF attachment is included.";
                    $mail->addAttachment($temporaryPdf, $requestId . '.pdf');
                    $mail->send();
                    $status = 'Sent';
                    $response = 'Email accepted by SMTP.';
                } catch(Throwable $exception){
                    $status = 'Failed';
                    $response = $exception->getMessage();
                }
                if(is_file($temporaryPdf)){ unlink($temporaryPdf); }
                $update = $mysqli->prepare("UPDATE part_requests SET email_status=?,email_response=? WHERE id=?");
                $update->bind_param('ssi', $status, $response, $id);
                $update->execute();
                logActivity($mysqli, $username, $role, 'CREATE PART REQUEST', "Created $requestId with " . count($submittedItems) . " item(s)");
                header('Location:part_request.php?created=' . urlencode($requestId) . '&email=' . urlencode($status));
                exit;
            } catch(Throwable $exception){
                $mysqli->rollback();
                $error = 'Unable to create request: ' . $exception->getMessage();
            }
        }
    }
}

$rows = $mysqli->query("SELECT pr.*, (SELECT COUNT(*) FROM part_request_items pri WHERE pri.part_request_id=pr.id) AS item_count FROM part_requests pr ORDER BY pr.id DESC");
?>
<!doctype html><html><head>
<title>Part Request</title>
<link rel="icon" type="image/png" href="../image/logo.png">
<link rel="stylesheet" href="style.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<style>
.pr-card{height:auto!important;display:block!important;cursor:default!important;border:1px solid #d9dee7!important}.pr-card th{background:#f3f5f8}.sending-icon{font-size:44px;color:#ffc107}.request-item{border:1px solid #d9dee7;border-radius:10px;background:#fafbfc;padding:18px;margin-bottom:14px}.request-item-title{font-weight:700;font-size:16px}.remove-item{min-width:38px}
</style>
</head><body>
<?php include "layout/header.php"; include "layout/sidebar.php"; ?>
<main class="main">
<div class="d-flex justify-content-between align-items-center mb-3"><div><h2>Part Request</h2><div class="text-muted">Create one request containing one or more different parts.</div></div><button class="btn btn-outline-dark" data-bs-toggle="modal" data-bs-target="#reportModal"><i class="fa fa-file-pdf"></i> Report</button></div>
<?php if(isset($_GET['created'])): ?><div class="alert alert-<?= ($_GET['email'] ?? '') === 'Sent' ? 'success' : 'warning' ?>">Request <?= pre($_GET['created']) ?> created. Email status: <?= pre($_GET['email'] ?? '') ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger"><?= pre($error) ?></div><?php endif; ?>

<?php if($canAdd): ?>
<div class="card pr-card mb-4"><div class="card-body p-4"><h5 class="mb-3">Create Part Request</h5>
<form method="post" id="partRequestForm">
<div class="mb-3"><label class="form-label">Purpose *</label><textarea name="purpose" class="form-control" rows="2" placeholder="Salam boss, nak order part untuk UM" required><?= pre($_POST['purpose'] ?? '') ?></textarea></div>
<div id="requestItems">
<?php foreach($formItems as $index=>$item): ?>
<div class="request-item"><div class="d-flex justify-content-between align-items-center mb-3"><div class="request-item-title">Item <span class="item-number"><?= $index + 1 ?></span></div><button type="button" class="btn btn-sm btn-outline-danger remove-item" title="Remove item"><i class="fa fa-trash"></i></button></div><div class="row"><div class="col-md-4 mb-3"><label class="form-label">Ticket Number *</label><input name="ticket_number[]" class="form-control" value="<?= pre($item['ticket_number']) ?>" placeholder="UM040010626" required></div><div class="col-md-4 mb-3"><label class="form-label">Part Number *</label><input name="part_number[]" class="form-control" value="<?= pre($item['part_number']) ?>" placeholder="0W347K" required></div><div class="col-md-4 mb-3"><label class="form-label">Description *</label><textarea name="description[]" class="form-control" rows="2" placeholder="600GB 15K 3.5-inch 6Gb/s" required><?= pre($item['description']) ?></textarea></div></div></div>
<?php endforeach; ?>
</div>
<div class="d-flex flex-wrap gap-2"><button type="button" id="addRequestItem" class="btn btn-outline-dark"><i class="fa fa-plus"></i> Add Another Item</button><button name="create_request" id="sendRequestButton" class="btn btn-warning"><i class="fa fa-paper-plane"></i> Create Request & Send Email</button></div>
<div class="small text-muted mt-3">Email will be sent automatically to <?= pre($fixedRecipient) ?> and CC <?= pre($fixedCc) ?>.</div>
</form></div></div>
<?php endif; ?>

<div class="card pr-card"><div class="card-body"><div class="table-responsive"><table id="prTable" class="table table-hover"><thead><tr><th>Request ID</th><th>Date</th><th>Purpose</th><th>Items</th><th>Recipient</th><th>Status</th><th>PDF</th></tr></thead><tbody><?php while($row=$rows->fetch_assoc()): ?><tr><td><strong><?= pre($row['request_id']) ?></strong></td><td><?= pre($row['request_date']) ?></td><td><?= pre($row['purpose']) ?></td><td><?= max(1, (int)$row['item_count']) ?></td><td><?= pre($fixedRecipient) ?><div class="small text-muted">CC: <?= pre($fixedCc) ?></div></td><td><span class="badge <?= $row['email_status']==='Sent'?'bg-success':'bg-danger' ?>"><?= pre($row['email_status']) ?></span></td><td><a target="_blank" class="btn btn-sm btn-outline-danger" href="part_request_pdf.php?id=<?= (int)$row['id'] ?>"><i class="fa fa-file-pdf"></i></a></td></tr><?php endwhile; ?></tbody></table></div></div></div>
</main>

<template id="requestItemTemplate"><div class="request-item"><div class="d-flex justify-content-between align-items-center mb-3"><div class="request-item-title">Item <span class="item-number"></span></div><button type="button" class="btn btn-sm btn-outline-danger remove-item" title="Remove item"><i class="fa fa-trash"></i></button></div><div class="row"><div class="col-md-4 mb-3"><label class="form-label">Ticket Number *</label><input name="ticket_number[]" class="form-control" placeholder="UM040010626" required></div><div class="col-md-4 mb-3"><label class="form-label">Part Number *</label><input name="part_number[]" class="form-control" placeholder="0W347K" required></div><div class="col-md-4 mb-3"><label class="form-label">Description *</label><textarea name="description[]" class="form-control" rows="2" placeholder="600GB 15K 3.5-inch 6Gb/s" required></textarea></div></div></div></template>

<div class="modal fade" id="reportModal"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header bg-dark text-white"><h5 class="modal-title">Report Range</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-2"><?php foreach(['today'=>'Today','7days'=>'7 Day','30days'=>'30 Day','monthly'=>'Monthly','yearly'=>'Yearly'] as $value=>$label): ?><div class="col-6"><a target="_blank" href="part_request_report.php?range=<?= $value ?>" class="btn btn-outline-dark w-100"><?= $label ?></a></div><?php endforeach; ?><div class="col-6"><button type="button" class="btn btn-outline-dark w-100" id="partCustomButton">Custom</button></div></div></div></div></div></div>
<div class="modal fade" id="customReportModal"><div class="modal-dialog modal-dialog-centered"><form action="part_request_report.php" method="get" target="_blank" class="modal-content"><div class="modal-header bg-dark text-white"><h5 class="modal-title">Custom Date Range</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="range" value="custom"><div class="mb-3"><label class="form-label">Start Date</label><input type="date" name="from" class="form-control" required></div><div><label class="form-label">End Date</label><input type="date" name="to" class="form-control" required></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-warning"><i class="fa fa-file-pdf"></i> Generate PDF</button></div></form></div></div></div>
<div class="modal fade" id="sendingModal" data-bs-backdrop="static" data-bs-keyboard="false"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-body text-center p-5"><div class="spinner-border sending-icon mb-3" role="status"></div><h5>Sending request email...</h5><p class="text-muted mb-0">Please wait while the PDF and item table are emailed.</p></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$('#prTable').DataTable({order:[[1,'desc']]});
function renumberRequestItems(){
    const items = document.querySelectorAll('#requestItems .request-item');
    items.forEach((item,index)=>{ item.querySelector('.item-number').textContent=index+1; item.querySelector('.remove-item').disabled=items.length===1; });
}
document.getElementById('addRequestItem')?.addEventListener('click',function(){
    const items=document.querySelectorAll('#requestItems .request-item');
    if(items.length>=50){alert('A maximum of 50 different items is allowed per request.');return;}
    document.getElementById('requestItems').appendChild(document.getElementById('requestItemTemplate').content.cloneNode(true));
    renumberRequestItems();
});
document.getElementById('requestItems')?.addEventListener('click',function(event){
    const button=event.target.closest('.remove-item');
    if(!button)return;
    if(document.querySelectorAll('#requestItems .request-item').length>1){button.closest('.request-item').remove();renumberRequestItems();}
});
renumberRequestItems();
document.getElementById('partCustomButton').addEventListener('click',function(){
    const first=bootstrap.Modal.getOrCreateInstance(document.getElementById('reportModal'));
    const element=document.getElementById('reportModal');
    element.addEventListener('hidden.bs.modal',function handler(){element.removeEventListener('hidden.bs.modal',handler);bootstrap.Modal.getOrCreateInstance(document.getElementById('customReportModal')).show();});
    first.hide();
});
const requestForm=document.getElementById('partRequestForm');
if(requestForm){let sending=false;requestForm.addEventListener('submit',function(event){if(sending||!requestForm.checkValidity())return;event.preventDefault();const button=document.getElementById('sendRequestButton');bootstrap.Modal.getOrCreateInstance(document.getElementById('sendingModal')).show();window.setTimeout(function(){sending=true;requestForm.requestSubmit(button);},180);});}
</script>
<?php include "layout/footer.php"; ?>
</body></html>
