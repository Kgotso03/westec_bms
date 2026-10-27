<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Operations Dashboard
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Enforce authentication
requireLogin();

$pdo = getDbConnection();
$currentUser = getCurrentUser();

// 1. Fetch system statistics
$totalClients = (int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$openTickets = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('Open', 'In Progress', 'Pending')")->fetchColumn();
$criticalTickets = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE priority = 'Critical' AND status NOT IN ('Resolved', 'Closed')")->fetchColumn();
$lowStockCount = (int)$pdo->query("SELECT COUNT(*) FROM inventory WHERE quantity_in_stock <= reorder_level")->fetchColumn();
$activeProjects = (int)$pdo->query("SELECT COUNT(*) FROM projects WHERE status IN ('Planning', 'In Progress')")->fetchColumn();
$totalStaff = (int)$pdo->query("SELECT COUNT(*) FROM staff WHERE is_active = 1")->fetchColumn();

// 2. Fetch Recent Service Tickets
$recentTicketsStmt = $pdo->query("
    SELECT t.ticket_id, t.ticket_number, t.title, t.priority, t.status, t.created_at,
           c.client_name, s.full_name AS assigned_staff
    FROM tickets t
    JOIN clients c ON t.client_id = c.client_id
    LEFT JOIN staff s ON t.assigned_staff_id = s.staff_id
    ORDER BY t.created_at DESC
    LIMIT 5
");
$recentTickets = $recentTicketsStmt->fetchAll();

// 3. Fetch Inventory Reorder Warnings
$reorderStmt = $pdo->query("
    SELECT item_id, sku, item_name, category, quantity_in_stock, reorder_level, unit_price
    FROM inventory
    WHERE quantity_in_stock <= reorder_level
    ORDER BY quantity_in_stock ASC
");
$reorderItems = $reorderStmt->fetchAll();

// 4. Fetch Active Projects
$projectsStmt = $pdo->query("
    SELECT p.project_id, p.project_name, p.status, p.start_date, p.due_date, p.budget,
           c.client_name,
           (SELECT COUNT(*) FROM milestones m WHERE m.project_id = p.project_id) AS total_milestones,
           (SELECT COUNT(*) FROM milestones m WHERE m.project_id = p.project_id AND m.status = 'Achieved') AS achieved_milestones
    FROM projects p
    JOIN clients c ON p.client_id = c.client_id
    ORDER BY p.start_date DESC
    LIMIT 4
");
$activeProjectsList = $projectsStmt->fetchAll();

$pageTitle = 'Operations Dashboard';
$pageSubtitle = 'IT Services, Infrastructure Deployment & Ticket Management Overview';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Metric Summary Row -->
<div class="row g-3 mb-4">
    <!-- Clients Metric -->
    <div class="col-sm-6 col-xl-2">
        <div class="content-section p-3 text-center mb-0 h-100">
            <span class="text-muted small text-uppercase fw-semibold d-block">Enterprise Clients</span>
            <span class="display-6 fw-bold text-dark d-block my-1"><?= $totalClients ?></span>
            <a href="/westec_bms/clients/index.php" class="small text-decoration-none fw-semibold" style="color: var(--westec-primary);">
                Manage Clients &rarr;
            </a>
        </div>
    </div>

    <!-- Active Tickets Metric -->
    <div class="col-sm-6 col-xl-2">
        <div class="content-section p-3 text-center mb-0 h-100">
            <span class="text-muted small text-uppercase fw-semibold d-block">Open Tickets</span>
            <span class="display-6 fw-bold text-primary d-block my-1"><?= $openTickets ?></span>
            <a href="/westec_bms/tickets/index.php" class="small text-decoration-none fw-semibold">
                Service Desk &rarr;
            </a>
        </div>
    </div>

    <!-- Critical Incidents Metric -->
    <div class="col-sm-6 col-xl-2">
        <div class="content-section p-3 text-center mb-0 h-100">
            <span class="text-muted small text-uppercase fw-semibold d-block">Critical SLA</span>
            <span class="display-6 fw-bold text-danger d-block my-1"><?= $criticalTickets ?></span>
            <span class="badge bg-danger bg-opacity-10 text-danger small">High Priority</span>
        </div>
    </div>

    <!-- Inventory Warnings Metric -->
    <div class="col-sm-6 col-xl-2">
        <div class="content-section p-3 text-center mb-0 h-100">
            <span class="text-muted small text-uppercase fw-semibold d-block">Low Stock Alert</span>
            <span class="display-6 fw-bold text-warning d-block my-1"><?= $lowStockCount ?></span>
            <a href="/westec_bms/inventory/index.php" class="small text-decoration-none fw-semibold text-warning">
                Review Stock &rarr;
            </a>
        </div>
    </div>

    <!-- Projects Metric -->
    <div class="col-sm-6 col-xl-2">
        <div class="content-section p-3 text-center mb-0 h-100">
            <span class="text-muted small text-uppercase fw-semibold d-block">Active Projects</span>
            <span class="display-6 fw-bold text-dark d-block my-1"><?= $activeProjects ?></span>
            <a href="/westec_bms/projects/index.php" class="small text-decoration-none fw-semibold" style="color: var(--westec-primary);">
                View Projects &rarr;
            </a>
        </div>
    </div>

    <!-- Active Staff Metric -->
    <div class="col-sm-6 col-xl-2">
        <div class="content-section p-3 text-center mb-0 h-100">
            <span class="text-muted small text-uppercase fw-semibold d-block">Active Engineers</span>
            <span class="display-6 fw-bold text-dark d-block my-1"><?= $totalStaff ?></span>
            <a href="/westec_bms/staff/index.php" class="small text-decoration-none fw-semibold text-secondary">
                Staff Directory &rarr;
            </a>
        </div>
    </div>
</div>

<!-- Main Operational Panels -->
<div class="row g-4 mb-4">
    <!-- Recent Tickets Panel -->
    <div class="col-lg-7">
        <div class="content-section h-100">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="bi bi-ticket-detailed-fill text-primary"></i>
                    Recent Service Tickets
                </h2>
                <a href="/westec_bms/tickets/index.php" class="btn btn-sm btn-westec-outline">
                    <i class="bi bi-plus-circle me-1"></i> New Ticket
                </a>
            </div>

            <div class="table-responsive">
                <table class="table-westec">
                    <thead>
                        <tr>
                            <th>Ticket #</th>
                            <th>Client & Subject</th>
                            <th>Priority</th>
                            <th>Assigned Staff</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentTickets)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-3">No service tickets recorded yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentTickets as $ticket): ?>
                                <tr>
                                    <td>
                                        <strong class="text-dark">#<?= e($ticket['ticket_number']) ?></strong>
                                        <span class="d-block text-muted small"><?= formatDate($ticket['created_at'], 'd M, H:i') ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= e($ticket['title']) ?></div>
                                        <div class="text-muted small"><?= e($ticket['client_name']) ?></div>
                                    </td>
                                    <td><?= getPriorityBadge($ticket['priority']) ?></td>
                                    <td>
                                        <span class="small"><?= e($ticket['assigned_staff'] ?? 'Unassigned') ?></span>
                                    </td>
                                    <td><?= getStatusBadge($ticket['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Fast Actions & Database Trigger Status -->
    <div class="col-lg-5">
        <div class="content-section mb-4">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="bi bi-lightning-charge-fill text-warning"></i>
                    Database Triggers & Business Rules
                </h2>
                <span class="badge bg-success">6 ACTIVE</span>
            </div>
            <p class="small text-muted mb-3">
                MySQL database triggers enforce system integrity autonomously across transactions, registration, and dates.
            </p>
            <ul class="list-group list-group-flush small mb-3">
                <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                    <div>
                        <strong>trg_after_user_register</strong>
                        <div class="text-muted" style="font-size: 0.75rem;">Logs registration events directly to audit trail</div>
                    </div>
                    <span class="badge bg-light text-success border">Verified</span>
                </li>
                <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                    <div>
                        <strong>trg_check_inventory_stock_before_insert</strong>
                        <div class="text-muted" style="font-size: 0.75rem;">Rejects stock issues exceeding current inventory</div>
                    </div>
                    <span class="badge bg-light text-success border">Enforced</span>
                </li>
                <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                    <div>
                        <strong>trg_update_inventory_stock_after_insert</strong>
                        <div class="text-muted" style="font-size: 0.75rem;">Updates inventory stock levels on transactions</div>
                    </div>
                    <span class="badge bg-light text-success border">Automated</span>
                </li>
                <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
                    <div>
                        <strong>trg_validate_project_dates_before_insert</strong>
                        <div class="text-muted" style="font-size: 0.75rem;">Rejects projects where due_date &lt; start_date</div>
                    </div>
                    <span class="badge bg-light text-success border">Enforced</span>
                </li>
            </ul>
            <div class="d-grid">
                <a href="/westec_bms/admin/trigger_demo.php" class="btn btn-westec btn-sm">
                    <i class="bi bi-play-circle-fill me-1"></i> Open Interactive Trigger Test Lab
                </a>
            </div>
        </div>

        <!-- Inventory Reorder Alert Section -->
        <?php if (!empty($reorderItems)): ?>
            <div class="content-section border-warning">
                <div class="section-header">
                    <h2 class="section-title text-warning">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        Reorder Alerts (<?= count($reorderItems) ?> Items)
                    </h2>
                    <a href="/westec_bms/inventory/index.php" class="btn btn-sm btn-outline-warning">
                        Stock In
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm small mb-0">
                        <thead>
                            <tr class="text-muted">
                                <th>SKU / Item</th>
                                <th class="text-center">Stock</th>
                                <th class="text-center">Reorder Lvl</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reorderItems as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($item['sku']) ?></strong>
                                        <div class="text-muted" style="font-size: 0.75rem;"><?= e($item['item_name']) ?></div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-danger"><?= e($item['quantity_in_stock']) ?></span>
                                    </td>
                                    <td class="text-center text-muted"><?= e($item['reorder_level']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Active Projects & Milestones Row -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i class="bi bi-diagram-3-fill" style="color: var(--westec-primary);"></i>
            Current Network & Infrastructure Projects
        </h2>
        <a href="/westec_bms/projects/index.php" class="btn btn-sm btn-westec">
            <i class="bi bi-plus-circle me-1"></i> Add Project
        </a>
    </div>

    <div class="row g-3">
        <?php foreach ($activeProjectsList as $proj): 
            $totalM = (int)$proj['total_milestones'];
            $achievedM = (int)$proj['achieved_milestones'];
            $percent = $totalM > 0 ? round(($achievedM / $totalM) * 100) : 0;
        ?>
            <div class="col-md-6 col-xl-4">
                <div class="border rounded p-3 bg-light h-100">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="badge bg-white text-dark border"><?= e($proj['client_name']) ?></span>
                        <?= getStatusBadge($proj['status']) ?>
                    </div>
                    <h3 class="h6 fw-bold text-dark mb-1"><?= e($proj['project_name']) ?></h3>
                    <div class="text-muted small mb-2">
                        Budget: <strong><?= formatCurrency($proj['budget']) ?></strong> &bull; Due: <?= formatDate($proj['due_date'], 'd M Y') ?>
                    </div>
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span>Milestones: <?= $achievedM ?> of <?= $totalM ?> completed</span>
                        <span class="fw-bold"><?= $percent ?>%</span>
                    </div>
                    <div class="progress" style="height: 6px;">
                        <div class="progress-bar" role="progressbar" style="width: <?= $percent ?>%; background-color: var(--westec-primary);" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
