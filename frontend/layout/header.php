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

    <link rel="icon" href="../image/logo.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="../frontend/style.css">

</head>

<body>

<!-- HEADER -->

<div class="topbar">

    <div class="header-left">

        <div class="menu-btn" id="menuBtn" onclick="toggleSidebar()">
            <span></span>
            <span></span>
            <span></span>
        </div>

        <img src="../image/logo.png" class="header-logo">

        <span class="company-title">Crossroad Solutions Sdn Bhd</span>

    </div>

    <div class="d-flex align-items-center gap-3">

        <span>
            <i class="fa fa-user"></i> <?= htmlspecialchars($nickname) ?>
        </span>

        <a href="logout.php" class="btn btn-outline-light btn-sm">
            Logout
        </a>

    </div>

</div>