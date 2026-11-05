<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * User Profile & Avatar Photo Management
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$pdo = getDbConnection();
$currentUser = getCurrentUser();
$userId = (int)$currentUser['user_id'];
$errors = [];

$uploadDir = __DIR__ . '/../uploads/profile_photos';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Fetch fresh user record from database
$stmt = $pdo->prepare("
    SELECT u.user_id, u.username, u.email, u.status, u.profile_photo, u.created_at,
           r.role_name, r.description AS role_desc
    FROM users u
    JOIN roles r ON u.role_id = r.role_id
    WHERE u.user_id = :uid
    LIMIT 1
");
$stmt->execute([':uid' => $userId]);
$userData = $stmt->fetch();

if (!$userData) {
    setFlash('danger', 'User profile not found.');
    header('Location: /westec_bms/dashboard/index.php');
    exit;
}

// Check if user is linked to staff record
$staffStmt = $pdo->prepare("
    SELECT s.*, m.username AS manager_username
    FROM staff s
    LEFT JOIN users m ON s.manager_id = m.user_id
    WHERE s.user_id = :uid
    LIMIT 1
");
$staffStmt->execute([':uid' => $userId]);
$staffData = $staffStmt->fetch();

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        setFlash('danger', 'Security validation failed (CSRF). Please refresh and try again.');
        header('Location: /westec_bms/auth/profile.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // 1. UPLOAD NEW PROFILE PHOTO
    if ($action === 'upload_photo') {
        if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
            $errCode = $_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE;
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE   => 'The uploaded image exceeds server upload limits.',
                UPLOAD_ERR_FORM_SIZE  => 'The uploaded image exceeds maximum allowed form size.',
                UPLOAD_ERR_PARTIAL    => 'The image was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'Please select an image file to upload as your profile photo.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write photo to disk.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the file upload.',
            ];
            $errors[] = $uploadErrors[$errCode] ?? 'An error occurred during file upload.';
        } else {
            $file = $_FILES['profile_photo'];
            $maxBytes = 5 * 1024 * 1024; // 5 MB

            if ($file['size'] > $maxBytes) {
                $errors[] = 'Profile photo file size must not exceed 5 MB.';
            }

            $origName = $file['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $allowedExts = ['png', 'jpg', 'jpeg', 'webp'];

            if (!in_array($ext, $allowedExts, true)) {
                $errors[] = 'Invalid image format. Allowed formats: PNG, JPG, JPEG, WEBP.';
            }

            // Verify MIME type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            $allowedMimes = ['image/png', 'image/jpeg', 'image/webp'];
            if (!in_array($mime, $allowedMimes, true)) {
                $errors[] = 'File is not a supported image type (detected: ' . e($mime) . ').';
            }

            // Verify image contents
            if (empty($errors)) {
                $imgInfo = @getimagesize($file['tmp_name']);
                if ($imgInfo === false) {
                    $errors[] = 'The selected file is not a valid image.';
                }
            }

            if (empty($errors)) {
                try {
                    // Remove old profile photo file if present
                    if (!empty($userData['profile_photo'])) {
                        $oldPath = $uploadDir . '/' . $userData['profile_photo'];
                        if (file_exists($oldPath)) {
                            @unlink($oldPath);
                        }
                    }

                    // Generate distinct filename: user_{id}_{timestamp}.{ext}
                    $filename = 'user_' . $userId . '_' . time() . '.' . $ext;
                    $destination = $uploadDir . '/' . $filename;

                    if (!move_uploaded_file($file['tmp_name'], $destination)) {
                        throw new Exception('Failed to save profile photo to uploads folder. Check permissions.');
                    }

                    // Update user record
                    $updateStmt = $pdo->prepare("UPDATE users SET profile_photo = :photo WHERE user_id = :uid");
                    $updateStmt->execute([
                        ':photo' => $filename,
                        ':uid'   => $userId
                    ]);

                    // Sync session
                    $_SESSION['profile_photo'] = $filename;

                    // Audit log
                    $pdo->prepare("
                        INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                        VALUES (?, 'PROFILE_PHOTO_UPDATE', 'users', ?, ?, ?, NOW())
                    ")->execute([
                        $userId,
                        $userId,
                        "User '{$userData['username']}' updated their profile photo ({$filename}).",
                        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                    ]);

                    setFlash('success', 'Your profile photo has been updated successfully!');
                    header('Location: /westec_bms/auth/profile.php');
                    exit;

                } catch (Exception $e) {
                    $errors[] = 'Failed to update profile photo: ' . $e->getMessage();
                }
            }
        }
    }

    // 2. REMOVE PROFILE PHOTO
    elseif ($action === 'remove_photo') {
        try {
            if (!empty($userData['profile_photo'])) {
                $oldPath = $uploadDir . '/' . $userData['profile_photo'];
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }

            $updateStmt = $pdo->prepare("UPDATE users SET profile_photo = NULL WHERE user_id = :uid");
            $updateStmt->execute([':uid' => $userId]);

            $_SESSION['profile_photo'] = null;

            // Audit log
            $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, table_affected, record_id, details, ip_address, created_at)
                VALUES (?, 'PROFILE_PHOTO_REMOVE', 'users', ?, ?, ?, NOW())
            ")->execute([
                $userId,
                $userId,
                "User '{$userData['username']}' removed their profile photo.",
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            setFlash('success', 'Your profile photo has been removed.');
            header('Location: /westec_bms/auth/profile.php');
            exit;

        } catch (Exception $e) {
            $errors[] = 'Failed to remove photo: ' . $e->getMessage();
        }
    }

    // 3. UPDATE BASIC PROFILE DETAILS (Email)
    elseif ($action === 'update_info') {
        $email = cleanInput($_POST['email'] ?? '');

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if (empty($errors)) {
            try {
                // Check if email taken by someone else
                if (!empty($email)) {
                    $emailChk = $pdo->prepare("SELECT user_id FROM users WHERE email = :email AND user_id != :uid LIMIT 1");
                    $emailChk->execute([':email' => $email, ':uid' => $userId]);
                    if ($emailChk->fetch()) {
                        $errors[] = 'That email address is already registered to another account.';
                    }
                }

                if (empty($errors)) {
                    $pdo->prepare("UPDATE users SET email = :email WHERE user_id = :uid")->execute([
                        ':email' => !empty($email) ? $email : null,
                        ':uid'   => $userId
                    ]);
                    $_SESSION['email'] = $email;

                    setFlash('success', 'Your contact details have been updated.');
                    header('Location: /westec_bms/auth/profile.php');
                    exit;
                }
            } catch (Exception $e) {
                $errors[] = parseDatabaseErrorMessage($e);
            }
        }
    }
}

// Current photo resolution
$currentAvatarUrl = getUserAvatarUrl($userData);

$pageTitle = 'My Profile & Photo';
$pageSubtitle = 'Manage your WesTec Account Avatar, Personal Info & Security';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-4 shadow-sm" role="alert">
        <div class="d-flex align-items-center mb-1">
            <i data-lucide="alert-triangle" class="me-2 fs-5"></i>
            <strong class="fs-6">Profile Error:</strong>
        </div>
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $e): ?>
                <li><?= e($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <!-- Column 1: Profile Photo Management Card -->
    <div class="col-lg-5">
        <div class="content-section h-100">
            <div class="section-header">
                <h2 class="section-title text-primary">
                    <i data-lucide="camera"></i>
                    Profile Photo
                </h2>
                <span class="badge bg-light text-muted border">Personal Avatar</span>
            </div>

            <!-- Current Avatar Display -->
            <div class="text-center my-3">
                <div class="position-relative d-inline-block">
                    <div class="p-1 bg-white border rounded-circle shadow" style="width: 140px; height: 140px;">
                        <?php if ($currentAvatarUrl): ?>
                            <img src="<?= $currentAvatarUrl ?>" alt="<?= e($userData['username']) ?>" id="avatarDisplay" class="rounded-circle" style="width: 100%; height: 100%; object-fit: cover;">
                        <?php else: ?>
                            <div id="avatarFallback" class="rounded-circle d-flex align-items-center justify-content-center bg-light text-primary" style="width: 100%; height: 100%; font-size: 3rem; font-weight: 700;">
                                <?= strtoupper(substr($userData['username'], 0, 2)) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <h5 class="fw-bold text-dark mt-3 mb-0"><?= e($userData['username']) ?></h5>
                <span class="badge bg-primary text-white mt-1"><?= e($userData['role_name']) ?></span>
            </div>

            <!-- Upload Photo Form -->
            <form method="POST" action="/westec_bms/auth/profile.php" enctype="multipart/form-data" id="photoUploadForm">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="upload_photo">

                <!-- Drag-and-drop Photo Box -->
                <div class="border border-2 border-dashed rounded-3 p-3 text-center my-3 bg-light bg-opacity-50" id="photoDropZone" style="cursor: pointer; transition: all 0.2s ease;">
                    <div id="photoDropPrompt">
                        <i data-lucide="image-plus" class="fs-2 text-primary mb-1 d-block mx-auto" style="width: 36px; height: 36px;"></i>
                        <span class="fw-semibold small d-block mb-1">Click to browse or drop new photo</span>
                        <small class="text-muted d-block" style="font-size: 0.75rem;">PNG, JPG, JPEG or WEBP (Max 5 MB)</small>
                    </div>

                    <!-- Instant Preview -->
                    <div id="photoDropPreview" class="d-none">
                        <span class="fw-bold small text-success d-block mb-1" id="photoFileName">Image Selected</span>
                        <small class="text-muted d-block mb-2" id="photoFileSize">0 KB</small>
                        <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2" id="cancelSelectedPhoto">
                            <i data-lucide="x" class="me-1"></i> Cancel
                        </button>
                    </div>

                    <input type="file" class="d-none" id="profile_photo" name="profile_photo" accept="image/png,image/jpeg,image/webp" required>
                </div>

                <div class="d-flex justify-content-between align-items-center pt-2">
                    <?php if ($userData['profile_photo']): ?>
                        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#removePhotoModal">
                            <i data-lucide="trash-2" class="me-1"></i> Remove Photo
                        </button>
                    <?php else: ?>
                        <span class="small text-muted"><i data-lucide="info" class="me-1"></i>No custom photo</span>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-westec btn-sm px-3" id="savePhotoBtn" disabled>
                        <i data-lucide="check-circle-2" class="me-1"></i> Save Photo
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Column 2: Account Overview & Details -->
    <div class="col-lg-7">
        <div class="content-section h-100">
            <div class="section-header">
                <h2 class="section-title">
                    <i data-lucide="user-check" class="text-dark"></i>
                    Account &amp; Staff Information
                </h2>
                <span class="badge bg-success">Active Account</span>
            </div>

            <!-- Profile Info Form -->
            <form method="POST" action="/westec_bms/auth/profile.php">
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="update_info">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase fw-bold">Username</label>
                        <input type="text" class="form-control bg-light" value="<?= e($userData['username']) ?>" readonly>
                        <small class="text-muted">Unique login identifier (managed by System Admin)</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase fw-bold">Assigned Role</label>
                        <input type="text" class="form-control bg-light" value="<?= e($userData['role_name']) ?>" readonly>
                        <small class="text-muted"><?= e($userData['role_desc']) ?></small>
                    </div>

                    <div class="col-12">
                        <label for="email" class="form-label text-muted small text-uppercase fw-bold">Email Address</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i data-lucide="mail"></i></span>
                            <input type="email" class="form-control" id="email" name="email" value="<?= e($userData['email']) ?>" placeholder="name@westec.sz">
                        </div>
                        <small class="text-muted">Used for password resets and system notifications</small>
                    </div>

                    <?php if ($staffData): ?>
                        <div class="col-12">
                            <hr class="my-2 text-muted">
                            <h6 class="fw-bold text-dark small text-uppercase mb-2">
                                <i data-lucide="id-card" class="me-1 text-primary"></i> Linked Staff Record Details
                            </h6>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label text-muted small">Full Legal Name</label>
                            <input type="text" class="form-control bg-light" value="<?= e($staffData['full_name']) ?>" readonly>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label text-muted small">Job Title / Technical Designation</label>
                            <input type="text" class="form-control bg-light" value="<?= e($staffData['job_title']) ?>" readonly>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label text-muted small">Department</label>
                            <input type="text" class="form-control bg-light" value="<?= e($staffData['department'] ?? 'Technical Infrastructure') ?>" readonly>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label text-muted small">Reporting IT Manager</label>
                            <input type="text" class="form-control bg-light" value="<?= e($staffData['manager_username'] ?? 'Corporate Management') ?>" readonly>
                        </div>
                    <?php endif; ?>

                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                            <a href="/westec_bms/auth/change_password.php" class="btn btn-outline-secondary btn-sm">
                                <i data-lucide="key" class="me-1"></i> Change Password
                            </a>
                            <button type="submit" class="btn btn-westec btn-sm px-4">
                                <i data-lucide="save" class="me-1"></i> Update Contact Info
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Remove Photo Confirmation Modal -->
<div class="modal fade" id="removePhotoModal" tabindex="-1" aria-labelledby="removePhotoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger" id="removePhotoModalLabel">
                    <i data-lucide="trash-2" class="me-1"></i> Remove Photo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-3">
                <p class="mb-0 small">Are you sure you want to remove your custom profile photo and revert to initials?</p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="/westec_bms/auth/profile.php">
                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="action" value="remove_photo">
                    <button type="submit" class="btn btn-danger btn-sm px-3">
                        <i data-lucide="trash-2" class="me-1"></i> Remove
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const dropZone = document.getElementById('photoDropZone');
    const fileInput = document.getElementById('profile_photo');
    const promptDiv = document.getElementById('photoDropPrompt');
    const previewDiv = document.getElementById('photoDropPreview');
    const nameLabel = document.getElementById('photoFileName');
    const sizeLabel = document.getElementById('photoFileSize');
    const cancelBtn = document.getElementById('cancelSelectedPhoto');
    const saveBtn = document.getElementById('savePhotoBtn');
    const avatarDisplay = document.getElementById('avatarDisplay');
    const avatarFallback = document.getElementById('avatarFallback');

    if (!dropZone || !fileInput) return;

    dropZone.addEventListener('click', function (e) {
        if (e.target !== cancelBtn && !cancelBtn.contains(e.target)) {
            fileInput.click();
        }
    });

    ['dragenter', 'dragover'].forEach(name => {
        dropZone.addEventListener(name, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.add('border-primary', 'bg-white');
        }, false);
    });

    ['dragleave', 'drop'].forEach(name => {
        dropZone.addEventListener(name, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove('border-primary', 'bg-white');
        }, false);
    });

    dropZone.addEventListener('drop', function (e) {
        const dt = e.dataTransfer;
        if (dt && dt.files && dt.files.length > 0) {
            fileInput.files = dt.files;
            handleFile(dt.files[0]);
        }
    }, false);

    fileInput.addEventListener('change', function () {
        if (this.files && this.files[0]) {
            handleFile(this.files[0]);
        }
    });

    function handleFile(file) {
        if (file.size > 5 * 1024 * 1024) {
            alert('Image exceeds maximum allowed size of 5 MB.');
            resetSelection();
            return;
        }

        const validTypes = ['image/png', 'image/jpeg', 'image/webp'];
        if (!validTypes.includes(file.type)) {
            alert('Please select a PNG, JPG, JPEG, or WEBP image.');
            resetSelection();
            return;
        }

        const reader = new FileReader();
        reader.onload = function (e) {
            nameLabel.textContent = file.name;
            sizeLabel.textContent = (file.size / 1024).toFixed(1) + ' KB';

            promptDiv.classList.add('d-none');
            previewDiv.classList.remove('d-none');
            saveBtn.disabled = false;

            // Live preview on the big avatar circle above
            if (avatarDisplay) {
                avatarDisplay.src = e.target.result;
            } else if (avatarFallback) {
                const parent = avatarFallback.parentElement;
                avatarFallback.classList.add('d-none');
                let img = document.getElementById('tempAvatarImg');
                if (!img) {
                    img = document.createElement('img');
                    img.id = 'tempAvatarImg';
                    img.className = 'rounded-circle';
                    img.style.cssText = 'width: 100%; height: 100%; object-fit: cover;';
                    parent.appendChild(img);
                }
                img.src = e.target.result;
            }

            if (window.lucide) lucide.createIcons();
        };
        reader.readAsDataURL(file);
    }

    function resetSelection() {
        fileInput.value = '';
        promptDiv.classList.remove('d-none');
        previewDiv.classList.add('d-none');
        saveBtn.disabled = true;

        const tempImg = document.getElementById('tempAvatarImg');
        if (tempImg) tempImg.remove();
        if (avatarFallback) avatarFallback.classList.remove('d-none');

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

