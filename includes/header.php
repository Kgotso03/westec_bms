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
$isLanding = !empty($isLandingPage) || empty($currentUser) || ($currentPage === 'index.php' && in_array($currentDir, ['westec_bms', ''])) || ($currentDir === 'auth' && $currentPage !== 'change_password.php');
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
    <!-- Lucide Icons -->
    <script src="/westec_bms/assets/js/lucide.min.js"></script>
    <!-- Custom Stylesheet -->
    <link rel="stylesheet" href="/westec_bms/assets/css/style.css">
</head>
<body>

<div class="app-wrapper">
<?php if (!$isLanding): ?>
    <!-- Backdrop Overlay for Three-Line Drawer Menu -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- Sidebar Drawer (Opens at top left corner) -->
    <aside class="app-sidebar" id="appSidebar">
        <div class="sidebar-header-bar d-flex align-items-center justify-content-between">
            <a href="/westec_bms/dashboard/index.php" class="sidebar-brand">
                <img src="<?= getSystemLogoUrl() ?>" alt="WesTec Logo" class="brand-logo-img">
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
                <i data-lucide="layout-dashboard"></i>
                <span>Dashboard</span>
            </a>
            <a href="/westec_bms/auth/profile.php" class="nav-link <?= ($currentPage === 'profile.php' && $currentDir === 'auth') ? 'active' : '' ?>">
                <i data-lucide="user-circle-2"></i>
                <span>My Profile</span>
            </a>
            <?php if (hasRole('Administrator')): ?>
            <a href="/westec_bms/admin/user_management.php" class="nav-link <?= ($currentDir === 'admin' && $currentPage === 'user_management.php') ? 'active' : '' ?>">
                <i data-lucide="users"></i>
                <span>User Management</span>
            </a>
            <?php elseif (hasRole('IT Manager')): ?>
            <a href="/westec_bms/manager/my_team.php" class="nav-link <?= ($currentDir === 'manager' && $currentPage === 'my_team.php') ? 'active' : '' ?>">
                <i data-lucide="users"></i>
                <span>My Team</span>
            </a>
            <?php endif; ?>
            <a href="/westec_bms/clients/index.php" class="nav-link <?= ($currentDir === 'clients') ? 'active' : '' ?>">
                <i data-lucide="building-2"></i>
                <span>Clients</span>
            </a>
            <?php if (hasRole(['Administrator', 'IT Manager'])): ?>
            <a href="/westec_bms/staff/index.php" class="nav-link <?= ($currentDir === 'staff') ? 'active' : '' ?>">
                <i data-lucide="users"></i>
                <span>Staff Directory</span>
            </a>
            <?php endif; ?>
            <a href="/westec_bms/tickets/index.php" class="nav-link <?= ($currentDir === 'tickets') ? 'active' : '' ?>">
                <i data-lucide="ticket"></i>
                <span>Service Tickets</span>
            </a>
            <?php if (hasInventoryPermission('can_view')): ?>
            <a href="/westec_bms/inventory/index.php" class="nav-link <?= ($currentDir === 'inventory') ? 'active' : '' ?>">
                <i data-lucide="package"></i>
                <span>Inventory & Stock</span>
            </a>
            <?php endif; ?>
            <a href="/westec_bms/projects/index.php" class="nav-link <?= ($currentDir === 'projects') ? 'active' : '' ?>">
                <i data-lucide="workflow"></i>
                <span>Projects & Milestones</span>
            </a>
            <a href="/westec_bms/reports/index.php" class="nav-link <?= ($currentDir === 'reports') ? 'active' : '' ?>">
                <i data-lucide="trending-up"></i>
                <span>Reports & Analytics</span>
            </a>

            <?php if (hasRole(['Administrator', 'IT Manager'])): ?>
            <p class="nav-section-title">System Governance</p>
            <a href="/westec_bms/admin/audit_logs.php" class="nav-link <?= ($currentDir === 'admin' && $currentPage === 'audit_logs.php') ? 'active' : '' ?>">
                <i data-lucide="book-open"></i>
                <span>Audit Trail Logs</span>
            </a>
            <a href="/westec_bms/admin/inventory_access.php" class="nav-link <?= ($currentDir === 'admin' && $currentPage === 'inventory_access.php') ? 'active' : '' ?>">
                <i data-lucide="shield" class="text-warning"></i>
                <span>Inventory Access Control</span>
            </a>
            <?php endif; ?>
            <?php if (hasRole('Administrator')): ?>
            <a href="/westec_bms/admin/system_settings.php" class="nav-link <?= ($currentDir === 'admin' && $currentPage === 'system_settings.php') ? 'active' : '' ?>">
                <i data-lucide="image"></i>
                <span>System Branding & Logo</span>
            </a>
            <?php endif; ?>
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
<?php endif; ?>

    <!-- Main Content Area -->
    <div class="app-main">
        <!-- Top Navigation Bar -->
        <header class="app-topbar">
            <div class="d-flex align-items-center">
                <?php if (!$isLanding): ?>
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
                <?php else: ?>
                    <div class="d-flex align-items-center gap-2">
                        <img src="<?= getSystemLogoUrl() ?>" alt="WesTec Logo" class="brand-logo-img" style="width: 36px; height: 36px;">
                        <h2 class="page-context-title mb-0" style="font-size: 1.25rem; font-weight: 700; color: var(--westec-dark);">WesTec Eswatini</h2>
                    </div>
                <?php endif; ?>
            </div>

            <div class="d-flex align-items-center gap-3">


                <?php if ($currentUser): ?>
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary dropdown-toggle d-flex align-items-center gap-2 py-1 px-3" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <?php $navAvatarUrl = getUserAvatarUrl($currentUser); ?>
                            <?php if ($navAvatarUrl): ?>
                                <img src="<?= $navAvatarUrl ?>" alt="<?= e($currentUser['username']) ?>" class="rounded-circle border" style="width: 28px; height: 28px; object-fit: cover;">
                            <?php else: ?>
                                <i data-lucide="circle-user" class="fs-5 text-primary"></i>
                            <?php endif; ?>
                            <div class="text-start">
                                <span class="d-block fw-bold small text-dark"><?= e($currentUser['username']) ?></span>
                                <span class="d-block text-muted" style="font-size: 0.7rem;"><?= e($currentUser['role_name']) ?></span>
                            </div>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            <li class="dropdown-header">Signed in as <?= e($currentUser['username']) ?></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item <?= ($currentPage === 'profile.php') ? 'active' : '' ?>" href="/westec_bms/auth/profile.php"><i data-lucide="user" class="me-2"></i>My Profile &amp; Photo</a></li>
                            <li><a class="dropdown-item" href="/westec_bms/dashboard/index.php"><i data-lucide="layout-dashboard" class="me-2"></i>Dashboard</a></li>
                            <li><a class="dropdown-item <?= ($currentPage === 'change_password.php') ? 'active' : '' ?>" href="/westec_bms/auth/change_password.php"><i data-lucide="key" class="me-2"></i>Change Password</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="/westec_bms/auth/logout.php"><i data-lucide="log-out" class="me-2"></i>Sign Out</a></li>
                        </ul>
                    </div>
                <?php else: ?>
                    <div class="d-flex gap-2">
                        <a href="/westec_bms/auth/login.php" class="btn btn-westec btn-sm px-3">
                            <i data-lucide="log-in" class="me-1"></i> Sign In
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <!-- Page Content Wrapper -->
        <main class="page-content">
            <?php renderFlashMessages(); ?>
