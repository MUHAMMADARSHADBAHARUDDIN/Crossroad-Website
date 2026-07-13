<?php
if(session_status() === PHP_SESSION_NONE){
    session_start();
}

if(!isset($_SESSION['username'])){
    header("Location: index.html");
    exit();
}

$role = $_SESSION['role'] ?? "";
$username = $_SESSION['username'] ?? "";

if(!isset($mysqli)){
    require_once __DIR__ . "/../../includes/db_connect.php";
}

require_once __DIR__ . "/../../includes/permissions.php";
require_once __DIR__ . "/../../includes/auth_schema.php";
require_once __DIR__ . "/../../includes/planner_profiles.php";

$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? "");
if($currentScript !== "change_password.php" && authCurrentAccountMustChangePassword($mysqli)){
    if(!headers_sent()){
        header("Location: change_password.php");
    } else {
        echo "<script>window.location.href='change_password.php';</script>";
    }
    exit();
}

/* =========================================================
   NICKNAME DISPLAY
   - Shows short name instead of full username
   - Example:
     Muhammad Arshad Bin Baharuddin => Arshad
     Mohd Fazdlan Bin Mohamad Rashid => Fazdlan
     Nur Shafiqa Binti Zulkefli => Shafiqa
     Wan Nur Azlin Binti Mohd Ghazali => Azlin
========================================================= */
function getNickname($fullName){
    $nickname = plannerAccountNickname($fullName);
    return $nickname !== "" ? $nickname : "User";
}

$nickname = getNickname($username);
?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Crossroad Solutions Inventory</title>

    <link rel="icon" type="image/png" href="../image/logo.png">
    <link rel="shortcut icon" type="image/png" href="../image/logo.png">
    <link rel="apple-touch-icon" href="../image/logo.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="../frontend/style.css">

</head>

<body>

<!-- HEADER -->

<div class="topbar">

    <div class="header-left">

        <button type="button" class="menu-btn" id="menuBtn" aria-label="Toggle navigation menu" aria-controls="sidebar" aria-expanded="false">
            <span></span>
            <span></span>
            <span></span>
        </button>

        <img src="../image/logo.png" class="header-logo">

        <span class="company-title">Crossroad Solutions Sdn Bhd</span>

    </div>

    <div class="topbar-right">

        <span class="user-pill">
            <i class="fa fa-user"></i> <?= htmlspecialchars($nickname) ?>
        </span>

        <a href="logout.php" class="btn btn-outline-light btn-sm">
            Logout
        </a>

    </div>

</div>

<script>
(function(){
    if(window.CrossroadSidebarShell){
        return;
    }

    function getShellElements(){
        return {
            sidebar: document.getElementById("sidebar"),
            main: document.getElementById("main") || document.querySelector(".main"),
            btn: document.getElementById("menuBtn")
        };
    }

    function isMobile(){
        return window.matchMedia && window.matchMedia("(max-width: 768px)").matches;
    }

    let wasMobile = null;

    function setButtonState(btn, isOpen, iconActive){
        if(!btn){
            return;
        }

        const visualActive = typeof iconActive === "boolean" ? iconActive : isOpen;

        btn.classList.toggle("active", visualActive);
        btn.setAttribute("aria-expanded", isOpen ? "true" : "false");
    }

    function closeMobileMenu(){
        const shell = getShellElements();

        if(!shell.sidebar){
            return;
        }

        shell.sidebar.classList.remove("mobile-open");
        document.body.classList.remove("sidebar-open", "sidebar-mobile-open");
        setButtonState(shell.btn, false, true);
    }

    function syncShell(forceClose){
        const shell = getShellElements();

        if(!shell.sidebar){
            return;
        }

        const mobile = isMobile();

        if(mobile){
            shell.sidebar.classList.remove("collapsed");

            if(shell.main){
                shell.main.classList.add("expanded");
            }

            if(forceClose || wasMobile !== true){
                closeMobileMenu();
            }

            wasMobile = true;
            return;
        }

        shell.sidebar.classList.remove("mobile-open");
        document.body.classList.remove("sidebar-open", "sidebar-mobile-open");

        if(shell.main){
            shell.main.classList.toggle("expanded", shell.sidebar.classList.contains("collapsed"));
        }

        setButtonState(shell.btn, shell.sidebar.classList.contains("collapsed"));
        wasMobile = false;
    }

    function toggleShell(event){
        if(event){
            event.preventDefault();
        }

        const shell = getShellElements();

        if(!shell.sidebar || !shell.btn){
            return false;
        }

        if(isMobile()){
            const isOpen = !shell.sidebar.classList.contains("mobile-open");

            shell.sidebar.classList.remove("collapsed");
            shell.sidebar.classList.toggle("mobile-open", isOpen);
            document.body.classList.toggle("sidebar-open", isOpen);
            document.body.classList.toggle("sidebar-mobile-open", isOpen);

            if(shell.main){
                shell.main.classList.add("expanded");
            }

            setButtonState(shell.btn, isOpen, !isOpen);
            return false;
        }

        shell.sidebar.classList.toggle("collapsed");

        if(shell.main){
            shell.main.classList.toggle("expanded", shell.sidebar.classList.contains("collapsed"));
        }

        setButtonState(shell.btn, shell.sidebar.classList.contains("collapsed"));
        return false;
    }

    function handleMenuButtonClick(event){
        const btn = document.getElementById("menuBtn");

        if(!btn || (event.target !== btn && !btn.contains(event.target))){
            return;
        }

        event.stopImmediatePropagation();
        toggleShell(event);
    }

    function handleDocumentClick(event){
        const shell = getShellElements();

        if(!isMobile() || !shell.sidebar || !shell.sidebar.classList.contains("mobile-open")){
            return;
        }

        if(shell.sidebar.contains(event.target) || (shell.btn && shell.btn.contains(event.target))){
            return;
        }

        closeMobileMenu();
    }

    function handleKeydown(event){
        if(event.key === "Escape"){
            closeMobileMenu();
        }
    }

    window.CrossroadSidebarShell = {
        toggle: toggleShell,
        close: closeMobileMenu,
        sync: syncShell
    };

    window.toggleSidebar = toggleShell;
    document.addEventListener("click", handleMenuButtonClick, true);
    document.addEventListener("click", handleDocumentClick);
    document.addEventListener("keydown", handleKeydown);
    window.addEventListener("resize", syncShell);

    if(document.readyState === "loading"){
        document.addEventListener("DOMContentLoaded", function(){
            window.toggleSidebar = toggleShell;
            syncShell(true);
        });
    }else{
        window.toggleSidebar = toggleShell;
        syncShell(true);
    }
})();
</script>

<script>
(function(){
    if(window.CrossroadResponsiveTables){
        return;
    }

    window.CrossroadResponsiveTables = true;

    function hasScrollShell(table){
        return table.closest(".table-responsive, .contract-table-responsive, .contracts-table-wrap, .responsive-table-scroll, .dataTables_wrapper, .dataTables_scroll");
    }

    function wrapTable(table){
        if(!table || table.tagName !== "TABLE" || !table.classList.contains("table") || hasScrollShell(table)){
            return;
        }

        const parent = table.parentNode;

        if(!parent){
            return;
        }

        const wrapper = document.createElement("div");
        wrapper.className = "responsive-table-scroll";
        parent.insertBefore(wrapper, table);
        wrapper.appendChild(table);
    }

    function enhanceTables(root){
        const scope = root && root.querySelectorAll ? root : document;

        if(scope.matches && scope.matches("table.table")){
            wrapTable(scope);
        }

        scope.querySelectorAll("table.table").forEach(wrapTable);
    }

    function observeTables(){
        if(!window.MutationObserver || !document.body){
            return;
        }

        let queued = false;
        const schedule = window.requestAnimationFrame || function(callback){
            return window.setTimeout(callback, 16);
        };

        const observer = new MutationObserver(function(){
            if(queued){
                return;
            }

            queued = true;
            schedule(function(){
                queued = false;
                enhanceTables(document);
            });
        });

        observer.observe(document.body, {
            childList:true,
            subtree:true
        });
    }

    function start(){
        enhanceTables(document);
        observeTables();
    }

    if(document.readyState === "loading"){
        document.addEventListener("DOMContentLoaded", start);
    }else{
        start();
    }
})();
</script>
