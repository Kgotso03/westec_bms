<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Clients Management Module (CRUD)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$pdo = getDbConnection();
$currentUser = getCurrentUser();

$errors = [];
$editClient = null;

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        setFlash('danger', 'Security validation failed (CSRF). Please retry.');
        header('Location: /westec_bms/clients/index.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ADD CLIENT
    if ($action === 'add') {
        $clientName    = cleanInput($_POST['client_name'] ?? '');
        $contactPerson = cleanInput($_POST['contact_person'] ?? '');
        $email         = cleanInput($_POST['email'] ?? '');
        $phone         = cleanInput($_POST['phone'] ?? '');
        $address       = cleanInput($_POST['address'] ?? '');

        if (empty($clientName)) {
            $errors[] = 'Client / Organization name is required.';
        }
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if (empty($phone)) {
            $errors[] = 'Phone contact number is required.';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO clients (client_name, contact_person, email, phone, address, created_at)
                    VALUES (:name, :contact, :email, :phone, :address, NOW())
                ");
                $stmt->execute([
                    ':name'    => $clientName,
                    ':contact' => !empty($contactPerson) ? $contactPerson : null,
                    ':email'   => $email,
                    ':phone'   => $phone,
                    ':address' => !empty($address) ? $address : null
                ]);

                $newId = (int)$pdo->lastInsertId();
                setFlash('success', "Client '{$clientName}' successfully added (ID #{$newId}).");
                header('Location: /westec_bms/clients/index.php');
                exit;
            } catch (Exception $e) {
                $errors[] = parseDatabaseErrorMessage($e);
            }
        }
    }

    // EDIT CLIENT
    elseif ($action === 'edit') {
        $clientId      = (int)($_POST['client_id'] ?? 0);
        $clientName    = cleanInput($_POST['client_name'] ?? '');
        $contactPerson = cleanInput($_POST['contact_person'] ?? '');
        $email         = cleanInput($_POST['email'] ?? '');
        $phone         = cleanInput($_POST['phone'] ?? '');
        $address       = cleanInput($_POST['address'] ?? '');

        if ($clientId <= 0) {
            $errors[] = 'Invalid client ID.';
        }
        if (empty($clientName)) {
            $errors[] = 'Client name is required.';
        }
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if (empty($phone)) {
            $errors[] = 'Phone contact number is required.';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE clients
                    SET client_name = :name,
                        contact_person = :contact,
                        email = :email,
                        phone = :phone,
                        address = :address
                    WHERE client_id = :id
                ");
                $stmt->execute([
                    ':name'    => $clientName,
                    ':contact' => !empty($contactPerson) ? $contactPerson : null,
                    ':email'   => $email,
                    ':phone'   => $phone,
                    ':address' => !empty($address) ? $address : null,
                    ':id'      => $clientId
                ]);

                setFlash('success', "Client '{$clientName}' details updated successfully.");
                header('Location: /westec_bms/clients/index.php');
                exit;
            } catch (Exception $e) {
                $errors[] = parseDatabaseErrorMessage($e);
            }
        }
    }

    // DELETE CLIENT (Protected with Foreign Key Safety)
    elseif ($action === 'delete') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($clientId > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM clients WHERE client_id = :id");
                $stmt->execute([':id' => $clientId]);
                setFlash('success', 'Client record removed successfully.');
                header('Location: /westec_bms/clients/index.php');
                exit;
            } catch (Exception $e) {
                setFlash('danger', parseDatabaseErrorMessage($e));
                header('Location: /westec_bms/clients/index.php');
                exit;
            }
        }
    }
}

// Check if an edit request is initiated via GET
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $fetchEdit = $pdo->prepare("SELECT * FROM clients WHERE client_id = :id");
    $fetchEdit->execute([':id' => $editId]);
    $editClient = $fetchEdit->fetch();
}

// Fetch all clients with related ticket and project counts
$clientsStmt = $pdo->query("
    SELECT c.*,
           (SELECT COUNT(*) FROM tickets t WHERE t.client_id = c.client_id) AS ticket_count,
           (SELECT COUNT(*) FROM projects p WHERE p.client_id = c.client_id) AS project_count
    FROM clients c
    ORDER BY c.client_name ASC
");
$clients = $clientsStmt->fetchAll();

$pageTitle = 'Client Management';
$pageSubtitle = 'Maintain WesTec Eswatini Enterprise Client Directory & Service Linkages';
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

<!-- Client Form (Add or Edit) -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i data-lucide="building-2" class="text-primary"></i>
            <?= $editClient ? 'Edit Client: ' . e($editClient['client_name']) : 'Add New Client' ?>
        </h2>
        <?php if ($editClient): ?>
            <a href="/westec_bms/clients/index.php" class="btn btn-sm btn-outline-secondary">
                <i data-lucide="x-circle" class="me-1"></i> Cancel Editing
            </a>
        <?php endif; ?>
    </div>

    <form method="POST" action="/westec_bms/clients/index.php" novalidate>
        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
        <input type="hidden" name="action" value="<?= $editClient ? 'edit' : 'add' ?>">
        <?php if ($editClient): ?>
            <input type="hidden" name="client_id" value="<?= $editClient['client_id'] ?>">
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-md-6 col-lg-4">
                <label for="client_name" class="form-label">Client / Entity Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="client_name" name="client_name" required value="<?= e($editClient['client_name'] ?? ($_POST['client_name'] ?? '')) ?>" placeholder="e.g. Standard Bank Eswatini">
            </div>

            <div class="col-md-6 col-lg-4">
                <label for="contact_person" class="form-label">Contact Person</label>
                <input type="text" class="form-control" id="contact_person" name="contact_person" value="<?= e($editClient['contact_person'] ?? ($_POST['contact_person'] ?? '')) ?>" placeholder="e.g. Lindiwe Masuku">
            </div>

            <div class="col-md-6 col-lg-4">
                <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                <input type="email" class="form-control" id="email" name="email" required value="<?= e($editClient['email'] ?? ($_POST['email'] ?? '')) ?>" placeholder="e.g. ict@standardbank.co.sz">
            </div>

            <div class="col-md-6 col-lg-4">
                <label for="phone" class="form-label">Phone Contact <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="phone" name="phone" required value="<?= e($editClient['phone'] ?? ($_POST['phone'] ?? '')) ?>" placeholder="e.g. +268 2405 6000">
            </div>

            <div class="col-lg-8">
                <label for="address" class="form-label">Physical Address</label>
                <input type="text" class="form-control" id="address" name="address" value="<?= e($editClient['address'] ?? ($_POST['address'] ?? '')) ?>" placeholder="e.g. Corporate Place, Swazi Plaza, Mbabane">
            </div>
        </div>

        <div class="text-end mt-3 pt-3 border-top">
            <button type="submit" class="btn btn-westec px-4">
                <i data-lucide="save" class="me-1"></i> <?= $editClient ? 'Update Client Record' : 'Save Client' ?>
            </button>
        </div>
    </form>
</div>

<!-- Clients Directory Table -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i data-lucide="list" class="text-primary"></i>
            Registered Clients Directory (<?= count($clients) ?>)
        </h2>
        <div class="d-flex gap-2">
            <div class="input-group input-group-sm" style="max-width: 250px;">
                <span class="input-group-text bg-light"><i data-lucide="search"></i></span>
                <input type="text" class="form-control" placeholder="Search clients..." data-table-filter="clientsTable">
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table-westec" id="clientsTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Client Organization</th>
                    <th>Contact Person</th>
                    <th>Email Address</th>
                    <th>Phone</th>
                    <th>Address</th>
                    <th class="text-center">Tickets</th>
                    <th class="text-center">Projects</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clients)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">No client records found. Add your first client above.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($clients as $client): ?>
                        <tr>
                            <td><span class="badge bg-light text-dark border">#<?= $client['client_id'] ?></span></td>
                            <td><strong class="text-dark"><?= e($client['client_name']) ?></strong></td>
                            <td><?= e($client['contact_person'] ?? '—') ?></td>
                            <td>
                                <a href="mailto:<?= e($client['email']) ?>" class="text-decoration-none">
                                    <i data-lucide="mail" class="me-1 text-muted"></i><?= e($client['email']) ?>
                                </a>
                            </td>
                            <td><i data-lucide="phone" class="me-1 text-muted"></i><?= e($client['phone']) ?></td>
                            <td><small class="text-muted"><?= e($client['address'] ?? '—') ?></small></td>
                            <td class="text-center">
                                <span class="badge bg-primary rounded-pill"><?= $client['ticket_count'] ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary rounded-pill"><?= $client['project_count'] ?></span>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="/westec_bms/clients/index.php?edit=<?= $client['client_id'] ?>" class="btn btn-outline-secondary" title="Edit Client">
                                        <i data-lucide="file-edit"></i>
                                    </a>
                                    <form method="POST" action="/westec_bms/clients/index.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="client_id" value="<?= $client['client_id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger" title="Delete Client" data-confirm-delete="Are you sure you want to delete client '<?= e($client['client_name']) ?>'? Note: Deletion will be rejected by the database if tickets or projects are attached.">
                                            <i data-lucide="trash-2"></i>
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
