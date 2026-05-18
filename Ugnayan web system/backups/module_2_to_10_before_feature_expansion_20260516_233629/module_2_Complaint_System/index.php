<?php
require_once __DIR__ . '/../module_1_IMVN Module/includes/auth.php';
require_login();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/module_helpers.php';

$isAdmin = ugnayan_is_admin_role();
$resident = ugnayan_get_resident($pdo);
$residentId = $resident ? (int) $resident['resident_id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'submit_complaint') {
            if (!$residentId) {
                ugnayan_require_admin_access();
            }

            $stmt = $pdo->prepare('INSERT INTO complaints (resident_id, category, title, description, is_anonymous, priority, status) VALUES (?, ?, ?, ?, ?, ?, "Pending")');
            $stmt->execute([
                $residentId,
                ugnayan_trim('category', 'others'),
                ugnayan_trim('title'),
                ugnayan_trim('description'),
                isset($_POST['is_anonymous']) ? 1 : 0,
                ugnayan_trim('priority', 'Medium'),
            ]);

            ugnayan_activity($pdo, $residentId, 'Complaint Submitted', 'Submitted complaint: ' . ugnayan_trim('title'));
            log_action($pdo, ugnayan_user_id(), 'Submitted Complaint', ugnayan_trim('title'));
            ugnayan_flash_set('Complaint submitted successfully.');
        } elseif ($action === 'admin_save_complaint') {
            ugnayan_require_admin_access();

            $complaintId = ugnayan_int('complaint_id');
            $selectedResidentId = ugnayan_int('resident_id') ?: null;

            if ($complaintId > 0) {
                $stmt = $pdo->prepare('UPDATE complaints SET resident_id=?, category=?, title=?, description=?, is_anonymous=?, priority=?, status=?, admin_response=?, updated_at=NOW(), resolved_at=CASE WHEN ?="Resolved" THEN NOW() ELSE resolved_at END WHERE complaint_id=?');
                $stmt->execute([
                    $selectedResidentId,
                    ugnayan_trim('category', 'others'),
                    ugnayan_trim('title'),
                    ugnayan_trim('description'),
                    isset($_POST['is_anonymous']) ? 1 : 0,
                    ugnayan_trim('priority', 'Medium'),
                    ugnayan_trim('status', 'Pending'),
                    ugnayan_trim('admin_response'),
                    ugnayan_trim('status', 'Pending'),
                    $complaintId,
                ]);
                log_action($pdo, ugnayan_user_id(), 'Updated Complaint', ugnayan_trim('title'));
                ugnayan_flash_set('Complaint updated.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO complaints (resident_id, category, title, description, is_anonymous, priority, status, admin_response, assigned_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $selectedResidentId,
                    ugnayan_trim('category', 'others'),
                    ugnayan_trim('title'),
                    ugnayan_trim('description'),
                    isset($_POST['is_anonymous']) ? 1 : 0,
                    ugnayan_trim('priority', 'Medium'),
                    ugnayan_trim('status', 'Pending'),
                    ugnayan_trim('admin_response'),
                    ugnayan_user_id(),
                ]);
                log_action($pdo, ugnayan_user_id(), 'Created Complaint', ugnayan_trim('title'));
                ugnayan_flash_set('Complaint record created.');
            }
        } elseif ($action === 'update_status') {
            ugnayan_require_admin_access();

            $complaintId = ugnayan_int('complaint_id');
            $status = ugnayan_trim('status', 'Pending');
            $response = ugnayan_trim('admin_response');

            $stmt = $pdo->prepare('UPDATE complaints SET status=?, admin_response=?, updated_at=NOW(), resolved_at=CASE WHEN ?="Resolved" THEN NOW() ELSE NULL END WHERE complaint_id=?');
            $stmt->execute([$status, $response, $status, $complaintId]);
            $pdo->prepare('INSERT INTO complaint_updates (complaint_id, updated_by, status, update_message) VALUES (?, ?, ?, ?)')->execute([$complaintId, ugnayan_user_id(), $status, $response]);

            $stmt = $pdo->prepare('SELECT c.title, c.resident_id, r.user_id FROM complaints c LEFT JOIN residents r ON r.resident_id=c.resident_id WHERE c.complaint_id=?');
            $stmt->execute([$complaintId]);
            $complaint = $stmt->fetch();

            if ($complaint && $complaint['user_id']) {
                $message = 'Your complaint "' . $complaint['title'] . '" is now ' . $status . '. ' . $response;
                ugnayan_notify_user($pdo, (int) $complaint['user_id'], 'Complaint Status Updated', $message, 'request_update');
                ugnayan_activity($pdo, (int) $complaint['resident_id'], 'Complaint Status Updated', $message);
            }

            log_action($pdo, ugnayan_user_id(), 'Updated Complaint Status', 'Complaint #' . $complaintId . ' set to ' . $status);
            ugnayan_flash_set('Complaint status updated.');
        } elseif ($action === 'delete_complaint') {
            ugnayan_require_admin_access();

            $complaintId = ugnayan_int('complaint_id');
            $pdo->prepare('DELETE FROM complaints WHERE complaint_id=?')->execute([$complaintId]);
            log_action($pdo, ugnayan_user_id(), 'Deleted Complaint', 'Complaint #' . $complaintId);
            ugnayan_flash_set('Complaint deleted.');
        }
    } catch (Throwable $e) {
        ugnayan_flash_set('Action failed: ' . $e->getMessage(), 'error');
    }

    ugnayan_redirect_here('#complaints');
}

$flash = ugnayan_flash_get();
$approvedResidents = [];

if ($isAdmin) {
    $approvedResidents = $pdo->query("SELECT resident_id, first_name, last_name, purok FROM residents WHERE application_status='Approved' ORDER BY last_name, first_name")->fetchAll();
    $totalComplaints = (int) $pdo->query('SELECT COUNT(*) FROM complaints')->fetchColumn();
    $pendingComplaints = (int) $pdo->query("SELECT COUNT(*) FROM complaints WHERE status='Pending'")->fetchColumn();
    $resolvedComplaints = (int) $pdo->query("SELECT COUNT(*) FROM complaints WHERE status='Resolved'")->fetchColumn();
    $stmt = $pdo->query('SELECT c.*, CONCAT(r.first_name, " ", r.last_name) AS resident_name, r.purok FROM complaints c LEFT JOIN residents r ON r.resident_id=c.resident_id ORDER BY c.complaint_id DESC');
} else {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM complaints WHERE resident_id=?');
    $stmt->execute([$residentId]);
    $totalComplaints = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM complaints WHERE resident_id=? AND status='Pending'");
    $stmt->execute([$residentId]);
    $pendingComplaints = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM complaints WHERE resident_id=? AND status='Resolved'");
    $stmt->execute([$residentId]);
    $resolvedComplaints = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT c.*, CONCAT(r.first_name, " ", r.last_name) AS resident_name, r.purok FROM complaints c LEFT JOIN residents r ON r.resident_id=c.resident_id WHERE c.resident_id=? ORDER BY c.complaint_id DESC');
    $stmt->execute([$residentId]);
}

$complaints = $stmt->fetchAll();
$recentComplaints = array_slice($complaints, 0, 5);
$categories = ['noise', 'dispute', 'sanitation', 'peace_and_order', 'health', 'environment', 'others'];
$priorities = ['Low', 'Medium', 'High'];
$statuses = ['Pending', 'Ongoing', 'Resolved', 'Rejected'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UGNAYAN - Complaint System</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/module-2.css">
</head>
<body>
    <?php render_ugnayan_sidebar('complaints', [
        'module_label' => 'Module 2',
        'brand_icon' => 'fa-file-circle-exclamation',
    ]); ?>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-title">
                <h2>Barangay Complaint System</h2>
                <p><?= $isAdmin ? 'Create, review, update, and close resident complaints' : 'Submit complaints and monitor admin updates' ?></p>
            </div>
            <div class="topbar-actions">
                <?php render_ugnayan_access_pill(); ?>
            </div>
        </header>

        <section class="module-content">
            <?php if ($flash): ?><div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div><?php endif; ?>

            <div class="stats-grid" id="summary">
                <div class="stat-card"><div class="stat-title">Total Complaints</div><div class="stat-value"><?= $totalComplaints ?></div><div class="stat-subtext"><?= $isAdmin ? 'All complaint records' : 'Your filed complaints' ?></div></div>
                <div class="stat-card"><div class="stat-title">Pending</div><div class="stat-value"><?= $pendingComplaints ?></div><div class="stat-subtext">Need review</div></div>
                <div class="stat-card"><div class="stat-title">Resolved</div><div class="stat-value"><?= $resolvedComplaints ?></div><div class="stat-subtext">Completed cases</div></div>
            </div>

            <div class="management-grid" id="complaints">
                <div class="panel">
                    <div class="panel-header">
                        <h3><?= $isAdmin ? 'Create Complaint Record' : 'Submit Complaint' ?></h3>
                    </div>
                    <form method="POST" class="form-grid">
                        <input type="hidden" name="action" value="<?= $isAdmin ? 'admin_save_complaint' : 'submit_complaint' ?>">
                        <?php if ($isAdmin): ?>
                            <div class="input-group full">
                                <label>Resident</label>
                                <select name="resident_id">
                                    <option value="">Walk-in or anonymous</option>
                                    <?php foreach ($approvedResidents as $person): ?>
                                        <option value="<?= (int) $person['resident_id'] ?>"><?= htmlspecialchars($person['last_name'] . ', ' . $person['first_name'] . ' - ' . $person['purok']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="input-group">
                            <label>Category</label>
                            <select name="category" required>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $category))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label>Priority</label>
                            <select name="priority" required>
                                <?php foreach ($priorities as $priority): ?><option><?= htmlspecialchars($priority) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($isAdmin): ?>
                            <div class="input-group">
                                <label>Status</label>
                                <select name="status">
                                    <?php foreach ($statuses as $status): ?><option><?= htmlspecialchars($status) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="input-group <?= $isAdmin ? '' : 'full' ?>">
                            <label>Title</label>
                            <input name="title" required maxlength="255">
                        </div>
                        <div class="input-group full">
                            <label>Description</label>
                            <textarea name="description" required></textarea>
                        </div>
                        <?php if ($isAdmin): ?>
                            <div class="input-group full">
                                <label>Admin Response</label>
                                <textarea name="admin_response"></textarea>
                            </div>
                        <?php endif; ?>
                        <div class="input-group full">
                            <label><input type="checkbox" name="is_anonymous" value="1"> Hide resident name from public view</label>
                        </div>
                        <div class="form-actions">
                            <button class="btn-primary"><i class="fa-solid fa-paper-plane"></i> Save Complaint</button>
                        </div>
                    </form>
                </div>

                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Manage Complaints' : 'My Complaints' ?></h3></div>
                    <div class="records-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Complaint</th>
                                    <th>Resident</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Admin Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$complaints): ?>
                                    <tr><td colspan="5" class="empty-state">No complaints recorded yet.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($complaints as $complaint): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($complaint['title']) ?></strong><br>
                                            <span class="muted"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $complaint['category']))) ?> - <?= htmlspecialchars(date('M d, Y', strtotime($complaint['created_at']))) ?></span><br>
                                            <span class="muted"><?= htmlspecialchars($complaint['description']) ?></span>
                                        </td>
                                        <td><?= $complaint['is_anonymous'] ? 'Anonymous' : htmlspecialchars($complaint['resident_name'] ?: 'Walk-in') ?></td>
                                        <td><span class="complaint-priority"><?= htmlspecialchars($complaint['priority']) ?></span></td>
                                        <td><span class="<?= ugnayan_status_class($complaint['status']) ?>"><?= htmlspecialchars($complaint['status']) ?></span></td>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="complaint_id" value="<?= (int) $complaint['complaint_id'] ?>">
                                                    <select name="status">
                                                        <?php foreach ($statuses as $status): ?><option <?= $complaint['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option><?php endforeach; ?>
                                                    </select>
                                                    <input name="admin_response" placeholder="Response" value="<?= htmlspecialchars($complaint['admin_response'] ?? '') ?>">
                                                    <button class="btn-secondary btn-small">Update</button>
                                                </form>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="action" value="delete_complaint">
                                                    <input type="hidden" name="complaint_id" value="<?= (int) $complaint['complaint_id'] ?>">
                                                    <button class="btn-danger btn-small">Delete</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="muted"><?= htmlspecialchars($complaint['admin_response'] ?: 'Waiting for admin response') ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="module-grid">
                <div class="panel">
                    <div class="panel-header"><h3>Recent Complaints</h3></div>
                    <div class="list-group">
                        <?php if (!$recentComplaints): ?><div class="empty-state">No complaints recorded yet.</div><?php endif; ?>
                        <?php foreach ($recentComplaints as $complaint): ?>
                            <div class="list-item">
                                <div class="item-info">
                                    <div class="item-icon"><i class="fa-solid fa-comment-dots"></i></div>
                                    <div>
                                        <h4><?= htmlspecialchars($complaint['title']) ?></h4>
                                        <p><?= htmlspecialchars(ucwords(str_replace('_', ' ', $complaint['category']))) ?> &middot; <span class="complaint-priority"><?= htmlspecialchars($complaint['priority']) ?></span></p>
                                    </div>
                                </div>
                                <span class="<?= ugnayan_status_class($complaint['status']) ?>"><?= htmlspecialchars($complaint['status']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Admin Workflow' : 'Resident Access' ?></h3></div>
                    <div class="list-group">
                        <?php if ($isAdmin): ?>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-inbox"></i></div><div><h4>Read</h4><p>Review all complaint details</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-pen-to-square"></i></div><div><h4>Update</h4><p>Change status and send responses</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-trash"></i></div><div><h4>Delete</h4><p>Remove invalid records</p></div></div></div>
                        <?php else: ?>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-paper-plane"></i></div><div><h4>Submit</h4><p>File a complaint for admin review</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-bars-progress"></i></div><div><h4>Track</h4><p>Monitor status updates only</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-lock"></i></div><div><h4>Read Only</h4><p>You cannot edit admin decisions</p></div></div></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
