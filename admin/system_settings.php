<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * System Settings & Branding Management — System Administrator Only
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Only System Administrator may manage system settings and branding
requireRole(['Administrator', 'System Administrator']);

$pdo = getDbConnection();
$currentUser = getCurrentUser();
$errors = [];
$messages = [];

$imagesDir = __DIR__ . '/../assets/images';
if (!is_dir($imagesDir)) {
    mkdir($imagesDir, 0755, true);
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        setFlash('danger', 'Security validation failed (CSRF). Please refresh and try again.');
        header('Location: /westec_bms/admin/system_settings.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // 1. UPLOAD NEW LOGO
    if ($action === 'upload_logo') {
        if (!isset($_FILES['system_logo']) || $_FILES['system_logo']['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the form.',
                UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No image file was selected. Please choose a file to upload.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on server.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
            ];
            $errCode = $_FILES['system_logo']['error'] ?? UPLOAD_ERR_NO_FILE;
            $errors[] = $uploadErrors[$errCode] ?? 'An error occurred during file upload.';
        } else {
            $file = $_FILES['system_logo'];
            $maxBytes = 5 * 1024 * 1024; // 5 MB

            if ($file['size'] > $maxBytes) {
                $errors[] = 'The selected file is too large. Maximum allowed size is 5 MB.';
            }

            $origName = $file['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $allowedExts = ['png', 'jpg', 'jpeg', 'webp', 'svg'];

            if (!in_array($ext, $allowedExts, true)) {
                $errors[] = 'Invalid file format. Please upload a PNG, JPG, JPEG, WEBP, or SVG image.';
            }

            // Verify MIME type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            $allowedMimes = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];
            if (!in_array($mime, $allowedMimes, true)) {
                $errors[] = 'Security validation failed: File MIME type (' . e($mime) . ') is not recognized as a supported image.';
            }

            // For non-SVG, verify image dimensions
            if (empty($errors) && $ext !== 'svg') {
                $imgInfo = @getimagesize($file['tmp_name']);
                if ($imgInfo === false) {
                    $errors[] = 'The uploaded file is not a valid image file.';
                }
            }

            if (empty($errors)) {
                try {
                    // Remove any previous custom logo files to avoid collision
                    foreach (['png', 'jpg', 'jpeg', 'webp', 'svg'] as $oldExt) {
                        $oldFile = $imagesDir . '/custom_logo.' . $oldExt;
                        if (file_exists($oldFile)) {
                            @unlink($oldFile);
                        }
                    }

                    // Save as custom_logo.<ext>
                    $targetFile = $imagesDir . '/custom_logo.' . $ext;
                    if (!move_uploaded_file($file['tmp_name'], $targetFile)) {
                        throw new Exception('Failed to save uploaded file to assets/images directory. Check write permissions.');
                    }

                    // Audit log
                    $logStmt = $pdo->prepare("
                        INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                        VALUES (:uid, 'SYSTEM_LOGO_UPDATE', 'system_settings', 1, :details, :ip, NOW())
                    ");
                    $logStmt->execute([
                        ':uid'     => $currentUser['user_id'],
                        ':details' => "System Administrator '{$currentUser['username']}' updated the system branding logo ({$origName}, {$ext}).",
                        ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                    ]);

                    setFlash('success', 'System branding logo has been updated successfully! Changes are now live across all pages.');
                    header('Location: /westec_bms/admin/system_settings.php');
                    exit;

                } catch (Exception $e) {
                    $errors[] = 'Failed to apply new logo: ' . $e->getMessage();
                }
            }
        }
    }

    // 2. RESET TO DEFAULT LOGO
    elseif ($action === 'reset_logo') {
        try {
            // Remove all custom logos
            foreach (['png', 'jpg', 'jpeg', 'webp', 'svg'] as $oldExt) {
                $oldFile = $imagesDir . '/custom_logo.' . $oldExt;
                if (file_exists($oldFile)) {
                    @unlink($oldFile);
                }
            }

            // If default_logo.jpg exists, make sure logo.jpg matches
            if (file_exists($imagesDir . '/default_logo.jpg')) {
                copy($imagesDir . '/default_logo.jpg', $imagesDir . '/logo.jpg');
            }

            // Audit log
            $logStmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                VALUES (:uid, 'SYSTEM_LOGO_RESET', 'system_settings', 1, :details, :ip, NOW())
            ");
            $logStmt->execute([
                ':uid'     => $currentUser['user_id'],
                ':details' => "System Administrator '{$currentUser['username']}' restored the original default WesTec logo emblem.",
                ':ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            setFlash('success', 'System logo has been restored to the original WesTec emblem.');
            header('Location: /westec_bms/admin/system_settings.php');
            exit;

        } catch (Exception $e) {
            $errors[] = 'Failed to reset logo: ' . $e->getMessage();
        }
    }
}

// Check current logo status
$currentLogoUrl = getSystemLogoUrl();
$isCustomLogo = false;
$activeFileName = 'default_logo.jpg';
$activeFileTime = null;
$activeFileSize = 0;

foreach (['png', 'jpg', 'jpeg', 'webp', 'svg'] as $ext) {
    $cPath = $imagesDir . '/custom_logo.' . $ext;
    if (file_exists($cPath)) {
        $isCustomLogo = true;
        $activeFileName = 'custom_logo.' . $ext;
        $activeFileTime = filemtime($cPath);
        $activeFileSize = filesize($cPath);
        break;
    }
}

if (!$isCustomLogo && file_exists($imagesDir . '/logo.jpg')) {
    $activeFileName = 'logo.jpg (Original WesTec Emblem)';
    $activeFileTime = filemtime($imagesDir . '/logo.jpg');
    $activeFileSize = filesize($imagesDir . '/logo.jpg');
}

// Fetch recent logo audit logs
$logoAuditLogs = $pdo->query("
    SELECT al.*, u.username
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    WHERE al.action IN ('SYSTEM_LOGO_UPDATE', 'SYSTEM_LOGO_RESET')
    ORDER BY al.created_at DESC
    LIMIT 5
")->fetchAll();

$pageTitle = 'System Branding & Logo Settings';
$pageSubtitle = 'Configure Corporate Identity, App Icons & Portal Visual Branding';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-4 shadow-sm" role="alert">
        <div class="d-flex align-items-center mb-1">
            <i data-lucide="alert-triangle" class="me-2 fs-5"></i>
            <strong class="fs-6">Operation Failed:</strong>
        </div>
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $e): ?>
                <li><?= e($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Quick Navigation Bar for Admin Suite -->
<div class="d-flex gap-2 mb-4">
    <a href="/westec_bms/admin/user_management.php" class="btn btn-outline-secondary btn-sm">
        <i data-lucide="users" class="me-1"></i> User Management
    </a>
    <a href="/westec_bms/admin/audit_logs.php" class="btn btn-outline-secondary btn-sm">
        <i data-lucide="book-open" class="me-1"></i> Audit Trail Logs
    </a>
    <a href="/westec_bms/admin/inventory_access.php" class="btn btn-outline-secondary btn-sm">
        <i data-lucide="shield" class="me-1"></i> Inventory Access Control
    </a>
    <a href="/westec_bms/admin/system_settings.php" class="btn btn-westec btn-sm">
        <i data-lucide="image" class="me-1"></i> System Branding &amp; Logo
    </a>
</div>

<div class="row g-4 mb-4">
    <!-- Column 1: Upload New Logo Form -->
    <div class="col-lg-6">
        <div class="content-section h-100">
            <div class="section-header">
                <h2 class="section-title text-primary">
                    <i data-lucide="upload-cloud"></i>
                    Upload New System Logo
                </h2>
                <span class="badge bg-primary text-white">System Administrator</span>
            </div>

            <p class="small text-muted mb-3">
                Upload a corporate logo or brand mark. The updated logo will instantly propagate to the sidebar navigation drawer, top application bar, and the authentication portal login cards.
            </p>

            <form method="POST" action="/westec_bms/admin/system_settings.php" enctype="multipart/form-data" id="logoUploadForm">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="upload_logo">

                <!-- Drag-and-drop Dropzone Container -->
                <div class="border border-2 border-dashed rounded-3 p-4 text-center mb-3 bg-light bg-opacity-50" id="dropZone" style="cursor: pointer; transition: all 0.2s ease;">
                    <div id="dropZonePrompt">
                        <i data-lucide="image-plus" class="fs-1 text-primary mb-2 d-block mx-auto" style="width: 48px; height: 48px;"></i>
                        <h6 class="fw-bold mb-1">Click to select or drag and drop logo</h6>
                        <p class="small text-muted mb-2">PNG, JPG, JPEG, WEBP or SVG (Max 5 MB)</p>
                        <span class="btn btn-sm btn-outline-primary px-3">Browse File</span>
                    </div>

                    <!-- Client-side Interactive Preview -->
                    <div id="dropZonePreview" class="d-none">
                        <div class="d-inline-block p-2 bg-white border rounded-circle shadow-sm mb-2" style="width: 100px; height: 100px;">
                            <img id="previewImage" src="#" alt="New Logo Preview" class="rounded-circle" style="width: 100%; height: 100%; object-fit: cover;">
                        </div>
                        <h6 class="fw-bold mb-0 text-success" id="previewFileName">File Selected</h6>
                        <small class="text-muted d-block mb-2" id="previewFileSize">0 KB</small>
                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" id="cancelSelectedFile">
                            <i data-lucide="x" class="me-1"></i> Choose Different File
                        </button>
                    </div>

                    <input type="file" class="d-none" id="system_logo" name="system_logo" accept="image/png,image/jpeg,image/webp,image/svg+xml" required>
                </div>

                <!-- Guidelines Card -->
                <div class="alert alert-light border small text-muted mb-3">
                    <div class="d-flex align-items-center mb-1 text-dark fw-semibold">
                        <i data-lucide="check-circle" class="me-1 text-success" style="width: 16px; height: 16px;"></i>
                        Recommended Specifications:
                    </div>
                    <ul class="mb-0 ps-3">
                        <li><strong>Dimensions:</strong> Square 1:1 aspect ratio (e.g. 200x200px or 512x512px).</li>
                        <li><strong>Format:</strong> Transparent PNG or high-resolution JPG / WEBP.</li>
                        <li><strong>Visibility:</strong> Emblems with clear contrast render best in both dark and light UI surfaces.</li>
                    </ul>
                </div>

                <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                    <?php if ($isCustomLogo): ?>
                        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#resetLogoModal">
                            <i data-lucide="rotate-ccw" class="me-1"></i> Reset to Default
                        </button>
                    <?php else: ?>
                        <span class="small text-muted"><i data-lucide="check" class="me-1 text-success"></i>Original emblem active</span>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-westec px-4" id="submitBtn" disabled>
                        <i data-lucide="save" class="me-1"></i> Save &amp; Apply Logo
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Column 2: Live Brand Previews -->
    <div class="col-lg-6">
        <div class="content-section h-100">
            <div class="section-header">
                <h2 class="section-title">
                    <i data-lucide="eye" class="text-secondary"></i>
                    Live Context Previews
                </h2>
                <span class="badge bg-light text-muted border">Real-Time Display</span>
            </div>

            <!-- Preview 1: Sidebar Navigation Drawer -->
            <div class="mb-4">
                <label class="form-label small text-muted text-uppercase fw-bold mb-2">
                    <i data-lucide="layout-sidebar" class="me-1" style="width: 14px; height: 14px;"></i>
                    1. Main Navigation Drawer (Dark Surface)
                </label>
                <div class="p-3 rounded-3 shadow-sm d-flex align-items-center gap-3" style="background-color: var(--westec-dark, #063d35); color: #fff;">
                    <img src="<?= $currentLogoUrl ?>" alt="WesTec Active Logo" class="brand-logo-img live-logo-preview">
                    <div>
                        <h5 class="mb-0 fw-bold text-white" style="font-size: 1.15rem;">WesTec BMS</h5>
                        <small class="text-white-50" style="font-size: 0.75rem; letter-spacing: 0.5px; text-transform: uppercase;">Eswatini IT Solutions</small>
                    </div>
                </div>
            </div>

            <!-- Preview 2: Portal Login Card Header -->
            <div class="mb-4">
                <label class="form-label small text-muted text-uppercase fw-bold mb-2">
                    <i data-lucide="log-in" class="me-1" style="width: 14px; height: 14px;"></i>
                    2. Login &amp; Authentication Card (Light Surface)
                </label>
                <div class="p-4 rounded-3 border bg-white shadow-sm text-center">
                    <div class="d-inline-block mb-2 p-1 bg-white border rounded-circle shadow-sm" style="width: 72px; height: 72px;">
                        <img src="<?= $currentLogoUrl ?>" alt="WesTec Active Logo" class="rounded-circle live-logo-preview" style="width: 100%; height: 100%; object-fit: cover;">
                    </div>
                    <h5 class="fw-bold text-dark mb-0">Portal Authentication</h5>
                    <small class="text-muted">Enter your authorized WesTec Eswatini credentials</small>
                </div>
            </div>

            <!-- Active Logo Metadata Details -->
            <div class="p-3 bg-light rounded-3 border">
                <h6 class="fw-bold mb-2 small text-uppercase text-secondary">Active Logo Information</h6>
                <div class="row g-2 small">
                    <div class="col-6">
                        <span class="text-muted d-block">Source Status:</span>
                        <strong class="<?= $isCustomLogo ? 'text-primary' : 'text-success' ?>">
                            <?= $isCustomLogo ? 'Custom Uploaded Logo' : 'Default Corporate Emblem' ?>
                        </strong>
                    </div>
                    <div class="col-6">
                        <span class="text-muted d-block">Active File:</span>
                        <code><?= e($activeFileName) ?></code>
                    </div>
                    <?php if ($activeFileTime): ?>
                    <div class="col-6">
                        <span class="text-muted d-block">Last Updated:</span>
                        <span><?= formatDate(date('Y-m-d H:i:s', $activeFileTime), 'd M Y, H:i') ?></span>
                    </div>
                    <div class="col-6">
                        <span class="text-muted d-block">File Size:</span>
                        <span><?= number_format($activeFileSize / 1024, 1) ?> KB</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Logo Change Audit History -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i data-lucide="history" class="text-secondary"></i>
            Branding &amp; Logo Activity History
        </h2>
        <span class="badge bg-light text-muted border">Audit Trail</span>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-striped small mb-0">
            <thead>
                <tr class="text-muted">
                    <th>Timestamp</th>
                    <th>Action</th>
                    <th>Administrator</th>
                    <th>Details</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logoAuditLogs)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-3">No logo modifications recorded yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logoAuditLogs as $log): ?>
                        <tr>
                            <td><?= formatDate($log['created_at'], 'd M Y, H:i:s') ?></td>
                            <td>
                                <?php if ($log['action'] === 'SYSTEM_LOGO_UPDATE'): ?>
                                    <span class="badge bg-primary"><i data-lucide="upload" class="me-1"></i>Updated</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><i data-lucide="rotate-ccw" class="me-1"></i>Reset</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= e($log['username'] ?? 'System') ?></strong></td>
                            <td><?= e($log['details']) ?></td>
                            <td><code><?= e($log['ip_address']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Reset Confirmation Modal -->
<div class="modal fade" id="resetLogoModal" tabindex="-1" aria-labelledby="resetLogoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger" id="resetLogoModalLabel">
                    <i data-lucide="alert-triangle" class="me-1"></i> Reset Logo to Default
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-3">
                <p class="mb-2">Are you sure you want to remove the custom logo and restore the original <strong>WesTec Eswatini</strong> corporate emblem?</p>
                <small class="text-muted">The original circular WesTec emblem will immediately be restored across all application headers and portals.</small>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="/westec_bms/admin/system_settings.php" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="action" value="reset_logo">
                    <button type="submit" class="btn btn-danger btn-sm px-3">
                        <i data-lucide="rotate-ccw" class="me-1"></i> Yes, Restore Default
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('system_logo');
    const promptDiv = document.getElementById('dropZonePrompt');
    const previewDiv = document.getElementById('dropZonePreview');
    const previewImg = document.getElementById('previewImage');
    const previewName = document.getElementById('previewFileName');
    const previewSize = document.getElementById('previewFileSize');
    const cancelBtn = document.getElementById('cancelSelectedFile');
    const submitBtn = document.getElementById('submitBtn');

    if (!dropZone || !fileInput) return;

    // Click dropzone triggers file dialog
    dropZone.addEventListener('click', function (e) {
        if (e.target !== cancelBtn && !cancelBtn.contains(e.target)) {
            fileInput.click();
        }
    });

    // Drag-and-drop hover effects
    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.add('border-primary', 'bg-white');
        }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove('border-primary', 'bg-white');
        }, false);
    });

    // Handle dropped files
    dropZone.addEventListener('drop', function (e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        if (files && files.length > 0) {
            fileInput.files = files;
            handleFileSelect(files[0]);
        }
    }, false);

    // Handle regular file selection
    fileInput.addEventListener('change', function () {
        if (this.files && this.files[0]) {
            handleFileSelect(this.files[0]);
        }
    });

    function handleFileSelect(file) {
        // Validate size
        if (file.size > 5 * 1024 * 1024) {
            alert('File is too large! Maximum allowed size is 5 MB.');
            resetSelection();
            return;
        }

        // Validate type
        const validTypes = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];
        if (!validTypes.includes(file.type)) {
            alert('Please select a valid image file (PNG, JPG, JPEG, WEBP, or SVG).');
            resetSelection();
            return;
        }

        const reader = new FileReader();
        reader.onload = function (e) {
            previewImg.src = e.target.result;
            previewName.textContent = file.name;
            previewSize.textContent = (file.size / 1024).toFixed(1) + ' KB';

            promptDiv.classList.add('d-none');
            previewDiv.classList.remove('d-none');
            submitBtn.disabled = false;

            // Also update live preview panels on the right
            document.querySelectorAll('.live-logo-preview').forEach(img => {
                img.src = e.target.result;
            });

            if (window.lucide) lucide.createIcons();
        };
        reader.readAsDataURL(file);
    }

    function resetSelection() {
        fileInput.value = '';
        promptDiv.classList.remove('d-none');
        previewDiv.classList.add('d-none');
        previewImg.src = '#';
        submitBtn.disabled = true;

        if (window.lucide) lucide.createIcons();
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            resetSelection();
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

