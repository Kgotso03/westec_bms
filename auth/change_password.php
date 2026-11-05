<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Change Password (Authenticated User Self-Service)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Must be authenticated
requireLogin();

$currentUser = getCurrentUser();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $error = 'Security validation failed (Invalid CSRF token). Please refresh and try again.';
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($currentPassword)) {
            $error = 'Please enter your current password.';
        } elseif (empty($newPassword)) {
            $error = 'Please enter a new password.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'The new password must be at least 8 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'The new password and confirmation password do not match.';
        } else {
            try {
                $pdo = getDbConnection();
                $stmt = $pdo->prepare("
                    SELECT user_id, username, password_hash, status 
                    FROM users 
                    WHERE user_id = :uid 
                    LIMIT 1
                ");
                $stmt->execute([':uid' => $currentUser['user_id']]);
                $user = $stmt->fetch();

                if (!$user) {
                    $error = 'User account not found.';
                } elseif (!password_verify($currentPassword, $user['password_hash'])) {
                    $error = 'Your current password was entered incorrectly. Please try again.';
                } elseif (password_verify($newPassword, $user['password_hash'])) {
                    $error = 'Your new password cannot be the same as your current password. Please choose a different password.';
                } else {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $updateStmt = $pdo->prepare("
                        UPDATE users 
                        SET password_hash = :ph, updated_at = NOW() 
                        WHERE user_id = :uid
                    ");
                    $updateStmt->execute([
                        ':ph'  => $newHash,
                        ':uid' => $user['user_id']
                    ]);

                    // Audit trail
                    try {
                        $auditStmt = $pdo->prepare("
                            INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                            VALUES (:uid, 'PASSWORD_CHANGED', 'users', :rec_id, :details, :ip, NOW())
                        ");
                        $auditStmt->execute([
                            ':uid'     => $user['user_id'],
                            ':rec_id'  => $user['user_id'],
                            ':details' => 'User ' . $user['username'] . ' changed their account password.',
                            ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                        ]);
                    } catch (Exception $e) {
                        // Non-fatal
                    }

                    setFlash('success', 'Your password has been changed successfully!');
                    header('Location: /westec_bms/auth/change_password.php');
                    exit;
                }
            } catch (Exception $e) {
                error_log($e->getMessage());
                $error = 'A database error occurred while updating your password. Please try again.';
            }
        }
    }
}

$pageTitle = 'Change Password';
$pageSubtitle = 'Update your security credentials and portal password';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-7 col-lg-6">
        <div class="content-section p-4 p-md-5">
            <div class="d-flex align-items-center gap-3 mb-4 pb-3 border-bottom">
                <div class="d-inline-flex align-items-center justify-content-center bg-light border rounded-circle" style="width: 56px; height: 56px; color: var(--westec-primary);">
                    <i data-lucide="shield" class="fs-3"></i>
                </div>
                <div>
                    <h2 class="h5 fw-bold text-dark mb-1">Change Account Password</h2>
                    <p class="text-muted small mb-0">Logged in as <strong><?= e($currentUser['username']) ?></strong> (<?= e($currentUser['role_name']) ?>)</p>
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
                    <i data-lucide="alert-triangle" class="me-2 fs-5 flex-shrink-0"></i>
                    <div><?= e($error) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="/westec_bms/auth/change_password.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">

                <div class="mb-3">
                    <label for="current_password" class="form-label">Current Password <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i data-lucide="key"></i></span>
                        <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password" placeholder="Enter your current password">
                    </div>
                </div>

                <div class="mb-3">
                    <label for="new_password" class="form-label">New Password <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i data-lucide="lock"></i></span>
                        <input type="password" class="form-control" id="new_password" name="new_password" required autocomplete="new-password" minlength="8" placeholder="Minimum 8 characters">
                    </div>
                    <div class="form-text text-muted small">
                        Choose a strong password with at least 8 characters.
                    </div>
                </div>

                <div class="mb-4">
                    <label for="confirm_password" class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i data-lucide="lock"></i></span>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required autocomplete="new-password" minlength="8" placeholder="Re-type your new password">
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between pt-2">
                    <a href="/westec_bms/dashboard/index.php" class="btn btn-outline-secondary">
                        <i data-lucide="arrow-left" class="me-1"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-westec">
                        <i data-lucide="check-circle-2" class="me-1"></i> Update Password
                    </button>
                </div>
            </form>
        </div>

        <div class="card mt-3 border-0 bg-transparent">
            <div class="card-body p-3 text-muted small">
                <h6 class="fw-bold text-dark small mb-2"><i data-lucide="shield-check" class="text-primary me-1"></i> Password Security Best Practices</h6>
                <ul class="mb-0 ps-3">
                    <li>Use at least 8 characters with a combination of uppercase letters, lowercase letters, numbers, and symbols.</li>
                    <li>Never share your credentials with unauthorized personnel.</li>
                    <li>If you suspect your credentials have been compromised, update your password immediately and alert the IT Administrator.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
