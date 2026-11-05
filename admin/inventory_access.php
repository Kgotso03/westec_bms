<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Inventory Access Control Panel — IT Manager Only
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

// Only Administrators and IT Managers may manage inventory permissions
if (!hasRole(['Administrator', 'IT Manager'])) {
    setFlash('danger', 'Access Denied: Only the IT Manager or Administrator may manage inventory access permissions.');
    header('Location: /westec_bms/dashboard/index.php');
    exit;
}

$pdo = getDbConnection();
$currentUser = getCurrentUser();
$errors   = [];
$messages = [];

// ── Handle permission save ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $errors[] = 'Security validation failed (CSRF). Please retry.';
    } else {
        $targetUserId   = (int)($_POST['target_user_id'] ?? 0);
        $canView        = isset($_POST['can_view'])               ? 1 : 0;
        $canAddItem     = isset($_POST['can_add_item'])           ? 1 : 0;
        $canEditItem    = isset($_POST['can_edit_item'])          ? 1 : 0;
        $canRecordTx    = isset($_POST['can_record_transaction']) ? 1 : 0;

        if ($targetUserId <= 0) {
            $errors[] = 'Invalid user identifier.';
        } else {
            // Verify target user exists and is not an Admin / IT Manager themselves
            $chkStmt = $pdo->prepare("
                SELECT u.user_id, u.username, r.role_name
                FROM users u
                JOIN roles r ON u.role_id = r.role_id
                WHERE u.user_id = :uid LIMIT 1
            ");
            $chkStmt->execute([':uid' => $targetUserId]);
            $targetUser = $chkStmt->fetch();

            if (!$targetUser) {
                $errors[] = 'Target user not found.';
            } elseif (in_array($targetUser['role_name'], ['Administrator', 'IT Manager'])) {
                $errors[] = 'You cannot restrict access for Administrators or IT Managers — they always have full inventory access.';
            } else {
                try {
                    // UPSERT: insert or update the permission record
                    $upsertStmt = $pdo->prepare("
                        INSERT INTO inventory_permissions
                            (user_id, can_view, can_add_item, can_edit_item, can_record_transaction, granted_by, granted_at, updated_at)
                        VALUES
                            (:uid, :cv, :cai, :cei, :crt, :gby, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE
                            can_view               = VALUES(can_view),
                            can_add_item           = VALUES(can_add_item),
                            can_edit_item          = VALUES(can_edit_item),
                            can_record_transaction = VALUES(can_record_transaction),
                            granted_by             = VALUES(granted_by),
                            updated_at             = NOW()
                    ");
                    $upsertStmt->execute([
                        ':uid'  => $targetUserId,
                        ':cv'   => $canView,
                        ':cai'  => $canAddItem,
                        ':cei'  => $canEditItem,
                        ':crt'  => $canRecordTx,
                        ':gby'  => $currentUser['user_id']
                    ]);

                    // Log the permission change to audit trail
                    $auditDetails = "Inventory permissions updated for user [{$targetUser['username']}] by [{$currentUser['username']}]: "
                        . "can_view={$canView}, can_add_item={$canAddItem}, can_edit_item={$canEditItem}, can_record_transaction={$canRecordTx}";
                    $auditStmt = $pdo->prepare("
                        INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                        VALUES (:uid, 'INVENTORY_PERMISSION_CHANGED', 'inventory_permissions', :rid, :det, :ip, NOW())
                    ");
                    $auditStmt->execute([
                        ':uid' => $currentUser['user_id'],
                        ':rid' => $targetUserId,
                        ':det' => $auditDetails,
                        ':ip'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                    ]);

                    $messages[] = "Inventory permissions for <strong>{$targetUser['username']}</strong> have been updated successfully.";

                } catch (Exception $e) {
                    $errors[] = parseDatabaseErrorMessage($e);
                }
            }
        }
    }
}

// ── Fetch all manageable users (exclude Admins and IT Managers) ─────────────
$usersStmt = $pdo->query("
    SELECT u.user_id, u.username, u.email, u.status, r.role_name,
           ip.permission_id,
           COALESCE(ip.can_view, 0)               AS can_view,
           COALESCE(ip.can_add_item, 0)           AS can_add_item,
           COALESCE(ip.can_edit_item, 0)          AS can_edit_item,
           COALESCE(ip.can_record_transaction, 0) AS can_record_transaction,
           ip.granted_at,
           ip.updated_at AS perms_updated_at,
           granter.username AS granted_by_name
    FROM users u
    JOIN roles r ON u.role_id = r.role_id
    LEFT JOIN inventory_permissions ip ON ip.user_id = u.user_id
    LEFT JOIN users granter ON granter.user_id = ip.granted_by
    WHERE r.role_name NOT IN ('Administrator', 'IT Manager')
    ORDER BY r.role_name ASC, u.username ASC
");
$managedUsers = $usersStmt->fetchAll();

$pageTitle    = 'Inventory Access Control';
$pageSubtitle = 'Manage Staff Inventory Permissions — IT Manager Panel';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Quick Navigation Bar for Admin Suite -->
<div class="d-flex gap-2 mb-4">
    <?php if (hasRole('Administrator')): ?>
    <a href="/westec_bms/admin/user_management.php" class="btn btn-outline-secondary btn-sm">
        <i data-lucide="users" class="me-1"></i> User Management
    </a>
    <?php endif; ?>
    <a href="/westec_bms/admin/audit_logs.php" class="btn btn-outline-secondary btn-sm">
        <i data-lucide="book-open" class="me-1"></i> Audit Trail Logs
    </a>
    <a href="/westec_bms/admin/inventory_access.php" class="btn btn-westec btn-sm">
        <i data-lucide="shield" class="me-1"></i> Inventory Access Control
    </a>
    <?php if (hasRole('Administrator')): ?>
    <a href="/westec_bms/admin/system_settings.php" class="btn btn-outline-secondary btn-sm">
        <i data-lucide="image" class="me-1"></i> System Branding &amp; Logo
    </a>
    <?php endif; ?>
</div>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm d-flex align-items-center mb-3" role="alert">
        <i data-lucide="alert-triangle" class="me-2 fs-5"></i>
        <div><?= e($err) ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endforeach; ?>

<?php foreach ($messages as $msg): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm d-flex align-items-center mb-3" role="alert">
        <i data-lucide="check-circle-2" class="me-2 fs-5 text-success"></i>
        <div><?= $msg ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endforeach; ?>

<!-- Explainer Banner -->
<div class="content-section p-4 mb-4 border-start border-4 border-warning bg-warning bg-opacity-10">
    <div class="d-flex align-items-start gap-3">
        <i data-lucide="shield" class="fs-2 text-warning mt-1"></i>
        <div>
            <h2 class="h5 fw-bold mb-1">Inventory Access Control</h2>
            <p class="mb-1 text-muted small">
                By default, all staff users below IT Manager level have <strong>no access</strong> to the Inventory module.
                Use this panel to grant each staff member specific inventory operations.
                Changes take effect immediately on their next page load.
            </p>
            <div class="d-flex flex-wrap gap-2 mt-2">
                <span class="badge bg-success px-3 py-2"><i data-lucide="eye" class="me-1"></i>View Catalog</span>
                <span class="badge bg-primary px-3 py-2"><i data-lucide="arrow-left-right" class="me-1"></i>Record Transactions</span>
                <span class="badge bg-info text-dark px-3 py-2"><i data-lucide="plus-circle" class="me-1"></i>Add Catalog Items</span>
                <span class="badge bg-dark px-3 py-2"><i data-lucide="pencil" class="me-1"></i>Edit Catalog Items</span>
            </div>
        </div>
    </div>
</div>

<!-- Staff Permissions Table -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i data-lucide="users" class="text-primary"></i>
            Staff Inventory Permissions
        </h2>
        <span class="badge bg-light text-dark border"><?= count($managedUsers) ?> user<?= count($managedUsers) !== 1 ? 's' : '' ?></span>
    </div>

    <?php if (empty($managedUsers)): ?>
        <div class="text-center text-muted py-5">
            <i data-lucide="users" class="fs-1 d-block mb-3 opacity-50"></i>
            <p class="mb-1">No staff users found in the system.</p>
            <small>Add team members first via <a href="/westec_bms/manager/my_team.php">My Team</a> (IT Managers) or <a href="/westec_bms/admin/user_management.php">User Management</a> (Administrators).</small>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($managedUsers as $u): ?>
            <div class="col-lg-6">
                <div class="card border shadow-sm h-100">
                    <!-- Card Header: User Info -->
                    <div class="card-header d-flex align-items-center justify-content-between py-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="d-flex align-items-center justify-content-center rounded-circle bg-light border"
                                 style="width:44px;height:44px;flex-shrink:0;">
                                <i data-lucide="circle-user" class="fs-4 text-primary"></i>
                            </div>
                            <div>
                                <strong class="d-block text-dark"><?= e($u['username']) ?></strong>
                                <span class="small text-muted"><?= e($u['role_name']) ?></span>
                                <?php if ($u['email']): ?>
                                    <span class="d-block small text-muted"><?= e($u['email']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-end">
                            <?php if ($u['status'] === 'Active'): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger"><?= e($u['status']) ?></span>
                            <?php endif; ?>
                            <?php if ($u['permission_id']): ?>
                                <br><small class="text-muted" style="font-size:0.7rem;">
                                    Updated <?= formatDate($u['perms_updated_at'], 'd M Y') ?>
                                    <?php if ($u['granted_by_name']): ?>
                                        by <?= e($u['granted_by_name']) ?>
                                    <?php endif; ?>
                                </small>
                            <?php else: ?>
                                <br><small class="text-warning" style="font-size:0.7rem;"><i data-lucide="alert-circle" class="me-1"></i>No permissions set</small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Card Body: Permission Toggles -->
                    <div class="card-body">
                        <form method="POST" action="/westec_bms/admin/inventory_access.php">
                            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                            <input type="hidden" name="target_user_id" value="<?= $u['user_id'] ?>">

                            <div class="row g-3 mb-3">
                                <!-- View Catalog -->
                                <div class="col-6">
                                    <div class="form-check form-switch p-3 rounded border <?= $u['can_view'] ? 'bg-success bg-opacity-10 border-success' : 'bg-light' ?>">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               role="switch"
                                               name="can_view"
                                               id="can_view_<?= $u['user_id'] ?>"
                                               <?= $u['can_view'] ? 'checked' : '' ?>
                                               onchange="this.closest('.form-check').className = 'form-check form-switch p-3 rounded border ' + (this.checked ? 'bg-success bg-opacity-10 border-success' : 'bg-light')">
                                        <label class="form-check-label small fw-semibold" for="can_view_<?= $u['user_id'] ?>">
                                            <i data-lucide="eye" class="me-1 text-success"></i>View Catalog
                                        </label>
                                        <div class="text-muted" style="font-size:0.7rem;">See stock levels &amp; items</div>
                                    </div>
                                </div>

                                <!-- Record Transactions -->
                                <div class="col-6">
                                    <div class="form-check form-switch p-3 rounded border <?= $u['can_record_transaction'] ? 'bg-primary bg-opacity-10 border-primary' : 'bg-light' ?>">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               role="switch"
                                               name="can_record_transaction"
                                               id="can_tx_<?= $u['user_id'] ?>"
                                               <?= $u['can_record_transaction'] ? 'checked' : '' ?>
                                               onchange="this.closest('.form-check').className = 'form-check form-switch p-3 rounded border ' + (this.checked ? 'bg-primary bg-opacity-10 border-primary' : 'bg-light')">
                                        <label class="form-check-label small fw-semibold" for="can_tx_<?= $u['user_id'] ?>">
                                            <i data-lucide="arrow-left-right" class="me-1 text-primary"></i>Record Transactions
                                        </label>
                                        <div class="text-muted" style="font-size:0.7rem;">IN / OUT / ADJUSTMENT</div>
                                    </div>
                                </div>

                                <!-- Add Items -->
                                <div class="col-6">
                                    <div class="form-check form-switch p-3 rounded border <?= $u['can_add_item'] ? 'bg-info bg-opacity-10 border-info' : 'bg-light' ?>">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               role="switch"
                                               name="can_add_item"
                                               id="can_add_<?= $u['user_id'] ?>"
                                               <?= $u['can_add_item'] ? 'checked' : '' ?>
                                               onchange="this.closest('.form-check').className = 'form-check form-switch p-3 rounded border ' + (this.checked ? 'bg-info bg-opacity-10 border-info' : 'bg-light')">
                                        <label class="form-check-label small fw-semibold" for="can_add_<?= $u['user_id'] ?>">
                                            <i data-lucide="plus-circle" class="me-1 text-info"></i>Add Catalog Items
                                        </label>
                                        <div class="text-muted" style="font-size:0.7rem;">Register new items</div>
                                    </div>
                                </div>

                                <!-- Edit Items -->
                                <div class="col-6">
                                    <div class="form-check form-switch p-3 rounded border <?= $u['can_edit_item'] ? 'bg-dark bg-opacity-10 border-dark' : 'bg-light' ?>">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               role="switch"
                                               name="can_edit_item"
                                               id="can_edit_<?= $u['user_id'] ?>"
                                               <?= $u['can_edit_item'] ? 'checked' : '' ?>
                                               onchange="this.closest('.form-check').className = 'form-check form-switch p-3 rounded border ' + (this.checked ? 'bg-dark bg-opacity-10 border-dark' : 'bg-light')">
                                        <label class="form-check-label small fw-semibold" for="can_edit_<?= $u['user_id'] ?>">
                                            <i data-lucide="pencil" class="me-1 text-dark"></i>Edit Catalog Items
                                        </label>
                                        <div class="text-muted" style="font-size:0.7rem;">Modify existing items</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Quick Shortcuts -->
                            <div class="d-flex gap-2 mb-3">
                                <button type="button" class="btn btn-sm btn-outline-success"
                                        onclick="setAllToggles(this, true)"
                                        title="Grant all 4 permissions">
                                    <i data-lucide="unlock" class="me-1"></i>Grant All
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        onclick="setAllToggles(this, false)"
                                        title="Revoke all 4 permissions">
                                    <i data-lucide="lock" class="me-1"></i>Revoke All
                                </button>
                                <button type="submit" class="btn btn-sm btn-westec ms-auto px-4">
                                    <i data-lucide="save" class="me-1"></i>Save Permissions
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
/**
 * Set all checkboxes in the same card to checked/unchecked state.
 */
function setAllToggles(btn, state) {
    const card = btn.closest('.card');
    card.querySelectorAll('input[type="checkbox"]').forEach(cb => {
        cb.checked = state;
        // Trigger the onchange to update the visual styling
        cb.dispatchEvent(new Event('change'));
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
