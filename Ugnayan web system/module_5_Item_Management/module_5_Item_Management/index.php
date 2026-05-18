<?php
require_once __DIR__ . '/../../module_1_IMVN Module/includes/auth.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/module_helpers.php';

$isAdmin = ugnayan_is_admin_role();
$resident = ugnayan_get_resident($pdo);
$residentId = $resident ? (int) $resident['resident_id'] : null;
$conditions = ['Good', 'Needs Repair', 'Damaged'];
$borrowStatuses = ['Pending', 'Approved', 'Rejected', 'Borrowed', 'Returned', 'Overdue'];
$activeBorrowStatuses = ['Approved', 'Borrowed', 'Overdue'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_item') {
            ugnayan_require_admin_access();

            $itemId = ugnayan_int('item_id');
            $total = max(0, ugnayan_int('total_quantity'));
            $available = max(0, min($total, ugnayan_int('available_quantity', $total)));
            $limit = ugnayan_trim('borrowing_limit') === '' ? null : max(1, ugnayan_int('borrowing_limit'));

            if ($itemId > 0) {
                $borrowed = max(0, $total - $available);
                $stmt = $pdo->prepare('UPDATE inventory_items SET item_name=?, description=?, total_quantity=?, available_quantity=?, borrowed_quantity=?, condition_status=?, borrowing_limit=? WHERE item_id=?');
                $stmt->execute([
                    ugnayan_trim('item_name'),
                    ugnayan_trim('description'),
                    $total,
                    $available,
                    $borrowed,
                    ugnayan_trim('condition_status', 'Good'),
                    $limit,
                    $itemId,
                ]);
                log_action($pdo, ugnayan_user_id(), 'Updated Inventory Item', ugnayan_trim('item_name'));
                ugnayan_flash_set('Inventory item updated.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO inventory_items (item_name, description, total_quantity, available_quantity, borrowed_quantity, condition_status, borrowing_limit) VALUES (?, ?, ?, ?, 0, ?, ?)');
                $stmt->execute([
                    ugnayan_trim('item_name'),
                    ugnayan_trim('description'),
                    $total,
                    $available,
                    ugnayan_trim('condition_status', 'Good'),
                    $limit,
                ]);
                log_action($pdo, ugnayan_user_id(), 'Created Inventory Item', ugnayan_trim('item_name'));
                ugnayan_flash_set('Inventory item created.');
            }
        } elseif ($action === 'delete_item') {
            ugnayan_require_admin_access();

            $itemId = ugnayan_int('item_id');
            $pdo->prepare('DELETE FROM inventory_items WHERE item_id=?')->execute([$itemId]);
            log_action($pdo, ugnayan_user_id(), 'Deleted Inventory Item', 'Item #' . $itemId);
            ugnayan_flash_set('Inventory item deleted.');
        } elseif ($action === 'save_maintenance') {
            ugnayan_require_admin_access();

            $maintenanceId = ugnayan_int('maintenance_id');

            if ($maintenanceId > 0) {
                $stmt = $pdo->prepare('UPDATE item_maintenance SET item_id=?, issue_description=?, maintenance_status=?, maintenance_date=?, cost=? WHERE maintenance_id=?');
                $stmt->execute([ugnayan_int('item_id'), ugnayan_trim('issue_description'), ugnayan_trim('maintenance_status', 'Pending'), ugnayan_trim('maintenance_date') ?: null, (float) ($_POST['cost'] ?? 0), $maintenanceId]);
                ugnayan_flash_set('Maintenance record updated.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO item_maintenance (item_id, issue_description, maintenance_status, maintenance_date, cost) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([ugnayan_int('item_id'), ugnayan_trim('issue_description'), ugnayan_trim('maintenance_status', 'Pending'), ugnayan_trim('maintenance_date') ?: null, (float) ($_POST['cost'] ?? 0)]);
                ugnayan_flash_set('Maintenance record created.');
            }

            log_action($pdo, ugnayan_user_id(), 'Saved Item Maintenance', 'Item #' . ugnayan_int('item_id'));
        } elseif ($action === 'delete_maintenance') {
            ugnayan_require_admin_access();

            $maintenanceId = ugnayan_int('maintenance_id');
            $pdo->prepare('DELETE FROM item_maintenance WHERE maintenance_id=?')->execute([$maintenanceId]);
            log_action($pdo, ugnayan_user_id(), 'Deleted Item Maintenance', 'Maintenance #' . $maintenanceId);
            ugnayan_flash_set('Maintenance record deleted.');
        } elseif ($action === 'request_borrow') {
            if (!$residentId) {
                ugnayan_require_admin_access();
            }

            $itemId = ugnayan_int('item_id');
            $quantity = max(1, ugnayan_int('quantity', 1));
            $stmt = $pdo->prepare('SELECT * FROM inventory_items WHERE item_id=?');
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();

            if (!$item) {
                throw new RuntimeException('Item not found.');
            }

            if ($quantity > (int) $item['available_quantity']) {
                throw new RuntimeException('Requested quantity is not available.');
            }

            if ($item['borrowing_limit'] !== null && $quantity > (int) $item['borrowing_limit']) {
                throw new RuntimeException('Requested quantity exceeds the borrowing limit.');
            }

            $stmt = $pdo->prepare('INSERT INTO item_borrow_requests (item_id, resident_id, quantity, purpose, borrow_date, return_date, status) VALUES (?, ?, ?, ?, ?, ?, "Pending")');
            $stmt->execute([
                $itemId,
                $residentId,
                $quantity,
                ugnayan_trim('purpose'),
                ugnayan_trim('borrow_date'),
                ugnayan_trim('return_date'),
            ]);

            ugnayan_activity($pdo, $residentId, 'Borrow Request', 'Requested to borrow ' . $quantity . ' ' . $item['item_name'] . '.');
            log_action($pdo, ugnayan_user_id(), 'Submitted Borrow Request', $item['item_name']);
            ugnayan_flash_set('Borrow request submitted.');
        } elseif ($action === 'update_borrow_status') {
            ugnayan_require_admin_access();

            $borrowId = ugnayan_int('borrow_id');
            $newStatus = ugnayan_trim('status', 'Pending');
            $remarks = ugnayan_trim('remarks');

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT b.*, i.item_name, i.available_quantity, i.borrowed_quantity, r.user_id FROM item_borrow_requests b JOIN inventory_items i ON i.item_id=b.item_id JOIN residents r ON r.resident_id=b.resident_id WHERE b.borrow_id=? FOR UPDATE');
            $stmt->execute([$borrowId]);
            $request = $stmt->fetch();

            if (!$request) {
                throw new RuntimeException('Borrow request not found.');
            }

            $oldActive = in_array($request['status'], $activeBorrowStatuses, true);
            $newActive = in_array($newStatus, $activeBorrowStatuses, true);
            $quantity = (int) $request['quantity'];

            if (!$oldActive && $newActive) {
                if ((int) $request['available_quantity'] < $quantity) {
                    throw new RuntimeException('Not enough available quantity to approve this request.');
                }
                $pdo->prepare('UPDATE inventory_items SET available_quantity=available_quantity-?, borrowed_quantity=borrowed_quantity+? WHERE item_id=?')->execute([$quantity, $quantity, $request['item_id']]);
            } elseif ($oldActive && !$newActive) {
                $pdo->prepare('UPDATE inventory_items SET available_quantity=available_quantity+?, borrowed_quantity=GREATEST(borrowed_quantity-?, 0) WHERE item_id=?')->execute([$quantity, $quantity, $request['item_id']]);
            }

            $pdo->prepare('UPDATE item_borrow_requests SET status=?, approved_by=?, remarks=? WHERE borrow_id=?')->execute([$newStatus, ugnayan_user_id(), $remarks, $borrowId]);
            $pdo->commit();

            $message = 'Your request to borrow ' . $request['item_name'] . ' is now ' . $newStatus . '. ' . $remarks;
            ugnayan_notify_user($pdo, (int) $request['user_id'], 'Borrow Request Updated', $message, 'request_update');
            ugnayan_activity($pdo, (int) $request['resident_id'], 'Borrow Request Updated', $message);
            log_action($pdo, ugnayan_user_id(), 'Updated Borrow Request', 'Borrow #' . $borrowId . ' set to ' . $newStatus);
            ugnayan_flash_set('Borrow request updated.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        ugnayan_flash_set('Action failed: ' . $e->getMessage(), 'error');
    }

    ugnayan_redirect_here('#inventory');
}

$flash = ugnayan_flash_get();
$items = $pdo->query('SELECT * FROM inventory_items ORDER BY item_name')->fetchAll();
$maintenanceRecords = $isAdmin ? $pdo->query('SELECT m.*, i.item_name FROM item_maintenance m JOIN inventory_items i ON i.item_id=m.item_id ORDER BY m.maintenance_id DESC')->fetchAll() : [];

if ($isAdmin) {
    $stmt = $pdo->query('SELECT b.*, i.item_name, CONCAT(r.first_name, " ", r.last_name) AS resident_name, r.purok FROM item_borrow_requests b JOIN inventory_items i ON i.item_id=b.item_id JOIN residents r ON r.resident_id=b.resident_id ORDER BY b.borrow_id DESC');
} else {
    $stmt = $pdo->prepare('SELECT b.*, i.item_name, CONCAT(r.first_name, " ", r.last_name) AS resident_name, r.purok FROM item_borrow_requests b JOIN inventory_items i ON i.item_id=b.item_id JOIN residents r ON r.resident_id=b.resident_id WHERE b.resident_id=? ORDER BY b.borrow_id DESC');
    $stmt->execute([$residentId]);
}

$borrowRequests = $stmt->fetchAll();
$totalItems = count($items);
$availableItems = ugnayan_sum_by($items, 'available_quantity');
$borrowedItems = ugnayan_sum_by($items, 'borrowed_quantity');
$damagedItems = ugnayan_count_by($items, 'condition_status', 'Damaged');
$pendingBorrows = ugnayan_count_by($borrowRequests, 'status', 'Pending');
$recentItems = array_slice($items, 0, 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UGNAYAN - Item Management</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/module-5.css">
</head>
<body>
    <?php render_ugnayan_sidebar('items', [
        'module_label' => 'Module 5',
        'brand_icon' => 'fa-boxes-stacked',
    ]); ?>
    <main class="main-content">
        <header class="topbar">
            <div class="topbar-title"><h2>Item Management Module</h2><p><?= $isAdmin ? 'CRUD inventory and manage borrowing requests' : 'View inventory and request to borrow available items' ?></p></div>
            <div class="topbar-actions"><?php render_ugnayan_topbar_tools($pdo); ?></div>
        </header>
        <section class="module-content">
            <?php if ($flash): ?><div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div><?php endif; ?>

            <div class="stats-grid" id="summary">
                <div class="stat-card"><div class="stat-title">Inventory Items</div><div class="stat-value"><?= $totalItems ?></div><div class="stat-subtext">Item types registered</div></div>
                <div class="stat-card"><div class="stat-title">Available Qty</div><div class="stat-value"><?= $availableItems ?></div><div class="stat-subtext">Ready to borrow</div></div>
                <div class="stat-card"><div class="stat-title">Pending Requests</div><div class="stat-value"><?= $pendingBorrows ?></div><div class="stat-subtext"><?= $isAdmin ? 'Need approval' : 'Your pending requests' ?></div></div>
            </div>

            <?php if ($isAdmin): ?>
                <div class="panel">
                    <div class="panel-header"><h3>Inventory Reports</h3></div>
                    <div class="report-grid">
                        <div class="report-tile"><span class="muted">Borrowed Quantity</span><strong><?= (int) $borrowedItems ?></strong></div>
                        <div class="report-tile"><span class="muted">Damaged Items</span><strong><?= (int) $damagedItems ?></strong></div>
                        <div class="report-tile"><span class="muted">Maintenance Records</span><strong><?= count($maintenanceRecords) ?></strong></div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="management-grid" id="inventory">
                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Create Inventory Item' : 'Borrow Item' ?></h3></div>
                    <?php if ($isAdmin): ?>
                        <form method="POST" class="form-grid">
                            <input type="hidden" name="action" value="save_item">
                            <div class="input-group full"><label>Item Name</label><input name="item_name" required maxlength="255"></div>
                            <div class="input-group"><label>Total Quantity</label><input type="number" name="total_quantity" min="0" value="0" required></div>
                            <div class="input-group"><label>Available Quantity</label><input type="number" name="available_quantity" min="0" value="0" required></div>
                            <div class="input-group"><label>Condition</label><select name="condition_status"><?php foreach ($conditions as $condition): ?><option><?= htmlspecialchars($condition) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Borrowing Limit</label><input type="number" name="borrowing_limit" min="1" placeholder="Optional"></div>
                            <div class="input-group full"><label>Description</label><textarea name="description"></textarea></div>
                            <div class="form-actions"><button class="btn-primary"><i class="fa-solid fa-plus"></i> Save Item</button></div>
                        </form>
                    <?php else: ?>
                        <form method="POST" class="form-grid">
                            <input type="hidden" name="action" value="request_borrow">
                            <div class="input-group full"><label>Item</label><select name="item_id" required><?php foreach ($items as $item): ?><option value="<?= (int) $item['item_id'] ?>"><?= htmlspecialchars($item['item_name']) ?> (<?= (int) $item['available_quantity'] ?> available)</option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Quantity</label><input type="number" name="quantity" min="1" value="1" required></div>
                            <div class="input-group"><label>Borrow Date</label><input type="date" name="borrow_date" required></div>
                            <div class="input-group"><label>Return Date</label><input type="date" name="return_date" required></div>
                            <div class="input-group full"><label>Purpose</label><textarea name="purpose" required></textarea></div>
                            <div class="form-actions"><button class="btn-primary"><i class="fa-solid fa-hand-holding"></i> Submit Request</button></div>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Manage Inventory' : 'Available Items' ?></h3></div>
                    <div class="records-table">
                        <table>
                            <thead><tr><th>Item</th><th>Quantity</th><th>Condition</th><th><?= $isAdmin ? 'Actions' : 'Limit' ?></th></tr></thead>
                            <tbody>
                                <?php if (!$items): ?><tr><td colspan="4" class="empty-state">No inventory items recorded yet.</td></tr><?php endif; ?>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                <form method="POST" class="form-grid">
                                                    <input type="hidden" name="action" value="save_item">
                                                    <input type="hidden" name="item_id" value="<?= (int) $item['item_id'] ?>">
                                                    <div class="input-group full"><input name="item_name" value="<?= htmlspecialchars($item['item_name']) ?>" required></div>
                                                    <div class="input-group full"><textarea name="description"><?= htmlspecialchars($item['description'] ?? '') ?></textarea></div>
                                            <?php else: ?>
                                                <strong><?= htmlspecialchars($item['item_name']) ?></strong><br>
                                                <span class="muted"><?= htmlspecialchars($item['description'] ?? '') ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                    <div class="input-group"><input type="number" name="total_quantity" min="0" value="<?= (int) $item['total_quantity'] ?>"></div>
                                                    <div class="input-group"><input type="number" name="available_quantity" min="0" value="<?= (int) $item['available_quantity'] ?>"></div>
                                            <?php else: ?>
                                                <span class="quantity-chip"><?= (int) $item['available_quantity'] ?></span> available of <?= (int) $item['total_quantity'] ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                    <div class="input-group"><select name="condition_status"><?php foreach ($conditions as $condition): ?><option <?= $item['condition_status'] === $condition ? 'selected' : '' ?>><?= htmlspecialchars($condition) ?></option><?php endforeach; ?></select></div>
                                                    <div class="input-group"><input type="number" name="borrowing_limit" min="1" value="<?= htmlspecialchars((string) ($item['borrowing_limit'] ?? '')) ?>" placeholder="Limit"></div>
                                            <?php else: ?>
                                                <span class="<?= ugnayan_status_class($item['condition_status']) ?>"><?= htmlspecialchars($item['condition_status']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                    <div class="row-actions"><button class="btn-secondary btn-small">Update</button>
                                                </form>
                                                        <form method="POST" class="inline-form">
                                                            <input type="hidden" name="action" value="delete_item">
                                                            <input type="hidden" name="item_id" value="<?= (int) $item['item_id'] ?>">
                                                            <button class="btn-danger btn-small">Delete</button>
                                                        </form>
                                                    </div>
                                            <?php else: ?>
                                                <?= $item['borrowing_limit'] ? (int) $item['borrowing_limit'] . ' per request' : 'No limit set' ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php if ($isAdmin): ?>
                <div class="management-grid">
                    <div class="panel">
                        <div class="panel-header"><h3>Log Item Maintenance</h3></div>
                        <form method="POST" class="form-grid">
                            <input type="hidden" name="action" value="save_maintenance">
                            <div class="input-group full"><label>Item</label><select name="item_id" required><?php foreach ($items as $item): ?><option value="<?= (int) $item['item_id'] ?>"><?= htmlspecialchars($item['item_name']) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Status</label><select name="maintenance_status"><?php foreach (['Pending', 'In Progress', 'Completed'] as $status): ?><option><?= htmlspecialchars($status) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Date</label><input type="date" name="maintenance_date"></div>
                            <div class="input-group"><label>Cost</label><input type="number" step="0.01" min="0" name="cost" value="0.00"></div>
                            <div class="input-group full"><label>Issue / Work Done</label><textarea name="issue_description" required></textarea></div>
                            <div class="form-actions"><button class="btn-primary"><i class="fa-solid fa-screwdriver-wrench"></i> Save Maintenance</button></div>
                        </form>
                    </div>

                    <div class="panel">
                        <div class="panel-header"><h3>Maintenance Records</h3></div>
                        <div class="list-group">
                            <?php if (!$maintenanceRecords): ?><div class="empty-state">No maintenance records yet.</div><?php endif; ?>
                            <?php foreach ($maintenanceRecords as $maintenance): ?>
                                <div class="list-item">
                                    <div class="item-info"><div class="item-icon"><i class="fa-solid fa-screwdriver-wrench"></i></div><div><h4><?= htmlspecialchars($maintenance['item_name']) ?></h4><p><?= htmlspecialchars($maintenance['issue_description']) ?> &middot; PHP <?= number_format((float) $maintenance['cost'], 2) ?></p></div></div>
                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="action" value="delete_maintenance">
                                        <input type="hidden" name="maintenance_id" value="<?= (int) $maintenance['maintenance_id'] ?>">
                                        <span class="<?= ugnayan_status_class($maintenance['maintenance_status']) ?>"><?= htmlspecialchars($maintenance['maintenance_status']) ?></span>
                                        <button class="btn-danger btn-small">Delete</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="module-grid">
                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Borrow Requests' : 'My Borrow Requests' ?></h3></div>
                    <div class="records-table">
                        <table>
                            <thead><tr><th>Request</th><th>Dates</th><th>Status</th><th><?= $isAdmin ? 'Admin Action' : 'Remarks' ?></th></tr></thead>
                            <tbody>
                                <?php if (!$borrowRequests): ?><tr><td colspan="4" class="empty-state">No borrowing requests yet.</td></tr><?php endif; ?>
                                <?php foreach ($borrowRequests as $request): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($request['item_name']) ?></strong><br><span class="muted"><?= htmlspecialchars($request['resident_name']) ?> - Qty <?= (int) $request['quantity'] ?></span><br><span class="muted"><?= htmlspecialchars($request['purpose'] ?? '') ?></span></td>
                                        <td><?= htmlspecialchars($request['borrow_date']) ?><br><span class="muted">Return: <?= htmlspecialchars($request['return_date']) ?></span></td>
                                        <td><span class="<?= ugnayan_status_class($request['status']) ?>"><?= htmlspecialchars($request['status']) ?></span></td>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="action" value="update_borrow_status">
                                                    <input type="hidden" name="borrow_id" value="<?= (int) $request['borrow_id'] ?>">
                                                    <select name="status"><?php foreach ($borrowStatuses as $status): ?><option <?= $request['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option><?php endforeach; ?></select>
                                                    <input name="remarks" placeholder="Remarks" value="<?= htmlspecialchars($request['remarks'] ?? '') ?>">
                                                    <button class="btn-secondary btn-small">Update</button>
                                                </form>
                                            <?php else: ?>
                                                <?= htmlspecialchars($request['remarks'] ?: 'Waiting for admin action') ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Inventory Flow' : 'Resident Access' ?></h3></div>
                    <div class="list-group">
                        <?php foreach ($recentItems as $item): ?>
                            <div class="list-item">
                                <div class="item-info"><div class="item-icon"><i class="fa-solid fa-box-open"></i></div><div><h4><?= htmlspecialchars($item['item_name']) ?></h4><p><span class="quantity-chip"><?= (int) $item['available_quantity'] ?></span> available of <?= (int) $item['total_quantity'] ?> total</p></div></div>
                                <span class="<?= ugnayan_status_class($item['condition_status']) ?>"><?= htmlspecialchars($item['condition_status']) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$recentItems): ?><div class="empty-state">No inventory items recorded yet.</div><?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
