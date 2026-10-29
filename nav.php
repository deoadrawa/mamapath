<?php
// ============================================
// FILE: nav.php
// PURPOSE: Colorful Sidebar Navigation + Top Bar with Dark Mode
// Includes: Dynamic menu, user info, live clock, dark mode toggle
// ============================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) && basename($_SERVER['PHP_SELF']) !== 'login.php') {
    header('Location: login.php');
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);
$user_name = $_SESSION['user_fullname'] ?? 'User';
$user_role = $_SESSION['user_role'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lubaga Hospital MCH System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            /* Light Mode Colors */
            --bg-body: #f0f4f8;
            --sidebar-bg: linear-gradient(180deg, #0a2b44 0%, #0d3b5e 100%);
            --topbar-bg: #ffffff;
            --card-bg: #ffffff;
            --text-primary: #1a2a3a;
            --text-secondary: #4a5568;
            --text-muted: #718096;
            --border: #e2e8f0;
            
            /* Brand Colors */
            --primary: #1e88e5;
            --primary-dark: #1565c0;
            --primary-light: #42a5f5;
            --secondary: #00acc1;
            --success: #43a047;
            --success-light: #e8f5e9;
            --warning: #fb8c00;
            --warning-light: #fff3e0;
            --danger: #e53935;
            --danger-light: #ffebee;
            --info: #26c6da;
            --info-light: #e0f7fa;
            --purple: #8e24aa;
            --purple-light: #f3e5f5;
            --teal: #00897b;
            --teal-light: #e0f2f1;
            
            /* Menu Item Colors */
            --menu-dashboard: #42a5f5;
            --menu-patients: #26c6da;
            --menu-anc: #43a047;
            --menu-delivery: #fb8c00;
            --menu-pnc: #8e24aa;
            --menu-search: #1e88e5;
            --menu-reports: #00897b;
        }

        body.dark {
            --bg-body: #121826;
            --sidebar-bg: linear-gradient(180deg, #0a1628 0%, #0c1a2e 100%);
            --topbar-bg: #1a2332;
            --card-bg: #1a2332;
            --text-primary: #e2e8f0;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --border: #2d3748;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            transition: background 0.2s, color 0.2s;
        }

        /* Layout */
        .app-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar - Colorful Gradient */
        .sidebar {
            width: 280px;
            background: var(--sidebar-bg);
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            overflow-y: auto;
            z-index: 100;
            transition: transform 0.3s;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
        }

        /* Custom Scrollbar for Sidebar */
        .sidebar::-webkit-scrollbar {
            width: 5px;
        }

        .sidebar::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
        }

        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 10px;
        }

        .sidebar-header {
            padding: 28px 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 16px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, #42a5f5, #1565c0);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: white;
        }

        .logo-text {
            font-size: 18px;
            font-weight: 700;
            color: white;
            letter-spacing: -0.5px;
        }

        .logo-text small {
            font-size: 11px;
            font-weight: 400;
            opacity: 0.7;
            display: block;
            margin-top: 2px;
        }

        /* Navigation Menu */
        .nav-menu {
            padding: 0 16px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 18px;
            border-radius: 14px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .nav-item i {
            width: 22px;
            font-size: 18px;
            text-align: center;
        }

        .nav-item span {
            font-size: 14px;
        }

        /* Individual Menu Item Colors on Hover/Active */
        .nav-item[data-menu="dashboard"]:hover,
        .nav-item[data-menu="dashboard"].active {
            background: rgba(66, 165, 245, 0.25);
            color: #90caf9;
            border-left: 3px solid #42a5f5;
        }

        .nav-item[data-menu="patients"]:hover,
        .nav-item[data-menu="patients"].active {
            background: rgba(38, 198, 218, 0.25);
            color: #80deea;
            border-left: 3px solid #26c6da;
        }

        .nav-item[data-menu="anc"]:hover,
        .nav-item[data-menu="anc"].active {
            background: rgba(67, 160, 71, 0.25);
            color: #a5d6a7;
            border-left: 3px solid #43a047;
        }

        .nav-item[data-menu="delivery"]:hover,
        .nav-item[data-menu="delivery"].active {
            background: rgba(251, 140, 0, 0.25);
            color: #ffcc80;
            border-left: 3px solid #fb8c00;
        }

        .nav-item[data-menu="pnc"]:hover,
        .nav-item[data-menu="pnc"].active {
            background: rgba(142, 36, 170, 0.25);
            color: #ce93d8;
            border-left: 3px solid #8e24aa;
        }

        .nav-item[data-menu="search"]:hover,
        .nav-item[data-menu="search"].active {
            background: rgba(30, 136, 229, 0.25);
            color: #90caf9;
            border-left: 3px solid #1e88e5;
        }

        .nav-item[data-menu="reports"]:hover,
        .nav-item[data-menu="reports"].active {
            background: rgba(0, 137, 123, 0.25);
            color: #80cbc4;
            border-left: 3px solid #00897b;
        }

        .nav-item[data-menu="appointments"]:hover,
        .nav-item[data-menu="appointments"].active,
        .nav-item[data-menu="users"]:hover,
        .nav-item[data-menu="users"].active,
        .nav-item[data-menu="audit"]:hover,
        .nav-item[data-menu="audit"].active {
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border-left: 3px solid #90caf9;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
        }

        /* Top Bar */
        .topbar {
            background: var(--topbar-bg);
            border-bottom: 1px solid var(--border);
            padding: 12px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 99;
            backdrop-filter: blur(10px);
        }

        .page-title {
            font-size: 20px;
            font-weight: 700;
            background: linear-gradient(135deg, #1e88e5, #00acc1);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .datetime {
            font-size: 13px;
            color: var(--text-secondary);
            background: var(--bg-body);
            padding: 6px 14px;
            border-radius: 40px;
            font-weight: 500;
        }

        .dark-toggle {
            background: var(--bg-body);
            border: 1px solid var(--border);
            font-size: 18px;
            cursor: pointer;
            color: var(--text-secondary);
            padding: 8px;
            border-radius: 40px;
            transition: 0.2s;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .dark-toggle:hover {
            background: var(--border);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 6px 12px 6px 8px;
            background: var(--bg-body);
            border-radius: 40px;
        }

        .user-avatar {
            width: 34px;
            height: 34px;
            background: linear-gradient(135deg, #1e88e5, #1565c0);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
        }

        .user-name {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-primary);
        }

        .user-role {
            font-size: 10px;
            color: var(--text-muted);
        }

        .logout-link {
            background: none;
            border: 0;
            color: var(--danger);
            cursor: pointer;
            font-size: 16px;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .logout-link:hover {
            opacity: 1;
        }

        /* Content Container */
        .content-container {
            padding: 28px 32px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .main-content {
                margin-left: 0;
            }
            .content-container {
                padding: 20px 16px;
            }
            .sidebar.open {
                transform: translateX(0);
            }
            .menu-toggle {
                display: block;
            }
        }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-primary);
        }
    </style>
</head>
<body>
<div class="app-wrapper">
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <div class="logo-icon">
                    <i class="fas fa-baby-carriage"></i>
                </div>
                <div class="logo-text">
                    Lubaga MCH
                    <small>Maternal & Child Health</small>
                </div>
            </div>
        </div>
        <div class="nav-menu">
            <a href="index.php" class="nav-item" data-menu="dashboard" id="menu-dashboard">
                <i class="fas fa-chart-line"></i>
                <span>Dashboard</span>
            </a>
            <a href="patients.php" class="nav-item" data-menu="patients" id="menu-patients">
                <i class="fas fa-users"></i>
                <span>Patient Registry</span>
            </a>
            <a href="register_patient.php" class="nav-item" data-menu="anc" id="menu-register">
                <i class="fas fa-plus-circle"></i>
                <span>ANC First Visit</span>
            </a>
            <a href="anc_visit.php" class="nav-item" data-menu="anc" id="menu-anc">
                <i class="fas fa-notes-medical"></i>
                <span>ANC Follow-up</span>
            </a>
            <a href="delivery_admission.php" class="nav-item" data-menu="delivery" id="menu-delivery">
                <i class="fas fa-birthday-cake"></i>
                <span>Delivery & Maternity</span>
            </a>
            <a href="postnatal_visit.php" class="nav-item" data-menu="pnc" id="menu-pnc">
                <i class="fas fa-hand-holding-heart"></i>
                <span>Postnatal Care</span>
            </a>
            <a href="search_global.php" class="nav-item" data-menu="search" id="menu-search">
                <i class="fas fa-search"></i>
                <span>Global Search</span>
            </a>
            <a href="reports.php" class="nav-item" data-menu="reports" id="menu-reports">
                <i class="fas fa-chart-bar"></i>
                <span>Reports & Analytics</span>
            </a>
            <a href="appointments.php" class="nav-item" data-menu="appointments" id="menu-appointments">
                <i class="fas fa-calendar-check"></i>
                <span>Appointments</span>
            </a>
            <a href="child_followup.php" class="nav-item" data-menu="child" id="menu-child">
                <i class="fas fa-baby"></i>
                <span>Child Health</span>
            </a>
            <a href="screenings.php" class="nav-item" data-menu="screenings" id="menu-screenings">
                <i class="fas fa-vial"></i>
                <span>Screening</span>
            </a>
            <a href="referrals.php" class="nav-item" data-menu="referrals" id="menu-referrals">
                <i class="fas fa-share-square"></i>
                <span>Referrals</span>
            </a>
            <a href="resource_planning.php" class="nav-item" data-menu="resources" id="menu-resources">
                <i class="fas fa-boxes"></i>
                <span>Resources & Workforce</span>
            </a>
            <?php if (hasRole('admin')): ?>
                <a href="users.php" class="nav-item" data-menu="users" id="menu-users">
                    <i class="fas fa-user-shield"></i>
                    <span>Staff Accounts</span>
                </a>
                <a href="audit_log.php" class="nav-item" data-menu="audit" id="menu-audit">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Audit Log</span>
                </a>
            <?php endif; ?>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="topbar">
            <button class="menu-toggle" id="menuToggle" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title">
                <?php
                    $titles = [
                        'index.php' => 'Clinical Dashboard',
                        'patients.php' => 'Patient Registry',
                        'register_patient.php' => 'New ANC Registration',
                        'anc_visit.php' => 'ANC Follow-up Visit',
                        'delivery_admission.php' => 'Delivery & Maternity Admission',
                        'postnatal_visit.php' => 'Postnatal Care Services',
                        'search_global.php' => 'Advanced Global Search',
                        'reports.php' => 'Reports & Analytics Hub',
                        'appointments.php' => 'Appointments',
                        'child_followup.php' => 'Child Health Follow-up',
                        'screenings.php' => 'Screening Register',
                        'referrals.php' => 'Referral Tracking',
                        'resource_planning.php' => 'Resources & Workforce Planning',
                        'users.php' => 'Staff Accounts',
                        'audit_log.php' => 'Audit Log',
                        'report_view.php' => 'Detailed Report Viewer',
                        'patient_profile.php' => 'Patient Journey Timeline'
                    ];
                    echo $titles[$current_page] ?? 'MCH System';
                ?>
            </div>
            <div class="topbar-right">
                <div class="datetime" id="liveDateTime"></div>
                <button class="dark-toggle" id="darkModeToggle">
                    <i class="fas fa-moon"></i>
                </button>
                <div class="user-info">
                    <div class="user-avatar">
                        <i class="fas fa-user-md"></i>
                    </div>
                    <div>
                        <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                        <div class="user-role"><?php echo htmlspecialchars(getUserRoleLabel($user_role)); ?></div>
                    </div>
                    <form method="POST" action="logout.php">
                        <?php echo csrfField(); ?>
                        <button type="submit" class="logout-link" title="Logout" aria-label="Log out">
                            <i class="fas fa-sign-out-alt"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <div class="content-container">

<script>
    // Set active menu item based on current page
    function setActiveMenu() {
        const currentPage = '<?php echo $current_page; ?>';
        const menuMap = {
            'index.php': 'menu-dashboard',
            'patients.php': 'menu-patients',
            'register_patient.php': 'menu-register',
            'anc_visit.php': 'menu-anc',
            'delivery_admission.php': 'menu-delivery',
            'postnatal_visit.php': 'menu-pnc',
            'search_global.php': 'menu-search',
            'reports.php': 'menu-reports',
            'appointments.php': 'menu-appointments',
            'child_followup.php': 'menu-child',
            'screenings.php': 'menu-screenings',
            'referrals.php': 'menu-referrals',
            'resource_planning.php': 'menu-resources',
            'users.php': 'menu-users',
            'audit_log.php': 'menu-audit',
            'report_view.php': 'menu-reports',
            'patient_profile.php': 'menu-patients'
        };
        
        const activeId = menuMap[currentPage];
        if (activeId) {
            document.getElementById(activeId)?.classList.add('active');
        }
    }
    
    // Live Date/Time Update
    function updateDateTime() {
        const now = new Date();
        const options = { 
            weekday: 'short',
            year: 'numeric', 
            month: 'short', 
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        };
        const datetimeElement = document.getElementById('liveDateTime');
        if (datetimeElement) {
            datetimeElement.innerText = now.toLocaleDateString('en-UG', options);
        }
    }
    
    // Dark Mode Toggle
    function initDarkMode() {
        const darkToggle = document.getElementById('darkModeToggle');
        if (localStorage.getItem('darkMode') === 'enabled') {
            document.body.classList.add('dark');
            if (darkToggle) darkToggle.innerHTML = '<i class="fas fa-sun"></i>';
        }
        darkToggle?.addEventListener('click', () => {
            document.body.classList.toggle('dark');
            if (document.body.classList.contains('dark')) {
                localStorage.setItem('darkMode', 'enabled');
                darkToggle.innerHTML = '<i class="fas fa-sun"></i>';
            } else {
                localStorage.setItem('darkMode', 'disabled');
                darkToggle.innerHTML = '<i class="fas fa-moon"></i>';
            }
        });
    }
    
    // Sidebar Toggle for Mobile
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
    }
    
    // Initialize all
    document.addEventListener('DOMContentLoaded', function() {
        setActiveMenu();
        updateDateTime();
        initDarkMode();
        setInterval(updateDateTime, 1000);
    });
</script>