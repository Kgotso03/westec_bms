<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Executive Reports & Operational Analytics Module
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$pdo = getDbConnection();
$currentUser = getCurrentUser();

// Handle Report Snapshot Generation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (validateCsrfToken($token)) {
        $reportType = cleanInput($_POST['report_type'] ?? 'TICKET_SUMMARY');
        $title      = cleanInput($_POST['report_title'] ?? 'Generated Operational Report');

        // Compile summary data based on type
        $summaryText = "";
        if ($reportType === 'TICKET_SUMMARY') {
            $total = $pdo->query("SELECT COUNT(*) FROM tickets")->fetchColumn();
            $resolved = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('Resolved', 'Closed')")->fetchColumn();
            $summaryText = "Service Ticket Metrics: Total Tickets Logged: {$total}. Successfully Resolved/Closed: {$resolved}.";
        } elseif ($reportType === 'INVENTORY_VALUATION') {
            $val = $pdo->query("SELECT SUM(quantity_in_stock * unit_price) FROM inventory")->fetchColumn();
            $count = $pdo->query("SELECT COUNT(*) FROM inventory")->fetchColumn();
            $summaryText = "Inventory Stock Valuation: Total SKUs: {$count}. Total Catalog Asset Value: " . formatCurrency($val);
        } else {
            $totalP = $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn();
            $summaryText = "Projects Overview: Active Infrastructure Deployments: {$totalP}.";
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO service_reports (report_title, report_type, generated_by, parameters, summary_data, created_at)
                VALUES (:title, :type, :uid, :params, :data, NOW())
            ");
            $stmt->execute([
                ':title'  => $title,
                ':type'   => $reportType,
                ':uid'    => $currentUser['user_id'],
                ':params' => json_encode(['generated_at' => date('c'), 'user' => $currentUser['username']]),
                ':data'   => $summaryText
            ]);

            setFlash('success', 'Report snapshot archived successfully into `service_reports`.');
            header('Location: /westec_bms/reports/index.php');
            exit;
        } catch (Exception $e) {
            setFlash('danger', parseDatabaseErrorMessage($e));
        }
    }
}

// 1. Report: Tickets Summary by Client & Priority
$ticketsReport = $pdo->query("
    SELECT c.client_name,
           COUNT(t.ticket_id) AS total_tickets,
           SUM(CASE WHEN t.priority = 'Critical' THEN 1 ELSE 0 END) AS critical_count,
           SUM(CASE WHEN t.status IN ('Resolved', 'Closed') THEN 1 ELSE 0 END) AS resolved_count,
           SUM(CASE WHEN t.status IN ('Open', 'In Progress', 'Pending') THEN 1 ELSE 0 END) AS active_count
    FROM clients c
    LEFT JOIN tickets t ON c.client_id = t.client_id
    GROUP BY c.client_id, c.client_name
    ORDER BY total_tickets DESC
")->fetchAll();

// 2. Report: Inventory Category Valuation
$inventoryReport = $pdo->query("
    SELECT category,
           COUNT(*) AS sku_count,
           SUM(quantity_in_stock) AS total_units,
           SUM(quantity_in_stock * unit_price) AS total_valuation,
           SUM(CASE WHEN quantity_in_stock <= reorder_level THEN 1 ELSE 0 END) AS reorder_needed
    FROM inventory
    GROUP BY category
    ORDER BY total_valuation DESC
")->fetchAll();

$totalInventoryValuation = $pdo->query("SELECT SUM(quantity_in_stock * unit_price) FROM inventory")->fetchColumn();

// 3. Report: Staff Engineer Workload
$staffReport = $pdo->query("
    SELECT s.full_name, s.job_title, s.department,
           COUNT(DISTINCT t.ticket_id) AS open_tickets,
           COUNT(DISTINCT pa.project_id) AS assigned_projects
    FROM staff s
    LEFT JOIN tickets t ON s.staff_id = t.assigned_staff_id AND t.status NOT IN ('Resolved', 'Closed')
    LEFT JOIN project_assignments pa ON s.staff_id = pa.staff_id
    WHERE s.is_active = 1
    GROUP BY s.staff_id
    ORDER BY open_tickets DESC, assigned_projects DESC
")->fetchAll();

// 4. Saved Snapshots
$savedReports = $pdo->query("
    SELECT sr.*, u.username AS author
    FROM service_reports sr
    LEFT JOIN users u ON sr.generated_by = u.user_id
    ORDER BY sr.created_at DESC
")->fetchAll();

$pageTitle = 'Operational Reports';
$pageSubtitle = 'Cross-Module Business Intelligence, Infrastructure Valuation & SLA Metrics';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Generate Snapshot Form Bar -->
<div class="content-section p-3 mb-4 bg-light">
    <form method="POST" action="/westec_bms/reports/index.php" class="row g-2 align-items-center">
        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
        <div class="col-md-4">
            <input type="text" name="report_title" class="form-control form-control-sm" required placeholder="Report Title (e.g. Q4 Executive SLA Summary)" value="WesTec Operational Snapshot - <?= date('d M Y') ?>">
        </div>
        <div class="col-md-4">
            <select name="report_type" class="form-select form-select-sm">
                <option value="TICKET_SUMMARY">Service Desk & Incident SLA Summary</option>
                <option value="INVENTORY_VALUATION">IT Hardware Inventory Valuation</option>
                <option value="PROJECT_PROGRESS">Infrastructure Projects Health Report</option>
            </select>
        </div>
        <div class="col-md-4">
            <button type="submit" class="btn btn-sm btn-westec w-100">
                <i class="bi bi-file-earmark-arrow-down-fill me-1"></i> Save Report Snapshot
            </button>
        </div>
    </form>
</div>

<!-- Report 1: Client Tickets & Incident Metrics -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i class="bi bi-ticket-detailed-fill text-primary"></i>
            Service Tickets & Client SLA Distribution
        </h2>
        <span class="badge bg-light text-dark border">Multi-Table Join: clients + tickets</span>
    </div>

    <div class="table-responsive">
        <table class="table-westec">
            <thead>
                <tr>
                    <th>Client Organization</th>
                    <th class="text-center">Total Incidents</th>
                    <th class="text-center">Critical SLA</th>
                    <th class="text-center">Active (Open/In Prog)</th>
                    <th class="text-center">Resolved / Closed</th>
                    <th class="text-center">Resolution Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ticketsReport as $row): 
                    $total = (int)$row['total_tickets'];
                    $res = (int)$row['resolved_count'];
                    $rate = $total > 0 ? round(($res / $total) * 100) : 0;
                ?>
                    <tr>
                        <td><strong class="text-dark"><?= e($row['client_name']) ?></strong></td>
                        <td class="text-center fw-bold"><?= $total ?></td>
                        <td class="text-center">
                            <?php if ($row['critical_count'] > 0): ?>
                                <span class="badge bg-danger"><?= $row['critical_count'] ?> Critical</span>
                            <?php else: ?>
                                <span class="badge bg-light text-muted border">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-warning text-dark"><?= $row['active_count'] ?> Active</span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-success"><?= $res ?> Resolved</span>
                        </td>
                        <td class="text-center">
                            <span class="fw-bold <?= $rate >= 50 ? 'text-success' : 'text-danger' ?>"><?= $rate ?>%</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Report 2: Inventory Valuation by Hardware Category -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i class="bi bi-cash-stack text-success"></i>
            IT Inventory Stock Valuation by Category
        </h2>
        <span class="badge bg-success text-white">Total Valuation: <?= formatCurrency($totalInventoryValuation) ?></span>
    </div>

    <div class="table-responsive">
        <table class="table-westec">
            <thead>
                <tr>
                    <th>Equipment Category</th>
                    <th class="text-center">Catalog SKUs</th>
                    <th class="text-center">Physical Units in Stock</th>
                    <th class="text-end">Total Asset Valuation</th>
                    <th class="text-center">Reorder Warnings</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inventoryReport as $cat): ?>
                    <tr>
                        <td><strong class="text-dark"><?= e($cat['category']) ?></strong></td>
                        <td class="text-center"><?= $cat['sku_count'] ?> SKUs</td>
                        <td class="text-center fw-bold"><?= $cat['total_units'] ?? 0 ?> units</td>
                        <td class="text-end fw-bold text-success"><?= formatCurrency($cat['total_valuation'] ?? 0) ?></td>
                        <td class="text-center">
                            <?php if ($cat['reorder_needed'] > 0): ?>
                                <span class="badge bg-danger"><?= $cat['reorder_needed'] ?> Item(s) Low</span>
                            <?php else: ?>
                                <span class="badge bg-success">Adequate</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Report 3: Staff Engineer Allocation & Workload -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i class="bi bi-person-workspace text-primary"></i>
            Staff Technical Workload & Project Deployment
        </h2>
        <span class="badge bg-light text-dark border">Multi-Table Join: staff + tickets + project_assignments</span>
    </div>

    <div class="table-responsive">
        <table class="table-westec">
            <thead>
                <tr>
                    <th>Engineer Name</th>
                    <th>Designation</th>
                    <th>Department</th>
                    <th class="text-center">Active Service Tickets</th>
                    <th class="text-center">Assigned Projects</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staffReport as $s): ?>
                    <tr>
                        <td><strong class="text-dark"><?= e($s['full_name']) ?></strong></td>
                        <td><?= e($s['job_title']) ?></td>
                        <td><span class="badge bg-light text-secondary border"><?= e($s['department']) ?></span></td>
                        <td class="text-center">
                            <span class="badge bg-primary fs-6"><?= $s['open_tickets'] ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-secondary fs-6"><?= $s['assigned_projects'] ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Saved Snapshot History -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i class="bi bi-archive text-secondary"></i>
            Archived Management Reports (service_reports)
        </h2>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-striped small mb-0">
            <thead>
                <tr class="text-muted">
                    <th>ID</th>
                    <th>Report Title</th>
                    <th>Category Type</th>
                    <th>Compiled Summary Data</th>
                    <th>Generated By</th>
                    <th>Timestamp</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($savedReports)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-3">No archived reports found. Generate one using the bar above.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($savedReports as $sr): ?>
                        <tr>
                            <td><span class="badge bg-dark">#<?= $sr['report_id'] ?></span></td>
                            <td><strong class="text-dark"><?= e($sr['report_title']) ?></strong></td>
                            <td><span class="badge bg-light text-dark border"><?= e($sr['report_type']) ?></span></td>
                            <td class="text-secondary"><?= e($sr['summary_data']) ?></td>
                            <td><span class="badge bg-light text-dark border"><?= e($sr['author'] ?? 'System') ?></span></td>
                            <td><?= formatDate($sr['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
