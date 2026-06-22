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
    $fullName = trim($fullName);

    if($fullName === ""){
        return "User";
    }

    $parts = preg_split('/\s+/', $fullName);

    if(count($parts) === 1){
        return $parts[0];
    }

    /*
        ✅ IMPORTANT:
        Keep all words lowercase because we compare using strtolower().
        Add more names here if you want to skip them.
    */
    $skipFirstNames = [
        "muhammad",
        "muhamad",
        "mohammad",
        "mohamad",
        "mohd",
        "ahmad",
        "nur",
        "wan",
        "siti",
        "syed",
        "sharifah",
        "tengku",
        "nik"
    ];

    /*
        ✅ This will skip multiple front names.
        Example:
        Wan Nur Azlin Binti Mohd Ghazali
        - skip Wan
        - skip Nur
        - show Azlin
    */
    foreach($parts as $part){
        $cleanPart = strtolower(trim($part));

        if($cleanPart === ""){
            continue;
        }

        if(in_array($cleanPart, $skipFirstNames, true)){
            continue;
        }

        return $part;
    }

    return $parts[0];
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

    function setButtonState(btn, isOpen){
        if(!btn){
            return;
        }

        btn.classList.toggle("active", isOpen);
        btn.setAttribute("aria-expanded", isOpen ? "true" : "false");
    }

    function closeMobileMenu(){
        const shell = getShellElements();

        if(!shell.sidebar){
            return;
        }

        shell.sidebar.classList.remove("mobile-open");
        document.body.classList.remove("sidebar-open", "sidebar-mobile-open");
        setButtonState(shell.btn, false);
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

            setButtonState(shell.btn, isOpen);
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
