<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * User Authentication (Login)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: /westec_bms/dashboard/index.php');
    exit;
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $error = 'Security validation failed (Invalid CSRF token). Please refresh the page and try again.';
    } else {
        $username = cleanInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Please enter both your username and password.';
        } else {
            try {
                $pdo = getDbConnection();
                $stmt = $pdo->prepare("
                    SELECT u.user_id, u.username, u.password_hash, u.role_id, u.email, u.status, r.role_name
                    FROM users u
                    JOIN roles r ON u.role_id = r.role_id
                    WHERE u.username = :username
                    LIMIT 1
                ");
                $stmt->execute([':username' => $username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
                    if ($user['status'] !== 'Active') {
                        $error = 'This account has been ' . strtolower($user['status']) . '. Please contact the system administrator.';
                    } else {
                        // Successful login
                        loginUser($user);

                        // Record audit log for login
                        try {
                            $auditStmt = $pdo->prepare("
                                INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                                VALUES (:uid, 'USER_LOGIN', 'users', :rec_id, :details, :ip, NOW())
                            ");
                            $auditStmt->execute([
                                ':uid'     => $user['user_id'],
                                ':rec_id'  => $user['user_id'],
                                ':details' => 'User logged in successfully via web interface.',
                                ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                            ]);
                        } catch (Exception $ignored) {
                            // Non-fatal if audit fails
                        }

                        setFlash('success', 'Welcome back, ' . e($user['username']) . '! Authenticated as ' . e($user['role_name']) . '.');

                        $redirectUrl = $_SESSION['redirect_after_login'] ?? '/westec_bms/dashboard/index.php';
                        unset($_SESSION['redirect_after_login']);
                        header('Location: ' . $redirectUrl);
                        exit;
                    }
                } else {
                    $error = 'Invalid username or password. Please verify your credentials and try again.';
                }
            } catch (Exception $e) {
                error_log($e->getMessage());
                $error = 'A database error occurred during authentication. Please ensure MySQL is running.';
            }
        }
    }
}

$pageTitle = 'Sign In';
$pageSubtitle = 'Access the WesTec Eswatini Business Management System';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="content-section p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="d-inline-flex align-items-center justify-content-center bg-light border rounded-circle mb-3" style="width: 64px; height: 64px; color: var(--westec-primary);">
                    <i class="bi bi-shield-lock-fill fs-2"></i>
                </div>
                <h2 class="h4 fw-bold text-dark">Portal Authentication</h2>
                <p class="text-muted small">Enter your authorized WesTec Eswatini credentials</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                    <div><?= e($error) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="/westec_bms/auth/login.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">

                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="bi bi-person"></i></span>
                        <input type="text" class="form-control" id="username" name="username" value="<?= e($username) ?>" required autofocus autocomplete="username" placeholder="e.g. admin">
                    </div>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="bi bi-key"></i></span>
                        <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password" placeholder="Enter your password">
                    </div>
                </div>

                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-westec btn-lg">
                        <i class="bi bi-box-arrow-in-right me-2"></i> Sign In to Portal
                    </button>
                </div>

                <div class="text-center">
                    <span class="text-muted small">Don't have an account?</span>
                    <a href="/westec_bms/users/register.php" class="small fw-bold text-decoration-none" style="color: var(--westec-primary);">
                        Register Here
                    </a>
                </div>
            </form>
        </div>

        <!-- Demonstration helper box for testing -->
        <div class="content-section p-3 bg-light">
            <h3 class="h6 fw-bold mb-2 text-dark"><i class="bi bi-info-circle-fill text-primary me-1"></i> Quick Test Credentials</h3>
            <ul class="list-unstyled small mb-0">
                <li><strong>Administrator:</strong> <code>admin</code> / <code>Admin@12345</code></li>
                <li><strong>IT Manager:</strong> <code>manager_nkosi</code> / <code>Pass@12345</code></li>
                <li><strong>Senior Tech:</strong> <code>tech_sbu</code> / <code>Pass@12345</code></li>
            </ul>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
