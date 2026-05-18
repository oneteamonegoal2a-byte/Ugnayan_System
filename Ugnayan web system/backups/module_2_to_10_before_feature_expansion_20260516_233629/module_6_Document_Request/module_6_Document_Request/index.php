<?php
require_once __DIR__ . '/../../module_1_IMVN Module/includes/auth.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/module_helpers.php';

$isAdmin = ugnayan_is_admin_role();
$resident = ugnayan_get_resident($pdo);
$residentId = $resident ? (int) $resident['resident_id'] : null;
$requestStatuses = ['Pending', 'Processing', 'Approved', 'Rejected', 'Ready', 'Released'];
$paymentStatuses = ['Not Required', 'Unpaid', 'Paid'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_document_type') {
            ugnayan_require_admin_access();

            $typeId = ugnayan_int('document_type_id');
            $fee = max(0, (float) ($_POST['fee'] ?? 0));
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($typeId > 0) {
                $stmt = $pdo->prepare('UPDATE document_types SET document_name=?, description=?, fee=?, template_path=?, is_active=? WHERE document_type_id=?');
                $stmt->execute([
                    ugnayan_trim('document_name'),
                    ugnayan_trim('description'),
                    $fee,
                    ugnayan_trim('template_path'),
                    $isActive,
                    $typeId,
                ]);
                log_action($pdo, ugnayan_user_id(), 'Updated Document Type', ugnayan_trim('document_name'));
                ugnayan_flash_set('Document type updated.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO document_types (document_name, description, fee, template_path, is_active) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([
                    ugnayan_trim('document_name'),
                    ugnayan_trim('description'),
                    $fee,
                    ugnayan_trim('template_path'),
                    $isActive,
                ]);
                log_action($pdo, ugnayan_user_id(), 'Created Document Type', ugnayan_trim('document_name'));
                ugnayan_flash_set('Document type created.');
            }
        } elseif ($action === 'delete_document_type') {
            ugnayan_require_admin_access();

            $typeId = ugnayan_int('document_type_id');
            $pdo->prepare('DELETE FROM document_types WHERE document_type_id=?')->execute([$typeId]);
            log_action($pdo, ugnayan_user_id(), 'Deleted Document Type', 'Document type #' . $typeId);
            ugnayan_flash_set('Document type deleted.');
        } elseif ($action === 'request_document') {
            if (!$residentId) {
                ugnayan_require_admin_access();
            }

            $typeId = ugnayan_int('document_type_id');
            $stmt = $pdo->prepare('SELECT * FROM document_types WHERE document_type_id=? AND is_active=1');
            $stmt->execute([$typeId]);
            $type = $stmt->fetch();

            if (!$type) {
                throw new RuntimeException('Document type is not available.');
            }

            $paymentStatus = (float) $type['fee'] > 0 ? 'Unpaid' : 'Not Required';
            $stmt = $pdo->prepare('INSERT INTO document_requests (document_type_id, resident_id, purpose, status, payment_status) VALUES (?, ?, ?, "Pending", ?)');
            $stmt->execute([$typeId, $residentId, ugnayan_trim('purpose'), $paymentStatus]);
            ugnayan_activity($pdo, $residentId, 'Document Request', 'Requested ' . $type['document_name'] . '.');
            log_action($pdo, ugnayan_user_id(), 'Submitted Document Request', $type['document_name']);
            ugnayan_flash_set('Document request submitted.');
        } elseif ($action === 'update_request') {
            ugnayan_require_admin_access();

            $requestId = ugnayan_int('request_id');
            $status = ugnayan_trim('status', 'Pending');
            $pickupSchedule = ugnayan_trim('pickup_schedule') ?: null;
            $paymentStatus = ugnayan_trim('payment_status', 'Not Required');
            $remarks = ugnayan_trim('remarks');

            $stmt = $pdo->prepare('UPDATE document_requests SET status=?, pickup_schedule=?, payment_status=?, remarks=?, reviewed_by=? WHERE request_id=?');
            $stmt->execute([$status, $pickupSchedule, $paymentStatus, $remarks, ugnayan_user_id(), $requestId]);

            $stmt = $pdo->prepare('SELECT dr.*, dt.document_name, r.user_id FROM document_requests dr JOIN document_types dt ON dt.document_type_id=dr.document_type_id JOIN residents r ON r.resident_id=dr.resident_id WHERE dr.request_id=?');
            $stmt->execute([$requestId]);
            $request = $stmt->fetch();

            if ($request) {
                $message = 'Your ' . $request['document_name'] . ' request is now ' . $status . '. ' . $remarks;
                if ($pickupSchedule) {
                    $message .= ' Pickup: ' . date('M d, Y h:i A', strtotime($pickupSchedule)) . '.';
                }
                ugnayan_notify_user($pdo, (int) $request['user_id'], 'Document Request Updated', $message, 'request_update');
                ugnayan_activity($pdo, (int) $request['resident_id'], 'Document Request Updated', $message);
            }

            log_action($pdo, ugnayan_user_id(), 'Updated Document Request', 'Request #' . $requestId . ' set to ' . $status);
            ugnayan_flash_set('Document request updated.');
        } elseif ($action === 'delete_request') {
            ugnayan_require_admin_access();

            $requestId = ugnayan_int('request_id');
            $pdo->prepare('DELETE FROM document_requests WHERE request_id=?')->execute([$requestId]);
            log_action($pdo, ugnayan_user_id(), 'Deleted Document Request', 'Request #' . $requestId);
            ugnayan_flash_set('Document request deleted.');
        }
    } catch (Throwable $e) {
        ugnayan_flash_set('Action failed: ' . $e->getMessage(), 'error');
    }

    ugnayan_redirect_here('#documents');
}

$flash = ugnayan_flash_get();
$documentTypes = $isAdmin
    ? $pdo->query('SELECT * FROM document_types ORDER BY document_name')->fetchAll()
    : $pdo->query('SELECT * FROM document_types WHERE is_active=1 ORDER BY document_name')->fetchAll();

if ($isAdmin) {
    $stmt = $pdo->query('SELECT dr.*, dt.document_name, dt.fee, CONCAT(r.first_name, " ", r.last_name) AS resident_name, r.purok FROM document_requests dr JOIN document_types dt ON dt.document_type_id=dr.document_type_id JOIN residents r ON r.resident_id=dr.resident_id ORDER BY dr.request_id DESC');
} else {
    $stmt = $pdo->prepare('SELECT dr.*, dt.document_name, dt.fee, CONCAT(r.first_name, " ", r.last_name) AS resident_name, r.purok FROM document_requests dr JOIN document_types dt ON dt.document_type_id=dr.document_type_id JOIN residents r ON r.resident_id=dr.resident_id WHERE dr.resident_id=? ORDER BY dr.request_id DESC');
    $stmt->execute([$residentId]);
}

$requests = $stmt->fetchAll();
$totalRequests = count($requests);
$pendingRequests = ugnayan_count_by($requests, 'status', 'Pending');
$readyRequests = ugnayan_count_by($requests, 'status', 'Ready');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UGNAYAN - Document Request</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/module-6.css">
</head>
<body>
    <?php render_ugnayan_sidebar('documents', [
        'module_label' => 'Module 6',
        'brand_icon' => 'fa-file-lines',
    ]); ?>
    <main class="main-content">
        <header class="topbar">
            <div class="topbar-title"><h2>Document Request Module</h2><p><?= $isAdmin ? 'CRUD document types and process resident requests' : 'Request barangay documents and track release status' ?></p></div>
            <div class="topbar-actions"><?php render_ugnayan_access_pill(); ?></div>
        </header>
        <section class="module-content">
            <?php if ($flash): ?><div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div><?php endif; ?>

            <div class="stats-grid" id="summary">
                <div class="stat-card"><div class="stat-title">Total Requests</div><div class="stat-value"><?= $totalRequests ?></div><div class="stat-subtext"><?= $isAdmin ? 'All document requests' : 'Your document requests' ?></div></div>
                <div class="stat-card"><div class="stat-title">Pending</div><div class="stat-value"><?= $pendingRequests ?></div><div class="stat-subtext">Awaiting review</div></div>
                <div class="stat-card"><div class="stat-title">Ready</div><div class="stat-value"><?= $readyRequests ?></div><div class="stat-subtext">Ready for pickup</div></div>
            </div>

            <div class="management-grid" id="documents">
                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Create Document Type' : 'Request Document' ?></h3></div>
                    <?php if ($isAdmin): ?>
                        <form method="POST" class="form-grid">
                            <input type="hidden" name="action" value="save_document_type">
                            <div class="input-group full"><label>Document Name</label><input name="document_name" required maxlength="150"></div>
                            <div class="input-group"><label>Fee</label><input type="number" step="0.01" min="0" name="fee" value="0.00"></div>
                            <div class="input-group"><label>Template Path</label><input name="template_path"></div>
                            <div class="input-group full"><label>Description</label><textarea name="description"></textarea></div>
                            <div class="input-group full"><label><input type="checkbox" name="is_active" value="1" checked> Active for residents</label></div>
                            <div class="form-actions"><button class="btn-primary"><i class="fa-solid fa-plus"></i> Save Type</button></div>
                        </form>
                    <?php else: ?>
                        <form method="POST" class="form-grid">
                            <input type="hidden" name="action" value="request_document">
                            <div class="input-group full"><label>Document Type</label><select name="document_type_id" required><?php foreach ($documentTypes as $type): ?><option value="<?= (int) $type['document_type_id'] ?>"><?= htmlspecialchars($type['document_name']) ?> - PHP <?= number_format((float) $type['fee'], 2) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group full"><label>Purpose</label><textarea name="purpose" required></textarea></div>
                            <div class="form-actions"><button class="btn-primary"><i class="fa-solid fa-paper-plane"></i> Submit Request</button></div>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Manage Document Types' : 'Available Document Types' ?></h3></div>
                    <div class="records-table">
                        <table>
                            <thead><tr><th>Document</th><th>Fee</th><th>Status</th><th><?= $isAdmin ? 'Actions' : 'Details' ?></th></tr></thead>
                            <tbody>
                                <?php if (!$documentTypes): ?><tr><td colspan="4" class="empty-state">No document types configured yet.</td></tr><?php endif; ?>
                                <?php foreach ($documentTypes as $type): ?>
                                    <tr>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                <form method="POST" class="form-grid">
                                                    <input type="hidden" name="action" value="save_document_type">
                                                    <input type="hidden" name="document_type_id" value="<?= (int) $type['document_type_id'] ?>">
                                                    <div class="input-group full"><input name="document_name" value="<?= htmlspecialchars($type['document_name']) ?>" required></div>
                                                    <div class="input-group full"><textarea name="description"><?= htmlspecialchars($type['description'] ?? '') ?></textarea></div>
                                            <?php else: ?>
                                                <strong><?= htmlspecialchars($type['document_name']) ?></strong><br><span class="muted"><?= htmlspecialchars($type['description'] ?? '') ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                    <input type="number" step="0.01" min="0" name="fee" value="<?= htmlspecialchars((string) $type['fee']) ?>">
                                            <?php else: ?>
                                                <span class="fee-chip">PHP <?= number_format((float) $type['fee'], 2) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                    <label><input type="checkbox" name="is_active" value="1" <?= $type['is_active'] ? 'checked' : '' ?>> Active</label>
                                                    <input name="template_path" value="<?= htmlspecialchars($type['template_path'] ?? '') ?>" placeholder="Template">
                                            <?php else: ?>
                                                <span class="status status-active">Active</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                    <div class="row-actions"><button class="btn-secondary btn-small">Update</button>
                                                </form>
                                                        <form method="POST" class="inline-form">
                                                            <input type="hidden" name="action" value="delete_document_type">
                                                            <input type="hidden" name="document_type_id" value="<?= (int) $type['document_type_id'] ?>">
                                                            <button class="btn-danger btn-small">Delete</button>
                                                        </form>
                                                    </div>
                                            <?php else: ?>
                                                Request from the form on the left.
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header"><h3><?= $isAdmin ? 'Manage Requests' : 'My Requests' ?></h3></div>
                <div class="records-table">
                    <table>
                        <thead><tr><th>Request</th><th>Payment</th><th>Status</th><th><?= $isAdmin ? 'Admin Action' : 'Pickup / Remarks' ?></th></tr></thead>
                        <tbody>
                            <?php if (!$requests): ?><tr><td colspan="4" class="empty-state">No document requests yet.</td></tr><?php endif; ?>
                            <?php foreach ($requests as $request): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($request['document_name']) ?></strong><br><span class="muted"><?= htmlspecialchars($request['resident_name']) ?> - <?= htmlspecialchars(date('M d, Y', strtotime($request['requested_at']))) ?></span><br><span class="muted"><?= htmlspecialchars($request['purpose'] ?? '') ?></span></td>
                                    <td><span class="<?= ugnayan_status_class($request['payment_status']) ?>"><?= htmlspecialchars($request['payment_status']) ?></span><br><span class="muted">Fee PHP <?= number_format((float) $request['fee'], 2) ?></span></td>
                                    <td><span class="<?= ugnayan_status_class($request['status']) ?>"><?= htmlspecialchars($request['status']) ?></span></td>
                                    <td>
                                        <?php if ($isAdmin): ?>
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="action" value="update_request">
                                                <input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>">
                                                <select name="status"><?php foreach ($requestStatuses as $status): ?><option <?= $request['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option><?php endforeach; ?></select>
                                                <select name="payment_status"><?php foreach ($paymentStatuses as $status): ?><option <?= $request['payment_status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option><?php endforeach; ?></select>
                                                <input type="datetime-local" name="pickup_schedule" value="<?= htmlspecialchars(ugnayan_date_input($request['pickup_schedule'] ?? null)) ?>">
                                                <input name="remarks" placeholder="Remarks" value="<?= htmlspecialchars($request['remarks'] ?? '') ?>">
                                                <button class="btn-secondary btn-small">Update</button>
                                            </form>
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="action" value="delete_request">
                                                <input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>">
                                                <button class="btn-danger btn-small">Delete</button>
                                            </form>
                                        <?php else: ?>
                                            <?= $request['pickup_schedule'] ? htmlspecialchars(date('M d, Y h:i A', strtotime($request['pickup_schedule']))) : 'No pickup schedule yet' ?><br>
                                            <span class="muted"><?= htmlspecialchars($request['remarks'] ?: 'Waiting for admin action') ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
