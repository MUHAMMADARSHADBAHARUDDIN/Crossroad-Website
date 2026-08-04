<?php
require_once '../includes/security.php';
startSecureSession();
require_once '../includes/db_connect.php';
require_once '../includes/permissions.php';
require_once '../includes/bulletin_schema.php';
require_once '../includes/realtime.php';

if(!isset($_SESSION['username'])){ header('Location: index.html'); exit; }
if(!hasPermission($mysqli, 'bulletin_view')){ http_response_code(403); die('Access denied.'); }
if(!ensureBulletinSchema($mysqli)){ http_response_code(500); die('Unable to prepare the bulletin module.'); }

$canAdd = hasPermission($mysqli, 'bulletin_add');
$canDelete = hasPermission($mysqli, 'bulletin_delete');
$message = '';
$messageType = 'success';

$names = [];
$nameResult = $mysqli->query("SELECT username FROM user UNION SELECT username FROM administrator ORDER BY username ASC");
if($nameResult){ while($row = $nameResult->fetch_assoc()){ $names[] = $row['username']; } }

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $action = trim((string)($_POST['action'] ?? ''));

    if($action === 'add'){
        if(!$canAdd){ http_response_code(403); die('Access denied.'); }
        $standbyName = trim((string)($_POST['standby_name'] ?? ''));
        $startDate = trim((string)($_POST['start_date'] ?? ''));
        $endDate = trim((string)($_POST['end_date'] ?? ''));

        if(!in_array($standbyName, $names, true)){
            $message = 'Please select a valid user.'; $messageType = 'danger';
        } elseif(!bulletinValidDate($startDate) || !bulletinValidDate($endDate) || $startDate > $endDate){
            $message = 'Please select a valid date range.'; $messageType = 'danger';
        } else {
            $stmt = $mysqli->prepare('INSERT INTO standby_bulletins (standby_name, start_date, end_date, created_by) VALUES (?, ?, ?, ?)');
            $createdBy = (string)$_SESSION['username'];
            $stmt->bind_param('ssss', $standbyName, $startDate, $endDate, $createdBy);
            if($stmt->execute()){ crossroadRealtimePublish('bulletin', 'ADD STANDBY BULLETIN'); header('Location: bulletin.php?saved=1'); exit; }
            $message = 'Unable to save the standby bulletin.'; $messageType = 'danger';
        }
    } elseif($action === 'add_message'){
        if(!$canAdd){ http_response_code(403); die('Access denied.'); }
        $bulletinMessage = trim((string)($_POST['message'] ?? ''));
        if($bulletinMessage === ''){
            $message = 'Please enter a message.'; $messageType = 'danger';
        } elseif(strlen($bulletinMessage) > 500){
            $message = 'Message must not exceed 500 characters.'; $messageType = 'danger';
        } else {
            $stmt = $mysqli->prepare('INSERT INTO bulletin_messages (message, created_by) VALUES (?, ?)');
            $createdBy = (string)$_SESSION['username'];
            $stmt->bind_param('ss', $bulletinMessage, $createdBy);
            if($stmt->execute()){ crossroadRealtimePublish('bulletin', 'ADD BULLETIN MESSAGE'); header('Location: bulletin.php?message_saved=1'); exit; }
            $message = 'Unable to save the message.'; $messageType = 'danger';
        }
    } elseif($action === 'delete'){
        if(!$canDelete){ http_response_code(403); die('Access denied.'); }
        $id = (int)($_POST['bulletin_id'] ?? 0);
        if($id > 0){
            $stmt = $mysqli->prepare('DELETE FROM standby_bulletins WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            crossroadRealtimePublish('bulletin', 'DELETE STANDBY BULLETIN');
        }
        header('Location: bulletin.php?deleted=1'); exit;
    } elseif($action === 'delete_message'){
        if(!$canDelete){ http_response_code(403); die('Access denied.'); }
        $id = (int)($_POST['message_id'] ?? 0);
        if($id > 0){
            $stmt = $mysqli->prepare('DELETE FROM bulletin_messages WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            crossroadRealtimePublish('bulletin', 'DELETE BULLETIN MESSAGE');
        }
        header('Location: bulletin.php?message_deleted=1'); exit;
    }
}

if(isset($_GET['saved'])){ $message = 'Standby bulletin added successfully.'; }
if(isset($_GET['deleted'])){ $message = 'Standby bulletin deleted.'; }
if(isset($_GET['message_saved'])){ $message = 'Message added successfully.'; }
if(isset($_GET['message_deleted'])){ $message = 'Message deleted.'; }
$bulletins = $mysqli->query('SELECT id, standby_name, start_date, end_date, created_by, created_at FROM standby_bulletins ORDER BY start_date DESC, id DESC');
$bulletinMessages = $mysqli->query('SELECT id, message, created_by, created_at FROM bulletin_messages ORDER BY created_at DESC, id DESC');
include 'layout/header.php';
?>
<style>
.bulletin-grid{display:grid;grid-template-columns:minmax(280px,390px) minmax(0,1fr);gap:24px}.bulletin-card{background:#fff;border-radius:14px;box-shadow:0 5px 18px rgba(0,0,0,.08);padding:24px}.bulletin-card h2,.bulletin-card h3{margin-top:0}.bulletin-table-wrap{overflow-x:auto}.bulletin-table{width:100%;border-collapse:collapse}.bulletin-table th,.bulletin-table td{padding:12px;border-bottom:1px solid #e5eaf0;text-align:left;vertical-align:middle}.bulletin-table th{background:#f4f6f9;white-space:nowrap}.date-range-fields{display:grid;grid-template-columns:1fr 1fr;gap:12px}.status-active{background:#d1e7dd;color:#0f5132}.status-upcoming{background:#cff4fc;color:#055160}.status-ended{background:#e9ecef;color:#495057}.standby-status{display:inline-block;padding:4px 9px;border-radius:999px;font-size:12px;font-weight:700}@media(max-width:900px){.bulletin-grid{grid-template-columns:1fr}}@media(max-width:520px){.date-range-fields{grid-template-columns:1fr}.bulletin-card{padding:18px}}
</style>
<?php include 'layout/sidebar.php'; ?>
<main class="main" id="main">
    <div class="mb-4"><h2><i class="fa fa-bullhorn"></i> Standby Bulletin</h2><p class="text-muted mb-0">Schedule the staff member who will be on standby.</p></div>
    <?php if($message !== ''): ?><div class="alert alert-<?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <div class="bulletin-grid">
        <?php if($canAdd): ?><section class="bulletin-card"><h3 class="h5 mb-3">Add Standby Schedule</h3><form method="post"><?= csrfTokenField() ?><input type="hidden" name="action" value="add"><div class="mb-3"><label class="form-label" for="standby_name">Staff name</label><select class="form-select" id="standby_name" name="standby_name" required><option value="">Select name</option><?php foreach($names as $name): ?><option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option><?php endforeach; ?></select></div><div class="date-range-fields"><div><label class="form-label" for="start_date">Start date</label><input class="form-control" type="date" id="start_date" name="start_date" required></div><div><label class="form-label" for="end_date">End date</label><input class="form-control" type="date" id="end_date" name="end_date" required></div></div><button class="btn btn-danger w-100 mt-4" type="submit"><i class="fa fa-plus"></i> Add Bulletin</button></form></section><?php endif; ?>
        <section class="bulletin-card"><h3 class="h5 mb-3">Standby Schedule</h3><div class="bulletin-table-wrap"><table class="bulletin-table"><thead><tr><th>Name</th><th>Date Standby</th><th>Status</th><?php if($canDelete): ?><th>Action</th><?php endif; ?></tr></thead><tbody>
        <?php if($bulletins && $bulletins->num_rows): while($row=$bulletins->fetch_assoc()): $today=date('Y-m-d'); $status=$today<$row['start_date']?'Upcoming':($today>$row['end_date']?'Ended':'Active'); $statusClass=strtolower($status); ?><tr><td><strong><?= htmlspecialchars($row['standby_name']) ?></strong></td><td><?= htmlspecialchars(date('d/m/Y',strtotime($row['start_date']))) ?> - <?= htmlspecialchars(date('d/m/Y',strtotime($row['end_date']))) ?></td><td><span class="standby-status status-<?= $statusClass ?>"><?= $status ?></span></td><?php if($canDelete): ?><td><form method="post" onsubmit="return confirm('Delete this standby bulletin?')"><?= csrfTokenField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="bulletin_id" value="<?= (int)$row['id'] ?>"><button class="btn btn-outline-danger btn-sm" type="submit" aria-label="Delete <?= htmlspecialchars($row['standby_name']) ?> standby"><i class="fa fa-trash"></i></button></form></td><?php endif; ?></tr><?php endwhile; else: ?><tr><td colspan="<?= $canDelete ? 4 : 3 ?>" class="text-center text-muted py-4">No standby schedules yet.</td></tr><?php endif; ?>
        </tbody></table></div></section>
    </div>
    <section class="bulletin-card mt-4">
        <h3 class="h5 mb-3">Message</h3>
        <?php if($canAdd): ?><form method="post" class="mb-4"><?= csrfTokenField() ?><input type="hidden" name="action" value="add_message"><label class="form-label" for="bulletin_message">Dashboard bulletin message</label><textarea class="form-control" id="bulletin_message" name="message" rows="3" maxlength="500" placeholder="Enter a standalone message" required></textarea><div class="form-text">This message stays in the Dashboard bulletin until it is deleted. Maximum 500 characters.</div><button class="btn btn-danger mt-3" type="submit"><i class="fa fa-plus"></i> Add Message</button></form><?php endif; ?>
        <div class="bulletin-table-wrap"><table class="bulletin-table"><thead><tr><th>Message</th><th>Created By</th><th>Created</th><?php if($canDelete): ?><th>Action</th><?php endif; ?></tr></thead><tbody>
        <?php if($bulletinMessages && $bulletinMessages->num_rows): while($messageRow=$bulletinMessages->fetch_assoc()): ?><tr><td><?= nl2br(htmlspecialchars($messageRow['message'])) ?></td><td><?= htmlspecialchars($messageRow['created_by']) ?></td><td><?= htmlspecialchars(date('d/m/Y h:i A',strtotime($messageRow['created_at']))) ?></td><?php if($canDelete): ?><td><form method="post" onsubmit="return confirm('Delete this bulletin message?')"><?= csrfTokenField() ?><input type="hidden" name="action" value="delete_message"><input type="hidden" name="message_id" value="<?= (int)$messageRow['id'] ?>"><button class="btn btn-outline-danger btn-sm" type="submit" aria-label="Delete bulletin message"><i class="fa fa-trash"></i></button></form></td><?php endif; ?></tr><?php endwhile; else: ?><tr><td colspan="<?= $canDelete ? 4 : 3 ?>" class="text-center text-muted py-4">No standalone messages yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</main>
<?php include 'layout/footer.php'; ?>
