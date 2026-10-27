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
                    Phase 4 integrates responsive data-capturing forms with the relational MySQL database via PHP PDO. All business rules, stock validations, project date constraints, and user audit logging are enforced via active database triggers.
                </p>
            </div>

            <div class="d-flex flex-wrap gap-3 pt-3 border-top">
                <a href="/westec_bms/auth/login.php" class="btn btn-westec btn-lg px-4">
                    <i class="bi bi-box-arrow-in-right me-2"></i> Access Management Portal
                </a>
                <a href="/westec_bms/users/register.php" class="btn btn-westec-outline btn-lg px-4">
                    <i class="bi bi-person-plus me-2"></i> Register New User
                </a>
                <a href="/westec_bms/admin/trigger_demo.php" class="btn btn-outline-secondary btn-lg px-3">
                    <i class="bi bi-lightning-charge text-warning me-1"></i> View Trigger Demos
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
                    <i class="bi bi-database-check text-success"></i>
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

        <!-- Demonstration Accounts Card -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="bi bi-key-fill text-primary"></i>
                    Evaluation Credentials
                </h2>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-borderless small mb-0">
                    <thead>
                        <tr class="text-muted border-bottom">
                            <th>Role</th>
                            <th>Username</th>
                            <th>Password</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span class="badge bg-danger">Administrator</span></td>
                            <td><code>admin</code></td>
                            <td><code>Admin@12345</code></td>
                        </tr>
                        <tr>
                            <td><span class="badge bg-primary">IT Manager</span></td>
                            <td><code>manager_nkosi</code></td>
                            <td><code>Pass@12345</code></td>
                        </tr>
                        <tr>
                            <td><span class="badge bg-info text-dark">Senior Tech</span></td>
                            <td><code>tech_sbu</code></td>
                            <td><code>Pass@12345</code></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="alert alert-light border small mt-3 mb-0 text-muted">
                <i class="bi bi-info-circle me-1"></i> Passwords are protected using PHP <code>password_hash()</code> with <code>PASSWORD_DEFAULT</code> (bcrypt).
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
