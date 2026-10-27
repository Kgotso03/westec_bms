<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Service Tickets Management Module (IT Support & Helpdesk Operations)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$pdo = getDbConnection();
$currentUser = getCurrentUser();

$errors = [];
$editTicket = null;

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        setFlash('danger', 'Security validation failed (CSRF). Please retry.');
        header('Location: /westec_bms/tickets/index.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // CREATE TICKET
    if ($action === 'create') {
        $clientId        = (int)($_POST['client_id'] ?? 0);
        $assignedStaffId = !empty($_POST['assigned_staff_id']) ? (int)$_POST['assigned_staff_id'] : null;
        $title           = cleanInput($_POST['title'] ?? '');
        $description     = cleanInput($_POST['description'] ?? '');
        $priority        = cleanInput($_POST['priority'] ?? 'Medium');
        $status          = cleanInput($_POST['status'] ?? 'Open');

        if ($clientId <= 0) {
            $errors[] = 'Please select a valid client.';
        }
        if (empty($title)) {
            $errors[] = 'Ticket title / incident summary is required.';
        }
        if (empty($description)) {
            $errors[] = 'Technical incident description is required.';
        }

        if (empty($errors)) {
            try {
                // Generate unique sequential ticket number: TK-YYYY-XXXX
                $countToday = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE YEAR(created_at) = YEAR(CURDATE())")->fetchColumn();
                $ticketNumber = 'TK-' . date('Y') . '-' . str_pad($countToday + 1, 3, '0', STR_PAD_LEFT);

                // Set resolved date if created directly as resolved/closed
                $resolvedAt = in_array($status, ['Resolved', 'Closed']) ? date('Y-m-d H:i:s') : null;

                $stmt = $pdo->prepare("
                    INSERT INTO tickets (ticket_number, client_id, assigned_staff_id, title, description, priority, status, created_at, resolved_at)
                    VALUES (:num, :cid, :sid, :title, :desc, :prio, :stat, NOW(), :res)
                ");
                $stmt->execute([
                    ':num'   => $ticketNumber,
                    ':cid'   => $clientId,
                    ':sid'   => $assignedStaffId,
                    ':title' => $title,
                    ':desc'  => $description,
                    ':prio'  => $priority,
                    ':stat'  => $status,
                    ':res'   => $resolvedAt
                ]);

                $ticketId = (int)$pdo->lastInsertId();
                setFlash('success', "Service Ticket #{$ticketNumber} created successfully.");
                header('Location: /westec_bms/tickets/index.php');
                exit;
            } catch (Exception $e) {
                $errors[] = parseDatabaseErrorMessage($e);
            }
        }
    }

    // UPDATE TICKET
    elseif ($action === 'update') {
        $ticketId        = (int)($_POST['ticket_id'] ?? 0);
        $clientId        = (int)($_POST['client_id'] ?? 0);
        $assignedStaffId = !empty($_POST['assigned_staff_id']) ? (int)$_POST['assigned_staff_id'] : null;
        $title           = cleanInput($_POST['title'] ?? '');
        $description     = cleanInput($_POST['description'] ?? '');
        $priority        = cleanInput($_POST['priority'] ?? 'Medium');
        $status          = cleanInput($_POST['status'] ?? 'Open');

        if ($ticketId <= 0) {
            $errors[] = 'Invalid ticket identifier.';
        }
        if ($clientId <= 0) {
            $errors[] = 'Please select a valid client.';
        }
        if (empty($title)) {
            $errors[] = 'Ticket title is required.';
        }
        if (empty($description)) {
            $errors[] = 'Technical description is required.';
        }

        if (empty($errors)) {
            try {
                // Determine resolved_at based on new status
                $resolvedAtQuery = "";
                if (in_array($status, ['Resolved', 'Closed'])) {
                    $resolvedAtQuery = ", resolved_at = COALESCE(resolved_at, NOW())";
                } else {
                    $resolvedAtQuery = ", resolved_at = NULL";
                }

                $stmt = $pdo->prepare("
                    UPDATE tickets
                    SET client_id = :cid,
                        assigned_staff_id = :sid,
                        title = :title,
                        description = :desc,
                        priority = :prio,
                        status = :stat
                        {$resolvedAtQuery}
                    WHERE ticket_id = :id
                ");
                $stmt->execute([
                    ':cid'   => $clientId,
                    ':sid'   => $assignedStaffId,
                    ':title' => $title,
                    ':desc'  => $description,
                    ':prio'  => $priority,
                    ':stat'  => $status,
                    ':id'    => $ticketId
                ]);

                setFlash('success', "Ticket updated successfully. Trigger `trg_after_ticket_status_update` recorded state transitions.");
                header('Location: /westec_bms/tickets/index.php');
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
    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE ticket_id = :id");
    $stmt->execute([':id' => $editId]);
    $editTicket = $stmt->fetch();
}

// Filter parameters
$filterStatus   = cleanInput($_GET['status'] ?? '');
$filterPriority = cleanInput($_GET['priority'] ?? '');
$filterClient   = (int)($_GET['client_id'] ?? 0);
$filterStaff    = (int)($_GET['staff_id'] ?? 0);

// Fetch dropdown datasets
$clients = $pdo->query("SELECT client_id, client_name FROM clients ORDER BY client_name ASC")->fetchAll();
$staffMembers = $pdo->query("SELECT staff_id, full_name, job_title FROM staff WHERE is_active = 1 ORDER BY full_name ASC")->fetchAll();

// Build filtered query
$query = "
    SELECT t.*, c.client_name, s.full_name AS assigned_staff, s.job_title
    FROM tickets t
    JOIN clients c ON t.client_id = c.client_id
    LEFT JOIN staff s ON t.assigned_staff_id = s.staff_id
    WHERE 1=1
";
$params = [];

if (!empty($filterStatus)) {
    $query .= " AND t.status = :f_status";
    $params[':f_status'] = $filterStatus;
}
if (!empty($filterPriority)) {
    $query .= " AND t.priority = :f_priority";
    $params[':f_priority'] = $filterPriority;
}
if ($filterClient > 0) {
    $query .= " AND t.client_id = :f_client";
    $params[':f_client'] = $filterClient;
}
if ($filterStaff > 0) {
    $query .= " AND t.assigned_staff_id = :f_staff";
    $params[':f_staff'] = $filterStaff;
}

$query .= " ORDER BY t.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$pageTitle = 'Service Tickets';
$pageSubtitle = 'IT Support Incidents, SLA Tracking, Priority Dispatch & Resolution Management';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-4">
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $e): ?>
                <li><?= e($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Ticket Form (Create / Edit) -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i class="bi bi-ticket-detailed-fill text-primary"></i>
            <?= $editTicket ? 'Update Service Ticket: #' . e($editTicket['ticket_number']) : 'Log New Service Ticket' ?>
        </h2>
        <?php if ($editTicket): ?>
            <a href="/westec_bms/tickets/index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-x-circle me-1"></i> Cancel Edit
            </a>
        <?php endif; ?>
    </div>

    <form method="POST" action="/westec_bms/tickets/index.php" novalidate>
        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
        <input type="hidden" name="action" value="<?= $editTicket ? 'update' : 'create' ?>">
        <?php if ($editTicket): ?>
            <input type="hidden" name="ticket_id" value="<?= $editTicket['ticket_id'] ?>">
        <?php endif; ?>

        <div class="row g-3">
            <!-- Client Dropdown (Retrieved from Database) -->
            <div class="col-md-6 col-lg-4">
                <label for="client_id" class="form-label">Client Organization <span class="text-danger">*</span></label>
                <select class="form-select" id="client_id" name="client_id" required>
                    <option value="">-- Select Client --</option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['client_id'] ?>" <?= (($editTicket['client_id'] ?? 0) == $c['client_id']) ? 'selected' : '' ?>>
                            <?= e($c['client_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Assigned Staff Dropdown -->
            <div class="col-md-6 col-lg-4">
                <label for="assigned_staff_id" class="form-label">Assigned IT Technician / Engineer</label>
                <select class="form-select" id="assigned_staff_id" name="assigned_staff_id">
                    <option value="">-- Unassigned (Dispatch Queue) --</option>
                    <?php foreach ($staffMembers as $s): ?>
                        <option value="<?= $s['staff_id'] ?>" <?= (($editTicket['assigned_staff_id'] ?? 0) == $s['staff_id']) ? 'selected' : '' ?>>
                            <?= e($s['full_name']) ?> (<?= e($s['job_title']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Priority -->
            <div class="col-md-6 col-lg-2">
                <label for="priority" class="form-label">SLA Priority <span class="text-danger">*</span></label>
                <select class="form-select" id="priority" name="priority" required>
                    <?php foreach (['Low', 'Medium', 'High', 'Critical'] as $p): ?>
                        <option value="<?= $p ?>" <?= (($editTicket['priority'] ?? 'Medium') === $p) ? 'selected' : '' ?>><?= $p ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Status -->
            <div class="col-md-6 col-lg-2">
                <label for="status" class="form-label">Status <span class="text-danger">*</span></label>
                <select class="form-select" id="status" name="status" required>
                    <?php foreach (['Open', 'In Progress', 'Pending', 'Resolved', 'Closed'] as $st): ?>
                        <option value="<?= $st ?>" <?= (($editTicket['status'] ?? 'Open') === $st) ? 'selected' : '' ?>><?= $st ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Title -->
            <div class="col-12">
                <label for="title" class="form-label">Incident Title / Summary <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="title" name="title" required value="<?= e($editTicket['title'] ?? '') ?>" placeholder="e.g. Core Switch Failover Latency Spike at Mbabane Substation">
            </div>

            <!-- Description -->
            <div class="col-12">
                <label for="description" class="form-label">Technical Incident Details <span class="text-danger">*</span></label>
                <textarea class="form-control" id="description" name="description" rows="3" required placeholder="Describe symptoms, equipment affected, diagnostic findings, or resolution actions..."><?= e($editTicket['description'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="text-end mt-3 pt-3 border-top">
            <button type="submit" class="btn btn-westec px-4">
                <i class="bi bi-save me-1"></i> <?= $editTicket ? 'Update Service Ticket' : 'Dispatch Ticket' ?>
            </button>
        </div>
    </form>
</div>

<!-- Search & Filter Controls -->
<div class="content-section p-3 mb-3 bg-light">
    <form method="GET" action="/westec_bms/tickets/index.php" class="row g-2 align-items-center">
        <div class="col-md-3">
            <select name="status" class="form-select form-select-sm">
                <option value="">-- All Statuses --</option>
                <?php foreach (['Open', 'In Progress', 'Pending', 'Resolved', 'Closed'] as $st): ?>
                    <option value="<?= $st ?>" <?= ($filterStatus === $st) ? 'selected' : '' ?>><?= $st ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3">
            <select name="priority" class="form-select form-select-sm">
                <option value="">-- All Priorities --</option>
                <?php foreach (['Critical', 'High', 'Medium', 'Low'] as $pr): ?>
                    <option value="<?= $pr ?>" <?= ($filterPriority === $pr) ? 'selected' : '' ?>><?= $pr ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3">
            <select name="client_id" class="form-select form-select-sm">
                <option value="0">-- All Clients --</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['client_id'] ?>" <?= ($filterClient == $c['client_id']) ? 'selected' : '' ?>><?= e($c['client_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-westec w-100">
                <i class="bi bi-filter me-1"></i> Apply Filter
            </button>
            <a href="/westec_bms/tickets/index.php" class="btn btn-sm btn-outline-secondary" title="Reset Filters">
                <i class="bi bi-arrow-counterclockwise"></i>
            </a>
        </div>
    </form>
</div>

<!-- Service Tickets Table -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i class="bi bi-list-check text-primary"></i>
            Service Tickets Directory (<?= count($tickets) ?>)
        </h2>
        <div class="input-group input-group-sm" style="max-width: 250px;">
            <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" placeholder="Search table..." data-table-filter="ticketsTable">
        </div>
    </div>

    <div class="table-responsive">
        <table class="table-westec" id="ticketsTable">
            <thead>
                <tr>
                    <th>Ticket #</th>
                    <th>Client Organization</th>
                    <th>Incident Summary</th>
                    <th>Priority</th>
                    <th>Assigned Engineer</th>
                    <th>Status</th>
                    <th>Logged / Resolved</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($tickets)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No service tickets matching the selected criteria.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tickets as $t): ?>
                        <tr>
                            <td>
                                <strong class="text-dark">#<?= e($t['ticket_number']) ?></strong>
                            </td>
                            <td><strong class="text-dark"><?= e($t['client_name']) ?></strong></td>
                            <td>
                                <div class="fw-semibold text-dark"><?= e($t['title']) ?></div>
                                <div class="small text-muted text-truncate" style="max-width: 320px;"><?= e($t['description']) ?></div>
                            </td>
                            <td><?= getPriorityBadge($t['priority']) ?></td>
                            <td>
                                <?php if ($t['assigned_staff']): ?>
                                    <span class="text-dark fw-medium small"><?= e($t['assigned_staff']) ?></span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td><?= getStatusBadge($t['status']) ?></td>
                            <td>
                                <div class="small text-muted">In: <?= formatDate($t['created_at'], 'd M, H:i') ?></div>
                                <?php if ($t['resolved_at']): ?>
                                    <div class="small text-success"><i class="bi bi-check-all me-1"></i>Out: <?= formatDate($t['resolved_at'], 'd M, H:i') ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="/westec_bms/tickets/index.php?edit=<?= $t['ticket_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit / Update Ticket Status">
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
