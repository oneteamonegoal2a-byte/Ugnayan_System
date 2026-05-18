<?php
require_once __DIR__ . '/../../module_1_IMVN Module/includes/auth.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/module_helpers.php';

$isAdmin = ugnayan_is_admin_role();
$resident = ugnayan_get_resident($pdo);
$residentId = $resident ? (int) $resident['resident_id'] : null;
$residentPurok = $resident['purok'] ?? null;
$eventTypes = ['event', 'meeting', 'program', 'appointment', 'others'];
$targetGroups = ['All', 'Residents', 'Staff', 'BHW', 'Specific Purok'];
$statuses = ['Scheduled', 'Cancelled', 'Completed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'rsvp_schedule') {
            if (!$residentId) {
                ugnayan_require_admin_access();
            }

            $scheduleId = ugnayan_int('schedule_id');
            $rsvpStatus = ugnayan_trim('rsvp_status', 'Going');
            $stmt = $pdo->prepare('SELECT attendance_id FROM schedule_attendance WHERE schedule_id=? AND user_id=? LIMIT 1');
            $stmt->execute([$scheduleId, ugnayan_user_id()]);
            $attendanceId = (int) $stmt->fetchColumn();

            if ($attendanceId) {
                $pdo->prepare('UPDATE schedule_attendance SET resident_id=?, rsvp_status=?, updated_at=NOW() WHERE attendance_id=?')->execute([$residentId, $rsvpStatus, $attendanceId]);
            } else {
                $pdo->prepare('INSERT INTO schedule_attendance (schedule_id, resident_id, user_id, rsvp_status) VALUES (?, ?, ?, ?)')->execute([$scheduleId, $residentId, ugnayan_user_id(), $rsvpStatus]);
            }

            ugnayan_activity($pdo, $residentId, 'Schedule RSVP', 'RSVP set to ' . $rsvpStatus . '.');
            ugnayan_flash_set('RSVP saved.');
        } else {
            ugnayan_require_admin_access();
        }

        if ($action === 'save_schedule') {
            $scheduleId = ugnayan_int('schedule_id');
            $targetGroup = ugnayan_trim('target_group', 'All');
            $targetPurok = $targetGroup === 'Specific Purok' ? (ugnayan_trim('target_purok') ?: null) : null;
            $start = ugnayan_trim('start_datetime');
            $end = ugnayan_trim('end_datetime');
            $location = ugnayan_trim('location');

            if ($location !== '') {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM schedules WHERE schedule_id<>? AND location=? AND status="Scheduled" AND start_datetime < ? AND end_datetime > ?');
                $stmt->execute([$scheduleId, $location, $end, $start]);
                if ((int) $stmt->fetchColumn() > 0) {
                    throw new RuntimeException('Schedule conflict found for the same location and time.');
                }
            }

            if ($scheduleId > 0) {
                $stmt = $pdo->prepare('UPDATE schedules SET title=?, description=?, event_type=?, target_group=?, target_purok=?, start_datetime=?, end_datetime=?, location=?, status=? WHERE schedule_id=?');
                $stmt->execute([
                    ugnayan_trim('title'),
                    ugnayan_trim('description'),
                    ugnayan_trim('event_type', 'event'),
                    $targetGroup,
                    $targetPurok,
                    $start,
                    $end,
                    $location,
                    ugnayan_trim('status', 'Scheduled'),
                    $scheduleId,
                ]);
                log_action($pdo, ugnayan_user_id(), 'Updated Schedule', ugnayan_trim('title'));
                ugnayan_flash_set('Schedule updated.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO schedules (created_by, title, description, event_type, target_group, target_purok, start_datetime, end_datetime, location, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    ugnayan_user_id(),
                    ugnayan_trim('title'),
                    ugnayan_trim('description'),
                    ugnayan_trim('event_type', 'event'),
                    $targetGroup,
                    $targetPurok,
                    $start,
                    $end,
                    $location,
                    ugnayan_trim('status', 'Scheduled'),
                ]);
                $scheduleId = (int) $pdo->lastInsertId();
                log_action($pdo, ugnayan_user_id(), 'Created Schedule', ugnayan_trim('title'));
                ugnayan_flash_set('Schedule created.');
            }

            if (ugnayan_trim('remind_at') !== '') {
                $pdo->prepare('INSERT INTO schedule_reminders (schedule_id, reminder_message, remind_at, is_sent) VALUES (?, ?, ?, 0)')->execute([$scheduleId, ugnayan_trim('reminder_message', 'Reminder: ' . ugnayan_trim('title')), ugnayan_trim('remind_at')]);
            }

            if (isset($_POST['notify_residents']) && in_array($targetGroup, ['All', 'Residents', 'Specific Purok'], true)) {
                $message = ugnayan_trim('title') . ' is scheduled on ' . date('M d, Y h:i A', strtotime($start)) . '. Location: ' . (ugnayan_trim('location') ?: 'TBA');
                $count = ugnayan_notify_residents($pdo, 'New Barangay Schedule', $message, 'announcement', $targetPurok);
                ugnayan_flash_set('Schedule saved and notification sent to ' . $count . ' resident(s).');
            }
        } elseif ($action === 'delete_schedule') {
            $scheduleId = ugnayan_int('schedule_id');
            $pdo->prepare('DELETE FROM schedules WHERE schedule_id=?')->execute([$scheduleId]);
            log_action($pdo, ugnayan_user_id(), 'Deleted Schedule', 'Schedule #' . $scheduleId);
            ugnayan_flash_set('Schedule deleted.');
        }
    } catch (Throwable $e) {
        ugnayan_flash_set('Action failed: ' . $e->getMessage(), 'error');
    }

    ugnayan_redirect_here('#events');
}

$flash = ugnayan_flash_get();

if ($isAdmin) {
    $schedules = $pdo->query('SELECT * FROM schedules ORDER BY start_datetime ASC')->fetchAll();
    $totalSchedules = (int) $pdo->query('SELECT COUNT(*) FROM schedules')->fetchColumn();
    $scheduledEvents = (int) $pdo->query("SELECT COUNT(*) FROM schedules WHERE status='Scheduled'")->fetchColumn();
    $completedEvents = (int) $pdo->query("SELECT COUNT(*) FROM schedules WHERE status='Completed'")->fetchColumn();
} else {
    $stmt = $pdo->prepare("SELECT * FROM schedules WHERE status='Scheduled' AND (target_group IN ('All','Residents') OR (target_group='Specific Purok' AND target_purok=?)) ORDER BY start_datetime ASC");
    $stmt->execute([$residentPurok]);
    $schedules = $stmt->fetchAll();
    $totalSchedules = count($schedules);
    $scheduledEvents = $totalSchedules;
    $completedEvents = 0;
}

$attendanceBySchedule = [];
$myRsvpBySchedule = [];
$scheduleIds = ugnayan_column_int($schedules, 'schedule_id');

if ($scheduleIds) {
    $placeholders = implode(',', array_fill(0, count($scheduleIds), '?'));

    if ($isAdmin) {
        $attendanceStmt = $pdo->prepare('SELECT sa.*, CONCAT(r.first_name, " ", r.last_name) AS resident_name FROM schedule_attendance sa LEFT JOIN residents r ON r.resident_id=sa.resident_id WHERE sa.schedule_id IN (' . $placeholders . ') ORDER BY sa.updated_at DESC');
        $attendanceStmt->execute($scheduleIds);
        foreach ($attendanceStmt->fetchAll() as $attendance) {
            $attendanceBySchedule[(int) $attendance['schedule_id']][] = $attendance;
        }
    } else {
        $params = $scheduleIds;
        $params[] = ugnayan_user_id();
        $rsvpStmt = $pdo->prepare('SELECT schedule_id, rsvp_status FROM schedule_attendance WHERE schedule_id IN (' . $placeholders . ') AND user_id=?');
        $rsvpStmt->execute($params);
        foreach ($rsvpStmt->fetchAll() as $rsvp) {
            $myRsvpBySchedule[(int) $rsvp['schedule_id']] = $rsvp['rsvp_status'];
        }
    }
}

$upcomingSchedules = array_slice($schedules, 0, 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UGNAYAN - Scheduling</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/module-4.css">
</head>
<body>
    <?php render_ugnayan_sidebar('scheduling', [
        'module_label' => 'Module 4',
        'brand_icon' => 'fa-calendar-days',
    ]); ?>
    <main class="main-content">
        <header class="topbar">
            <div class="topbar-title"><h2>Scheduling Module</h2><p><?= $isAdmin ? 'Create, update, delete, and notify schedules' : 'View barangay schedules published by admin' ?></p></div>
            <div class="topbar-actions"><?php render_ugnayan_topbar_tools($pdo); ?></div>
        </header>
        <section class="module-content">
            <?php if ($flash): ?><div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div><?php endif; ?>

            <div class="stats-grid" id="summary">
                <div class="stat-card"><div class="stat-title">Total Schedules</div><div class="stat-value"><?= $totalSchedules ?></div><div class="stat-subtext"><?= $isAdmin ? 'All calendar entries' : 'Visible entries' ?></div></div>
                <div class="stat-card"><div class="stat-title">Scheduled</div><div class="stat-value"><?= $scheduledEvents ?></div><div class="stat-subtext">Upcoming or active</div></div>
                <div class="stat-card"><div class="stat-title">Completed</div><div class="stat-value"><?= $completedEvents ?></div><div class="stat-subtext">Finished activities</div></div>
            </div>

            <?php if ($isAdmin): ?>
                <div class="management-grid" id="events">
                    <div class="panel">
                        <div class="panel-header"><h3>Create Schedule</h3></div>
                        <form method="POST" class="form-grid">
                            <input type="hidden" name="action" value="save_schedule">
                            <div class="input-group full"><label>Title</label><input name="title" required maxlength="255"></div>
                            <div class="input-group"><label>Type</label><select name="event_type"><?php foreach ($eventTypes as $type): ?><option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars(ucwords($type)) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Status</label><select name="status"><?php foreach ($statuses as $status): ?><option><?= htmlspecialchars($status) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Target Group</label><select name="target_group"><?php foreach ($targetGroups as $group): ?><option><?= htmlspecialchars($group) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Target Purok</label><input name="target_purok" placeholder="For specific purok"></div>
                            <div class="input-group"><label>Start</label><input type="datetime-local" name="start_datetime" required></div>
                            <div class="input-group"><label>End</label><input type="datetime-local" name="end_datetime" required></div>
                            <div class="input-group full"><label>Location</label><input name="location"></div>
                            <div class="input-group full"><label>Description</label><textarea name="description"></textarea></div>
                            <div class="input-group"><label>Reminder Time</label><input type="datetime-local" name="remind_at"></div>
                            <div class="input-group"><label>Reminder Message</label><input name="reminder_message" placeholder="Optional reminder text"></div>
                            <div class="input-group full"><label><input type="checkbox" name="notify_residents" value="1" checked> Notify residents when saved</label></div>
                            <div class="form-actions"><button class="btn-primary"><i class="fa-solid fa-calendar-plus"></i> Save Schedule</button></div>
                        </form>
                    </div>

                    <div class="panel">
                        <div class="panel-header"><h3>Manage Schedules</h3></div>
                        <div class="records-table">
                            <table>
                                <thead><tr><th>Schedule</th><th>Timing</th><th>Target</th><th>Actions</th></tr></thead>
                                <tbody>
                                    <?php if (!$schedules): ?><tr><td colspan="4" class="empty-state">No schedules recorded yet.</td></tr><?php endif; ?>
                                    <?php foreach ($schedules as $schedule): ?>
                                        <tr>
                                            <td>
                                                <form method="POST" class="form-grid">
                                                    <input type="hidden" name="action" value="save_schedule">
                                                    <input type="hidden" name="schedule_id" value="<?= (int) $schedule['schedule_id'] ?>">
                                                    <div class="input-group full"><input name="title" value="<?= htmlspecialchars($schedule['title']) ?>" required></div>
                                                    <div class="input-group"><select name="event_type"><?php foreach ($eventTypes as $type): ?><option value="<?= htmlspecialchars($type) ?>" <?= $schedule['event_type'] === $type ? 'selected' : '' ?>><?= htmlspecialchars(ucwords($type)) ?></option><?php endforeach; ?></select></div>
                                                    <div class="input-group"><select name="status"><?php foreach ($statuses as $status): ?><option <?= $schedule['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option><?php endforeach; ?></select></div>
                                                    <div class="input-group full"><textarea name="description"><?= htmlspecialchars($schedule['description'] ?? '') ?></textarea></div>
                                            </td>
                                            <td>
                                                    <div class="input-group"><input type="datetime-local" name="start_datetime" value="<?= htmlspecialchars(ugnayan_date_input($schedule['start_datetime'])) ?>" required></div>
                                                    <div class="input-group"><input type="datetime-local" name="end_datetime" value="<?= htmlspecialchars(ugnayan_date_input($schedule['end_datetime'])) ?>" required></div>
                                                    <div class="input-group"><input name="location" value="<?= htmlspecialchars($schedule['location'] ?? '') ?>" placeholder="Location"></div>
                                                    <div class="input-group"><input type="datetime-local" name="remind_at" placeholder="Reminder"></div>
                                                    <div class="input-group"><input name="reminder_message" placeholder="Reminder message"></div>
                                            </td>
                                            <td>
                                                    <div class="input-group"><select name="target_group"><?php foreach ($targetGroups as $group): ?><option <?= $schedule['target_group'] === $group ? 'selected' : '' ?>><?= htmlspecialchars($group) ?></option><?php endforeach; ?></select></div>
                                                    <div class="input-group"><input name="target_purok" value="<?= htmlspecialchars($schedule['target_purok'] ?? '') ?>" placeholder="Purok"></div>
                                                    <span class="<?= ugnayan_status_class($schedule['status']) ?>"><?= htmlspecialchars($schedule['status']) ?></span>
                                            </td>
                                            <td>
                                                    <label><input type="checkbox" name="notify_residents" value="1"> Notify</label>
                                                    <div class="row-actions">
                                                        <button class="btn-secondary btn-small">Update</button>
                                                </form>
                                                        <form method="POST" class="inline-form">
                                                            <input type="hidden" name="action" value="delete_schedule">
                                                            <input type="hidden" name="schedule_id" value="<?= (int) $schedule['schedule_id'] ?>">
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

            <?php if ($isAdmin): ?>
                <div class="panel">
                    <div class="panel-header"><h3>Attendance and RSVP Tracker</h3></div>
                    <div class="records-table">
                        <table>
                            <thead><tr><th>Schedule</th><th>Resident</th><th>RSVP</th><th>Attended</th></tr></thead>
                            <tbody>
                                <?php $hasAttendance = false; ?>
                                <?php foreach ($schedules as $schedule): ?>
                                    <?php foreach ($attendanceBySchedule[(int) $schedule['schedule_id']] ?? [] as $attendance): $hasAttendance = true; ?>
                                        <tr>
                                            <td><?= htmlspecialchars($schedule['title']) ?></td>
                                            <td><?= htmlspecialchars($attendance['resident_name'] ?: 'Resident') ?></td>
                                            <td><span class="<?= ugnayan_status_class($attendance['rsvp_status']) ?>"><?= htmlspecialchars($attendance['rsvp_status']) ?></span></td>
                                            <td><?= $attendance['attended'] ? 'Yes' : 'No' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                                <?php if (!$hasAttendance): ?><tr><td colspan="4" class="empty-state">No RSVP records yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <div class="module-grid">
                <div class="panel" id="<?= $isAdmin ? 'upcoming' : 'events' ?>">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Upcoming Schedules' : 'Published Schedules' ?></h3></div>
                    <div class="list-group">
                        <?php if (!$upcomingSchedules): ?><div class="empty-state">No schedules recorded yet.</div><?php endif; ?>
                        <?php foreach ($upcomingSchedules as $schedule): ?>
                            <div class="list-item">
                                <div class="item-info"><div class="item-icon"><i class="fa-solid fa-calendar-day"></i></div><div><h4><?= htmlspecialchars($schedule['title']) ?></h4><p><span class="calendar-chip"><?= htmlspecialchars(date('M d, Y h:i A', strtotime($schedule['start_datetime']))) ?></span> &middot; <?= htmlspecialchars($schedule['location'] ?: 'No location set') ?></p><p><?= htmlspecialchars($schedule['description'] ?: '') ?></p><?php if ($isAdmin && !empty($attendanceBySchedule[(int) $schedule['schedule_id']])): ?><p><?= count($attendanceBySchedule[(int) $schedule['schedule_id']]) ?> RSVP(s) recorded</p><?php endif; ?></div></div>
                                <?php if ($isAdmin): ?>
                                    <span class="<?= ugnayan_status_class($schedule['status']) ?>"><?= htmlspecialchars($schedule['status']) ?></span>
                                <?php else: ?>
                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="action" value="rsvp_schedule">
                                        <input type="hidden" name="schedule_id" value="<?= (int) $schedule['schedule_id'] ?>">
                                        <select name="rsvp_status">
                                            <?php foreach (['Going', 'Not Going', 'Pending'] as $rsvpStatus): ?><option <?= ($myRsvpBySchedule[(int) $schedule['schedule_id']] ?? 'Pending') === $rsvpStatus ? 'selected' : '' ?>><?= htmlspecialchars($rsvpStatus) ?></option><?php endforeach; ?>
                                        </select>
                                        <button class="btn-secondary btn-small">RSVP</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Schedule Tools' : 'Resident View Only' ?></h3></div>
                    <div class="list-group">
                        <?php if ($isAdmin): ?>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-plus"></i></div><div><h4>Create</h4><p>Add events, meetings, programs, and appointments</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-pen-to-square"></i></div><div><h4>Update</h4><p>Change schedule details and status</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-bell"></i></div><div><h4>Notify</h4><p>Send schedule notifications</p></div></div></div>
                        <?php else: ?>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-calendar-days"></i></div><div><h4>View Calendar</h4><p>See official barangay events and meetings</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-lock"></i></div><div><h4>Read Only</h4><p>Residents cannot change schedules</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-heart-pulse"></i></div><div><h4>Health Appointments</h4><p>Use Healthcare for appointment requests</p></div></div></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
