<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Password Reset Request (Forgot Password)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: /westec_bms/dashboard/index.php');
    exit;
}

$error = '';
$success = '';
$accountIdentifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $error = 'Security validation failed (Invalid CSRF token). Please refresh the page and try again.';
    } else {
        $accountIdentifier = cleanInput($_POST['account_identifier'] ?? '');

        if (empty($accountIdentifier)) {
            $error = 'Please enter your registered email address or username.';
        } else {
            try {
                $pdo = getDbConnection();
                $stmt = $pdo->prepare("
                    SELECT user_id, username, email, status
                    FROM users
                    WHERE (email = :ident OR username = :ident)
                    LIMIT 1
                ");
                $stmt->execute([':ident' => $accountIdentifier]);
                $user = $stmt->fetch();

                if ($user && !empty($user['email'])) {
                    if ($user['status'] !== 'Active') {
                        $error = 'This account is currently ' . strtolower($user['status']) . '. Please contact your IT Manager or Administrator.';
                    } else {
                        // Invalidate prior unused tokens for this user
                        $invalidateStmt = $pdo->prepare("
                            UPDATE password_reset_tokens 
                            SET used = 1 
                            WHERE user_id = :uid AND used = 0
                        ");
                        $invalidateStmt->execute([':uid' => $user['user_id']]);

                        // Generate secure 32-byte token
                        $rawToken = bin2hex(random_bytes(32));
                        $tokenHash = hash('sha256', $rawToken);

                        // Insert token into database with 1 hour expiration
                        $tokenStmt = $pdo->prepare("
                            INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, used, created_at)
                            VALUES (:uid, :th, DATE_ADD(NOW(), INTERVAL 1 HOUR), 0, NOW())
                        ");
                        $tokenStmt->execute([
                            ':uid' => $user['user_id'],
                            ':th'  => $tokenHash
                        ]);

                        // Build reset link
                        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $resetUrl = $protocol . $host . '/westec_bms/auth/reset_password.php?token=' . $rawToken;

                        // Dispatch email via mailer
                        $mailResult = sendPasswordResetEmail([
                            'to_email'   => $user['email'],
                            'username'   => $user['username'],
                            'reset_url'  => $resetUrl,
                            'expires_in' => '1 hour'
                        ]);

                        // Log audit trail
                        try {
                            $auditStmt = $pdo->prepare("
                                INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                                VALUES (:uid, 'PASSWORD_RESET_REQUESTED', 'users', :rec_id, :details, :ip, NOW())
                            ");
                            $auditStmt->execute([
                                ':uid'     => $user['user_id'],
                                ':rec_id'  => $user['user_id'],
                                ':details' => 'Password reset requested for user ' . $user['username'] . '. Email dispatched to: ' . $user['email'],
                                ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                            ]);
                        } catch (Exception $e) {
                            // Non-fatal
                        }

                        $success = 'Password reset instructions have been sent to your registered email address (' . e($user['email']) . '). Please check your inbox and follow the link to reset your password.';
                    }
                } else {
                    // For security, don't reveal if account exists or lacks email
                    $success = 'If that account is registered in our system with a valid email address, password reset instructions have been dispatched. Please check your inbox.';
                }
            } catch (Exception $e) {
                error_log($e->getMessage());
                $error = 'A database error occurred while processing your request. Please try again later.';
            }
        }
    }
}

$pageTitle = 'Forgot Password';
$pageSubtitle = 'Reset your WesTec Eswatini BMS account password';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="content-section p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="d-inline-block mb-3 p-1 bg-white border rounded-circle shadow-sm" style="width: 84px; height: 84px;">
                    <img src="<?= getSystemLogoUrl() ?>" alt="WesTec Logo" class="rounded-circle" style="width: 100%; height: 100%; object-fit: cover;">
                </div>
                <h2 class="h4 fw-bold text-dark">Password Recovery</h2>
                <p class="text-muted small">Enter your registered email address or username to receive a secure reset link</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
                    <i data-lucide="alert-triangle" class="me-2 fs-5"></i>
                    <div><?= e($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success d-flex align-items-start mb-4" role="alert">
                    <i data-lucide="check-circle-2" class="me-2 fs-5 mt-1"></i>
                    <div>
                        <h6 class="alert-heading fw-bold mb-1">Check Your Email</h6>
                        <p class="mb-0 small"><?= e($success) ?></p>
                    </div>
                </div>
                <div class="d-grid mb-3">
                    <a href="/westec_bms/auth/login.php" class="btn btn-outline-secondary">
                        <i data-lucide="arrow-left" class="me-2"></i> Return to Sign In
                    </a>
                </div>
            <?php else: ?>

                <form method="POST" action="/westec_bms/auth/forgot_password.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">

                    <div class="mb-4">
                        <label for="account_identifier" class="form-label">Email Address or Username</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i data-lucide="mail"></i></span>
                            <input type="text" class="form-control" id="account_identifier" name="account_identifier" value="<?= e($accountIdentifier) ?>" required autofocus placeholder="e.g. user@westec.sz or username">
                        </div>
                        <div class="form-text text-muted small">
                            A secure, single-use password reset link will be sent to the email address tied to your account.
                        </div>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-westec btn-lg">
                            <i data-lucide="send" class="me-2"></i> Send Password Reset Link
                        </button>
                    </div>

                    <div class="text-center">
                        <a href="/westec_bms/auth/login.php" class="text-decoration-none small text-muted">
                            <i data-lucide="arrow-left" class="me-1"></i> Remembered your password? <strong>Sign In</strong>
                        </a>
                    </div>
                </form>

            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
