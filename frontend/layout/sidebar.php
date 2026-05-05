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
$canViewInventory = hasPermission($mysqli, "inventory_view");

$menu = [

    "MAIN" => [
        [
            "name" => "Dashboard",
            "icon" => "fa-dashboard",
            "link" => "dashboard.php",
            "show" => true
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
    ],

    "TECHNICAL" => [
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
    ],

    "ADMIN" => [
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