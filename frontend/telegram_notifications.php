<?php
if(session_status() === PHP_SESSION_NONE){ session_start(); }

require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/planner_profiles.php";

if(!isset($_SESSION["username"])){
    header("Location: index.html");
    exit();
}
if(!hasPermission($mysqli, "planner_view")){
    die("Access denied");
}

$username = trim((string)$_SESSION["username"]);
$accountType = getCurrentAccountType($mysqli);
ensurePlannerProfileSchema($mysqli);

if(empty($_SESSION["telegram_settings_csrf"])){
    $_SESSION["telegram_settings_csrf"] = bin2hex(random_bytes(24));
}

$notice = "";
if($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "disconnect"){
    $csrf = (string)($_POST["csrf"] ?? "");
    if(!hash_equals((string)$_SESSION["telegram_settings_csrf"], $csrf)){
        die("Invalid request");
    }
    plannerDisconnectTelegram($mysqli, $username, $accountType);
    $notice = "Telegram notifications have been disconnected.";
}

$profile = plannerGetUserProfile($mysqli, $username, $accountType);
$connected = trim((string)($profile["telegram_chat_id"] ?? "")) !== "";
$botUsername = ltrim(trim((string)(getenv("CROSSROAD_TELEGRAM_BOT_USERNAME") ?: "Cssb_Planner_bot")), "@");
$linkToken = !$connected ? plannerCreateTelegramLinkToken($mysqli, $username, $accountType) : "";
$connectUrl = $linkToken !== "" ? "https://t.me/" . rawurlencode($botUsername) . "?start=" . rawurlencode($linkToken) : "";
?>
<?php include "layout/header.php"; ?>
<?php include "layout/sidebar.php"; ?>

<div class="main" id="main">
    <div class="container-fluid py-4">
        <div class="telegram-settings-card">
            <div class="telegram-icon" aria-hidden="true"><i class="fa-brands fa-telegram"></i></div>
            <p class="telegram-kicker">PLANNER NOTIFICATIONS</p>
            <h1>Telegram reminders</h1>

            <?php if($notice !== ""): ?>
                <div class="alert alert-success"><?= htmlspecialchars($notice) ?></div>
            <?php endif; ?>

            <?php if($connected): ?>
                <div class="telegram-status connected"><i class="fa fa-circle-check"></i> Connected</div>
                <p>Your Telegram is ready. When your name is selected as the PIC, the CSSB Planner bot will send task reminders directly to you.</p>
                <form method="post">
                    <input type="hidden" name="action" value="disconnect">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION["telegram_settings_csrf"]) ?>">
                    <button type="submit" class="btn btn-outline-danger">Disconnect Telegram</button>
                </form>
            <?php elseif($connectUrl !== ""): ?>
                <p>Connect once to receive planner reminders. Your Telegram chat ID is captured securely and automatically—there is nothing to copy or type.</p>
                <ol class="telegram-steps">
                    <li>Tap the button below.</li>
                    <li>Telegram opens; tap <strong>Start</strong>.</li>
                    <li>The bot confirms that you are connected.</li>
                </ol>
                <a class="telegram-connect-btn" href="<?= htmlspecialchars($connectUrl) ?>" target="_blank" rel="noopener">
                    <i class="fa-brands fa-telegram"></i> Connect Telegram
                </a>
                <p class="telegram-help">The secure link expires in 15 minutes. Refresh this page if it expires.</p>
            <?php else: ?>
                <div class="alert alert-warning">Telegram linking is unavailable for this account. Please contact an administrator.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.telegram-settings-card{max-width:680px;margin:36px auto;background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:38px;box-shadow:0 12px 35px rgba(15,23,42,.08);text-align:center}
.telegram-icon{font-size:58px;color:#229ed9;line-height:1}
.telegram-kicker{margin:18px 0 5px;color:#64748b;font-size:12px;font-weight:800;letter-spacing:.14em}
.telegram-settings-card h1{font-size:30px;margin-bottom:14px}
.telegram-settings-card>p{color:#475569;line-height:1.7}
.telegram-status{display:inline-flex;align-items:center;gap:8px;margin:5px 0 16px;padding:8px 14px;border-radius:999px;font-weight:700}
.telegram-status.connected{background:#dcfce7;color:#166534}
.telegram-steps{text-align:left;max-width:420px;margin:22px auto;padding-left:24px;color:#334155}
.telegram-steps li{margin:10px 0}
.telegram-connect-btn{display:inline-flex;align-items:center;gap:10px;background:#229ed9;color:#fff;text-decoration:none;font-size:17px;font-weight:700;padding:13px 24px;border-radius:10px}
.telegram-connect-btn:hover{background:#1787bd;color:#fff}
.telegram-help{font-size:13px;margin-top:18px!important;color:#64748b!important}
@media(max-width:576px){.telegram-settings-card{margin:10px auto;padding:26px 20px}.telegram-settings-card h1{font-size:25px}}
</style>

<?php include "layout/footer.php"; ?>
