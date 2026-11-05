<?php
/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Inventory Management & Trigger-Driven Stock Transactions
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

// ── Access Control ──────────────────────────────────────────────────────────
// Admins & IT Managers always get full access.
// All other roles require an explicit grant from the manager.
$invPerms = getInventoryPermissions();

if (!$invPerms['can_view']) {
    setFlash('danger', 'Access Denied: You do not have permission to access the Inventory module. Please contact your IT Manager.');
    header('Location: /westec_bms/dashboard/index.php');
    exit;
}

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
        if (!$invPerms['can_add_item']) {
            setFlash('danger', 'Access Denied: You do not have permission to add catalog items.');
            header('Location: /westec_bms/inventory/index.php');
            exit;
        }

        $sku            = strtoupper(cleanInput($_POST['sku'] ?? ''));
        $itemName       = cleanInput($_POST['item_name'] ?? '');
        $categorySelect = cleanInput($_POST['category_select'] ?? $_POST['category'] ?? '');
        $customCategory = cleanInput($_POST['custom_category'] ?? '');
        $category       = ($categorySelect === '__NEW__') ? $customCategory : (!empty($categorySelect) ? $categorySelect : $customCategory);
        $unitPrice      = (float)($_POST['unit_price'] ?? 0);
        $reorderLevel   = (int)($_POST['reorder_level'] ?? 5);
        $initialStock   = max(0, (int)($_POST['initial_stock'] ?? 0));

        if (empty($sku)) {
            $errors[] = 'SKU (Stock Keeping Unit) is required.';
        }
        if (empty($itemName)) {
            $errors[] = 'Item description / model name is required.';
        }
        if (empty($category)) {
            $errors[] = 'Category is required. Please select or type a category.';
        }
        if ($unitPrice < 0) {
            $errors[] = 'Unit price cannot be negative.';
        }
        if ($reorderLevel < 0) {
            $errors[] = 'Reorder level cannot be negative.';
        }

        if (empty($errors)) {
            try {
                // Insert new catalog item
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

                // If starting stock is specified, record initial IN transaction (triggers update stock autonomously)
                if ($initialStock > 0 && $invPerms['can_record_transaction']) {
                    $txStmt = $pdo->prepare("
                        INSERT INTO inventory_transactions (item_id, transaction_type, quantity, unit_cost, reference_note, created_by, transaction_date)
                        VALUES (:item, 'IN', :qty, :cost, :note, :user, NOW())
                    ");
                    $txStmt->execute([
                        ':item' => $newId,
                        ':qty'  => $initialStock,
                        ':cost' => $unitPrice,
                        ':note' => 'Initial inventory stock registered',
                        ':user' => $currentUser['user_id']
                    ]);
                    setFlash('success', "Item '{$itemName}' ({$sku}) added to catalog with {$initialStock} units in stock.");
                } else {
                    setFlash('success', "Item '{$itemName}' ({$sku}) added to catalog.");
                }

                header('Location: /westec_bms/inventory/index.php');
                exit;
            } catch (Exception $e) {
                $errors[] = parseDatabaseErrorMessage($e);
            }
        }
    }

    // 2. EDIT CATALOG ITEM DETAILS (Stock count CANNOT be altered here - rule compliance)
    elseif ($action === 'edit_item') {
        if (!$invPerms['can_edit_item']) {
            setFlash('danger', 'Access Denied: You do not have permission to edit catalog items.');
            header('Location: /westec_bms/inventory/index.php');
            exit;
        }

        $itemId         = (int)($_POST['item_id'] ?? 0);
        $sku            = strtoupper(cleanInput($_POST['sku'] ?? ''));
        $itemName       = cleanInput($_POST['item_name'] ?? '');
        $categorySelect = cleanInput($_POST['category_select'] ?? $_POST['category'] ?? '');
        $customCategory = cleanInput($_POST['custom_category'] ?? '');
        $category       = ($categorySelect === '__NEW__') ? $customCategory : (!empty($categorySelect) ? $categorySelect : $customCategory);
        $unitPrice      = (float)($_POST['unit_price'] ?? 0);
        $reorderLevel   = (int)($_POST['reorder_level'] ?? 5);
        $addStockQty    = max(0, (int)($_POST['add_stock_qty'] ?? 0));
        $stockNote      = cleanInput($_POST['stock_note'] ?? '');

        if ($itemId <= 0) {
            $errors[] = 'Invalid item identifier.';
        }
        if (empty($sku)) {
            $errors[] = 'SKU is required.';
        }
        if (empty($itemName)) {
            $errors[] = 'Item name is required.';
        }
        if (empty($category)) {
            $errors[] = 'Category is required. Please select or type a category.';
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

                // If stock was added during edit, record an IN transaction
                if ($addStockQty > 0 && $invPerms['can_record_transaction']) {
                    $txStmt = $pdo->prepare("
                        INSERT INTO inventory_transactions (item_id, transaction_type, quantity, unit_cost, reference_note, created_by, transaction_date)
                        VALUES (:item, 'IN', :qty, :cost, :note, :user, NOW())
                    ");
                    $txStmt->execute([
                        ':item' => $itemId,
                        ':qty'  => $addStockQty,
                        ':cost' => $unitPrice,
                        ':note' => !empty($stockNote) ? $stockNote : 'Stock added during catalog item edit',
                        ':user' => $currentUser['user_id']
                    ]);
                    setFlash('success', "Catalog details updated and +{$addStockQty} units added to stock for '{$itemName}'.");
                } else {
                    setFlash('success', "Catalog details for '{$itemName}' updated successfully.");
                }

                header('Location: /westec_bms/inventory/index.php');
                exit;
            } catch (Exception $e) {
                $errors[] = parseDatabaseErrorMessage($e);
            }
        }
    }

    // 3. RECORD INVENTORY TRANSACTION (Trigger trg_check_inventory_stock_before_insert & trg_update_inventory_stock_after_insert)
    elseif ($action === 'record_transaction') {
        if (!$invPerms['can_record_transaction']) {
            setFlash('danger', 'Access Denied: You do not have permission to record stock transactions.');
            header('Location: /westec_bms/inventory/index.php');
            exit;
        }

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

// Fetch edit target if requested (only if user can edit)
if (isset($_GET['edit']) && $invPerms['can_edit_item']) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM inventory WHERE item_id = :id");
    $stmt->execute([':id' => $editId]);
    $editItem = $stmt->fetch();
} elseif (isset($_GET['edit']) && !$invPerms['can_edit_item']) {
    setFlash('warning', 'You do not have permission to edit catalog items.');
}

// Fetch all inventory items
$inventoryItems = $pdo->query("
    SELECT i.*,
           (SELECT COUNT(*) FROM inventory_transactions it WHERE it.item_id = i.item_id) AS total_transactions
    FROM inventory i
    ORDER BY (i.quantity_in_stock <= i.reorder_level) DESC, i.category ASC, i.item_name ASC
")->fetchAll();

// Fetch distinct categories already in the DB (so custom ones reappear in the list)
$existingCats = $pdo->query("SELECT DISTINCT category FROM inventory WHERE category IS NOT NULL AND category != '' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

$defaultCats = [
    'Networking Hardware',
    'Wireless Solutions',
    'Cabling & Structured Cabling',
    'Server Hardware',
    'Network Security',
    'Rack Accessories',
    'Software Licenses',
    'Peripherals'
];
$allCategories = array_values(array_unique(array_filter(array_merge($defaultCats, $existingCats))));
sort($allCategories);

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
            <i data-lucide="shield-x" class="me-2 fs-5"></i>
            <strong class="fs-6">Database Validation Rejected Operation:</strong>
        </div>
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $e): ?>
                <li><?= e($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Inventory Access Permission Summary Bar -->
<div class="content-section p-3 mb-4 d-flex flex-wrap align-items-center gap-2">
    <span class="fw-semibold text-muted small me-2"><i data-lucide="shield" class="me-1"></i>Your Inventory Access:</span>
    <span class="badge <?= $invPerms['can_view']               ? 'bg-success' : 'bg-secondary' ?> px-3 py-2">
        <i data-lucide="eye" class="me-1"></i>View Catalog
    </span>
    <span class="badge <?= $invPerms['can_record_transaction'] ? 'bg-success' : 'bg-secondary' ?> px-3 py-2">
        <i data-lucide="arrow-left-right" class="me-1"></i>Record Transactions
    </span>
    <span class="badge <?= $invPerms['can_add_item']           ? 'bg-success' : 'bg-secondary' ?> px-3 py-2">
        <i data-lucide="plus-circle" class="me-1"></i>Add Items
    </span>
    <span class="badge <?= $invPerms['can_edit_item']          ? 'bg-success' : 'bg-secondary' ?> px-3 py-2">
        <i data-lucide="pencil" class="me-1"></i>Edit Items
    </span>
    <?php if (!hasRole(['Administrator', 'IT Manager'])): ?>
        <span class="ms-auto small text-muted"><i data-lucide="info" class="me-1"></i>Contact your IT Manager to modify these permissions.</span>
    <?php endif; ?>
</div>

<!-- Two-Column Control Row: Record Stock Transaction & Catalog Form -->
<div class="row g-4 mb-4">
    <!-- 1. Stock Movement Transaction Form (Trigger Demonstration) -->
    <div class="<?= ($invPerms['can_record_transaction'] && ($invPerms['can_add_item'] || $invPerms['can_edit_item'])) ? 'col-lg-6' : 'col-12' ?>">
        <div class="content-section h-100 <?= $invPerms['can_record_transaction'] ? 'border-primary' : '' ?>">
            <div class="section-header">
                <h2 class="section-title <?= $invPerms['can_record_transaction'] ? 'text-primary' : 'text-muted' ?>">
                    <i data-lucide="arrow-left-right"></i>
                    Record Stock Transaction
                </h2>
                <?php if ($invPerms['can_record_transaction']): ?>
                    <span class="badge bg-primary text-white">Stock Automation</span>
                <?php else: ?>
                    <span class="badge bg-secondary"><i data-lucide="lock" class="me-1"></i>Access Restricted</span>
                <?php endif; ?>
            </div>

            <p class="small text-muted mb-3">
                Stock movements are recorded as auditable transactions. Available stock levels and inventory balances are automatically updated upon submission.
            </p>

            <?php if ($invPerms['can_record_transaction']): ?>
            <form method="POST" action="/westec_bms/inventory/index.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="action" value="record_transaction">

                <div class="row g-3">
                    <div class="col-12">
                        <label for="trans_item_id" class="form-label">Select Equipment / Component <span class="text-danger">*</span></label>
                        <?php $selectedTransItem = (int)($_GET['trans_item_id'] ?? $_GET['edit'] ?? 0); ?>
                        <select class="form-select" id="trans_item_id" name="trans_item_id" required>
                            <option value="">-- Select Inventory Item --</option>
                            <?php foreach ($inventoryItems as $item): ?>
                                <option value="<?= $item['item_id'] ?>" <?= ($selectedTransItem === (int)$item['item_id']) ? 'selected' : '' ?>>
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
                    <span class="small text-muted"><i data-lucide="shield-check" class="me-1 text-success"></i>Stock balance validated automatically</span>
                    <button type="submit" class="btn btn-westec px-4">
                        <i data-lucide="check-circle-2" class="me-1"></i> Post Transaction
                    </button>
                </div>
            </form>
            <?php else: ?>
            <div class="text-center py-4">
                <i data-lucide="lock" class="fs-1 text-secondary opacity-50 d-block mb-3"></i>
                <p class="text-muted mb-1">You do not have permission to record stock transactions.</p>
                <small class="text-muted">Contact your IT Manager to request access.</small>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 2. Catalog Definition Form (Add / Edit Item Details) -->
    <?php if ($invPerms['can_add_item'] || $invPerms['can_edit_item']): ?>
    <div class="<?= ($invPerms['can_record_transaction']) ? 'col-lg-6' : 'col-12' ?>">
        <div class="content-section h-100">
            <div class="section-header">
                <h2 class="section-title">
                    <i data-lucide="package" class="text-dark"></i>
                    <?= $editItem ? 'Edit Catalog Item: ' . e($editItem['sku']) : 'Add New Catalog Item' ?>
                </h2>
                <?php if ($editItem): ?>
                    <div class="d-flex gap-2">
                        <a href="/westec_bms/inventory/index.php" class="btn btn-sm btn-westec">
                            <i data-lucide="plus-circle" class="me-1"></i> Add New Item
                        </a>
                        <a href="/westec_bms/inventory/index.php" class="btn btn-sm btn-outline-secondary">
                            <i data-lucide="x-circle" class="me-1"></i> Cancel
                        </a>
                    </div>
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
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="category_select" class="form-label mb-0">Category <span class="text-danger">*</span></label>
                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none text-primary" id="toggleCategoryBtn" style="font-size: 0.82rem;">
                                <span id="toggleCategoryLabel"><i data-lucide="plus-circle" class="me-1" style="width:13px;height:13px;"></i>Type new category</span>
                            </button>
                        </div>
                        
                        <?php 
                        $currCat = $editItem['category'] ?? 'Networking Hardware';
                        $isCustomCat = (!empty($currCat) && !in_array($currCat, $allCategories));
                        ?>
                        
                        <div id="categorySelectBox">
                            <select class="form-select" id="category_select" name="category_select" required>
                                <option value="">-- Select Category --</option>
                                <?php foreach ($allCategories as $c): ?>
                                    <option value="<?= e($c) ?>" <?= (!$isCustomCat && $currCat === $c) ? 'selected' : '' ?>><?= e($c) ?></option>
                                <?php endforeach; ?>
                                <option value="__NEW__" <?= $isCustomCat ? 'selected' : '' ?>>+ Other (Type new category...)</option>
                            </select>
                        </div>

                        <div id="categoryInputBox" class="<?= $isCustomCat ? '' : 'd-none' ?> mt-2">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light text-muted"><i data-lucide="tag" style="width:14px;height:14px;"></i></span>
                                <input type="text" class="form-control" id="custom_category" name="custom_category" placeholder="Type new category name..." value="<?= $isCustomCat ? e($currCat) : '' ?>">
                                <button type="button" class="btn btn-outline-secondary" id="cancelCustomCatBtn" title="Back to existing categories list">
                                    <i data-lucide="list" class="me-1" style="width:14px;height:14px;"></i>List
                                </button>
                            </div>
                            <small class="text-muted" style="font-size: 0.78rem;">Type what you are adding if it is not in the list.</small>
                        </div>
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

                    <?php if ($editItem): ?>
                        <div class="col-md-6">
                            <label for="add_stock_qty" class="form-label">Add Stock / Inventory Units (+)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted">
                                    Stock: <strong class="ms-1 <?= ($editItem['quantity_in_stock'] <= $editItem['reorder_level']) ? 'text-danger' : 'text-success' ?>"><?= (int)$editItem['quantity_in_stock'] ?></strong>
                                </span>
                                <input type="number" class="form-control" id="add_stock_qty" name="add_stock_qty" min="0" placeholder="Units to add (e.g. 10)">
                            </div>
                            <small class="text-muted">Enter quantity to increase stock for this item</small>
                        </div>
                    <?php else: ?>
                        <div class="col-md-6">
                            <label for="initial_stock" class="form-label">Initial Stock Quantity</label>
                            <input type="number" class="form-control" id="initial_stock" name="initial_stock" value="0" min="0" placeholder="0">
                            <small class="text-muted">Starting inventory units upon creation</small>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                    <small class="text-muted">
                        <i data-lucide="info" class="me-1"></i>
                        <?= $editItem ? 'Adding stock units will automatically log a transaction' : 'Initial stock will be received automatically' ?>
                    </small>
                    <button type="submit" class="btn btn-westec px-4" <?= ($editItem && !$invPerms['can_edit_item']) || (!$editItem && !$invPerms['can_add_item']) ? 'disabled' : '' ?>>
                        <i data-lucide="save" class="me-1"></i> <?= $editItem ? 'Save Item Changes' : 'Register Catalog Item' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Stock Inventory Catalog Table -->
<div class="content-section">
    <div class="section-header">
        <h2 class="section-title">
            <i data-lucide="boxes" class="text-primary"></i>
            WesTec Inventory Catalog & Live Stock Balances
        </h2>
        <div class="d-flex gap-2 align-items-center">
            <?php if ($invPerms['can_add_item']): ?>
                <a href="/westec_bms/inventory/index.php" class="btn btn-sm btn-westec">
                    <i data-lucide="plus-circle" class="me-1"></i> Add Inventory Item
                </a>
            <?php endif; ?>
            <div class="input-group input-group-sm" style="max-width: 250px;">
                <span class="input-group-text bg-light"><i data-lucide="search"></i></span>
                <input type="text" class="form-control" placeholder="Search catalog..." data-table-filter="inventoryTable">
            </div>
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
                                    <span class="badge bg-warning text-dark"><i data-lucide="alert-triangle" class="me-1"></i>REORDER</span>
                                <?php else: ?>
                                    <span class="badge bg-success">IN STOCK</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="d-flex gap-1 justify-content-end">
                                    <?php if ($invPerms['can_record_transaction']): ?>
                                        <a href="/westec_bms/inventory/index.php?trans_item_id=<?= $item['item_id'] ?>#trans_item_id" class="btn btn-sm btn-outline-success py-0 px-2" title="Record Stock Movement / Transaction">
                                            <i data-lucide="plus" class="me-1"></i>Stock
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($invPerms['can_edit_item']): ?>
                                        <a href="/westec_bms/inventory/index.php?edit=<?= $item['item_id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Edit Catalog Details">
                                            <i data-lucide="file-edit"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="btn btn-sm btn-outline-secondary disabled py-0 px-2" title="Edit access not granted">
                                            <i data-lucide="lock"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
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
            <i data-lucide="history" class="text-secondary"></i>
            Recent Stock Movement Ledger
        </h2>
        <span class="badge bg-light text-muted border">Transaction History</span>
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
                                    <span class="badge bg-success"><i data-lucide="arrow-down-left" class="me-1"></i>IN</span>
                                <?php elseif ($tx['transaction_type'] === 'OUT'): ?>
                                    <span class="badge bg-danger"><i data-lucide="arrow-up-right" class="me-1"></i>OUT</span>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const catSelect = document.getElementById('category_select');
    const catInputBox = document.getElementById('categoryInputBox');
    const customCatInput = document.getElementById('custom_category');
    const toggleBtn = document.getElementById('toggleCategoryBtn');
    const toggleLabel = document.getElementById('toggleCategoryLabel');
    const cancelBtn = document.getElementById('cancelCustomCatBtn');

    if (!catSelect || !catInputBox) return;

    function showCustomInput(focus = true) {
        catInputBox.classList.remove('d-none');
        if (catSelect.value !== '__NEW__') {
            catSelect.value = '__NEW__';
        }
        if (customCatInput) {
            customCatInput.required = true;
            if (focus) customCatInput.focus();
        }
        if (toggleLabel) {
            toggleLabel.innerHTML = '<i data-lucide="list" class="me-1" style="width:13px;height:13px;"></i>Choose from list';
            if (window.lucide) lucide.createIcons();
        }
    }

    function showSelectList() {
        catInputBox.classList.add('d-none');
        if (customCatInput) {
            customCatInput.required = false;
            customCatInput.value = '';
        }
        if (catSelect.value === '__NEW__') {
            catSelect.value = catSelect.options[1] ? catSelect.options[1].value : '';
        }
        if (toggleLabel) {
            toggleLabel.innerHTML = '<i data-lucide="plus-circle" class="me-1" style="width:13px;height:13px;"></i>Type new category';
            if (window.lucide) lucide.createIcons();
        }
    }

    catSelect.addEventListener('change', function () {
        if (this.value === '__NEW__') {
            showCustomInput(true);
        } else {
            catInputBox.classList.add('d-none');
            if (customCatInput) {
                customCatInput.required = false;
                customCatInput.value = '';
            }
            if (toggleLabel) {
                toggleLabel.innerHTML = '<i data-lucide="plus-circle" class="me-1" style="width:13px;height:13px;"></i>Type new category';
                if (window.lucide) lucide.createIcons();
            }
        }
    });

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            if (catInputBox.classList.contains('d-none')) {
                showCustomInput(true);
            } else {
                showSelectList();
            }
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            showSelectList();
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
