<div class="sidebar" id="sidebar">

    <a href="home.php">
        <i class="fa fa-home"></i>
        <span>Homepage</span>
    </a>

    <a href="contracts.php">
        <i class="fa fa-file-contract"></i>
        <span>Contracts</span>
    </a>

    <a href="asset_inventory.php">
        <i class="fa fa-box"></i>
        <span>Asset Inventory</span>
    </a>

    <a href="#">
        <i class="fa fa-chart-line"></i>
        <span>Tender Tracker</span>
    </a>

    <?php if($role === "Administrator"): ?>

        <a href="#" class="manage-users">
            <i class="fa fa-user-cog"></i>
            <span>Manage Users</span>
        </a>

    <?php endif; ?>

</div>
