<?php
global $mysqli;

if(session_status() === PHP_SESSION_NONE){
    session_start();
}

if(!isset($mysqli)){
    require_once __DIR__ . "/../../includes/db_connect.php";
}

require_once __DIR__ . "/../../includes/permissions.php";

$current = basename($_SERVER['SCRIPT_NAME']);
$role = $_SESSION['role'] ?? "";
$isRealAdmin = ($role === "Administrator");
/*
|--------------------------------------------------------------------------
| MODULE PERMISSIONS
|--------------------------------------------------------------------------
| These follow checkbox/account permission.
|--------------------------------------------------------------------------
*/
$canViewUsers = hasPermission($mysqli, "users_view");
$canViewContracts = hasPermission($mysqli, "contracts_view");
$canViewMasterBudget = hasContractMasterBudgetAccess($mysqli);
$canViewInventory = hasPermission($mysqli, "inventory_view");
$canViewOfficeInventory = hasPermission($mysqli, "office_inventory_view");
$canViewReceiving = hasPermission($mysqli, "receiving_view");
$canViewPartRequest = hasPermission($mysqli, "part_request_view");
$canViewPlanner = hasPermission($mysqli, "planner_view");
$canViewVisitors = hasPermission($mysqli, "visitor_view");
$canViewBulletin = hasPermission($mysqli, "bulletin_view");
$canViewSystemHealth = isAdministratorAccount($mysqli);

$menu = [

    "MAIN" => [
        [
            "name" => "Dashboard",
            "icon" => "fa-dashboard",
            "link" => "dashboard.php",
            "show" => true
        ],
        [
            "name" => "Bulletin",
            "icon" => "fa-bullhorn",
            "link" => "bulletin.php",
            "show" => $canViewBulletin
        ],
        [
            "name" => "CSSB Planner",
            "icon" => "fa-calendar-days",
            "link" => "planner.php",
            "show" => $canViewPlanner
        ],
        [
            "name" => "Personal Planner",
            "icon" => "fa-angle-right",
            "link" => "personal_planner.php",
            "submenu" => true,
            "show" => $canViewPlanner
        ],
        [
            "name" => "Technical Planner",
            "icon" => "fa-angle-right",
            "link" => "technical_planner.php",
            "submenu" => true,
            "show" => $canViewPlanner
        ],
        [
            "name" => "Telegram Notifications",
            "icon" => "fab fa-telegram",
            "link" => "telegram_notifications.php",
            "show" => $canViewPlanner
        ],
    ],

    "PRE-SALE" => [
        [
            "name" => "Contracts",
            "icon" => "fa-file-contract",
            "link" => "contracts.php",
            "show" => $canViewContracts
        ],
        [
            "name" => "Project Tracker",
            "icon" => "fa-chart-line",
            "link" => "project_tracker.php",
            "show" => $canViewContracts
        ],
        [
            "name" => "Master Budget",
            "icon" => "fa-wallet",
            "link" => "master_budget.php",
            "show" => $canViewMasterBudget
        ],
    ],

    "TECHNICAL" => [
        [
            "name" => "Item Receive",
            "icon" => "fa-truck-ramp-box",
            "link" => "item_receive.php",
            "show" => $canViewReceiving
        ],
        [
            "name" => "Part Request",
            "icon" => "fa-file-circle-plus",
            "link" => "part_request.php",
            "show" => $canViewPartRequest
        ],
        [
            "name" => "Parts Inventory",
            "icon" => "fa-box",
            "link" => "asset_inventory.php",
            "show" => $canViewInventory
        ],

        [
            "name" => "Stock Out",
            "icon" => "fa-angle-right",
            "link" => "stock_out.php",
            "submenu" => true,
            "show" => $canViewInventory
        ],

        [
            "name" => "Asset Inventory",
            "icon" => "fa-server",
            "link" => "server_inventory.php",
            "show" => $canViewInventory
        ],

        [
            "name" => "Stock Out",
            "icon" => "fa-angle-right",
            "link" => "server_stockout.php",
            "submenu" => true,
            "show" => $canViewInventory
        ],

        [
            "name" => "Office Inventory",
            "icon" => "fa-laptop",
            "link" => "office_inventory.php",
            "show" => $canViewOfficeInventory
        ],

        [
            "name" => "License Office 365",
            "icon" => "fa-angle-right",
            "link" => "office_license.php",
            "submenu" => true,
            "show" => $canViewOfficeInventory
        ],

        [
            "name" => "License Antivirus",
            "icon" => "fa-angle-right",
            "link" => "office_license_antivirus.php",
            "submenu" => true,
            "show" => $canViewOfficeInventory
        ],
    ],

    "ADMIN" => [
        [
            "name" => "Visitors",
            "icon" => "fa-address-book",
            "link" => "visitors.php",
            "show" => $canViewVisitors
        ],
        [
            "name" => "Manage Users",
            "icon" => "fa-user-cog",
            "link" => "manage_users.php",
            "show" => $canViewUsers
        ],
        [
            "name" => "Activity Tracker",
            "icon" => "fa-history",
            "link" => "tracking.php",
            "show" => $isRealAdmin
        ],
        [
            "name" => "System Health",
            "icon" => "fa-heart-pulse",
            "link" => "system_health.php",
            "show" => $canViewSystemHealth
        ],
    ],
];
?>

<div class="sidebar" id="sidebar">

<?php foreach($menu as $section => $items): ?>

    <?php
    $visibleItems = array_filter($items, function($item){
        return !empty($item['show']);
    });

    if(count($visibleItems) === 0){
        continue;
    }
    ?>

    <div class="sidebar-section"><?= htmlspecialchars($section) ?></div>

    <?php foreach($visibleItems as $item): ?>

        <?php
        $active = ($current == $item['link']) ? "active" : "";
        $submenu = isset($item['submenu']) ? "submenu" : "";
        ?>

        <a href="<?= htmlspecialchars($item['link']) ?>" class="<?= $active ?> <?= $submenu ?>" title="<?= htmlspecialchars($item['name']) ?>">
            <i class="fa <?= htmlspecialchars($item['icon']) ?>"></i>
            <span><?= htmlspecialchars($item['name']) ?></span>
        </a>

    <?php endforeach; ?>

<?php endforeach; ?>

</div>

<button type="button" class="sidebar-scrim" id="sidebarScrim" aria-label="Close navigation menu"></button>

<?php if($canViewSystemHealth): ?>
<div id="systemHealthLoadingOverlay" class="system-health-loading-overlay" role="dialog" aria-modal="true" aria-labelledby="systemHealthLoadingTitle" aria-describedby="systemHealthLoadingText" hidden>
    <div class="system-health-loading-box">
        <div class="spinner-border text-warning" role="status" aria-hidden="true"></div>
        <div>
            <strong id="systemHealthLoadingTitle">Checking system health</strong>
            <span id="systemHealthLoadingText">Please wait while the services are being checked.</span>
        </div>
    </div>
</div>
<style>
.system-health-loading-overlay{position:fixed;inset:0;z-index:20000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(15,18,22,.64);backdrop-filter:blur(2px)}
.system-health-loading-overlay[hidden]{display:none}
.system-health-loading-box{display:flex;align-items:center;gap:17px;width:min(420px,100%);padding:24px;border:1px solid rgba(255,255,255,.2);border-radius:14px;background:#fff;color:#171b20;box-shadow:0 20px 55px rgba(0,0,0,.35)}
.system-health-loading-box .spinner-border{width:2.6rem;height:2.6rem;flex:0 0 2.6rem}
.system-health-loading-box div:last-child{display:flex;flex-direction:column;gap:4px}
.system-health-loading-box strong{font-size:1rem}
.system-health-loading-box span{color:#6c757d;font-size:.84rem;line-height:1.4}
@media(max-width:480px){.system-health-loading-box{padding:20px}.system-health-loading-box .spinner-border{width:2.2rem;height:2.2rem;flex-basis:2.2rem}}
</style>
<script>
(function(){
    const overlay = document.getElementById('systemHealthLoadingOverlay');
    if(!overlay){ return; }

    function hideHealthLoading(){
        overlay.hidden = true;
        document.body.removeAttribute('aria-busy');
    }

    document.addEventListener('click', function(event){
        const link = event.target.closest('a[href="system_health.php"], a[data-system-health-check]');
        if(!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey){
            return;
        }

        event.preventDefault();
        overlay.hidden = false;
        document.body.setAttribute('aria-busy', 'true');
        link.setAttribute('aria-disabled', 'true');

        window.setTimeout(function(){
            window.location.assign(link.href);
        }, 120);
    });

    window.addEventListener('pageshow', hideHealthLoading);
    window.addEventListener('pagehide', function(){ document.body.removeAttribute('aria-busy'); });
})();
</script>
<?php endif; ?>
