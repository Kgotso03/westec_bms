<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Password Reset Execution (Set New Password with Token)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: /westec_bms/dashboard/index.php');
    exit;
}

$rawToken = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$tokenValid = false;
$tokenRecord = null;

if (empty($rawToken)) {
    $error = 'No reset token was provided. Please use the link sent to your email or request a new password reset.';
} else {
    try {
        $pdo = getDbConnection();
        $tokenHash = hash('sha256', $rawToken);

        $stmt = $pdo->prepare("
            SELECT prt.id AS token_id, prt.user_id, prt.expires_at, prt.used, 
                   (prt.expires_at <= NOW()) AS is_expired,
                   u.username, u.email, u.status
            FROM password_reset_tokens prt
            JOIN users u ON prt.user_id = u.user_id
            WHERE prt.token_hash = :th
            LIMIT 1
        ");
        $stmt->execute([':th' => $tokenHash]);
        $tokenRecord = $stmt->fetch();

        if (!$tokenRecord) {
            $error = 'This password reset link is invalid. It may have been typed incorrectly or superseded.';
        } elseif ((int)$tokenRecord['used'] === 1) {
            $error = 'This password reset link has already been used. For security, reset links are single-use only. Please request a new one.';
        } elseif ((int)$tokenRecord['is_expired'] === 1) {
            $error = 'This password reset link has expired. Password reset links are valid for 1 hour. Please request a new one.';
        } elseif ($tokenRecord['status'] !== 'Active') {
            $error = 'The associated account is currently ' . strtolower($tokenRecord['status']) . '. Please contact your system administrator.';
        } else {
            $tokenValid = true;
        }
    } catch (Exception $e) {
        error_log($e->getMessage());
        $error = 'A database error occurred while validating your reset request.';
    }
}

// Process new password submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrfToken)) {
        $error = 'Security validation failed (Invalid CSRF token). Please refresh and try again.';
    } else {
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (strlen($newPassword) < 8) {
            $error = 'The new password must be at least 8 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'The new password and confirmation password do not match.';
        } else {
            try {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                // Update user password
                $updateUser = $pdo->prepare("
                    UPDATE users 
                    SET password_hash = :ph, updated_at = NOW() 
                    WHERE user_id = :uid
                ");
                $updateUser->execute([
                    ':ph'  => $newHash,
                    ':uid' => $tokenRecord['user_id']
                ]);

                // Invalidate the reset token
                $markToken = $pdo->prepare("
                    UPDATE password_reset_tokens 
                    SET used = 1 
                    WHERE id = :id
                ");
                $markToken->execute([':id' => $tokenRecord['token_id']]);

                // Audit logging
                try {
                    $auditStmt = $pdo->prepare("
                        INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                        VALUES (:uid, 'PASSWORD_RESET_SUCCESS', 'users', :rec_id, :details, :ip, NOW())
                    ");
                    $auditStmt->execute([
                        ':uid'     => $tokenRecord['user_id'],
                        ':rec_id'  => $tokenRecord['user_id'],
                        ':details' => 'User ' . $tokenRecord['username'] . ' successfully reset their password via email token.',
                        ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                    ]);
                } catch (Exception $e) {
                    // Non-fatal
                }

                setFlash('success', 'Your password has been reset successfully! Please sign in with your new credentials.');
                header('Location: /westec_bms/auth/login.php');
                exit;
            } catch (Exception $e) {
                error_log($e->getMessage());
                $error = 'A database error occurred while updating your password. Please try again.';
            }
        }
    }
}

$pageTitle = 'Set New Password';
$pageSubtitle = 'Create a secure new password for your account';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="content-section p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="d-inline-block mb-3 p-1 bg-white border rounded-circle shadow-sm" style="width: 84px; height: 84px;">
                    <img src="<?= getSystemLogoUrl() ?>" alt="WesTec Logo" class="rounded-circle" style="width: 100%; height: 100%; object-fit: cover;">
                </div>
                <h2 class="h4 fw-bold text-dark">Set New Password</h2>
                <?php if ($tokenValid && $tokenRecord): ?>
                    <p class="text-muted small">Choose a new password for account <strong><?= e($tokenRecord['username']) ?></strong></p>
                <?php else: ?>
                    <p class="text-muted small">WesTec Eswatini Portal Password Reset</p>
                <?php endif; ?>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
                    <i data-lucide="alert-triangle" class="me-2 fs-5 flex-shrink-0"></i>
                    <div><?= e($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($tokenValid): ?>

                <form method="POST" action="/westec_bms/auth/reset_password.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="token" value="<?= e($rawToken) ?>">

                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i data-lucide="lock"></i></span>
                            <input type="password" class="form-control" id="new_password" name="new_password" required autofocus minlength="8" placeholder="Minimum 8 characters">
                        </div>
                        <div class="form-text text-muted small">
                            Must be at least 8 characters long. Mix letters, numbers, and symbols for best security.
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i data-lucide="lock"></i></span>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8" placeholder="Re-type new password">
                        </div>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-westec btn-lg">
                            <i data-lucide="check-circle-2" class="me-2"></i> Save New Password
                        </button>
                    </div>

                    <div class="text-center">
                        <a href="/westec_bms/auth/login.php" class="text-decoration-none small text-muted">
                            <i data-lucide="arrow-left" class="me-1"></i> Cancel and return to <strong>Sign In</strong>
                        </a>
                    </div>
                </form>

            <?php else: ?>

                <div class="text-center">
                    <p class="text-muted small mb-4">
                        If you need a new password reset link, please visit the password recovery page.
                    </p>
                    <div class="d-grid gap-2">
                        <a href="/westec_bms/auth/forgot_password.php" class="btn btn-westec">
                            <i data-lucide="repeat" class="me-2"></i> Request New Reset Link
                        </a>
                        <a href="/westec_bms/auth/login.php" class="btn btn-outline-secondary">
                            <i data-lucide="log-in" class="me-2"></i> Return to Sign In
                        </a>
                    </div>
                </div>

            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
