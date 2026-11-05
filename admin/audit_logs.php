<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * System Audit Trail & Trigger Execution Ledger
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Enforce role-based access: Administrator and IT Manager only
requireRole(['Administrator', 'IT Manager']);

$pdo = getDbConnection();
$currentUser = getCurrentUser();

// Filters
$filterAction = cleanInput($_GET['action'] ?? '');
$filterTable  = cleanInput($_GET['table'] ?? '');

$query = "
    SELECT a.*, u.username, r.role_name
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.user_id
    LEFT JOIN roles r ON u.role_id = r.role_id
    WHERE 1=1
";
$params = [];

if (!empty($filterAction)) {
    $query .= " AND a.action = :act";
    $params[':act'] = $filterAction;
}
if (!empty($filterTable)) {
    $query .= " AND a.table_affected = :tbl";
    $params[':tbl'] = $filterTable;
}

$query .= " ORDER BY a.log_id DESC LIMIT 100";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get distinct action types and tables for filter dropdowns
$actionTypes = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);
$tablesList  = $pdo->query("SELECT DISTINCT table_affected FROM audit_logs ORDER BY table_affected ASC")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Audit Trail Ledger';
$pageSubtitle = 'Complete System Governance, Security Events & Operations Activity Records';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Quick Navigation Bar for Admin Suite -->
<div class="d-flex gap-2 mb-4">
    <?php if (hasRole('Administrator')): ?>
    <a href="/westec_bms/admin/user_management.php" class="btn btn-outline-secondary btn-sm">
        <i data-lucide="users" class="me-1"></i> User Management
    </a>
    <?php endif; ?>
    <a href="/westec_bms/admin/audit_logs.php" class="btn btn-westec btn-sm">
        <i data-lucide="book-open" class="me-1"></i> Audit Trail Logs
    </a>
    <a href="/westec_bms/admin/inventory_access.php" class="btn btn-outline-secondary btn-sm">
        <i data-lucide="shield" class="me-1"></i> Inventory Access Control
    </a>
    <?php if (hasRole('Administrator')): ?>
    <a href="/westec_bms/admin/system_settings.php" class="btn btn-outline-secondary btn-sm">
        <i data-lucide="image" class="me-1"></i> System Branding &amp; Logo
    </a>
    <?php endif; ?>
</div>

<!-- Filter Bar -->
<div class="content-section p-3 mb-3 bg-light">
    <form method="GET" action="/westec_bms/admin/audit_logs.php" class="row g-2 align-items-center">
        <div class="col-md-4">
            <select name="action" class="form-select form-select-sm">
                <option value="">-- All Audit Actions --</option>
                <?php foreach ($actionTypes as $act): ?>
                    <option value="<?= e($act) ?>" <?= ($filterAction === $act) ? 'selected' : '' ?>><?= e($act) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4">
            <select name="table" class="form-select form-select-sm">
                <option value="">-- All Affected Tables --</option>
                <?php foreach ($tablesList as $tbl): ?>
                    <option value="<?= e($tbl) ?>" <?= ($filterTable === $tbl) ? 'selected' : '' ?>><?= e($tbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-westec w-100">
                <i data-lucide="filter" class="me-1"></i> Filter Logs
            </button>
            <a href="/westec_bms/admin/audit_logs.php" class="btn btn-sm btn-outline-secondary" title="Reset Filters">
                <i data-lucide="rotate-ccw"></i>
            </a>
        </div>
    </form>
</div>

<!-- Audit Trail Table -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i data-lucide="book-open" class="text-primary"></i>
            Audit Trail Records (<?= count($logs) ?>)
        </h2>
        <div class="input-group input-group-sm" style="max-width: 250px;">
            <span class="input-group-text bg-light"><i data-lucide="search"></i></span>
            <input type="text" class="form-control" placeholder="Search logs..." data-table-filter="auditTable">
        </div>
    </div>

    <div class="table-responsive">
        <table class="table-westec" id="auditTable">
            <thead>
                <tr>
                    <th>Log ID</th>
                    <th>Timestamp</th>
                    <th>Action</th>
                    <th>Affected Table</th>
                    <th>Record ID</th>
                    <th>User / Subject</th>
                    <th>IP Address</th>
                    <th>Audit Details & Activity Description</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No audit records found matching the filter criteria.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): 
                        $actionBadge = match ($log['action']) {
                            'USER_REGISTERED' => 'bg-success',
                            'USER_LOGIN'      => 'bg-info text-dark',
                            'USER_LOGOUT'     => 'bg-secondary',
                            'TICKET_STATUS_CHANGED' => 'bg-primary',
                            'SYSTEM_INIT'     => 'bg-dark',
                            default           => 'bg-light text-dark border'
                        };
                    ?>
                        <tr>
                            <td><strong class="font-monospace">#<?= $log['log_id'] ?></strong></td>
                            <td class="text-nowrap small"><?= formatDate($log['created_at'], 'd M Y, H:i:s') ?></td>
                            <td><span class="badge <?= $actionBadge ?>"><?= e($log['action']) ?></span></td>
                            <td><code><?= e($log['table_affected']) ?></code></td>
                            <td><?= $log['record_id'] ? '#' . $log['record_id'] : '—' ?></td>
                            <td>
                                <?php if ($log['username']): ?>
                                    <strong class="text-dark small d-block"><?= e($log['username']) ?></strong>
                                    <small class="text-muted"><?= e($log['role_name']) ?></small>
                                <?php else: ?>
                                    <span class="text-muted small"><em>System Automated</em></span>
                                <?php endif; ?>
                            </td>
                            <td><small class="text-muted font-monospace"><?= e($log['ip_address']) ?></small></td>
                            <td class="small text-secondary"><?= e($log['details']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
