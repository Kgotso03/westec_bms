<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Projects Management Module (CRUD & Trigger-Enforced Date Validation)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$pdo = getDbConnection();
$currentUser = getCurrentUser();

$errors = [];
$editProject = null;

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        setFlash('danger', 'Security validation failed (CSRF). Please retry.');
        header('Location: /westec_bms/projects/index.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // CREATE PROJECT
    if ($action === 'create') {
        $projectName = cleanInput($_POST['project_name'] ?? '');
        $clientId    = (int)($_POST['client_id'] ?? 0);
        $description = cleanInput($_POST['description'] ?? '');
        $startDate   = cleanInput($_POST['start_date'] ?? '');
        $dueDate     = cleanInput($_POST['due_date'] ?? '');
        $budget      = (float)($_POST['budget'] ?? 0);
        $status      = cleanInput($_POST['status'] ?? 'Planning');

        if (empty($projectName)) {
            $errors[] = 'Project title is required.';
        }
        if ($clientId <= 0) {
            $errors[] = 'Please select a valid client.';
        }
        if (empty($startDate) || empty($dueDate)) {
            $errors[] = 'Both start date and estimated completion/due date are required.';
        }

        if (empty($errors)) {
            try {
                // Insert into projects table.
                // Database trigger `trg_validate_project_dates_before_insert` validates:
                // IF NEW.due_date < NEW.start_date THEN SIGNAL SQLSTATE '45000'...
                $stmt = $pdo->prepare("
                    INSERT INTO projects (project_name, client_id, description, start_date, due_date, budget, status, created_at)
                    VALUES (:name, :cid, :desc, :sdate, :ddate, :budget, :status, NOW())
                ");
                $stmt->execute([
                    ':name'   => $projectName,
                    ':cid'    => $clientId,
                    ':desc'   => !empty($description) ? $description : null,
                    ':sdate'  => $startDate,
                    ':ddate'  => $dueDate,
                    ':budget' => $budget,
                    ':status' => $status
                ]);

                $newId = (int)$pdo->lastInsertId();
                setFlash('success', "Project '{$projectName}' created successfully (Project #{$newId}). Date validation trigger passed.");
                header('Location: /westec_bms/projects/index.php');
                exit;
            } catch (Exception $e) {
                // Catches database trigger rejection if due_date < start_date
                $errors[] = parseDatabaseErrorMessage($e);
            }
        }
    }

    // UPDATE PROJECT
    elseif ($action === 'update') {
        $projectId   = (int)($_POST['project_id'] ?? 0);
        $projectName = cleanInput($_POST['project_name'] ?? '');
        $clientId    = (int)($_POST['client_id'] ?? 0);
        $description = cleanInput($_POST['description'] ?? '');
        $startDate   = cleanInput($_POST['start_date'] ?? '');
        $dueDate     = cleanInput($_POST['due_date'] ?? '');
        $budget      = (float)($_POST['budget'] ?? 0);
        $status      = cleanInput($_POST['status'] ?? 'Planning');

        if ($projectId <= 0) {
            $errors[] = 'Invalid project identifier.';
        }
        if (empty($projectName)) {
            $errors[] = 'Project title is required.';
        }
        if ($clientId <= 0) {
            $errors[] = 'Please select a valid client.';
        }
        if (empty($startDate) || empty($dueDate)) {
            $errors[] = 'Start date and due date are required.';
        }

        if (empty($errors)) {
            try {
                // Database trigger `trg_validate_project_dates_before_update` enforces date validation
                $stmt = $pdo->prepare("
                    UPDATE projects
                    SET project_name = :name,
                        client_id = :cid,
                        description = :desc,
                        start_date = :sdate,
                        due_date = :ddate,
                        budget = :budget,
                        status = :status
                    WHERE project_id = :id
                ");
                $stmt->execute([
                    ':name'   => $projectName,
                    ':cid'    => $clientId,
                    ':desc'   => !empty($description) ? $description : null,
                    ':sdate'  => $startDate,
                    ':ddate'  => $dueDate,
                    ':budget' => $budget,
                    ':status' => $status,
                    ':id'     => $projectId
                ]);

                setFlash('success', "Project '{$projectName}' updated successfully.");
                header('Location: /westec_bms/projects/index.php');
                exit;
            } catch (Exception $e) {
                $errors[] = parseDatabaseErrorMessage($e);
            }
        }
    }
}

// Fetch edit target if requested
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE project_id = :id");
    $stmt->execute([':id' => $editId]);
    $editProject = $stmt->fetch();
}

// Clients list for dropdown
$clients = $pdo->query("SELECT client_id, client_name FROM clients ORDER BY client_name ASC")->fetchAll();

// Fetch all projects with client details and milestone progress metrics
$projects = $pdo->query("
    SELECT p.*, c.client_name,
           (SELECT COUNT(*) FROM milestones m WHERE m.project_id = p.project_id) AS total_milestones,
           (SELECT COUNT(*) FROM milestones m WHERE m.project_id = p.project_id AND m.status = 'Achieved') AS achieved_milestones,
           (SELECT COUNT(*) FROM project_assignments pa WHERE pa.project_id = p.project_id) AS team_count
    FROM projects p
    JOIN clients c ON p.client_id = c.client_id
    ORDER BY p.start_date DESC
")->fetchAll();

$pageTitle = 'Projects & Deliverables';
$pageSubtitle = 'IT Infrastructure Implementations, Client Contracts & Date Trigger Enforcements';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-4 shadow-sm" role="alert">
        <div class="d-flex align-items-center mb-1">
            <i data-lucide="shield-x" class="me-2 fs-5"></i>
            <strong class="fs-6">Project Validation Rejected:</strong>
        </div>
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $e): ?>
                <li><?= e($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Quick Navigation Bar for Projects Suite -->
<div class="d-flex gap-2 mb-3">
    <a href="/westec_bms/projects/index.php" class="btn btn-westec btn-sm">
        <i data-lucide="workflow" class="me-1"></i> Projects Overview
    </a>
    <a href="/westec_bms/projects/milestones.php" class="btn btn-outline-secondary btn-sm">
        <i data-lucide="flag" class="me-1"></i> Milestones Management
    </a>
    <a href="/westec_bms/projects/assignments.php" class="btn btn-outline-secondary btn-sm">
        <i data-lucide="users" class="me-1"></i> Team Staff Assignments
    </a>
</div>

<!-- Project Form (Create or Edit) -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i data-lucide="folder-plus" class="text-primary"></i>
            <?= $editProject ? 'Edit Project: ' . e($editProject['project_name']) : 'Initiate New Project (Trigger Enforced Dates)' ?>
        </h2>
        <?php if ($editProject): ?>
            <a href="/westec_bms/projects/index.php" class="btn btn-sm btn-outline-secondary">
                <i data-lucide="x-circle" class="me-1"></i> Cancel Edit
            </a>
        <?php endif; ?>
    </div>

    <form method="POST" action="/westec_bms/projects/index.php" novalidate>
        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
        <input type="hidden" name="action" value="<?= $editProject ? 'update' : 'create' ?>">
        <?php if ($editProject): ?>
            <input type="hidden" name="project_id" value="<?= $editProject['project_id'] ?>">
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-md-6 col-lg-5">
                <label for="project_name" class="form-label">Project Name / Contract Title <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="project_name" name="project_name" required value="<?= e($editProject['project_name'] ?? '') ?>" placeholder="e.g. EEC Data Center Fiber Backbone Migration">
            </div>

            <div class="col-md-6 col-lg-4">
                <label for="client_id" class="form-label">Client Organization <span class="text-danger">*</span></label>
                <select class="form-select" id="client_id" name="client_id" required>
                    <option value="">-- Select Client --</option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['client_id'] ?>" <?= (($editProject['client_id'] ?? 0) == $c['client_id']) ? 'selected' : '' ?>>
                            <?= e($c['client_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6 col-lg-3">
                <label for="status" class="form-label">Project Phase / Status</label>
                <select class="form-select" id="status" name="status">
                    <?php foreach (['Planning', 'In Progress', 'On Hold', 'Completed', 'Cancelled'] as $st): ?>
                        <option value="<?= $st ?>" <?= (($editProject['status'] ?? 'Planning') === $st) ? 'selected' : '' ?>><?= $st ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label for="start_date" class="form-label">Start Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" id="start_date" name="start_date" required value="<?= e($editProject['start_date'] ?? date('Y-m-d')) ?>">
            </div>

            <div class="col-md-4">
                <label for="due_date" class="form-label">Due Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" id="due_date" name="due_date" required value="<?= e($editProject['due_date'] ?? date('Y-m-d', strtotime('+30 days'))) ?>">
                <div class="form-text text-muted">Must be on or after Start Date.</div>
            </div>

            <div class="col-md-4">
                <label for="budget" class="form-label">Approved Budget (E / SZL)</label>
                <input type="number" step="0.01" class="form-control" id="budget" name="budget" value="<?= e($editProject['budget'] ?? '0.00') ?>" placeholder="0.00">
            </div>

            <div class="col-12">
                <label for="description" class="form-label">Project Scope & Technical Description</label>
                <textarea class="form-control" id="description" name="description" rows="2" placeholder="Brief statement of work, architecture objectives, deliverables..."><?= e($editProject['description'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="text-end mt-3 pt-3 border-top">
            <button type="submit" class="btn btn-westec px-4">
                <i data-lucide="save" class="me-1"></i> <?= $editProject ? 'Update Project' : 'Save Project' ?>
            </button>
        </div>
    </form>
</div>

<!-- Projects Directory Table -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i data-lucide="kanban" class="text-primary"></i>
            Active Projects Directory (<?= count($projects) ?>)
        </h2>
        <div class="input-group input-group-sm" style="max-width: 250px;">
            <span class="input-group-text bg-light"><i data-lucide="search"></i></span>
            <input type="text" class="form-control" placeholder="Search projects..." data-table-filter="projectsTable">
        </div>
    </div>

    <div class="table-responsive">
        <table class="table-westec" id="projectsTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Project Name & Client</th>
                    <th>Timeline (Start &rarr; Due)</th>
                    <th class="text-end">Budget</th>
                    <th class="text-center">Progress</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($projects)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No projects registered. Create your first project above.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($projects as $proj): 
                        $totalM = (int)$proj['total_milestones'];
                        $achievedM = (int)$proj['achieved_milestones'];
                        $percent = $totalM > 0 ? round(($achievedM / $totalM) * 100) : 0;
                    ?>
                        <tr>
                            <td><span class="badge bg-light text-dark border">#<?= $proj['project_id'] ?></span></td>
                            <td>
                                <strong class="text-dark d-block"><?= e($proj['project_name']) ?></strong>
                                <span class="text-muted small"><i data-lucide="building-2" class="me-1"></i><?= e($proj['client_name']) ?></span>
                            </td>
                            <td>
                                <div class="small">Start: <strong><?= formatDate($proj['start_date'], 'd M Y') ?></strong></div>
                                <div class="small text-muted">Due: <strong><?= formatDate($proj['due_date'], 'd M Y') ?></strong></div>
                            </td>
                            <td class="text-end fw-semibold">
                                <?= formatCurrency($proj['budget']) ?>
                            </td>
                            <td class="text-center" style="min-width: 130px;">
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span><?= $achievedM ?>/<?= $totalM ?> Milestones</span>
                                    <span class="fw-bold"><?= $percent ?>%</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar" style="width: <?= $percent ?>%; background-color: var(--westec-primary);"></div>
                                </div>
                            </td>
                            <td class="text-center"><?= getStatusBadge($proj['status']) ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="/westec_bms/projects/milestones.php?project_id=<?= $proj['project_id'] ?>" class="btn btn-outline-primary" title="Manage Project Milestones">
                                        <i data-lucide="flag"></i>
                                    </a>
                                    <a href="/westec_bms/projects/assignments.php?project_id=<?= $proj['project_id'] ?>" class="btn btn-outline-info text-dark" title="Assign Engineers">
                                        <i data-lucide="user-plus"></i>
                                    </a>
                                    <a href="/westec_bms/projects/index.php?edit=<?= $proj['project_id'] ?>" class="btn btn-outline-secondary" title="Edit Project Details">
                                        <i data-lucide="file-edit"></i>
                                    </a>
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
