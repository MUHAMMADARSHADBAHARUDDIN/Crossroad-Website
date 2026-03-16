<?php
global $role;
$current = basename($_SERVER['SCRIPT_NAME']);
?>

<div class="sidebar" id="sidebar">

    <a href="home.php" class="<?php if(strpos($current,'home') !== false) echo 'active'; ?>">
        <i class="fa fa-home"></i>
        <span>Homepage</span>
    </a>

    <a href="contracts.php" class="<?php if($current=='contracts.php') echo 'active'; ?>">
        <i class="fa fa-file-contract"></i>
        <span>Contracts</span>
    </a>

    <a href="asset_inventory.php" class="<?php if($current=='asset_inventory.php') echo 'active'; ?>">
        <i class="fa fa-box"></i>
        <span>Asset Inventory</span>
    </a>

    <a href="#">
        <i class="fa fa-chart-line"></i>
        <span>Tender Tracker</span>
    </a>

    <?php if($role === "Administrator"): ?>

        <a href="manage_users.php" class="<?php if($current=='manage_users.php') echo 'active'; ?>">
            <i class="fa fa-user-cog"></i>
            <span>Manage Users</span>
        </a>

        <a href="tracking.php" class="<?php if($current=='tracking.php') echo 'active'; ?>">
            <i class="fa fa-history"></i>
            <span>Activity Tracker</span>
        </a>

    <?php endif; ?>

</div>