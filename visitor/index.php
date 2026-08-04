<?php
require_once __DIR__ . '/../includes/security.php';
startSecureSession(false);
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/visitor_schema.php';
require_once __DIR__ . '/../includes/realtime.php';

$success = false;
$error = '';
$values = ['name' => '', 'phone' => '', 'unit_number' => '01', 'company' => '', 'person_to_meet' => '', 'purpose' => ''];

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    foreach($values as $key => $unused){
        $values[$key] = trim((string)($_POST[$key] ?? ''));
    }

    $token = (string)($_POST['csrf_token'] ?? '');
    if(!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)){
        $error = 'Your form session expired. Please refresh and try again.';
    } elseif(in_array('', $values, true)){
        $error = 'Please complete all fields.';
    } elseif(!in_array($values['unit_number'], ['01', '06'], true)){
        $error = 'Please select a valid unit number.';
    } elseif(strlen($values['name']) > 150 || strlen($values['phone']) > 40 || strlen($values['company']) > 180 || strlen($values['person_to_meet']) > 150 || strlen($values['purpose']) > 500){
        $error = 'One or more entries are too long.';
    } elseif(!preg_match('/^[0-9+() .-]{7,40}$/', $values['phone'])){
        $error = 'Please enter a valid phone number.';
    } elseif(!ensureVisitorSchema($mysqli)){
        $error = 'The visitor service is temporarily unavailable.';
    } else {
        $stmt = $mysqli->prepare('INSERT INTO visitors (name, phone, unit_number, company, person_to_meet, purpose) VALUES (?, ?, ?, ?, ?, ?)');
        if($stmt){
            $stmt->bind_param('ssssss', $values['name'], $values['phone'], $values['unit_number'], $values['company'], $values['person_to_meet'], $values['purpose']);
            $success = $stmt->execute();
            if($success){ crossroadRealtimePublish('visitors', 'ADD VISITOR'); }
        }
        if(!$success){
            $error = 'We could not save your check-in. Please try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Visitor Check-in | Crossroad Solutions</title>
    <link rel="icon" href="../image/logo.png">
    <style>
        :root{--navy:#102a43;--blue:#1677c8;--line:#dbe4ec;--muted:#627d98}*{box-sizing:border-box}
        body{margin:0;min-height:100vh;font-family:Arial,sans-serif;background:linear-gradient(145deg,#edf5fb,#f8fbfd);color:var(--navy);padding:28px 16px}
        .card{max-width:620px;margin:auto;background:#fff;border-radius:20px;box-shadow:0 18px 50px rgba(16,42,67,.13);overflow:hidden}
        .head{padding:30px 34px 22px;border-bottom:1px solid var(--line)}.brand{display:flex;align-items:center;gap:14px}.brand img{width:58px;height:58px;object-fit:contain}.brand strong{font-size:20px}.head p{color:var(--muted);margin:18px 0 0;line-height:1.5}
        form,.message{padding:28px 34px 34px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.full{grid-column:1/-1}label{display:block;font-weight:700;font-size:14px;margin-bottom:7px}input,select,textarea{width:100%;border:1px solid #bcccdc;border-radius:10px;padding:13px 14px;font:inherit;color:#243b53;background:#fff}input:focus,select:focus,textarea:focus{outline:3px solid rgba(22,119,200,.14);border-color:var(--blue)}textarea{min-height:110px;resize:vertical}.btn{width:100%;border:0;border-radius:11px;background:var(--blue);color:white;font-weight:700;font-size:16px;padding:14px;cursor:pointer}.error{margin:0 34px;background:#fff0f0;color:#9b1c1c;border-radius:10px;padding:12px 14px}.message{text-align:center}.check{font-size:52px;color:#198754}.message h1{font-size:25px}.message p{color:var(--muted);line-height:1.5}@media(max-width:560px){.grid{grid-template-columns:1fr}.head,form,.message{padding-left:22px;padding-right:22px}.error{margin-left:22px;margin-right:22px}}
    </style>
</head>
<body><main class="card">
    <header class="head"><div class="brand"><img src="../image/logo.png" alt="Crossroad Solutions logo"><strong>Crossroad Solutions Sdn Bhd</strong></div><p>Welcome. Please register your visit before entering.</p></header>
    <?php if($success): ?>
        <section class="message"><div class="check">&#10003;</div><h1>Check-in complete</h1><p>Thank you, <?= htmlspecialchars($values['name']) ?>. Your visit has been registered.</p></section>
    <?php else: ?>
        <?php if($error !== ''): ?><p class="error" role="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <form method="post" autocomplete="on"><?= csrfTokenField() ?><div class="grid">
            <div><label for="name">Full name</label><input id="name" name="name" maxlength="150" value="<?= htmlspecialchars($values['name']) ?>" autocomplete="name" required></div>
            <div><label for="phone">Phone number</label><input id="phone" name="phone" type="tel" maxlength="40" value="<?= htmlspecialchars($values['phone']) ?>" autocomplete="tel" required></div>
            <div><label for="unit_number">Unit number</label><select id="unit_number" name="unit_number" required><option value="01" <?= $values['unit_number'] === '01' ? 'selected' : '' ?>>01</option><option value="06" <?= $values['unit_number'] === '06' ? 'selected' : '' ?>>06</option></select></div>
            <div class="full"><label for="company">Company name</label><input id="company" name="company" maxlength="180" value="<?= htmlspecialchars($values['company']) ?>" autocomplete="organization" required></div>
            <div class="full"><label for="person_to_meet">Person to meet</label><input id="person_to_meet" name="person_to_meet" maxlength="150" value="<?= htmlspecialchars($values['person_to_meet']) ?>" required></div>
            <div class="full"><label for="purpose">Purpose of visit</label><textarea id="purpose" name="purpose" maxlength="500" required><?= htmlspecialchars($values['purpose']) ?></textarea></div>
            <div class="full"><button class="btn" type="submit">Submit check-in</button></div>
        </div></form>
    <?php endif; ?>
</main></body></html>
