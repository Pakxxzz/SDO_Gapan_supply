<?php
// admin-sidebar.php
include_once "../API/NotificationHelper.php";
include "../API/db-connector.php";
require "../API/auth-check.php";

$notifHelper = new NotificationHelper($conn);
$sidebarUnreadCount = (int) $notifHelper->getUnreadCount();

ob_start(); // Turns on output buffering

date_default_timezone_set('Asia/Manila');


if (!isset($_SESSION['user_id'])) {
    header("Location: ./index.php");
    exit();
}

// Redirect to login page if user is not Admin
if ($_SESSION['role'] != 'Admin') {
    header("Location: ./logout.php");
    exit;
}

$username = $_SESSION['username'];
$role = $_SESSION['role'];

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SDO Gapan City Supply/Property Unit</title>
    <link rel="shortcut icon" href="../image/favicon.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        /* General styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Poppins", sans-serif;
        }

        html,
        body {
            height: 100%;
            /* Fallback */
            height: 100dvh;
            /* Modern fix for mobile bars */
            width: 100%;
            margin: 0;
            padding: 0;
            overflow: hidden;
            /* Prevent the whole page from shaking */
        }

        body {
            display: flex;
            background: #f9f9f9;
        }

        .company-name {
            font-size: 20px;
            font-weight: bold;
            color: #0047bb;
            font-family: "Palatino Linotype", "Georgia", "Times New Roman", serif;
        }

        .highlight {
            color: #009fda;
            font-size: 25px;
            font-family: "Palatino Linotype", "Georgia", "Times New Roman", serif;
            ;
        }

        .subtext {
            margin-top: 8px;
            font-size: 15px;
            font-weight: bold;
            color: black;
        }

        .sidebar {
            position: relative;
            z-index: 1000;
            width: 300px;
            min-width: 300px;
            background: white;
            /* Use dvh here too */
            height: 100dvh;
            padding: 20px;
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
            transition: width 0.15s ease-in-out, background 0.1s ease-in-out;
            color: #222;
            display: flex;
            flex-direction: column;

            /* ADD THIS: Allow the sidebar itself to scroll if items overflow */
            overflow-y: auto;
            scrollbar-width: none;
            /* Firefox */

            contain: paint;
            /* Performance boost: isolates sidebar rendering */
            will-change: width;
        }

        /* Sidebar notification badge */
        .sidebar .notif a {
            position: relative;
        }

        .sidebar .notif .notif-badge {
            position: absolute;
            top: 6px;
            right: 10px;
            background: #dc3545;
            color: #fff;
            border-radius: 9999px;
            font-size: 10px;
            line-height: 16px;
            min-width: 16px;
            height: 16px;
            padding: 0 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            border: 2px solid #fff;
            /* crisp on white sidebar */
        }

        /* Keep readable when the sidebar is collapsed */
        .sidebar.closed .notif .notif-badge {
            top: 4px;
            right: 8px;
        }

        /* Active & hover states play nice */
        .sidebar ul li.active .notif-badge {
            border-color: rgb(0, 66, 172);
            /* same as active background */
        }

        .sidebar.closed ul li.active .notif-badge {
            border-color: rgb(1, 39, 102);
        }

        .sidebar::-webkit-scrollbar {
            display: none;
        }

        .sidebar.closed {
            width: 70px !important;
            min-width: 70px !important;
            background: rgb(2, 2, 215);
            color: white;
        }

        /* Toggle button styles */
        .toggle-btn {
            cursor: pointer;
            padding: 10px;
            position: absolute;
            top: 20px;
            right: 10px;
            border-radius: 50%;
            background: #fff;
            color: #222;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease-in-out;
        }

        .sidebar.closed .toggle-btn {
            background: rgb(2, 2, 215);
            color: #fff;
        }

        /* User info styles */
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            padding: 15px;
            background: #f1f1f1;
            border-radius: 10px;
            width: 100%;
        }

        .user-details {
            display: flex;
            flex-direction: column;
            transition: opacity 0.15s ease-in-out;
        }

        .user-details span,
        .user-details strong {
            font-size: 10px;
        }

        .sidebar.closed .user-details {
            display: none;
        }

        .sidebar.closed .user-info {
            visibility: hidden;
            display: none;
        }

        /* Navigation styles */
        .sidebar ul {
            list-style: none;
            padding-left: 0;
            margin-top: 20px;
            flex-grow: 1;
            /* Pushes the logout button to the bottom */
            display: flex;
            flex-direction: column;
        }

        .sidebar.closed ul {
            margin-top: 80px;
        }

        .sidebar ul li.active {
            background: rgb(0, 66, 172) !important;
            /* Highlight color */
            color: #fff !important;
        }

        .sidebar ul li.active a {
            color: #fff !important;
        }

        .sidebar.closed ul li.active {
            background: rgb(1, 39, 102) !important;
            color: #fff !important;
        }


        .sidebar ul li {
            padding: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            transition: background 0.1s, color 0.1s;
            border-radius: 15px;
        }

        .sidebar ul li svg {
            width: 16px !important;
            height: 16px !important;
            flex-shrink: 0;
            transition: stroke 0.1s ease-in-out;
        }

        .sidebar ul li:hover {
            background: rgba(0, 0, 0, 0.1);
        }

        .sidebar.closed ul li:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .sidebar ul li a {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: inherit;
            font-weight: 500;
            width: 100%;
            transition: color 0.1s ease-in-out;
            font-size: 13px;
        }

        .sidebar.closed ul li a {
            justify-content: center;
            gap: 0;
            color: #fff;
        }

        .sidebar.closed ul li a span {
            display: none;
        }

        /* Logout button */
        .logout {
            margin-top: auto;
            /* Push logout to the bottom */
        }

        .content {
            flex: 1;
            height: 100dvh;
            overflow-y: auto;
            /* This allows your dashboard content to scroll */
            padding-top: 80px;
            /* Space for your fixed header */
            position: relative;
            z-index: 900;

            will-change: margin-left;
        }

        .sidebar li {
            position: relative;
            z-index: 1001;
            /* Ensure tooltips are on top */
        }

        /* Tooltip Styles */
        .sidebar li .tooltip {
            position: absolute;
            top: 50%;
            left: calc(100% + 10px);
            transform: translateY(-50%);
            background: rgba(0, 0, 0, 0.9);
            color: white;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 400;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.2s ease-in-out, visibility 0.2s ease-in-out;
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.3);
            z-index: 1100;
        }

        /* Show tooltip ONLY when sidebar is closed */
        .sidebar.closed li:hover .tooltip {
            opacity: 1;
            visibility: visible;
        }

        /* Hide tooltips when sidebar is open */
        .sidebar.open .tooltip {
            opacity: 0 !important;
            visibility: hidden !important;
            pointer-events: none !important;
        }

        /* HEADER */
        header {
            background: white;
            color: black;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            position: fixed;
            width: 100%;
            z-index: 1000;
        }

        header .logo-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        header img {
            width: 80px !important;
            height: 70px !important;
        }

        header .company-name {
            font-size: 18px;
            font-weight: bold;
            color: #0047bb;
        }

        header .subtext {
            font-size: 12px;
            font-weight: bold;
            color: black;
        }

        /* Toggle Button inside Header */
        #mobileMenuBtn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 24px;
            color: black;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px;
        }

        .nuevalg {
            display: block;
        }

        .nuevasm {
            display: none;
        }


        @media (max-width: 1024px) {
            .sidebar {
                width: 250px;
            }

            .content {
                margin-left: 250px;
                width: calc(100% - 250px);
            }

            .sidebar.closed~.content {
                margin-left: 70px;
                width: calc(100% - 70px);
            }
        }

        @media (max-width: 767px) {
            .custom-scroll {
                overflow-y: visible !important;
            }
        }

        @media (min-width: 768px) {
            .custom-scroll {
                overflow-y: auto;
            }
        }

        @media (min-width: 769px) {
            #mobileMenuBtn {
                display: none !important;
            }

            .notif {
                display: none !important;
            }

            #toggleBtn {
                display: flex !important;
                /* Ensure sidebar toggle is visible */
            }

            .sidebar {
                left: 0 !important;
                /* Sidebar always open */
                width: 300px;
                /* Full width */
            }

        }


        /* Sidebar behavior on mobile */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                left: -100%;
                top: 0;
                height: 100dvh;
                /* Ensure it matches mobile height exactly */
                width: 280px;
                /* Slightly wider for better mobile tap targets */
                transition: left 0.3s ease-in-out;
            }

            body.menu-open {
                overflow: hidden;
            }

            .nuevalg {
                display: none;
            }

            .nuevasm {
                display: block;
            }

            .sidebar.open {
                left: 0;
            }

            .content {
                margin-left: 0;
                width: 100%;
            }

            header {
                padding: 10px;
                display: flex;
                justify-content: space-between;
                align-items: center;

            }

            header .text-container {
                display: flex;
                justify-content: center;
                align-items: center;
                text-align: center;
                width: 100%;
                position: absolute;
                left: 50%;
                transform: translateX(-50%);
            }

            header img {
                width: 80px !important;
                height: 70px !important;
            }

            #mobileMenuBtn {
                display: flex !important;
            }

            #toggleBtn {
                display: none !important;
            }
        }

        /* Adjustments for very small screens */
        @media (max-width: 480px) {
            .sidebar {
                width: 220px;
            }

            .nuevalg {
                display: none;
            }

            .nuevasm {
                display: block;
            }

            header .company-name {
                font-size: 14px;
            }

            header .subtext {
                font-size: 10px;
            }

            #mobileMenuBtn {
                font-size: 20px;
                padding: 8px;
            }
        }

        /* Updated Dropdown Styles - Perfectly Matched to Your Design */
        .has-dropdown {
            position: relative;
            padding: 8px 8px 0 8px;
            /* Top padding only */
            border-radius: 15px;
            transition: background 0.1s;
            overflow: hidden;
            /* Add this to contain children */
        }


        .has-dropdown>div {
            display: flex;
            flex-direction: column;
            width: 100%;
            padding: 0;
            margin: 0;
            gap: 0;
        }

        /* Update the anchor tag styling */
        .has-dropdown>div>a {
            display: flex;
            justify-content: space-between;
            /* This is key */
            align-items: center;
            width: 100%;
            position: relative;
        }

        /* Text container adjustment */
        .has-dropdown>div>a>div {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            /* Takes all available space */
            min-width: 0;
            /* Allows text truncation */
        }

        /* Arrow icon positioning */
        .dropdown-arrow {
            margin-left: auto;
            /* The magic property */
            flex-shrink: 0;
            /* Never shrinks */
            transition: transform 0.3s ease;
        }

        /* Active state rotation */
        .has-dropdown.drop .dropdown-arrow {
            transform: rotate(180deg);
            color: inherit !important;
        }

        /* Remove the pseudo-element arrow (using real icon now) */
        .has-dropdown>a::after {
            display: none !important;
            /* Disable the CSS arrow */
        }

        .has-dropdown.drop {
            background-color: rgba(0, 0, 0, 0.1) !important;
        }

        .has-dropdown ul {
            display: none;
            background-color: white;
        }

        .has-dropdown.drop ul {
            display: block;
            background-color: transparent !important;
        }


        .has-dropdown:hover {
            background: rgba(0, 0, 0, 0.1);
        }

        .sidebar.closed .has-dropdown:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        /* Update the dropdown-menu styles */
        .dropdown-menu {
            width: calc(100% - 20px);
            margin-left: 20px;
            max-height: 0;
            overflow: hidden;
            transition:
                max-height 0.3s ease-in-out,
                visibility 0.3s ease-in-out;
            /* Add visibility transition */
            margin: 0 0 0 20px;
            padding: 0 0 0 10px;
            background: transparent;
            border-left: 1px solid rgba(0, 0, 0, 0.1);
            visibility: hidden;
            /* Start hidden */
        }

        /* Update the active state */
        .has-dropdown.drop .dropdown-menu {
            max-height: 200px;
            margin-top: 5px;
            padding-bottom: 5px;
            visibility: visible;
            color: #222 !important;
            padding-left: 5px;
            border-left: 1px solid #222 !important;
        }

        .has-dropdown.drop .dropdown-menu li {
            background: transparent !important;
        }

        .has-dropdown.drop .dropdown-menu li a {
            color: #222 !important;

        }

        .has-dropdown.drop .dropdown-menu li:hover {
            background: rgba(0, 0, 0, 0.1) !important;
        }

        .has-dropdown.drop .dropdown-menu li.active:hover {
            background-color: rgb(0, 66, 172) !important;
        }

        .has-dropdown.drop>div>a {
            color: inherit !important;
        }

        .has-dropdown:not(.drop) .dropdown-menu {
            transition:
                max-height 0.2s ease-in-out,
                visibility 0.2s ease-in-out;
        }

        .dropdown-menu li {
            opacity: 1;
            transition: opacity 0.1s ease;
        }

        .has-dropdown:not(.drop) .dropdown-menu li {
            opacity: 0;
            /* Hide items immediately when closing */
        }

        .dropdown-menu li a {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 12px !important;
            color: inherit !important;
        }

        .dropdown-menu li:hover {
            background: rgba(0, 0, 0, 0.1) !important;
        }

        .sidebar.closed .dropdown-menu li:hover {
            background: rgba(255, 255, 255, 0.1) !important;
        }

        /* Dropdown arrow - perfectly matched */
        .has-dropdown>a {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            position: relative;
        }

        .has-dropdown>a::after {
            content: "";
            margin-left: auto;
            width: 0;
            height: 0;
            border-left: 5px solid transparent;
            border-right: 5px solid transparent;
            border-top: 5px solid currentColor;
            transition: transform 0.3s ease;
            margin-right: 5px;
        }

        .has-dropdown.drop>a::after {
            transform: rotate(180deg);
        }


        .has-dropdown.drop div {
            transition: transform 0.3s ease;
            display: block;
        }

        .has-dropdown.drop a {
            color: #fff !important;
        }


        .sidebar.closed .has-dropdown.drop {
            background: rgba(255, 255, 255, 0.1) !important;
        }

        /* Closed sidebar adjustments */
        .sidebar.closed .dropdown-menu {
            display: none !important;
        }


        .sidebar.closed .has-dropdown>a::after {
            display: none;
        }

        .sidebar.closed .has-dropdown.drop {
            background: rgb(1, 39, 102) !important;
        }

        .has-dropdown .dropdown-menu li.active {
            background-color: rgb(0, 66, 172) !important;
            color: white !important;
            border-radius: 15px;
        }

        .has-dropdown .dropdown-menu li.active a {
            color: white !important;

        }

        
    </style>
</head>

<body>
    <div class="sidebar overflow-y-scroll overflow-x-hidden" id="sidebar">
        <div class="toggle-btn" id="toggleBtn">
            <i data-lucide="menu"></i>
        </div>
        <div class="user-info bg-[#0047bb]">
            <i data-lucide="user" class="text-white"></i>
            <div class="user-details">
                <strong class="text-xs text-white "><?= $username ?></strong>
                <span class="text-xs text-white"><?= $role ?></span>
            </div>
        </div>
        <ul>
            <li>
                <a href="admin-dashboard.php">
                    <i data-lucide="layout-dashboard"></i>
                    <span>Dashboard</span>
                </a>
                <span class="tooltip">Dashboard</span>
            </li>
            <li class="notif">
                <a href="Notifications.php">
                    <i data-lucide="bell"></i>
                    <span>Notifications</span>
                    <?php if (!empty($sidebarUnreadCount)): ?>
                        <span class="notif-badge"><?= $sidebarUnreadCount ?></span>
                    <?php endif; ?>
                </a>
                <span class="tooltip">Notifications</span>
            </li>

            <li>
                <a href="user-management.php">
                    <i data-lucide="users"></i>
                    <span>User Management</span>
                </a>
                <span class="tooltip">User Management</span>
            </li>
            <li>
                <a href="office.php">
                    <i data-lucide="briefcase"></i>
                    <span>Office</span>
                </a>
                <span class="tooltip">Office</span>
            </li>
            <li>
                <a href="item.php">
                    <i data-lucide="package"></i>
                    <span>Item</span>
                </a>
                <span class="tooltip">Item</span>
            </li>
            <li>
                <a href="inventory.php">
                    <i data-lucide="warehouse"></i>
                    <span>Inventory</span>
                </a>
                <span class="tooltip">Inventory</span>
            </li>
            <li>
                <a href="stockIn.php">
                    <i data-lucide="package-plus"></i>
                    <span>Stock In</span>
                </a>
                <span class="tooltip">Stock In</span>
            </li>
            <li>
                <a href="stockOut.php">
                    <i data-lucide="package-minus"></i>
                    <span>Stock Out</span>
                </a>
                <span class="tooltip">Stock Out</span>
            </li>
            <li>
                <a href="inventory-alignment.php">
                    <i data-lucide="clipboard-check"></i>
                    <span>Inventory Alignment</span>
                </a>
                <span class="tooltip">Inventory Alignment</span>
            </li>
            <li class="has-dropdown">
                <div>
                    <a href="#">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <i data-lucide="bar-chart-2"></i>
                            <span>Reports</span>
                        </div>
                        <i data-lucide="chevron-down" class="dropdown-arrow"></i>
                    </a>
                    <span class="tooltip">Reports</span>
                    <ul class="dropdown-menu">
                        <!-- <li class="nav-item">
                            <a href="report-ris-monthly.php" class="nav-link">
                                <i data-lucide="file-bar-chart"></i>
                                <span class="nav-text">RIS Monthly Report</span>
                            </a>
                        </li> -->
                        <li>
                            <a href="report-ris-monthly.php">
                                <i data-lucide="file-bar-chart"></i>
                                <span>RSMI Report</span>
                            </a>
                            <span class="tooltip">RSMI Report</span>
                        </li>
                        <li>
                            <a href="report_rpci.php">
                                <i data-lucide="file"></i>
                                <span>RPCI Report</span>
                            </a>
                            <span class="tooltip">RPCI Report</span>
                        </li>
                        <li>
                            <a href="inventory_transaction.php">
                                <i data-lucide="folder-clock"></i>
                                <span>Inventory Transaction</span>
                            </a>
                            <span class="tooltip">Inventory Transaction</span>
                        </li>
                        <li>
                            <a href="item-movement.php">
                                <i data-lucide="clipboard-list"></i>
                                <span>Item Movement</span>
                            </a>
                            <span class="tooltip">Item Movement</span>
                        </li>
                        <li>
                            <a href="baseline-inventory.php">
                                <i data-lucide="folder"></i>
                                <span>Baseline Inventory</span>
                            </a>
                            <span class="tooltip">Baseline Inventory</span>
                        </li>
                    </ul>
                </div>
            </li>
            <li class="has-dropdown">
                <div>
                    <a href="#">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <i data-lucide="history"></i>
                            <span>History</span>
                        </div>
                        <i data-lucide="chevron-down" class="dropdown-arrow"></i>
                    </a>
                    <span class="tooltip">History</span>
                    <ul class="dropdown-menu">
                        <li>
                            <a href="user-login-history.php">
                                <i data-lucide="log-in"></i>
                                <span>User Login History</span>
                            </a>
                            <span class="tooltip">User Login history</span>
                        </li>
                        <li>
                            <a href="user-activity-history.php">
                                <i data-lucide="activity"></i>
                                <span>User Activity History</span>
                            </a>
                            <span class="tooltip">User Activity History</span>
                        </li>

                    </ul>
                </div>
            </li>

            <li>
                <a href="archive.php">
                    <i data-lucide="archive"></i>
                    <span>Archive</span>
                </a>
                <span class="tooltip">Archive</span>
            </li>

            <!-- Logout button inside <ul> -->
            <li class="logout">
                <a href="logout.php">
                    <i data-lucide="log-out"></i>
                    <span>Logout</span>
                </a>
                <span class="tooltip">Logout</span>
            </li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="flex-1 w-full mx-auto flex flex-col custom-scroll">
        <!-- Navbar -->
        <header class="bg-white text-black flex items-center justify-between z-[999] shadow-lg relative px-4">
            <div class="flex items-center space-x-2">
                <a href="#" class="flex items-center space-x-2">
                    <img src="../image/logo.png" alt="warehouse logo" class="w-20 h-20">
                </a>

                <div class="text-container relative">
                    <a href="#"
                        class="flex flex-col sm:flex-row items-center space-x-0 sm:space-x-4 text-center sm:text-left">
                        <!-- <div class="text-[14px] xs:text-[16px] sm:text-[24px] font-bold text-[#0047bb] font-serif">
                            AD<span
                                class="text-[#0047bb] text-[18px] xs:text-[20px] sm:text-[30px] font-serif">V</span>ECT
                        </div> -->
                        <div class="mt-1 sm:mt-0 text-[10px] xs:text-[12px] sm:text-[15px] font-bold text-black">
                            SDO Gapan City Supply/Property Unit
                        </div>
                        <!-- <div class="nuevasm sm:mt-0 text-[10px] xs:text-[12px] sm:text-[15px] font-bold text-black">
                            Supply/Property Unit
                        </div> -->

                    </a>
                </div>

            </div>
            <div class="nuevalg text-[10px] xs:text-[12px] sm:text-[15px] font-bold text-black">
                <!-- NUEVA -->
                <span><?php include "notifications_widget.php"; ?></span>
            </div>  

            <button id="mobileMenuBtn" class="toggle-btn sm:hidden">
                <i data-lucide="menu"></i>
            </button>
        </header>
        <script>
            window.addEventListener('DOMContentLoaded', async () => {
                // Check for successful baseline start confirmation
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.has('baseline_started')) {
                    Swal.fire({
                        title: 'Baseline Started!',
                        text: 'Inventory process has been initialized.',
                        icon: 'success',
                        timer: 3000
                    });
                    // Clean URL after showing
                    window.history.replaceState({}, document.title, window.location.pathname);
                }

                // Check if today's baseline exists AND if items exist
                const response = await fetch('./check-baseline.php');
                const data = await response.json();

                // Only show alert if items exist but baseline doesn't
                if (data.items_exist && !data.inventory_exists) {
                    Swal.fire({
                        title: "Start Month's Inventory?",
                        text: "Monthly inventory process needs to be initialized.",
                        icon: "info",
                        showCancelButton: false,
                        allowOutsideClick: false,
                        confirmButtonText: "Initialize Now",
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        allowEnterKey: false,
                        backdrop: true
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.href = 'start-baseline.php';
                        }
                    });
                }
            });
        </script>
</body>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    // Optimized sidebar functionality
    let sidebarToggleTimeout = null;
    let resizeTimeout = null;

    function mobileToggle(e) {
        if (e) e.stopPropagation();
        let sidebar = document.getElementById("sidebar");
        sidebar.classList.toggle("open");
        document.body.classList.toggle("menu-open");
        setActiveSidebarLink();
    }

    // Optimized Sidebar Toggle Logic
    function sidebarToggle() {
        const sidebar = document.getElementById("sidebar");
        const isClosing = !sidebar.classList.contains("closed");

        // 1. Use RequestAnimationFrame for smoother transition start
        requestAnimationFrame(() => {
            sidebar.classList.toggle("closed");

            // 2. Only recreate icons inside the sidebar to save CPU
            if (typeof lucide !== 'undefined') {
                lucide.createIcons({
                    attrs: { class: 'lucide-icon' },
                    nameAttr: 'data-lucide',
                    root: sidebar // ONLY scan the sidebar, not the whole dashboard
                });
            }

            // 3. Notify charts to resize ONLY once after the animation finishes
            setTimeout(() => {
                if (window.myCharts) { // Ensure you store chart instances in a global array
                    Object.values(window.myCharts).forEach(chart => chart.resize());
                }
            }, 160); // Match your 0.15s CSS transition
        });
    }
    // Debounced resize handler
    function handleResize() {
        if (resizeTimeout) clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(() => {
            let sidebar = document.getElementById("sidebar");
            let screenWidth = window.innerWidth;

            if (screenWidth > 768) {
                sidebar.classList.remove("open");
                document.body.classList.remove("menu-open");
                // Don't force remove closed class - let it maintain user preference
            } else {
                sidebar.classList.remove("closed");
            }
            setActiveSidebarLink();
        }, 150);
    }

    // Close sidebar when clicking outside (for mobile only) - optimized
    document.addEventListener("click", function (event) {
        if (window.innerWidth <= 768) {
            let sidebar = document.getElementById("sidebar");
            let mobileMenuBtn = document.getElementById("mobileMenuBtn");

            // Only process if sidebar is open
            if (sidebar.classList.contains("open")) {
                if (!sidebar.contains(event.target) && !mobileMenuBtn.contains(event.target)) {
                    sidebar.classList.remove("open");
                    document.body.classList.remove("menu-open");
                    setActiveSidebarLink();
                }
            }
        }
    });

    // Optimized dropdown handling
    function initDropdowns() {
        const dropdownItems = document.querySelectorAll('.has-dropdown');
        const currentPath = window.location.pathname.split("/").pop();

        dropdownItems.forEach(item => {
            const link = item.querySelector('a:first-child');

            // Remove old listener to prevent duplicates
            link.removeEventListener('click', handleDropdownClick);
            link.addEventListener('click', handleDropdownClick);

            function handleDropdownClick(e) {
                const sidebar = document.getElementById("sidebar");
                // On mobile, always allow dropdown toggling
                if (window.innerWidth <= 768 || !sidebar.classList.contains('closed')) {
                    e.preventDefault();
                    e.stopPropagation();

                    // Toggle current dropdown
                    const isOpen = item.classList.contains('drop');

                    // Close other dropdowns
                    dropdownItems.forEach(otherItem => {
                        if (otherItem !== item && otherItem.classList.contains('drop')) {
                            otherItem.classList.remove('drop');
                        }
                    });

                    // Toggle current
                    if (!isOpen) {
                        item.classList.add('drop');
                    } else {
                        item.classList.remove('drop');
                    }
                }
            }

            // Check if current page belongs to this dropdown
            const dropdownLinks = item.querySelectorAll('ul li a');
            let shouldStayOpen = false;

            dropdownLinks.forEach(dropdownLink => {
                const linkPath = dropdownLink.getAttribute('href');
                const reportPages = ['baseline-inventory.php', 'item-movement.php', 'inventory_transaction.php',
                    'report-ris-monthly.php', 'report_rpci.php'];
                const historyPages = ['user-login-history.php', 'user-activity-history.php'];

                if (linkPath === currentPath ||
                    (reportPages.includes(currentPath) && reportPages.includes(linkPath)) ||
                    (historyPages.includes(currentPath) && historyPages.includes(linkPath))) {
                    shouldStayOpen = true;
                }
            });

            if (shouldStayOpen) {
                item.classList.add('drop');
            }
        });
    }

    // Optimized active link setter
    function setActiveSidebarLink() {
        const sidebarLinks = document.querySelectorAll(".sidebar ul li a");
        const currentPath = window.location.pathname.split("/").pop();

        // Remove all active classes
        sidebarLinks.forEach(link => {
            link.parentElement.classList.remove("active");
        });

        // Apply active classes
        sidebarLinks.forEach(link => {
            const linkPath = link.getAttribute("href");
            const parentLi = link.parentElement;

            if (linkPath === currentPath) {
                parentLi.classList.add("active");
            } else if ((currentPath === "archive-principal.php" || currentPath === "archive-item.php") &&
                linkPath === "archive.php") {
                parentLi.classList.add("active");
            }
        });

        // Update dropdown open states
        const dropdownParents = document.querySelectorAll('.has-dropdown');
        dropdownParents.forEach(parent => {
            const hasActiveChild = parent.querySelector('.active') !== null;
            if (hasActiveChild) {
                parent.classList.add('drop');
            }
        });
    }

    // Initialize everything when DOM is ready
    document.addEventListener('DOMContentLoaded', function () {
        // Set initial states
        const sidebar = document.getElementById("sidebar");
        if (window.innerWidth > 768) {
            sidebar.classList.remove("open");
            sidebar.classList.remove("closed");
        } else {
            sidebar.classList.remove("closed");
        }

        // Initialize dropdowns
        initDropdowns();

        // Set active link
        setActiveSidebarLink();

        // Initialize Lucide icons
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }

        // Add event listeners with optimization
        const mobileBtn = document.getElementById("mobileMenuBtn");
        const toggleBtn = document.getElementById("toggleBtn");

        if (mobileBtn) {
            mobileBtn.removeEventListener("click", mobileToggle);
            mobileBtn.addEventListener("click", mobileToggle);
        }

        if (toggleBtn) {
            toggleBtn.removeEventListener("click", sidebarToggle);
            toggleBtn.addEventListener("click", sidebarToggle);
        }

        window.removeEventListener("resize", handleResize);
        window.addEventListener("resize", handleResize);

        // Initial resize handling
        handleResize();
    });
</script>

</html>