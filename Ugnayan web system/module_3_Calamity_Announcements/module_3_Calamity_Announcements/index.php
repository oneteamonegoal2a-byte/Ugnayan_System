<?php
require_once __DIR__ . '/../../module_1_IMVN Module/includes/auth.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/module_helpers.php';

$isAdmin = ugnayan_is_admin_role();
$resident = ugnayan_get_resident($pdo);
$residentPurok = $resident['purok'] ?? null;
$types = ['typhoon', 'flood', 'fire', 'earthquake', 'landslide', 'health_emergency', 'others'];
$priorities = ['Urgent', 'Warning', 'Info'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        ugnayan_require_admin_access();

        if ($action === 'save_announcement') {
            $announcementId = ugnayan_int('announcement_id');
            $targetPurok = ugnayan_trim('target_purok') ?: null;
            $scheduledAt = ugnayan_trim('scheduled_at') ?: null;
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($announcementId > 0) {
                $stmt = $pdo->prepare('UPDATE calamity_announcements SET title=?, message=?, calamity_type=?, priority=?, target_purok=?, safety_instructions=?, evacuation_plan=?, scheduled_at=?, is_active=? WHERE announcement_id=?');
                $stmt->execute([
                    ugnayan_trim('title'),
                    ugnayan_trim('message'),
                    ugnayan_trim('calamity_type', 'others'),
                    ugnayan_trim('priority', 'Info'),
                    $targetPurok,
                    ugnayan_trim('safety_instructions'),
                    ugnayan_trim('evacuation_plan'),
                    $scheduledAt,
                    $isActive,
                    $announcementId,
                ]);
                $savedAnnouncementId = $announcementId;
                log_action($pdo, ugnayan_user_id(), 'Updated Calamity Announcement', ugnayan_trim('title'));
                ugnayan_flash_set('Announcement updated.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO calamity_announcements (created_by, title, message, calamity_type, priority, target_purok, safety_instructions, evacuation_plan, scheduled_at, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    ugnayan_user_id(),
                    ugnayan_trim('title'),
                    ugnayan_trim('message'),
                    ugnayan_trim('calamity_type', 'others'),
                    ugnayan_trim('priority', 'Info'),
                    $targetPurok,
                    ugnayan_trim('safety_instructions'),
                    ugnayan_trim('evacuation_plan'),
                    $scheduledAt,
                    $isActive,
                ]);
                $savedAnnouncementId = (int) $pdo->lastInsertId();
                log_action($pdo, ugnayan_user_id(), 'Created Calamity Announcement', ugnayan_trim('title'));
                ugnayan_flash_set('Announcement created.');
            }

            foreach (ugnayan_upload_files('attachments', 'module_3_calamity') as $file) {
                $pdo->prepare('INSERT INTO calamity_attachments (announcement_id, file_path, file_name) VALUES (?, ?, ?)')->execute([$savedAnnouncementId, $file['path'], $file['name']]);
            }

            if ($isActive && isset($_POST['notify_residents'])) {
                $count = ugnayan_notify_residents($pdo, ugnayan_trim('title'), ugnayan_trim('message'), 'announcement', $targetPurok);
                ugnayan_flash_set('Announcement saved and notification sent to ' . $count . ' resident(s).');
            }
        } elseif ($action === 'delete_announcement') {
            $announcementId = ugnayan_int('announcement_id');
            $pdo->prepare('DELETE FROM calamity_announcements WHERE announcement_id=?')->execute([$announcementId]);
            log_action($pdo, ugnayan_user_id(), 'Deleted Calamity Announcement', 'Announcement #' . $announcementId);
            ugnayan_flash_set('Announcement deleted.');
        }
    } catch (Throwable $e) {
        ugnayan_flash_set('Action failed: ' . $e->getMessage(), 'error');
    }

    ugnayan_redirect_here('#announcements');
}

$flash = ugnayan_flash_get();

if ($isAdmin) {
    $announcements = $pdo->query('SELECT a.*, u.email AS creator_email FROM calamity_announcements a LEFT JOIN users u ON u.user_id=a.created_by ORDER BY a.announcement_id DESC')->fetchAll();
    $totalAnnouncements = (int) $pdo->query('SELECT COUNT(*) FROM calamity_announcements')->fetchColumn();
    $activeAnnouncements = (int) $pdo->query('SELECT COUNT(*) FROM calamity_announcements WHERE is_active=1')->fetchColumn();
    $urgentAnnouncements = (int) $pdo->query("SELECT COUNT(*) FROM calamity_announcements WHERE priority='Urgent'")->fetchColumn();
} else {
    $stmt = $pdo->prepare('SELECT a.*, u.email AS creator_email FROM calamity_announcements a LEFT JOIN users u ON u.user_id=a.created_by WHERE a.is_active=1 AND (a.target_purok IS NULL OR a.target_purok="" OR a.target_purok=?) ORDER BY a.announcement_id DESC');
    $stmt->execute([$residentPurok]);
    $announcements = $stmt->fetchAll();
    $totalAnnouncements = count($announcements);
    $activeAnnouncements = $totalAnnouncements;
    $urgentAnnouncements = ugnayan_count_by($announcements, 'priority', 'Urgent');
}

$announcementAttachments = [];
$readCounts = [];
$announcementIds = ugnayan_column_int($announcements, 'announcement_id');

if ($announcementIds) {
    if (!$isAdmin) {
        foreach ($announcementIds as $announcementId) {
            $stmt = $pdo->prepare('SELECT read_id FROM calamity_reads WHERE announcement_id=? AND user_id=? LIMIT 1');
            $stmt->execute([$announcementId, ugnayan_user_id()]);
            $readId = (int) $stmt->fetchColumn();

            if ($readId) {
                $pdo->prepare('UPDATE calamity_reads SET is_read=1, read_at=COALESCE(read_at, NOW()) WHERE read_id=?')->execute([$readId]);
            } else {
                $pdo->prepare('INSERT INTO calamity_reads (announcement_id, user_id, is_read, read_at) VALUES (?, ?, 1, NOW())')->execute([$announcementId, ugnayan_user_id()]);
            }
        }
    }

    $placeholders = implode(',', array_fill(0, count($announcementIds), '?'));
    $attachmentStmt = $pdo->prepare('SELECT * FROM calamity_attachments WHERE announcement_id IN (' . $placeholders . ') ORDER BY attachment_id DESC');
    $attachmentStmt->execute($announcementIds);
    foreach ($attachmentStmt->fetchAll() as $attachment) {
        $announcementAttachments[(int) $attachment['announcement_id']][] = $attachment;
    }

    if ($isAdmin) {
        $readStmt = $pdo->prepare('SELECT announcement_id, COUNT(*) AS total_reads FROM calamity_reads WHERE is_read=1 AND announcement_id IN (' . $placeholders . ') GROUP BY announcement_id');
        $readStmt->execute($announcementIds);
        foreach ($readStmt->fetchAll() as $readRow) {
            $readCounts[(int) $readRow['announcement_id']] = (int) $readRow['total_reads'];
        }
    }
}

$recentAnnouncements = array_slice($announcements, 0, 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UGNAYAN - Calamity Announcements</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/module-3.css">
</head>
<body>
    <?php render_ugnayan_sidebar('calamity', [
        'module_label' => 'Module 3',
        'brand_icon' => 'fa-triangle-exclamation',
    ]); ?>
    <main class="main-content">
        <header class="topbar">
            <div class="topbar-title"><h2>Calamity Announcements Module</h2><p><?= $isAdmin ? 'Create, update, delete, and notify residents about emergency alerts' : 'Read official barangay alerts and safety instructions' ?></p></div>
            <div class="topbar-actions"><?php render_ugnayan_topbar_tools($pdo); ?></div>
        </header>
        <section class="module-content">
            <?php if ($flash): ?><div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div><?php endif; ?>

            <div class="stats-grid" id="summary">
                <div class="stat-card"><div class="stat-title">Total Announcements</div><div class="stat-value"><?= $totalAnnouncements ?></div><div class="stat-subtext"><?= $isAdmin ? 'All alerts' : 'Visible to your area' ?></div></div>
                <div class="stat-card"><div class="stat-title">Active Alerts</div><div class="stat-value"><?= $activeAnnouncements ?></div><div class="stat-subtext">Visible to residents</div></div>
                <div class="stat-card"><div class="stat-title">Urgent</div><div class="stat-value"><?= $urgentAnnouncements ?></div><div class="stat-subtext">High priority notices</div></div>
            </div>

            <?php if ($isAdmin): ?>
                <div class="management-grid" id="announcements">
                    <div class="panel">
                        <div class="panel-header"><h3>Create Announcement</h3></div>
                        <form method="POST" class="form-grid" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="save_announcement">
                            <div class="input-group full"><label>Title</label><input name="title" required maxlength="255"></div>
                            <div class="input-group"><label>Type</label><select name="calamity_type"><?php foreach ($types as $type): ?><option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $type))) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Priority</label><select name="priority"><?php foreach ($priorities as $priority): ?><option><?= htmlspecialchars($priority) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Target Purok</label><input name="target_purok" placeholder="Leave blank for all"></div>
                            <div class="input-group"><label>Schedule</label><input type="datetime-local" name="scheduled_at"></div>
                            <div class="input-group full"><label>Message</label><textarea name="message" required></textarea></div>
                            <div class="input-group full"><label>Safety Instructions</label><textarea name="safety_instructions"></textarea></div>
                            <div class="input-group full"><label>Evacuation Plan</label><textarea name="evacuation_plan"></textarea></div>
                            <div class="input-group full"><label>Attachments / Maps / Advisories</label><input type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx"></div>
                            <div class="input-group"><label><input type="checkbox" name="is_active" value="1" checked> Active</label></div>
                            <div class="input-group"><label><input type="checkbox" name="notify_residents" value="1" checked> Notify residents</label></div>
                            <div class="form-actions"><button class="btn-primary"><i class="fa-solid fa-bullhorn"></i> Publish Alert</button></div>
                        </form>
                    </div>

                    <div class="panel">
                        <div class="panel-header"><h3>Manage Announcements</h3></div>
                        <div class="records-table">
                            <table>
                                <thead><tr><th>Alert</th><th>Target</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody>
                                    <?php if (!$announcements): ?><tr><td colspan="4" class="empty-state">No calamity announcements yet.</td></tr><?php endif; ?>
                                    <?php foreach ($announcements as $announcement): ?>
                                        <tr>
                                            <td>
                                                <form method="POST" class="form-grid" enctype="multipart/form-data">
                                                    <input type="hidden" name="action" value="save_announcement">
                                                    <input type="hidden" name="announcement_id" value="<?= (int) $announcement['announcement_id'] ?>">
                                                    <div class="input-group full"><input name="title" value="<?= htmlspecialchars($announcement['title']) ?>" required></div>
                                                    <div class="input-group"><select name="calamity_type"><?php foreach ($types as $type): ?><option value="<?= htmlspecialchars($type) ?>" <?= $announcement['calamity_type'] === $type ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $type))) ?></option><?php endforeach; ?></select></div>
                                                    <div class="input-group"><select name="priority"><?php foreach ($priorities as $priority): ?><option <?= $announcement['priority'] === $priority ? 'selected' : '' ?>><?= htmlspecialchars($priority) ?></option><?php endforeach; ?></select></div>
                                                    <div class="input-group full"><textarea name="message" required><?= htmlspecialchars($announcement['message']) ?></textarea></div>
                                                    <div class="input-group full"><textarea name="safety_instructions" placeholder="Safety instructions"><?= htmlspecialchars($announcement['safety_instructions'] ?? '') ?></textarea></div>
                                                    <div class="input-group full"><textarea name="evacuation_plan" placeholder="Evacuation plan"><?= htmlspecialchars($announcement['evacuation_plan'] ?? '') ?></textarea></div>
                                                    <div class="input-group full"><input type="file" name="attachments[]" multiple accept="image/*,.pdf,.doc,.docx"></div>
                                            </td>
                                            <td>
                                                    <div class="input-group"><input name="target_purok" value="<?= htmlspecialchars($announcement['target_purok'] ?? '') ?>" placeholder="All areas"></div>
                                                    <div class="input-group"><input type="datetime-local" name="scheduled_at" value="<?= htmlspecialchars(ugnayan_date_input($announcement['scheduled_at'] ?? null)) ?>"></div>
                                            </td>
                                            <td>
                                                    <label><input type="checkbox" name="is_active" value="1" <?= $announcement['is_active'] ? 'checked' : '' ?>> Active</label><br>
                                                    <span class="<?= $announcement['priority'] === 'Urgent' ? 'status status-urgent' : 'status status-active' ?>"><?= htmlspecialchars($announcement['priority']) ?></span>
                                                    <p class="muted"><?= (int) ($readCounts[(int) $announcement['announcement_id']] ?? 0) ?> resident read(s)</p>
                                            </td>
                                            <td>
                                                    <label><input type="checkbox" name="notify_residents" value="1"> Notify</label>
                                                    <div class="row-actions">
                                                        <button class="btn-secondary btn-small">Update</button>
                                                </form>
                                                        <form method="POST" class="inline-form">
                                                            <input type="hidden" name="action" value="delete_announcement">
                                                            <input type="hidden" name="announcement_id" value="<?= (int) $announcement['announcement_id'] ?>">
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
                <div class="panel" id="<?= $isAdmin ? 'recent' : 'announcements' ?>">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Recent Announcements' : 'Active Announcements' ?></h3></div>
                    <div class="list-group">
                        <?php if (!$recentAnnouncements): ?><div class="empty-state">No calamity announcements yet.</div><?php endif; ?>
                        <?php foreach ($recentAnnouncements as $announcement): ?>
                            <div class="list-item alert-note">
                                <div class="item-info"><div class="item-icon"><i class="fa-solid fa-bullhorn"></i></div><div><h4><?= htmlspecialchars($announcement['title']) ?></h4><p><?= htmlspecialchars(ucwords(str_replace('_', ' ', $announcement['calamity_type']))) ?> &middot; <?= htmlspecialchars($announcement['target_purok'] ?: 'All areas') ?></p><p><?= htmlspecialchars($announcement['message']) ?></p><?php if ($announcement['safety_instructions']): ?><p><?= htmlspecialchars($announcement['safety_instructions']) ?></p><?php endif; ?><?php if (!empty($announcementAttachments[(int) $announcement['announcement_id']])): ?><div class="attachment-list"><?php foreach ($announcementAttachments[(int) $announcement['announcement_id']] as $attachment): ?><a class="attachment-link" href="<?= htmlspecialchars(ugnayan_url($attachment['file_path'])) ?>" target="_blank"><i class="fa-solid fa-paperclip"></i><?= htmlspecialchars($attachment['file_name'] ?: 'Attachment') ?></a><?php endforeach; ?></div><?php endif; ?></div></div>
                                <span class="<?= $announcement['priority'] === 'Urgent' ? 'status status-urgent' : 'status status-active' ?>"><?= htmlspecialchars($announcement['priority']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Alert Checklist' : 'Resident View Only' ?></h3></div>
                    <div class="list-group">
                        <?php if ($isAdmin): ?>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-plus"></i></div><div><h4>Create</h4><p>Publish alert records</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-pen-to-square"></i></div><div><h4>Update</h4><p>Edit instructions and visibility</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-bell"></i></div><div><h4>Notify</h4><p>Send alerts to all or targeted purok</p></div></div></div>
                        <?php else: ?>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-bullhorn"></i></div><div><h4>View Alerts</h4><p>Read active barangay advisories</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-shield-halved"></i></div><div><h4>Safety Instructions</h4><p>Follow published response steps</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-lock"></i></div><div><h4>Read Only</h4><p>Residents cannot edit announcements</p></div></div></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
