<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Main Application Entry Point / Landing Portal
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// If user is already authenticated, redirect directly to dashboard
if (isLoggedIn()) {
    header('Location: /westec_bms/dashboard/index.php');
    exit;
}

$pageTitle = 'Welcome';
$pageSubtitle = 'Enterprise Business Management & IT Operations Platform';
$dbHealth = checkDatabaseHealth();

require_once __DIR__ . '/includes/header.php';
?>

<div class="row g-4 align-items-stretch">
    <!-- Hero / Welcome Section -->
    <div class="col-lg-8">
        <div class="content-section h-100 d-flex flex-column justify-content-between p-4 p-md-5">
            <div>
                <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill bg-light border text-success mb-3">
                    <span class="status-dot status-online"></span>
                    <span class="fw-semibold small">Phase 4 Database Connectivity Operational</span>
                </div>
                <h1 class="display-6 fw-bold text-dark mb-3">
                    WesTec Eswatini <br><span style="color: var(--westec-primary);">Business Management System</span>
                </h1>
                <p class="lead text-secondary mb-4">
                    WesTec Eswatini delivers enterprise IT services, networking solutions, mission-critical technical support, and digital infrastructure across Eswatini.
                </p>
                <p class="text-muted mb-4">
                    An integrated enterprise operations portal providing ticket management, client and staff directories, inventory tracking, and project milestone monitoring.
                </p>
            </div>

            <div class="d-flex flex-wrap gap-3 pt-3 border-top">
                <a href="/westec_bms/auth/login.php" class="btn btn-westec btn-lg px-4">
                    <i data-lucide="log-in" class="me-2"></i> Access Management Portal
                </a>
            </div>
        </div>
    </div>

    <!-- Quick Status & Credentials Sidebar Box -->
    <div class="col-lg-4">
        <!-- Database Connection Health Card -->
        <div class="content-section mb-4">
            <div class="section-header">
                <h2 class="section-title">
                    <i data-lucide="database" class="text-success"></i>
                    Database Connectivity
                </h2>
                <span class="badge <?= $dbHealth['connected'] ? 'bg-success' : 'bg-danger' ?>">
                    <?= $dbHealth['connected'] ? 'CONNECTED' : 'DISCONNECTED' ?>
                </span>
            </div>
            <p class="small text-muted mb-3">
                <?= e($dbHealth['message']) ?>
            </p>
            <ul class="list-unstyled small mb-0">
                <li class="d-flex justify-content-between py-1 border-bottom">
                    <span class="text-muted">Target Host:</span>
                    <strong><?= e(DB_HOST) ?></strong>
                </li>
                <li class="d-flex justify-content-between py-1 border-bottom">
                    <span class="text-muted">Database Name:</span>
                    <strong><?= e(DB_NAME) ?></strong>
                </li>
                <li class="d-flex justify-content-between py-1 border-bottom">
                    <span class="text-muted">Relational Tables:</span>
                    <strong class="text-success"><?= e($dbHealth['table_count']) ?> Verified</strong>
                </li>
                <li class="d-flex justify-content-between py-1">
                    <span class="text-muted">Driver & Mode:</span>
                    <span>PHP PDO (Exceptions)</span>
                </li>
            </ul>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
