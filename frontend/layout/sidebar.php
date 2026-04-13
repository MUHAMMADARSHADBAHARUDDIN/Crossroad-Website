<?php
global $role;

$current = basename($_SERVER['SCRIPT_NAME']);

// ROLE CHECK FUNCTION
function allow($roles, $role){
    return in_array($role, $roles);
}

// MENU CONFIG
$menu = [

    "MAIN" => [
        ["name"=>"Dashboard","icon"=>"fa-dashboard","link"=>"dashboard.php"],
        ["name"=>"My Home","icon"=>"fa-home","link"=>"myhome.php"],
    ],

    "PRE-SALE" => [
        ["name"=>"Contracts","icon"=>"fa-file-contract","link"=>"contracts.php"],
        ["name"=>"Tender Tracker","icon"=>"fa-chart-line","link"=>"#"],
    ],

    "TECHNICAL" => [
        ["name"=>"Asset Inventory","icon"=>"fa-box","link"=>"asset_inventory.php"],

        ["name"=>"Stock Out","icon"=>"fa-angle-right","link"=>"stock_out.php",
            "roles"=>["Administrator","System Admin","User (Technical)"],
            "submenu"=>true
        ],

        ["name"=>"Server Inventory","icon"=>"fa-server","link"=>"server_inventory.php"],

        ["name"=>"Stock Out","icon"=>"fa-angle-right","link"=>"server_stockout.php",
            "roles"=>["Administrator","System Admin","User (Technical)"],
            "submenu"=>true
        ],
    ],
];

// ADMIN SECTION
if($role === "Administrator"){
    $menu["ADMIN"] = [
        ["name"=>"Manage Users","icon"=>"fa-user-cog","link"=>"manage_users.php"],
        ["name"=>"Activity Tracker","icon"=>"fa-history","link"=>"tracking.php"],
    ];
}
?>

<div class="sidebar" id="sidebar">

<?php foreach($menu as $section => $items): ?>

    <div class="sidebar-section"><?= $section ?></div>

    <?php foreach($items as $item): ?>

        <?php
        // ROLE FILTER
        if(isset($item['roles']) && !allow($item['roles'], $role)){
            continue;
        }

        $active = ($current == $item['link']) ? "active" : "";
        $submenu = isset($item['submenu']) ? "submenu" : "";
        ?>

        <a href="<?= $item['link'] ?>" class="<?= $active ?> <?= $submenu ?>" title="<?= $item['name'] ?>">
            <i class="fa <?= $item['icon'] ?>"></i>
            <span><?= $item['name'] ?></span>
        </a>

    <?php endforeach; ?>

<?php endforeach; ?>

</div>