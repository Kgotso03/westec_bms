<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Inventory Management & Trigger-Driven Stock Transactions
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$pdo = getDbConnection();
$currentUser = getCurrentUser();

$errors = [];
$editItem = null;

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        setFlash('danger', 'Security validation failed (CSRF). Please retry.');
        header('Location: /westec_bms/inventory/index.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // 1. ADD INVENTORY CATALOG ITEM (Initial stock starts at 0; must be received via transaction)
    if ($action === 'add_item') {
        $sku          = strtoupper(cleanInput($_POST['sku'] ?? ''));
        $itemName     = cleanInput($_POST['item_name'] ?? '');
        $category     = cleanInput($_POST['category'] ?? '');
        $unitPrice    = (float)($_POST['unit_price'] ?? 0);
        $reorderLevel = (int)($_POST['reorder_level'] ?? 5);

        if (empty($sku)) {
            $errors[] = 'SKU (Stock Keeping Unit) is required.';
        }
        if (empty($itemName)) {
            $errors[] = 'Item description / model name is required.';
        }
        if (empty($category)) {
            $errors[] = 'Category is required.';
        }
        if ($unitPrice < 0) {
            $errors[] = 'Unit price cannot be negative.';
        }
        if ($reorderLevel < 0) {
            $errors[] = 'Reorder level cannot be negative.';
        }

        if (empty($errors)) {
            try {
                // Insert new item with quantity_in_stock = 0. Stock changes MUST occur via inventory_transactions triggers
                $stmt = $pdo->prepare("
                    INSERT INTO inventory (sku, item_name, category, quantity_in_stock, unit_price, reorder_level, created_at)
                    VALUES (:sku, :name, :cat, 0, :price, :reorder, NOW())
                ");
                $stmt->execute([
                    ':sku'     => $sku,
                    ':name'    => $itemName,
                    ':cat'     => $category,
                    ':price'   => $unitPrice,
                    ':reorder' => $reorderLevel
                ]);

                $newId = (int)$pdo->lastInsertId();
                setFlash('success', "Item '{$itemName}' ({$sku}) added to catalog. Record an 'IN' stock transaction below to populate stock.");
                header('Location: /westec_bms/inventory/index.php');
                exit;
            } catch (Exception $e) {
                $errors[] = parseDatabaseErrorMessage($e);
            }
        }
    }

    // 2. EDIT CATALOG ITEM DETAILS (Stock count CANNOT be altered here - rule compliance)
    elseif ($action === 'edit_item') {
        $itemId       = (int)($_POST['item_id'] ?? 0);
        $sku          = strtoupper(cleanInput($_POST['sku'] ?? ''));
        $itemName     = cleanInput($_POST['item_name'] ?? '');
        $category     = cleanInput($_POST['category'] ?? '');
        $unitPrice    = (float)($_POST['unit_price'] ?? 0);
        $reorderLevel = (int)($_POST['reorder_level'] ?? 5);

        if ($itemId <= 0) {
            $errors[] = 'Invalid item identifier.';
        }
        if (empty($sku)) {
            $errors[] = 'SKU is required.';
        }
        if (empty($itemName)) {
            $errors[] = 'Item name is required.';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE inventory
                    SET sku = :sku,
                        item_name = :name,
                        category = :cat,
                        unit_price = :price,
                        reorder_level = :reorder
                    WHERE item_id = :id
                ");
                $stmt->execute([
                    ':sku'     => $sku,
                    ':name'    => $itemName,
                    ':cat'     => $category,
                    ':price'   => $unitPrice,
                    ':reorder' => $reorderLevel,
                    ':id'      => $itemId
                ]);

                setFlash('success', "Catalog details for '{$itemName}' updated successfully.");
                header('Location: /westec_bms/inventory/index.php');
                exit;
            } catch (Exception $e) {
                $errors[] = parseDatabaseErrorMessage($e);
            }
        }
    }

    // 3. RECORD INVENTORY TRANSACTION (Trigger trg_check_inventory_stock_before_insert & trg_update_inventory_stock_after_insert)
    elseif ($action === 'record_transaction') {
        $itemId          = (int)($_POST['trans_item_id'] ?? 0);
        $transactionType = cleanInput($_POST['transaction_type'] ?? '');
        $quantity        = (int)($_POST['quantity'] ?? 0);
        $unitCost        = (float)($_POST['unit_cost'] ?? 0);
        $referenceNote   = cleanInput($_POST['reference_note'] ?? '');

        if ($itemId <= 0) {
            $errors[] = 'Please select a valid inventory item.';
        }
        if (!in_array($transactionType, ['IN', 'OUT', 'ADJUSTMENT'])) {
            $errors[] = 'Invalid transaction type selected.';
        }
        if ($quantity <= 0) {
            $errors[] = 'Quantity must be a positive integer greater than zero.';
        }

        if (empty($errors)) {
            try {
                // Fetch stock level before insert to display verification
                $beforeStock = (int)$pdo->query("SELECT quantity_in_stock FROM inventory WHERE item_id = {$itemId}")->fetchColumn();

                // Insert into inventory_transactions.
                // 1. trg_check_inventory_stock_before_insert will execute BEFORE INSERT
                // 2. trg_update_inventory_stock_after_insert will execute AFTER INSERT
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_transactions (item_id, transaction_type, quantity, unit_cost, reference_note, created_by, transaction_date)
                    VALUES (:item, :type, :qty, :cost, :note, :user, NOW())
                ");
                $stmt->execute([
                    ':item' => $itemId,
                    ':type' => $transactionType,
                    ':qty'  => $quantity,
                    ':cost' => $unitCost,
                    ':note' => !empty($referenceNote) ? $referenceNote : null,
                    ':user' => $currentUser['user_id']
                ]);

                // Fetch updated stock level after trigger execution
                $afterStock = (int)$pdo->query("SELECT quantity_in_stock FROM inventory WHERE item_id = {$itemId}")->fetchColumn();

                setFlash('success', "Stock transaction [{$transactionType} {$quantity} units] recorded successfully! Database trigger updated stock level from {$beforeStock} to {$afterStock}.");
                header('Location: /westec_bms/inventory/index.php');
                exit;

            } catch (Exception $e) {
                // Catches database trigger rejection (e.g. OUT exceeds quantity_in_stock)
                $errors[] = parseDatabaseErrorMessage($e);
            }
        }
    }
}

// Fetch edit target if requested
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM inventory WHERE item_id = :id");
    $stmt->execute([':id' => $editId]);
    $editItem = $stmt->fetch();
}

// Fetch all inventory items
$inventoryItems = $pdo->query("
    SELECT i.*,
           (SELECT COUNT(*) FROM inventory_transactions it WHERE it.item_id = i.item_id) AS total_transactions
    FROM inventory i
    ORDER BY (i.quantity_in_stock <= i.reorder_level) DESC, i.category ASC, i.item_name ASC
")->fetchAll();

// Fetch recent 10 transactions
$recentTransactions = $pdo->query("
    SELECT t.*, i.sku, i.item_name, u.username AS author
    FROM inventory_transactions t
    JOIN inventory i ON t.item_id = i.item_id
    LEFT JOIN users u ON t.created_by = u.user_id
    ORDER BY t.transaction_date DESC
    LIMIT 10
")->fetchAll();

$pageTitle = 'Inventory Management';
$pageSubtitle = 'IT Hardware Stock, Components, Reorder Monitoring & Trigger-Managed Transactions';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-4 shadow-sm" role="alert">
        <div class="d-flex align-items-center mb-1">
            <i class="bi bi-shield-x me-2 fs-5"></i>
            <strong class="fs-6">Database Validation Rejected Operation:</strong>
        </div>
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $e): ?>
                <li><?= e($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Two-Column Control Row: Record Stock Transaction & Catalog Form -->
<div class="row g-4 mb-4">
    <!-- 1. Stock Movement Transaction Form (Trigger Demonstration) -->
    <div class="col-lg-6">
        <div class="content-section h-100 border-primary">
            <div class="section-header">
                <h2 class="section-title text-primary">
                    <i class="bi bi-arrow-left-right"></i>
                    Record Stock Transaction (Triggers Active)
                </h2>
                <span class="badge bg-primary text-white">Stock Automation</span>
            </div>

            <p class="small text-muted mb-3">
                Stock changes are handled exclusively through <code>inventory_transactions</code>. Database trigger <code>trg_check_inventory_stock_before_insert</code> validates stock availability, and <code>trg_update_inventory_stock_after_insert</code> updates quantities automatically.
            </p>

            <form method="POST" action="/westec_bms/inventory/index.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="record_transaction">

                <div class="row g-3">
                    <div class="col-12">
                        <label for="trans_item_id" class="form-label">Select Equipment / Component <span class="text-danger">*</span></label>
                        <select class="form-select" id="trans_item_id" name="trans_item_id" required>
                            <option value="">-- Select Inventory Item --</option>
                            <?php foreach ($inventoryItems as $item): ?>
                                <option value="<?= $item['item_id'] ?>">
                                    <?= e($item['sku']) ?> &mdash; <?= e($item['item_name']) ?> (Current Stock: <?= $item['quantity_in_stock'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="transaction_type" class="form-label">Transaction Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="transaction_type" name="transaction_type" required>
                            <option value="IN">IN &mdash; Goods Received / Restock (+)</option>
                            <option value="OUT">OUT &mdash; Dispatch to Project / Client (-)</option>
                            <option value="ADJUSTMENT">ADJUSTMENT &mdash; Physical Audit Balance (=)</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="quantity" class="form-label">Quantity <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="quantity" name="quantity" min="1" required placeholder="e.g. 5">
                    </div>

                    <div class="col-md-6">
                        <label for="unit_cost" class="form-label">Unit Cost (E / SZL)</label>
                        <input type="number" step="0.01" class="form-control" id="unit_cost" name="unit_cost" placeholder="0.00">
                    </div>

                    <div class="col-md-6">
                        <label for="reference_note" class="form-label">Reference / Ticket / GRN</label>
                        <input type="text" class="form-control" id="reference_note" name="reference_note" placeholder="e.g. Dispatch for Project #1">
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                    <span class="small text-muted"><i class="bi bi-shield-check me-1 text-success"></i>Trigger validates stock balance</span>
                    <button type="submit" class="btn btn-westec px-4">
                        <i class="bi bi-check2-circle me-1"></i> Post Transaction
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 2. Catalog Definition Form (Add / Edit Item Details) -->
    <div class="col-lg-6">
        <div class="content-section h-100">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="bi bi-box-seam text-dark"></i>
                    <?= $editItem ? 'Edit Catalog Item: ' . e($editItem['sku']) : 'Add New Catalog Item' ?>
                </h2>
                <?php if ($editItem): ?>
                    <a href="/westec_bms/inventory/index.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-x-circle me-1"></i> Cancel Edit
                    </a>
                <?php endif; ?>
            </div>

            <form method="POST" action="/westec_bms/inventory/index.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="<?= $editItem ? 'edit_item' : 'add_item' ?>">
                <?php if ($editItem): ?>
                    <input type="hidden" name="item_id" value="<?= $editItem['item_id'] ?>">
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="sku" class="form-label">Item SKU Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="sku" name="sku" required value="<?= e($editItem['sku'] ?? '') ?>" placeholder="e.g. NET-CIS-9200L" style="text-transform: uppercase;">
                    </div>

                    <div class="col-md-6">
                        <label for="category" class="form-label">Category <span class="text-danger">*</span></label>
                        <select class="form-select" id="category" name="category" required>
                            <?php 
                            $cats = ['Networking Hardware', 'Wireless Solutions', 'Cabling & Structured Cabling', 'Server Hardware', 'Network Security', 'Rack Accessories', 'Software Licenses', 'Peripherals'];
                            $currCat = $editItem['category'] ?? 'Networking Hardware';
                            foreach ($cats as $c): ?>
                                <option value="<?= $c ?>" <?= ($currCat === $c) ? 'selected' : '' ?>><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <label for="item_name" class="form-label">Item Name / Specification <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="item_name" name="item_name" required value="<?= e($editItem['item_name'] ?? '') ?>" placeholder="e.g. Cisco Catalyst 9200L 48-Port PoE+ Gigabit Switch">
                    </div>

                    <div class="col-md-6">
                        <label for="unit_price" class="form-label">Selling / Unit Price (E)</label>
                        <input type="number" step="0.01" class="form-control" id="unit_price" name="unit_price" value="<?= e($editItem['unit_price'] ?? '0.00') ?>" placeholder="0.00">
                    </div>

                    <div class="col-md-6">
                        <label for="reorder_level" class="form-label">Reorder Level Alert Threshold</label>
                        <input type="number" class="form-control" id="reorder_level" name="reorder_level" value="<?= e($editItem['reorder_level'] ?? 5) ?>" min="0">
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                    <small class="text-muted"><i class="bi bi-info-circle me-1"></i>New items start at 0 stock</small>
                    <button type="submit" class="btn btn-outline-dark px-4">
                        <i class="bi bi-save me-1"></i> <?= $editItem ? 'Save Item Changes' : 'Register Catalog Item' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Stock Inventory Catalog Table -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i class="bi bi-boxes text-primary"></i>
            WesTec Inventory Catalog & Live Stock Balances
        </h2>
        <div class="input-group input-group-sm" style="max-width: 250px;">
            <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
            <input type="text" class="form-control" placeholder="Search catalog..." data-table-filter="inventoryTable">
        </div>
    </div>

    <div class="table-responsive">
        <table class="table-westec" id="inventoryTable">
            <thead>
                <tr>
                    <th>SKU Code</th>
                    <th>Equipment Description</th>
                    <th>Category</th>
                    <th class="text-end">Unit Price</th>
                    <th class="text-center">Stock Level</th>
                    <th class="text-center">Reorder Lvl</th>
                    <th class="text-center">Stock Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($inventoryItems)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No inventory items found. Add items to catalog above.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($inventoryItems as $item): 
                        $isLowStock = ($item['quantity_in_stock'] <= $item['reorder_level']);
                    ?>
                        <tr class="<?= $isLowStock ? 'table-warning bg-opacity-25' : '' ?>">
                            <td><strong class="text-dark font-monospace"><?= e($item['sku']) ?></strong></td>
                            <td>
                                <strong class="text-dark d-block"><?= e($item['item_name']) ?></strong>
                                <small class="text-muted"><?= $item['total_transactions'] ?> transaction(s) recorded</small>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= e($item['category']) ?></span></td>
                            <td class="text-end fw-semibold"><?= formatCurrency($item['unit_price']) ?></td>
                            <td class="text-center">
                                <span class="fs-6 fw-bold <?= $isLowStock ? 'text-danger' : 'text-success' ?>">
                                    <?= $item['quantity_in_stock'] ?>
                                </span>
                            </td>
                            <td class="text-center text-muted"><?= $item['reorder_level'] ?></td>
                            <td class="text-center">
                                <?php if ($item['quantity_in_stock'] <= 0): ?>
                                    <span class="badge bg-danger">OUT OF STOCK</span>
                                <?php elseif ($isLowStock): ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>REORDER</span>
                                <?php else: ?>
                                    <span class="badge bg-success">IN STOCK</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="/westec_bms/inventory/index.php?edit=<?= $item['item_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edit Catalog Details">
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

<!-- Recent Transactions Log -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i class="bi bi-clock-history text-secondary"></i>
            Recent Stock Movement Audit Ledger (inventory_transactions)
        </h2>
        <span class="badge bg-light text-muted border">Automatic Trigger Execution Log</span>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-striped small mb-0">
            <thead>
                <tr class="text-muted">
                    <th>TX ID</th>
                    <th>Date / Time</th>
                    <th>Item SKU & Name</th>
                    <th class="text-center">Type</th>
                    <th class="text-center">Quantity</th>
                    <th class="text-end">Unit Cost</th>
                    <th>Reference / Notes</th>
                    <th>Operator</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentTransactions)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-3">No inventory transactions logged yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentTransactions as $tx): ?>
                        <tr>
                            <td><span class="badge bg-dark">#<?= $tx['transaction_id'] ?></span></td>
                            <td><?= formatDate($tx['transaction_date'], 'd M Y, H:i') ?></td>
                            <td>
                                <strong><?= e($tx['sku']) ?></strong> &mdash; <?= e($tx['item_name']) ?>
                            </td>
                            <td class="text-center">
                                <?php if ($tx['transaction_type'] === 'IN'): ?>
                                    <span class="badge bg-success"><i class="bi bi-arrow-down-left me-1"></i>IN</span>
                                <?php elseif ($tx['transaction_type'] === 'OUT'): ?>
                                    <span class="badge bg-danger"><i class="bi bi-arrow-up-right me-1"></i>OUT</span>
                                <?php else: ?>
                                    <span class="badge bg-info text-dark">ADJUST</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center fw-bold"><?= $tx['quantity'] ?></td>
                            <td class="text-end"><?= formatCurrency($tx['unit_cost']) ?></td>
                            <td><?= e($tx['reference_note'] ?? '—') ?></td>
                            <td><span class="badge bg-light text-dark border"><?= e($tx['author'] ?? 'System') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
