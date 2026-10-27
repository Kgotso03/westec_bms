<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Project Milestones Management Module
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$pdo = getDbConnection();
$currentUser = getCurrentUser();

$errors = [];
$editMilestone = null;
$selectedProjectId = (int)($_GET['project_id'] ?? 0);

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        setFlash('danger', 'Security validation failed (CSRF). Please retry.');
        header('Location: /westec_bms/projects/milestones.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ADD MILESTONE
    if ($action === 'add') {
        $projectId     = (int)($_POST['project_id'] ?? 0);
        $milestoneName = cleanInput($_POST['milestone_name'] ?? '');
        $description   = cleanInput($_POST['description'] ?? '');
        $dueDate       = cleanInput($_POST['due_date'] ?? '');
        $status        = cleanInput($_POST['status'] ?? 'Pending');

        if ($projectId <= 0) {
            $errors[] = 'Please select a parent project.';
        }
        if (empty($milestoneName)) {
            $errors[] = 'Milestone deliverable name is required.';
        }
        if (empty($dueDate)) {
            $errors[] = 'Target completion / due date is required.';
        }

        if (empty($errors)) {
            try {
                $completedAt = ($status === 'Achieved') ? date('Y-m-d') : null;

                $stmt = $pdo->prepare("
                    INSERT INTO milestones (project_id, milestone_name, description, due_date, status, completed_at)
                    VALUES (:pid, :name, :desc, :due, :stat, :comp)
                ");
                $stmt->execute([
                    ':pid'  => $projectId,
                    ':name' => $milestoneName,
                    ':desc' => !empty($description) ? $description : null,
                    ':due'  => $dueDate,
                    ':stat' => $status,
                    ':comp' => $completedAt
                ]);

                setFlash('success', "Milestone '{$milestoneName}' added successfully.");
                header("Location: /westec_bms/projects/milestones.php?project_id={$projectId}");
                exit;
            } catch (Exception $e) {
                $errors[] = parseDatabaseErrorMessage($e);
            }
        }
    }

    // UPDATE MILESTONE
    elseif ($action === 'update') {
        $milestoneId   = (int)($_POST['milestone_id'] ?? 0);
        $projectId     = (int)($_POST['project_id'] ?? 0);
        $milestoneName = cleanInput($_POST['milestone_name'] ?? '');
        $description   = cleanInput($_POST['description'] ?? '');
        $dueDate       = cleanInput($_POST['due_date'] ?? '');
        $status        = cleanInput($_POST['status'] ?? 'Pending');

        if ($milestoneId <= 0 || $projectId <= 0) {
            $errors[] = 'Invalid milestone or project identifier.';
        }
        if (empty($milestoneName)) {
            $errors[] = 'Milestone name is required.';
        }
        if (empty($dueDate)) {
            $errors[] = 'Due date is required.';
        }

        if (empty($errors)) {
            try {
                $completedAt = ($status === 'Achieved') ? date('Y-m-d') : null;

                $stmt = $pdo->prepare("
                    UPDATE milestones
                    SET project_id = :pid,
                        milestone_name = :name,
                        description = :desc,
                        due_date = :due,
                        status = :stat,
                        completed_at = :comp
                    WHERE milestone_id = :id
                ");
                $stmt->execute([
                    ':pid'  => $projectId,
                    ':name' => $milestoneName,
                    ':desc' => !empty($description) ? $description : null,
                    ':due'  => $dueDate,
                    ':stat' => $status,
                    ':comp' => $completedAt,
                    ':id'   => $milestoneId
                ]);

                setFlash('success', "Milestone '{$milestoneName}' updated successfully.");
                header("Location: /westec_bms/projects/milestones.php?project_id={$projectId}");
                exit;
            } catch (Exception $e) {
                $errors[] = parseDatabaseErrorMessage($e);
            }
        }
    }

    // DELETE MILESTONE
    elseif ($action === 'delete') {
        $milestoneId = (int)($_POST['milestone_id'] ?? 0);
        $projectId   = (int)($_POST['project_id'] ?? 0);

        if ($milestoneId > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM milestones WHERE milestone_id = :id");
                $stmt->execute([':id' => $milestoneId]);
                setFlash('success', 'Milestone record removed.');
                header("Location: /westec_bms/projects/milestones.php" . ($projectId > 0 ? "?project_id={$projectId}" : ""));
                exit;
            } catch (Exception $e) {
                setFlash('danger', parseDatabaseErrorMessage($e));
            }
        }
    }
}

// Fetch edit target
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM milestones WHERE milestone_id = :id");
    $stmt->execute([':id' => $editId]);
    $editMilestone = $stmt->fetch();
    if ($editMilestone && $selectedProjectId === 0) {
        $selectedProjectId = (int)$editMilestone['project_id'];
    }
}

// Fetch projects for dropdown
$projectsList = $pdo->query("SELECT project_id, project_name FROM projects ORDER BY project_name ASC")->fetchAll();

// Fetch milestones
$query = "
    SELECT m.*, p.project_name, c.client_name
    FROM milestones m
    JOIN projects p ON m.project_id = p.project_id
    JOIN clients c ON p.client_id = c.client_id
";
$params = [];
if ($selectedProjectId > 0) {
    $query .= " WHERE m.project_id = :pid";
    $params[':pid'] = $selectedProjectId;
}
$query .= " ORDER BY p.project_name ASC, m.due_date ASC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$milestones = $stmt->fetchAll();

$pageTitle = 'Project Milestones';
$pageSubtitle = 'Track Key Deliverables, Acceptance Criteria & Completion Progress';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Quick Navigation Bar for Projects Suite -->
<div class="d-flex gap-2 mb-3">
    <a href="/westec_bms/projects/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-diagram-3-fill me-1"></i> Projects Overview
    </a>
    <a href="/westec_bms/projects/milestones.php" class="btn btn-westec btn-sm">
        <i class="bi bi-flag-fill me-1"></i> Milestones Management
    </a>
    <a href="/westec_bms/projects/assignments.php" class="btn btn-outline-secondary btn-sm">
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

<!-- Milestone Form (Create or Edit) -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i class="bi bi-flag-fill text-primary"></i>
            <?= $editMilestone ? 'Edit Milestone: ' . e($editMilestone['milestone_name']) : 'Add Project Milestone' ?>
        </h2>
        <?php if ($editMilestone): ?>
            <a href="/westec_bms/projects/milestones.php?project_id=<?= $selectedProjectId ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-x-circle me-1"></i> Cancel Edit
            </a>
        <?php endif; ?>
    </div>

    <form method="POST" action="/westec_bms/projects/milestones.php" novalidate>
        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
        <input type="hidden" name="action" value="<?= $editMilestone ? 'update' : 'add' ?>">
        <?php if ($editMilestone): ?>
            <input type="hidden" name="milestone_id" value="<?= $editMilestone['milestone_id'] ?>">
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-md-6 col-lg-4">
                <label for="project_id" class="form-label">Parent Project <span class="text-danger">*</span></label>
                <select class="form-select" id="project_id" name="project_id" required>
                    <option value="">-- Select Project --</option>
                    <?php foreach ($projectsList as $p): ?>
                        <option value="<?= $p['project_id'] ?>" <?= (($editMilestone['project_id'] ?? $selectedProjectId) == $p['project_id']) ? 'selected' : '' ?>>
                            <?= e($p['project_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6 col-lg-5">
                <label for="milestone_name" class="form-label">Milestone Deliverable Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="milestone_name" name="milestone_name" required value="<?= e($editMilestone['milestone_name'] ?? '') ?>" placeholder="e.g. Core Switch Aggregation & Transceiver Setup">
            </div>

            <div class="col-md-6 col-lg-3">
                <label for="due_date" class="form-label">Target Due Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" id="due_date" name="due_date" required value="<?= e($editMilestone['due_date'] ?? date('Y-m-d', strtotime('+14 days'))) ?>">
            </div>

            <div class="col-md-6 col-lg-4">
                <label for="status" class="form-label">Deliverable Status <span class="text-danger">*</span></label>
                <select class="form-select" id="status" name="status" required>
                    <?php foreach (['Pending', 'In Progress', 'Achieved', 'Delayed'] as $st): ?>
                        <option value="<?= $st ?>" <?= (($editMilestone['status'] ?? 'Pending') === $st) ? 'selected' : '' ?>><?= $st ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Marking 'Achieved' records completion timestamp.</div>
            </div>

            <div class="col-lg-8">
                <label for="description" class="form-label">Deliverable Scope & Verification Criteria</label>
                <input type="text" class="form-control" id="description" name="description" value="<?= e($editMilestone['description'] ?? '') ?>" placeholder="Criteria required for stakeholder sign-off...">
            </div>
        </div>

        <div class="text-end mt-3 pt-3 border-top">
            <button type="submit" class="btn btn-westec px-4">
                <i class="bi bi-save me-1"></i> <?= $editMilestone ? 'Update Milestone' : 'Save Milestone' ?>
            </button>
        </div>
    </form>
</div>

<!-- Milestones List Table -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i class="bi bi-list-task text-primary"></i>
            Milestones Register (<?= count($milestones) ?>)
        </h2>
        <div class="d-flex gap-2">
            <!-- Project Filter Dropdown -->
            <form method="GET" action="/westec_bms/projects/milestones.php" class="d-inline-block">
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
                <input type="text" class="form-control" placeholder="Search..." data-table-filter="milestonesTable">
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table-westec" id="milestonesTable">
            <thead>
                <tr>
                    <th>Milestone Deliverable</th>
                    <th>Parent Project</th>
                    <th>Client Organization</th>
                    <th>Target Due Date</th>
                    <th class="text-center">Status</th>
                    <th>Completed On</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($milestones)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No milestones logged for this project view.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($milestones as $m): ?>
                        <tr>
                            <td>
                                <strong class="text-dark d-block"><?= e($m['milestone_name']) ?></strong>
                                <small class="text-muted"><?= e($m['description'] ?? 'No extra scope notes') ?></small>
                            </td>
                            <td><strong class="text-dark"><?= e($m['project_name']) ?></strong></td>
                            <td><span class="badge bg-light text-dark border"><?= e($m['client_name']) ?></span></td>
                            <td><?= formatDate($m['due_date'], 'd M Y') ?></td>
                            <td class="text-center"><?= getStatusBadge($m['status']) ?></td>
                            <td><?= $m['completed_at'] ? formatDate($m['completed_at'], 'd M Y') : '<span class="text-muted">—</span>' ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="/westec_bms/projects/milestones.php?edit=<?= $m['milestone_id'] ?>" class="btn btn-outline-secondary" title="Edit Milestone">
                                        <i class="bi bi-pencil-square"></i>
                                    </a>
                                    <form method="POST" action="/westec_bms/projects/milestones.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="milestone_id" value="<?= $m['milestone_id'] ?>">
                                        <input type="hidden" name="project_id" value="<?= $m['project_id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger" title="Delete Milestone" data-confirm-delete="Delete this milestone?">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
