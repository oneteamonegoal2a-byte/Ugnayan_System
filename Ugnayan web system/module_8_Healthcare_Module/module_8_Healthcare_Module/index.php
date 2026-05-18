<?php
require_once __DIR__ . '/../../module_1_IMVN Module/includes/auth.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/module_helpers.php';

$isAdmin = ugnayan_is_admin_role();
$resident = ugnayan_get_resident($pdo);
$residentId = $resident ? (int) $resident['resident_id'] : null;
$residentPurok = $resident['purok'] ?? null;
$programTypes = ['vaccination', 'checkup', 'medical_mission', 'health_tips', 'others'];
$appointmentStatuses = ['Pending', 'Confirmed', 'Completed', 'Cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_program') {
            ugnayan_require_admin_access();

            $programId = ugnayan_int('program_id');
            $targetPurok = ugnayan_trim('target_purok') ?: null;
            $scheduleDate = ugnayan_trim('schedule_date');

            if ($programId > 0) {
                $stmt = $pdo->prepare('UPDATE health_programs SET title=?, description=?, program_type=?, schedule_date=?, location=?, target_purok=? WHERE program_id=?');
                $stmt->execute([
                    ugnayan_trim('title'),
                    ugnayan_trim('description'),
                    ugnayan_trim('program_type', 'others'),
                    $scheduleDate,
                    ugnayan_trim('location'),
                    $targetPurok,
                    $programId,
                ]);
                log_action($pdo, ugnayan_user_id(), 'Updated Health Program', ugnayan_trim('title'));
                ugnayan_flash_set('Health program updated.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO health_programs (created_by, title, description, program_type, schedule_date, location, target_purok) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    ugnayan_user_id(),
                    ugnayan_trim('title'),
                    ugnayan_trim('description'),
                    ugnayan_trim('program_type', 'others'),
                    $scheduleDate,
                    ugnayan_trim('location'),
                    $targetPurok,
                ]);
                log_action($pdo, ugnayan_user_id(), 'Created Health Program', ugnayan_trim('title'));
                ugnayan_flash_set('Health program created.');
            }

            if (isset($_POST['notify_residents'])) {
                $message = ugnayan_trim('title') . ' is scheduled on ' . date('M d, Y h:i A', strtotime($scheduleDate)) . '. Location: ' . (ugnayan_trim('location') ?: 'TBA');
                $count = ugnayan_notify_residents($pdo, 'Health Program Announcement', $message, 'announcement', $targetPurok);
                ugnayan_flash_set('Health program saved and notification sent to ' . $count . ' resident(s).');
            }
        } elseif ($action === 'delete_program') {
            ugnayan_require_admin_access();

            $programId = ugnayan_int('program_id');
            $pdo->prepare('DELETE FROM health_programs WHERE program_id=?')->execute([$programId]);
            log_action($pdo, ugnayan_user_id(), 'Deleted Health Program', 'Program #' . $programId);
            ugnayan_flash_set('Health program deleted.');
        } elseif ($action === 'save_health_record') {
            ugnayan_require_admin_access();

            $targetResidentId = ugnayan_int('resident_id');
            $stmt = $pdo->prepare('SELECT health_record_id FROM health_records WHERE resident_id=? LIMIT 1');
            $stmt->execute([$targetResidentId]);
            $recordId = (int) $stmt->fetchColumn();

            if ($recordId) {
                $stmt = $pdo->prepare('UPDATE health_records SET blood_type=?, allergies=?, medical_conditions=?, emergency_contact_name=?, emergency_contact_number=?, updated_by=?, updated_at=NOW() WHERE health_record_id=?');
                $stmt->execute([ugnayan_trim('blood_type'), ugnayan_trim('allergies'), ugnayan_trim('medical_conditions'), ugnayan_trim('emergency_contact_name'), ugnayan_trim('emergency_contact_number'), ugnayan_user_id(), $recordId]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO health_records (resident_id, blood_type, allergies, medical_conditions, emergency_contact_name, emergency_contact_number, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$targetResidentId, ugnayan_trim('blood_type'), ugnayan_trim('allergies'), ugnayan_trim('medical_conditions'), ugnayan_trim('emergency_contact_name'), ugnayan_trim('emergency_contact_number'), ugnayan_user_id()]);
            }

            ugnayan_activity($pdo, $targetResidentId, 'Health Record Updated', 'Barangay health record was updated.');
            log_action($pdo, ugnayan_user_id(), 'Updated Health Record', 'Resident #' . $targetResidentId);
            ugnayan_flash_set('Health record saved.');
        } elseif ($action === 'request_appointment') {
            if (!$residentId) {
                ugnayan_require_admin_access();
            }

            $programId = ugnayan_int('program_id') ?: null;
            $stmt = $pdo->prepare('INSERT INTO health_appointments (program_id, resident_id, appointment_date, status, notes) VALUES (?, ?, ?, "Pending", ?)');
            $stmt->execute([$programId, $residentId, ugnayan_trim('appointment_date'), ugnayan_trim('notes')]);
            ugnayan_activity($pdo, $residentId, 'Health Appointment', 'Requested a health appointment.');
            log_action($pdo, ugnayan_user_id(), 'Requested Health Appointment', 'Resident #' . $residentId);
            ugnayan_flash_set('Health appointment request submitted.');
        } elseif ($action === 'update_appointment') {
            ugnayan_require_admin_access();

            $appointmentId = ugnayan_int('appointment_id');
            $status = ugnayan_trim('status', 'Pending');
            $notes = ugnayan_trim('notes');
            $appointmentDate = ugnayan_trim('appointment_date');

            $stmt = $pdo->prepare('UPDATE health_appointments SET appointment_date=?, status=?, notes=? WHERE appointment_id=?');
            $stmt->execute([$appointmentDate, $status, $notes, $appointmentId]);

            $stmt = $pdo->prepare('SELECT ha.*, hp.title AS program_title, r.user_id FROM health_appointments ha LEFT JOIN health_programs hp ON hp.program_id=ha.program_id JOIN residents r ON r.resident_id=ha.resident_id WHERE ha.appointment_id=?');
            $stmt->execute([$appointmentId]);
            $appointment = $stmt->fetch();

            if ($appointment) {
                $message = 'Your health appointment is now ' . $status . ' for ' . date('M d, Y h:i A', strtotime($appointmentDate)) . '. ' . $notes;
                ugnayan_notify_user($pdo, (int) $appointment['user_id'], 'Health Appointment Updated', $message, 'request_update');
                ugnayan_activity($pdo, (int) $appointment['resident_id'], 'Health Appointment Updated', $message);
            }

            log_action($pdo, ugnayan_user_id(), 'Updated Health Appointment', 'Appointment #' . $appointmentId . ' set to ' . $status);
            ugnayan_flash_set('Appointment updated.');
        } elseif ($action === 'delete_appointment') {
            ugnayan_require_admin_access();

            $appointmentId = ugnayan_int('appointment_id');
            $pdo->prepare('DELETE FROM health_appointments WHERE appointment_id=?')->execute([$appointmentId]);
            log_action($pdo, ugnayan_user_id(), 'Deleted Health Appointment', 'Appointment #' . $appointmentId);
            ugnayan_flash_set('Appointment deleted.');
        }
    } catch (Throwable $e) {
        ugnayan_flash_set('Action failed: ' . $e->getMessage(), 'error');
    }

    ugnayan_redirect_here('#programs');
}

$flash = ugnayan_flash_get();
$approvedResidents = $isAdmin ? $pdo->query("SELECT resident_id, first_name, last_name, purok FROM residents WHERE application_status='Approved' ORDER BY last_name, first_name")->fetchAll() : [];

if ($isAdmin) {
    $programs = $pdo->query('SELECT * FROM health_programs ORDER BY schedule_date ASC')->fetchAll();
    $stmt = $pdo->query('SELECT ha.*, hp.title AS program_title, CONCAT(r.first_name, " ", r.last_name) AS resident_name, r.purok FROM health_appointments ha LEFT JOIN health_programs hp ON hp.program_id=ha.program_id JOIN residents r ON r.resident_id=ha.resident_id ORDER BY ha.appointment_id DESC');
    $healthRecords = $pdo->query('SELECT hr.*, CONCAT(r.first_name, " ", r.last_name) AS resident_name, r.purok FROM health_records hr JOIN residents r ON r.resident_id=hr.resident_id ORDER BY hr.updated_at DESC')->fetchAll();
} else {
    $stmt = $pdo->prepare('SELECT * FROM health_programs WHERE target_purok IS NULL OR target_purok="" OR target_purok=? ORDER BY schedule_date ASC');
    $stmt->execute([$residentPurok]);
    $programs = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT ha.*, hp.title AS program_title, CONCAT(r.first_name, " ", r.last_name) AS resident_name, r.purok FROM health_appointments ha LEFT JOIN health_programs hp ON hp.program_id=ha.program_id JOIN residents r ON r.resident_id=ha.resident_id WHERE ha.resident_id=? ORDER BY ha.appointment_id DESC');
    $stmt->execute([$residentId]);
    $recordStmt = $pdo->prepare('SELECT hr.*, CONCAT(r.first_name, " ", r.last_name) AS resident_name, r.purok FROM health_records hr JOIN residents r ON r.resident_id=hr.resident_id WHERE hr.resident_id=? ORDER BY hr.updated_at DESC');
    $recordStmt->execute([$residentId]);
    $healthRecords = $recordStmt->fetchAll();
}

$appointments = $stmt->fetchAll();
$totalPrograms = count($programs);
$totalAppointments = count($appointments);
$confirmedAppointments = ugnayan_count_by($appointments, 'status', 'Confirmed');
$recentPrograms = array_slice($programs, 0, 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UGNAYAN - Healthcare</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/module-8.css">
</head>
<body>
    <?php render_ugnayan_sidebar('healthcare', [
        'module_label' => 'Module 8',
        'brand_icon' => 'fa-heart-pulse',
    ]); ?>
    <main class="main-content">
        <header class="topbar">
            <div class="topbar-title"><h2>Healthcare Module</h2><p><?= $isAdmin ? 'CRUD health programs and manage resident appointments' : 'View programs and request health appointments' ?></p></div>
            <div class="topbar-actions"><?php render_ugnayan_topbar_tools($pdo); ?></div>
        </header>
        <section class="module-content">
            <?php if ($flash): ?><div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div><?php endif; ?>

            <div class="stats-grid" id="summary">
                <div class="stat-card"><div class="stat-title">Health Programs</div><div class="stat-value"><?= $totalPrograms ?></div><div class="stat-subtext">Programs scheduled</div></div>
                <div class="stat-card"><div class="stat-title">Appointments</div><div class="stat-value"><?= $totalAppointments ?></div><div class="stat-subtext"><?= $isAdmin ? 'All appointment records' : 'Your appointment requests' ?></div></div>
                <div class="stat-card"><div class="stat-title">Confirmed</div><div class="stat-value"><?= $confirmedAppointments ?></div><div class="stat-subtext">Ready for service</div></div>
            </div>

            <?php if ($isAdmin): ?>
                <div class="panel">
                    <div class="panel-header"><h3>Community Health Statistics</h3></div>
                    <div class="report-grid">
                        <div class="report-tile"><span class="muted">Health Records</span><strong><?= count($healthRecords) ?></strong></div>
                        <div class="report-tile"><span class="muted">Pending Appointments</span><strong><?= ugnayan_count_by($appointments, 'status', 'Pending') ?></strong></div>
                        <div class="report-tile"><span class="muted">Completed Appointments</span><strong><?= ugnayan_count_by($appointments, 'status', 'Completed') ?></strong></div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="management-grid" id="programs">
                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Create Health Program' : 'Request Health Appointment' ?></h3></div>
                    <?php if ($isAdmin): ?>
                        <form method="POST" class="form-grid">
                            <input type="hidden" name="action" value="save_program">
                            <div class="input-group full"><label>Title</label><input name="title" required maxlength="255"></div>
                            <div class="input-group"><label>Program Type</label><select name="program_type"><?php foreach ($programTypes as $type): ?><option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $type))) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Schedule</label><input type="datetime-local" name="schedule_date" required></div>
                            <div class="input-group"><label>Location</label><input name="location"></div>
                            <div class="input-group"><label>Target Purok</label><input name="target_purok" placeholder="Leave blank for all"></div>
                            <div class="input-group full"><label>Description</label><textarea name="description"></textarea></div>
                            <div class="input-group full"><label><input type="checkbox" name="notify_residents" value="1" checked> Notify residents</label></div>
                            <div class="form-actions"><button class="btn-primary"><i class="fa-solid fa-plus"></i> Save Program</button></div>
                        </form>
                    <?php else: ?>
                        <form method="POST" class="form-grid">
                            <input type="hidden" name="action" value="request_appointment">
                            <div class="input-group full"><label>Program</label><select name="program_id"><option value="">General health appointment</option><?php foreach ($programs as $program): ?><option value="<?= (int) $program['program_id'] ?>"><?= htmlspecialchars($program['title']) ?> - <?= htmlspecialchars(date('M d, Y', strtotime($program['schedule_date']))) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group full"><label>Preferred Appointment Date</label><input type="datetime-local" name="appointment_date" required></div>
                            <div class="input-group full"><label>Notes / Concern</label><textarea name="notes" required></textarea></div>
                            <div class="form-actions"><button class="btn-primary"><i class="fa-solid fa-calendar-check"></i> Submit Appointment</button></div>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Manage Health Programs' : 'Available Health Programs' ?></h3></div>
                    <div class="records-table">
                        <table>
                            <thead><tr><th>Program</th><th>Schedule</th><th>Target</th><th><?= $isAdmin ? 'Actions' : 'Details' ?></th></tr></thead>
                            <tbody>
                                <?php if (!$programs): ?><tr><td colspan="4" class="empty-state">No health programs recorded yet.</td></tr><?php endif; ?>
                                <?php foreach ($programs as $program): ?>
                                    <tr>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                <form method="POST" class="form-grid">
                                                    <input type="hidden" name="action" value="save_program">
                                                    <input type="hidden" name="program_id" value="<?= (int) $program['program_id'] ?>">
                                                    <div class="input-group full"><input name="title" value="<?= htmlspecialchars($program['title']) ?>" required></div>
                                                    <div class="input-group"><select name="program_type"><?php foreach ($programTypes as $type): ?><option value="<?= htmlspecialchars($type) ?>" <?= $program['program_type'] === $type ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $type))) ?></option><?php endforeach; ?></select></div>
                                                    <div class="input-group full"><textarea name="description"><?= htmlspecialchars($program['description'] ?? '') ?></textarea></div>
                                            <?php else: ?>
                                                <strong><?= htmlspecialchars($program['title']) ?></strong><br><span class="muted"><?= htmlspecialchars($program['description'] ?? '') ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                    <input type="datetime-local" name="schedule_date" value="<?= htmlspecialchars(ugnayan_date_input($program['schedule_date'])) ?>" required>
                                                    <input name="location" value="<?= htmlspecialchars($program['location'] ?? '') ?>" placeholder="Location">
                                            <?php else: ?>
                                                <span class="health-date"><?= htmlspecialchars(date('M d, Y h:i A', strtotime($program['schedule_date']))) ?></span><br><?= htmlspecialchars($program['location'] ?: 'No location set') ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                    <input name="target_purok" value="<?= htmlspecialchars($program['target_purok'] ?? '') ?>" placeholder="All purok">
                                                    <label><input type="checkbox" name="notify_residents" value="1"> Notify</label>
                                            <?php else: ?>
                                                <?= htmlspecialchars($program['target_purok'] ?: 'All areas') ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                    <div class="row-actions"><button class="btn-secondary btn-small">Update</button>
                                                </form>
                                                        <form method="POST" class="inline-form">
                                                            <input type="hidden" name="action" value="delete_program">
                                                            <input type="hidden" name="program_id" value="<?= (int) $program['program_id'] ?>">
                                                            <button class="btn-danger btn-small">Delete</button>
                                                        </form>
                                                    </div>
                                            <?php else: ?>
                                                <span class="status status-active"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $program['program_type']))) ?></span>
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
                <?php if ($isAdmin): ?>
                    <div class="panel">
                        <div class="panel-header"><h3>Manage Basic Health Record</h3></div>
                        <form method="POST" class="form-grid">
                            <input type="hidden" name="action" value="save_health_record">
                            <div class="input-group full"><label>Resident</label><select name="resident_id" required><?php foreach ($approvedResidents as $person): ?><option value="<?= (int) $person['resident_id'] ?>"><?= htmlspecialchars($person['last_name'] . ', ' . $person['first_name'] . ' - ' . $person['purok']) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Blood Type</label><input name="blood_type" maxlength="5"></div>
                            <div class="input-group"><label>Emergency Contact Number</label><input name="emergency_contact_number"></div>
                            <div class="input-group full"><label>Emergency Contact Name</label><input name="emergency_contact_name"></div>
                            <div class="input-group full"><label>Allergies</label><textarea name="allergies"></textarea></div>
                            <div class="input-group full"><label>Medical Conditions</label><textarea name="medical_conditions"></textarea></div>
                            <div class="form-actions"><button class="btn-primary"><i class="fa-solid fa-notes-medical"></i> Save Record</button></div>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Health Records' : 'My Basic Health Record' ?></h3></div>
                    <div class="list-group">
                        <?php if (!$healthRecords): ?><div class="empty-state">No health records yet.</div><?php endif; ?>
                        <?php foreach (array_slice($healthRecords, 0, 8) as $record): ?>
                            <div class="list-item">
                                <div class="item-info"><div class="item-icon"><i class="fa-solid fa-notes-medical"></i></div><div><h4><?= htmlspecialchars($record['resident_name']) ?></h4><p>Blood: <?= htmlspecialchars($record['blood_type'] ?: 'N/A') ?> &middot; <?= htmlspecialchars($record['medical_conditions'] ?: 'No conditions listed') ?></p></div></div>
                                <span class="muted"><?= htmlspecialchars(date('M d, Y', strtotime($record['updated_at']))) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header"><h3><?= $isAdmin ? 'Manage Appointments' : 'My Health Appointments' ?></h3></div>
                <div class="records-table">
                    <table>
                        <thead><tr><th>Appointment</th><th>Date</th><th>Status</th><th><?= $isAdmin ? 'Admin Action' : 'Notes' ?></th></tr></thead>
                        <tbody>
                            <?php if (!$appointments): ?><tr><td colspan="4" class="empty-state">No health appointments yet.</td></tr><?php endif; ?>
                            <?php foreach ($appointments as $appointment): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($appointment['program_title'] ?: 'General appointment') ?></strong><br><span class="muted"><?= htmlspecialchars($appointment['resident_name']) ?> - <?= htmlspecialchars($appointment['purok']) ?></span></td>
                                    <td><?= htmlspecialchars(date('M d, Y h:i A', strtotime($appointment['appointment_date']))) ?></td>
                                    <td><span class="<?= ugnayan_status_class($appointment['status']) ?>"><?= htmlspecialchars($appointment['status']) ?></span></td>
                                    <td>
                                        <?php if ($isAdmin): ?>
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="action" value="update_appointment">
                                                <input type="hidden" name="appointment_id" value="<?= (int) $appointment['appointment_id'] ?>">
                                                <input type="datetime-local" name="appointment_date" value="<?= htmlspecialchars(ugnayan_date_input($appointment['appointment_date'])) ?>" required>
                                                <select name="status"><?php foreach ($appointmentStatuses as $status): ?><option <?= $appointment['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option><?php endforeach; ?></select>
                                                <input name="notes" placeholder="Notes" value="<?= htmlspecialchars($appointment['notes'] ?? '') ?>">
                                                <button class="btn-secondary btn-small">Update</button>
                                            </form>
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="action" value="delete_appointment">
                                                <input type="hidden" name="appointment_id" value="<?= (int) $appointment['appointment_id'] ?>">
                                                <button class="btn-danger btn-small">Delete</button>
                                            </form>
                                        <?php else: ?>
                                            <?= htmlspecialchars($appointment['notes'] ?: 'Waiting for BHW/admin notes') ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="module-grid">
                <div class="panel">
                    <div class="panel-header"><h3>Upcoming Health Programs</h3></div>
                    <div class="list-group">
                        <?php if (!$recentPrograms): ?><div class="empty-state">No health programs recorded yet.</div><?php endif; ?>
                        <?php foreach ($recentPrograms as $program): ?>
                            <div class="list-item">
                                <div class="item-info"><div class="item-icon"><i class="fa-solid fa-stethoscope"></i></div><div><h4><?= htmlspecialchars($program['title']) ?></h4><p><span class="health-date"><?= htmlspecialchars(date('M d, Y', strtotime($program['schedule_date']))) ?></span> &middot; <?= htmlspecialchars($program['location'] ?: 'No location set') ?></p></div></div>
                                <span class="status status-active"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $program['program_type']))) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Healthcare Areas' : 'Resident Access' ?></h3></div>
                    <div class="list-group">
                        <?php if ($isAdmin): ?>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-syringe"></i></div><div><h4>Programs</h4><p>Create and edit health services</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-calendar-check"></i></div><div><h4>Appointments</h4><p>Confirm, complete, or cancel requests</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-bell"></i></div><div><h4>Reminders</h4><p>Notify residents about programs</p></div></div></div>
                        <?php else: ?>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-syringe"></i></div><div><h4>Programs</h4><p>View vaccination and health programs</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-calendar-check"></i></div><div><h4>Appointments</h4><p>Request health appointments</p></div></div></div>
                            <div class="list-item"><div class="item-info"><div class="item-icon"><i class="fa-solid fa-lock"></i></div><div><h4>Read Only</h4><p>You cannot edit admin records</p></div></div></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
