<?php
$crossroadRealtimePublicUrl = trim((string)(getenv("CROSSROAD_REALTIME_PUBLIC_URL") ?: ""));
?>
<?php if($crossroadRealtimePublicUrl !== ""): ?>
<script>
(function(){
    if(window.CrossroadRealtimeStarted){ return; }
    window.CrossroadRealtimeStarted = true;
    const socketUrl = <?= json_encode($crossroadRealtimePublicUrl, JSON_UNESCAPED_SLASHES) ?>;
    const page = (location.pathname.split("/").pop() || "").toLowerCase();
    const moduleMap = {
        planner: ["planner.php", "personal_planner.php", "technical_planner.php"],
        office_inventory: ["office_inventory.php", "office_add.php", "office_edit.php", "office_license.php", "office_license_antivirus.php"],
        receiving: ["item_receive.php", "receive_item.php", "edit_receive_item.php", "item_receive_report.php"],
        part_request: ["part_request.php", "part_request_pdf.php", "part_request_report.php"],
        contracts: ["contracts.php", "contract_add.php", "contract_edit.php", "project_tracker.php", "project_insights.php", "master_budget.php"],
        asset_inventory: ["asset_inventory.php", "asset_add.php", "asset_edit.php", "asset_delete.php", "stock_out.php"],
        server_inventory: ["server_inventory.php", "server_add.php", "server_edit.php", "server_stockout.php"],
        users: ["manage_users.php"],
        visitors: ["visitors.php"],
        bulletin: ["bulletin.php"],
        tracking: ["tracking.php"]
    };
    const channel = Object.keys(moduleMap).find(key => moduleMap[key].includes(page)) || (page === "dashboard.php" ? "dashboard" : "");
    if(!channel){ return; }

    let dirty = false;
    let reconnectTimer = null;
    document.addEventListener("input", event => { if(event.target.closest("form")){ dirty = true; } });
    document.addEventListener("submit", () => { dirty = false; });

    function showUpdateNotice(){
        if(document.getElementById("crossroadRealtimeNotice")){ return; }
        const notice = document.createElement("button");
        notice.id = "crossroadRealtimeNotice";
        notice.type = "button";
        notice.textContent = "New data is available — refresh";
        notice.style.cssText = "position:fixed;right:18px;bottom:18px;z-index:11000;border:0;border-radius:10px;padding:12px 18px;background:#212529;color:#fff;font-weight:700;box-shadow:0 8px 24px rgba(0,0,0,.22);";
        notice.addEventListener("click", () => location.reload());
        document.body.appendChild(notice);
    }

    function connect(){
        const socket = new WebSocket(socketUrl);
        socket.addEventListener("message", event => {
            let message;
            try { message = JSON.parse(event.data); } catch { return; }
            if(message.type !== "data_changed" || (message.channel !== channel && channel !== "dashboard")){ return; }
            const modalOpen = !!document.querySelector(".modal.show");
            if(dirty || modalOpen){ showUpdateNotice(); return; }
            window.setTimeout(() => location.reload(), 700);
        });
        socket.addEventListener("close", () => {
            window.clearTimeout(reconnectTimer);
            reconnectTimer = window.setTimeout(connect, 3000);
        });
    }
    connect();
})();
</script>
<?php endif; ?>
