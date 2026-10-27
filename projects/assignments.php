<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Project Staff Assignments Module
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$pdo = getDbConnection();
$currentUser = getCurrentUser();

$errors = [];
$selectedProjectId = (int)($_GET['project_id'] ?? 0);

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        setFlash('danger', 'Security validation failed (CSRF). Please retry.');
        header('Location: /westec_bms/projects/assignments.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ADD ASSIGNMENT
    if ($action === 'assign') {
        $projectId     = (int)($_POST['project_id'] ?? 0);
        $staffId       = (int)($_POST['staff_id'] ?? 0);
        $roleInProject = cleanInput($_POST['role_in_project'] ?? 'Team Member');
        $assignedDate  = cleanInput($_POST['assigned_date'] ?? date('Y-m-d'));
        $notes         = cleanInput($_POST['notes'] ?? '');

        if ($projectId <= 0) {
            $errors[] = 'Please select a project.';
        }
        if ($staffId <= 0) {
            $errors[] = 'Please select a staff member to assign.';
        }

        if (empty($errors)) {
            try {
                // Check if already assigned to this project
                $checkStmt = $pdo->prepare("SELECT assignment_id FROM project_assignments WHERE project_id = :pid AND staff_id = :sid");
                $checkStmt->execute([':pid' => $projectId, ':sid' => $staffId]);
                if ($checkStmt->fetch()) {
                    $errors[] = 'This engineer is already assigned to this project. Please update the existing assignment if needed.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO project_assignments (project_id, staff_id, role_in_project, assigned_date, notes)
                        VALUES (:pid, :sid, :role, :adate, :notes)
                    ");
                    $stmt->execute([
                        ':pid'   => $projectId,
                        ':sid'   => $staffId,
                        ':role'  => $roleInProject,
                        ':adate' => $assignedDate,
                        ':notes' => !empty($notes) ? $notes : null
                    ]);

                    setFlash('success', 'Staff member successfully assigned to the project.');
                    header("Location: /westec_bms/projects/assignments.php?project_id={$projectId}");
                    exit;
                }
            } catch (Exception $e) {
                $errors[] = parseDatabaseErrorMessage($e);
            }
        }
    }

    // REMOVE ASSIGNMENT
    elseif ($action === 'remove') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $projectId    = (int)($_POST['project_id'] ?? 0);

        if ($assignmentId > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM project_assignments WHERE assignment_id = :id");
                $stmt->execute([':id' => $assignmentId]);
                setFlash('success', 'Staff assignment removed from project.');
                header("Location: /westec_bms/projects/assignments.php" . ($projectId > 0 ? "?project_id={$projectId}" : ""));
                exit;
            } catch (Exception $e) {
                setFlash('danger', parseDatabaseErrorMessage($e));
            }
        }
    }
}

// Fetch lists for dropdowns
$projectsList = $pdo->query("SELECT project_id, project_name FROM projects ORDER BY project_name ASC")->fetchAll();
$staffList = $pdo->query("SELECT staff_id, full_name, job_title FROM staff WHERE is_active = 1 ORDER BY full_name ASC")->fetchAll();

// Fetch assignments
$query = "
    SELECT pa.*, p.project_name, c.client_name, s.full_name, s.job_title, s.phone, s.email
    FROM project_assignments pa
    JOIN projects p ON pa.project_id = p.project_id
    JOIN clients c ON p.client_id = c.client_id
    JOIN staff s ON pa.staff_id = s.staff_id
";
$params = [];
if ($selectedProjectId > 0) {
    $query .= " WHERE pa.project_id = :pid";
    $params[':pid'] = $selectedProjectId;
}
$query .= " ORDER BY p.project_name ASC, pa.assigned_date DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

$pageTitle = 'Project Staff Assignments';
$pageSubtitle = 'Allocate Technical Engineers, Project Managers & Define Roles on Contracts';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Quick Navigation Bar for Projects Suite -->
<div class="d-flex gap-2 mb-3">
    <a href="/westec_bms/projects/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-diagram-3-fill me-1"></i> Projects Overview
    </a>
    <a href="/westec_bms/projects/milestones.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-flag-fill me-1"></i> Milestones Management
    </a>
    <a href="/westec_bms/projects/assignments.php" class="btn btn-westec btn-sm">
        <i class="bi bi-person-lines-fill me-1"></i> Team Staff Assignments
    </a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-4">
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $e): ?>
                <li><?= e($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Assign Staff Form -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i class="bi bi-person-plus-fill text-primary"></i>
            Assign Staff Member to Project Team
        </h2>
    </div>

    <form method="POST" action="/westec_bms/projects/assignments.php" novalidate>
        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
        <input type="hidden" name="action" value="assign">

        <div class="row g-3">
            <div class="col-md-6 col-lg-4">
                <label for="project_id" class="form-label">Target Project <span class="text-danger">*</span></label>
                <select class="form-select" id="project_id" name="project_id" required>
                    <option value="">-- Select Project --</option>
                    <?php foreach ($projectsList as $p): ?>
                        <option value="<?= $p['project_id'] ?>" <?= ($selectedProjectId == $p['project_id']) ? 'selected' : '' ?>>
                            <?= e($p['project_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6 col-lg-4">
                <label for="staff_id" class="form-label">Staff Engineer / Specialist <span class="text-danger">*</span></label>
                <select class="form-select" id="staff_id" name="staff_id" required>
                    <option value="">-- Select Staff Member --</option>
                    <?php foreach ($staffList as $s): ?>
                        <option value="<?= $s['staff_id'] ?>">
                            <?= e($s['full_name']) ?> (<?= e($s['job_title']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6 col-lg-4">
                <label for="role_in_project" class="form-label">Role in Project Team <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="role_in_project" name="role_in_project" required placeholder="e.g. Lead Optical Engineer, Solutions Architect">
            </div>

            <div class="col-md-6 col-lg-4">
                <label for="assigned_date" class="form-label">Assignment Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" id="assigned_date" name="assigned_date" required value="<?= date('Y-m-d') ?>">
            </div>

            <div class="col-lg-8">
                <label for="notes" class="form-label">Responsibility Notes / Scope of Authority</label>
                <input type="text" class="form-control" id="notes" name="notes" placeholder="e.g. Responsible for physical fiber termination, OTDR calibration, and client handover.">
            </div>
        </div>

        <div class="text-end mt-3 pt-3 border-top">
            <button type="submit" class="btn btn-westec px-4">
                <i class="bi bi-check2-circle me-1"></i> Save Assignment
            </button>
        </div>
    </form>
</div>

<!-- Assignments Directory Table -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i class="bi bi-people-fill text-primary"></i>
            Active Project Staff Allocations (<?= count($assignments) ?>)
        </h2>
        <div class="d-flex gap-2">
            <!-- Project Filter Dropdown -->
            <form method="GET" action="/westec_bms/projects/assignments.php" class="d-inline-block">
                <select name="project_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="0">-- All Projects --</option>
                    <?php foreach ($projectsList as $p): ?>
                        <option value="<?= $p['project_id'] ?>" <?= ($selectedProjectId == $p['project_id']) ? 'selected' : '' ?>>
                            <?= e($p['project_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <div class="input-group input-group-sm" style="max-width: 200px;">
                <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" placeholder="Search..." data-table-filter="assignTable">
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table-westec" id="assignTable">
            <thead>
                <tr>
                    <th>Engineer / Staff Member</th>
                    <th>Project & Client</th>
                    <th>Assigned Role</th>
                    <th>Assigned Date</th>
                    <th>Responsibility Scope</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($assignments)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No staff assignments found for this view.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($assignments as $a): ?>
                        <tr>
                            <td>
                                <strong class="text-dark d-block"><?= e($a['full_name']) ?></strong>
                                <small class="text-muted"><i class="bi bi-telephone me-1"></i><?= e($a['phone']) ?></small>
                            </td>
                            <td>
                                <strong class="text-dark d-block"><?= e($a['project_name']) ?></strong>
                                <span class="badge bg-light text-dark border"><?= e($a['client_name']) ?></span>
                            </td>
                            <td><span class="badge bg-primary text-white"><?= e($a['role_in_project']) ?></span></td>
                            <td><?= formatDate($a['assigned_date'], 'd M Y') ?></td>
                            <td><small class="text-muted"><?= e($a['notes'] ?? 'General technical support') ?></small></td>
                            <td class="text-end">
                                <form method="POST" action="/westec_bms/projects/assignments.php" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="assignment_id" value="<?= $a['assignment_id'] ?>">
                                    <input type="hidden" name="project_id" value="<?= $a['project_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove Assignment" data-confirm-delete="Remove this staff member from project?">
                                        <i class="bi bi-person-x"></i> Remove
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
