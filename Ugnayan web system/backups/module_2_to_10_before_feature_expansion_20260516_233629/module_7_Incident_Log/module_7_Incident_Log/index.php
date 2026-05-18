<?php
require_once __DIR__ . '/../../module_1_IMVN Module/includes/auth.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/module_helpers.php';

$isAdmin = ugnayan_is_admin_role();
$resident = ugnayan_get_resident($pdo);
$residentId = $resident ? (int) $resident['resident_id'] : null;
$incidentStatuses = ['Open', 'Under Review', 'Closed'];
$visibilities = ['Admin Only', 'Resident Visible'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        ugnayan_require_admin_access();

        if ($action === 'save_incident') {
            $incidentId = ugnayan_int('incident_id');
            $linkedResidentId = ugnayan_int('resident_id') ?: null;
            $visibility = ugnayan_trim('visibility', 'Admin Only');
            $incidentDate = ugnayan_trim('incident_date');

            if ($incidentId > 0) {
                $stmt = $pdo->prepare('UPDATE incident_logs SET resident_id=?, incident_title=?, incident_type=?, description=?, incident_date=?, location=?, status=?, visibility=? WHERE incident_id=?');
                $stmt->execute([
                    $linkedResidentId,
                    ugnayan_trim('incident_title'),
                    ugnayan_trim('incident_type'),
                    ugnayan_trim('description'),
                    $incidentDate,
                    ugnayan_trim('location'),
                    ugnayan_trim('status', 'Open'),
                    $visibility,
                    $incidentId,
                ]);
                log_action($pdo, ugnayan_user_id(), 'Updated Incident Log', ugnayan_trim('incident_title'));
                ugnayan_flash_set('Incident updated.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO incident_logs (recorded_by, resident_id, incident_title, incident_type, description, incident_date, location, status, visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    ugnayan_user_id(),
                    $linkedResidentId,
                    ugnayan_trim('incident_title'),
                    ugnayan_trim('incident_type'),
                    ugnayan_trim('description'),
                    $incidentDate,
                    ugnayan_trim('location'),
                    ugnayan_trim('status', 'Open'),
                    $visibility,
                ]);
                $incidentId = (int) $pdo->lastInsertId();
                log_action($pdo, ugnayan_user_id(), 'Created Incident Log', ugnayan_trim('incident_title'));
                ugnayan_flash_set('Incident recorded.');
            }

            $updateText = ugnayan_trim('update_text');
            if ($updateText !== '') {
                $pdo->prepare('INSERT INTO incident_updates (incident_id, updated_by, update_text) VALUES (?, ?, ?)')->execute([$incidentId, ugnayan_user_id(), $updateText]);
            }

            if ($visibility === 'Resident Visible' && isset($_POST['notify_residents'])) {
                $title = 'Incident Log Update';
                $message = ugnayan_trim('incident_title') . ' is now visible. Status: ' . ugnayan_trim('status', 'Open') . '.';

                if ($linkedResidentId) {
                    $stmt = $pdo->prepare('SELECT user_id FROM residents WHERE resident_id=?');
                    $stmt->execute([$linkedResidentId]);
                    ugnayan_notify_user($pdo, (int) $stmt->fetchColumn(), $title, $message, 'request_update');
                    ugnayan_activity($pdo, $linkedResidentId, $title, $message);
                } else {
                    ugnayan_notify_residents($pdo, $title, $message, 'announcement');
                }
            }
        } elseif ($action === 'delete_incident') {
            $incidentId = ugnayan_int('incident_id');
            $pdo->prepare('DELETE FROM incident_logs WHERE incident_id=?')->execute([$incidentId]);
            log_action($pdo, ugnayan_user_id(), 'Deleted Incident Log', 'Incident #' . $incidentId);
            ugnayan_flash_set('Incident deleted.');
        }
    } catch (Throwable $e) {
        ugnayan_flash_set('Action failed: ' . $e->getMessage(), 'error');
    }

    ugnayan_redirect_here('#incidents');
}

$flash = ugnayan_flash_get();
$approvedResidents = $isAdmin ? $pdo->query("SELECT resident_id, first_name, last_name, purok FROM residents WHERE application_status='Approved' ORDER BY last_name, first_name")->fetchAll() : [];

if ($isAdmin) {
    $incidents = $pdo->query('SELECT i.*, CONCAT(r.first_name, " ", r.last_name) AS resident_name FROM incident_logs i LEFT JOIN residents r ON r.resident_id=i.resident_id ORDER BY i.incident_id DESC')->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT i.*, CONCAT(r.first_name, ' ', r.last_name) AS resident_name FROM incident_logs i LEFT JOIN residents r ON r.resident_id=i.resident_id WHERE i.visibility='Resident Visible' AND (i.resident_id IS NULL OR i.resident_id=?) ORDER BY i.incident_id DESC");
    $stmt->execute([$residentId]);
    $incidents = $stmt->fetchAll();
}

$totalIncidents = count($incidents);
$openIncidents = ugnayan_count_by($incidents, 'status', 'Open');
$closedIncidents = ugnayan_count_by($incidents, 'status', 'Closed');
$recentIncidents = array_slice($incidents, 0, 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UGNAYAN - Incident Log</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/module-7.css">
</head>
<body>
    <?php render_ugnayan_sidebar('incidents', [
        'module_label' => 'Module 7',
        'brand_icon' => 'fa-clipboard-list',
    ]); ?>
    <main class="main-content">
        <header class="topbar">
            <div class="topbar-title"><h2>Barangay Incident Log Module</h2><p><?= $isAdmin ? 'Create, update, delete, and control visibility of incident records' : 'View incident records made visible by admin' ?></p></div>
            <div class="topbar-actions"><?php render_ugnayan_access_pill(); ?></div>
        </header>
        <section class="module-content">
            <?php if ($flash): ?><div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div><?php endif; ?>

            <div class="stats-grid" id="summary">
                <div class="stat-card"><div class="stat-title">Total Incidents</div><div class="stat-value"><?= $totalIncidents ?></div><div class="stat-subtext"><?= $isAdmin ? 'All incident logs' : 'Visible records' ?></div></div>
                <div class="stat-card"><div class="stat-title">Open</div><div class="stat-value"><?= $openIncidents ?></div><div class="stat-subtext">Still under action</div></div>
                <div class="stat-card"><div class="stat-title">Closed</div><div class="stat-value"><?= $closedIncidents ?></div><div class="stat-subtext">Resolved or archived</div></div>
            </div>

            <?php if ($isAdmin): ?>
                <div class="management-grid" id="incidents">
                    <div class="panel">
                        <div class="panel-header"><h3>Record Incident</h3></div>
                        <form method="POST" class="form-grid">
                            <input type="hidden" name="action" value="save_incident">
                            <div class="input-group full"><label>Resident Involved</label><select name="resident_id"><option value="">No specific resident</option><?php foreach ($approvedResidents as $person): ?><option value="<?= (int) $person['resident_id'] ?>"><?= htmlspecialchars($person['last_name'] . ', ' . $person['first_name'] . ' - ' . $person['purok']) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group full"><label>Title</label><input name="incident_title" required maxlength="255"></div>
                            <div class="input-group"><label>Type</label><input name="incident_type" required maxlength="150"></div>
                            <div class="input-group"><label>Date</label><input type="datetime-local" name="incident_date" required></div>
                            <div class="input-group"><label>Status</label><select name="status"><?php foreach ($incidentStatuses as $status): ?><option><?= htmlspecialchars($status) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Visibility</label><select name="visibility"><?php foreach ($visibilities as $visibility): ?><option><?= htmlspecialchars($visibility) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group full"><label>Location</label><input name="location"></div>
                            <div class="input-group full"><label>Description</label><textarea name="description" required></textarea></div>
                            <div class="input-group full"><label>Update Note</label><textarea name="update_text"></textarea></div>
                            <div class="input-group full"><label><input type="checkbox" name="notify_residents" value="1"> Notify resident(s) if visible</label></div>
                            <div class="form-actions"><button class="btn-primary"><i class="fa-solid fa-plus"></i> Save Incident</button></div>
                        </form>
                    </div>

                    <div class="panel">
                        <div class="panel-header"><h3>Manage Incidents</h3></div>
                        <div class="records-table">
                            <table>
                                <thead><tr><th>Incident</th><th>Details</th><th>Visibility</th><th>Actions</th></tr></thead>
                                <tbody>
                                    <?php if (!$incidents): ?><tr><td colspan="4" class="empty-state">No incidents recorded yet.</td></tr><?php endif; ?>
                                    <?php foreach ($incidents as $incident): ?>
                                        <tr>
                                            <td>
                                                <form method="POST" class="form-grid">
                                                    <input type="hidden" name="action" value="save_incident">
                                                    <input type="hidden" name="incident_id" value="<?= (int) $incident['incident_id'] ?>">
                                                    <div class="input-group full"><input name="incident_title" value="<?= htmlspecialchars($incident['incident_title']) ?>" required></div>
                                                    <div class="input-group"><input name="incident_type" value="<?= htmlspecialchars($incident['incident_type']) ?>" required></div>
                                                    <div class="input-group"><select name="resident_id"><option value="">No specific resident</option><?php foreach ($approvedResidents as $person): ?><option value="<?= (int) $person['resident_id'] ?>" <?= (int) ($incident['resident_id'] ?? 0) === (int) $person['resident_id'] ? 'selected' : '' ?>><?= htmlspecialchars($person['last_name'] . ', ' . $person['first_name']) ?></option><?php endforeach; ?></select></div>
                                                    <div class="input-group full"><textarea name="description" required><?= htmlspecialchars($incident['description']) ?></textarea></div>
                                            </td>
                                            <td>
                                                    <div class="input-group"><input type="datetime-local" name="incident_date" value="<?= htmlspecialchars(ugnayan_date_input($incident['incident_date'])) ?>" required></div>
                                                    <div class="input-group"><input name="location" value="<?= htmlspecialchars($incident['location'] ?? '') ?>"></div>
                                                    <div class="input-group"><select name="status"><?php foreach ($incidentStatuses as $status): ?><option <?= $incident['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option><?php endforeach; ?></select></div>
                                            </td>
                                            <td>
                                                    <div class="input-group"><select name="visibility"><?php foreach ($visibilities as $visibility): ?><option <?= $incident['visibility'] === $visibility ? 'selected' : '' ?>><?= htmlspecialchars($visibility) ?></option><?php endforeach; ?></select></div>
                                                    <textarea name="update_text" placeholder="Add update note"></textarea>
                                                    <label><input type="checkbox" name="notify_residents" value="1"> Notify</label>
                                                    <span class="<?= ugnayan_status_class($incident['status']) ?>"><?= htmlspecialchars($incident['status']) ?></span>
                                            </td>
                                            <td>
                                                    <div class="row-actions"><button class="btn-secondary btn-small">Update</button>
                                                </form>
                                                        <form method="POST" class="inline-form">
                                                            <input type="hidden" name="action" value="delete_incident">
                                                            <input type="hidden" name="incident_id" value="<?= (int) $incident['incident_id'] ?>">
                                                            <button class="btn-danger btn-small">Delete</button>
                                                        </form>
                                                    </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="module-grid">
                <div class="panel" id="<?= $isAdmin ? 'recent' : 'incidents' ?>">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Recent Incidents' : 'Visible Incident Records' ?></h3></div>
                    <div class="list-group">
                        <?php if (!$recentIncidents): ?><div class="empty-state">No incidents recorded yet.</div><?php endif; ?>
                        <?php foreach ($recentIncidents as $incident): ?>
                            <div class="list-item">
                                <div class="item-info"><div class="item-icon"><i class="fa-solid fa-file-shield"></i></div><div><h4><?= htmlspecialchars($incident['incident_title']) ?></h4><p><?= htmlspecialchars($incident['incident_type']) ?> &middot; <span class="incident-location"><?= htmlspecialchars($incident['location'] ?: 'No location set') ?></span></p><p><?= htmlspecialchars($incident['description']) ?></p></div></div>
                                <span class="<?= ugnayan_status_class($incident['status']) ?>"><?= htmlspecialchars($incident['status']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Log Contents' : 'Resident View Only' ?></h3></div>
                    <div class="list-group">
                        <?php if ($isAdmin): ?>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-location-dot"></i></div><div><h4>Record</h4><p>Log location, date, and details</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-eye"></i></div><div><h4>Visibility</h4><p>Choose admin-only or resident-visible records</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-bell"></i></div><div><h4>Notify</h4><p>Send visible updates when needed</p></div></div></div>
                        <?php else: ?>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-book-open"></i></div><div><h4>Authorized Records</h4><p>View only records made visible by admin</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-lock"></i></div><div><h4>Read Only</h4><p>Residents cannot update incident logs</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-bell"></i></div><div><h4>Updates</h4><p>Receive notifications when admin sends them</p></div></div></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
