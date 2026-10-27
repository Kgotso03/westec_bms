<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Global Header Component
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$currentUser = getCurrentUser();
$dbHealth = checkDatabaseHealth();
$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' | ' : '' ?>WesTec Eswatini BMS</title>
    
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Custom Stylesheet -->
    <link rel="stylesheet" href="/westec_bms/assets/css/style.css">
</head>
<body>

<div class="app-wrapper">
    <!-- Backdrop Overlay for Three-Line Drawer Menu -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- Sidebar Drawer (Opens at top left corner) -->
    <aside class="app-sidebar" id="appSidebar">
        <div class="sidebar-header-bar d-flex align-items-center justify-content-between">
            <a href="/westec_bms/dashboard/index.php" class="sidebar-brand">
                <div class="brand-icon">
                    <i class="bi bi-shield-check"></i>
                </div>
                <div class="brand-info">
                    <h1 class="brand-title">WesTec BMS</h1>
                    <p class="brand-subtitle">Eswatini IT Solutions</p>
                </div>
            </a>
            <!-- Close Button inside menu drawer -->
            <button type="button" class="btn-close btn-close-white me-3" id="menuCloseBtn" aria-label="Close navigation menu" title="Close Menu"></button>
        </div>

        <div class="sidebar-nav">
            <p class="nav-section-title">Core Operations</p>
            <a href="/westec_bms/dashboard/index.php" class="nav-link <?= ($currentDir === 'dashboard') ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i>
                <span>Dashboard</span>
            </a>
            <a href="/westec_bms/users/register.php" class="nav-link <?= ($currentDir === 'users' && $currentPage === 'register.php') ? 'active' : '' ?>">
                <i class="bi bi-person-plus-fill"></i>
                <span>User Registration</span>
            </a>
            <a href="/westec_bms/clients/index.php" class="nav-link <?= ($currentDir === 'clients') ? 'active' : '' ?>">
                <i class="bi bi-building"></i>
                <span>Clients</span>
            </a>
            <a href="/westec_bms/staff/index.php" class="nav-link <?= ($currentDir === 'staff') ? 'active' : '' ?>">
                <i class="bi bi-people-fill"></i>
                <span>Staff Directory</span>
            </a>
            <a href="/westec_bms/tickets/index.php" class="nav-link <?= ($currentDir === 'tickets') ? 'active' : '' ?>">
                <i class="bi bi-ticket-detailed-fill"></i>
                <span>Service Tickets</span>
            </a>
            <a href="/westec_bms/inventory/index.php" class="nav-link <?= ($currentDir === 'inventory') ? 'active' : '' ?>">
                <i class="bi bi-box-seam-fill"></i>
                <span>Inventory & Stock</span>
            </a>
            <a href="/westec_bms/projects/index.php" class="nav-link <?= ($currentDir === 'projects') ? 'active' : '' ?>">
                <i class="bi bi-diagram-3-fill"></i>
                <span>Projects & Milestones</span>
            </a>
            <a href="/westec_bms/reports/index.php" class="nav-link <?= ($currentDir === 'reports') ? 'active' : '' ?>">
                <i class="bi bi-graph-up-arrow"></i>
                <span>Reports & Analytics</span>
            </a>

            <p class="nav-section-title">Triggers & Governance</p>
            <a href="/westec_bms/admin/trigger_demo.php" class="nav-link <?= ($currentDir === 'admin' && $currentPage === 'trigger_demo.php') ? 'active' : '' ?>">
                <i class="bi bi-lightning-charge-fill text-warning"></i>
                <span>Trigger Demonstrations</span>
            </a>
            <a href="/westec_bms/admin/audit_logs.php" class="nav-link <?= ($currentDir === 'admin' && $currentPage === 'audit_logs.php') ? 'active' : '' ?>">
                <i class="bi bi-journal-text"></i>
                <span>Audit Trail Logs</span>
            </a>
        </div>

        <div class="sidebar-footer">
            <div class="d-flex align-items-center justify-content-between mb-1">
                <span>Database Connection</span>
                <span class="status-pill bg-dark bg-opacity-50">
                    <span class="status-dot <?= $dbHealth['connected'] ? 'status-online' : 'status-offline' ?>"></span>
                    <span class="text-white small"><?= $dbHealth['connected'] ? 'Online' : 'Offline' ?></span>
                </span>
            </div>
            <div class="text-white-50" style="font-size: 0.72rem;">
                MySQL: <?= e($dbHealth['database']) ?> (<?= $dbHealth['table_count'] ?> tables)
            </div>
        </div>
    </aside>

    <!-- Main Content Area -->
    <div class="app-main">
        <!-- Top Navigation Bar -->
        <header class="app-topbar">
            <div class="d-flex align-items-center">
                <!-- Three-Line Menu (Hamburger) Button at Top Left Corner -->
                <button class="hamburger-btn me-3" id="menuToggleBtn" type="button" aria-label="Toggle navigation menu" title="Open Navigation Menu">
                    <span class="hamburger-line"></span>
                    <span class="hamburger-line"></span>
                    <span class="hamburger-line"></span>
                </button>
                <div>
                    <h2 class="page-context-title"><?= isset($pageTitle) ? e($pageTitle) : 'WesTec Eswatini' ?></h2>
                    <p class="page-context-desc"><?= isset($pageSubtitle) ? e($pageSubtitle) : 'Business Management & IT Infrastructure Operations' ?></p>
                </div>
            </div>

            <div class="d-flex align-items-center gap-3">
                <span class="badge bg-light text-dark border px-3 py-2 d-none d-md-inline-block">
                    <i class="bi bi-database-check text-success me-1"></i>
                    MySQL: <strong class="text-primary"><?= e($dbHealth['database']) ?></strong>
                </span>

                <?php if ($currentUser): ?>
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary dropdown-toggle d-flex align-items-center gap-2 py-1 px-3" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-person-circle fs-5 text-primary"></i>
                            <div class="text-start">
                                <span class="d-block fw-bold small text-dark"><?= e($currentUser['username']) ?></span>
                                <span class="d-block text-muted" style="font-size: 0.7rem;"><?= e($currentUser['role_name']) ?></span>
                            </div>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li class="dropdown-header">Signed in as <?= e($currentUser['username']) ?></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="/westec_bms/dashboard/index.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a></li>
                            <li><a class="dropdown-item" href="/westec_bms/admin/trigger_demo.php"><i class="bi bi-lightning-charge me-2"></i>Trigger Demos</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="/westec_bms/auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign Out</a></li>
                        </ul>
                    </div>
                <?php else: ?>
                    <div class="d-flex gap-2">
                        <a href="/westec_bms/auth/login.php" class="btn btn-westec btn-sm px-3">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                        </a>
                        <a href="/westec_bms/users/register.php" class="btn btn-westec-outline btn-sm px-3">
                            <i class="bi bi-person-plus me-1"></i> Register
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <!-- Page Content Wrapper -->
        <main class="page-content">
            <?php renderFlashMessages(); ?>
