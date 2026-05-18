<?php
require_once __DIR__ . '/../../module_1_IMVN Module/includes/auth.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/module_helpers.php';

$isAdmin = ugnayan_is_admin_role();
$resident = ugnayan_get_resident($pdo);
$residentId = $resident ? (int) $resident['resident_id'] : null;
$residentPurok = $resident['purok'] ?? null;
$profileStatuses = ['Active', 'Inactive'];
$priorityTypes = ['Pregnant Woman', 'Infant/Child', 'Senior Citizen', 'PWD', 'High Risk'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        ugnayan_require_admin_access();

        if ($action === 'save_bhw_profile') {
            $bhwId = ugnayan_int('bhw_id');

            if ($bhwId > 0) {
                $stmt = $pdo->prepare('UPDATE bhw_profiles SET user_id=?, assigned_purok=?, contact_number=?, status=? WHERE bhw_id=?');
                $stmt->execute([
                    ugnayan_int('user_id'),
                    ugnayan_trim('assigned_purok'),
                    ugnayan_trim('contact_number'),
                    ugnayan_trim('status', 'Active'),
                    $bhwId,
                ]);
                log_action($pdo, ugnayan_user_id(), 'Updated BHW Profile', 'BHW profile #' . $bhwId);
                ugnayan_flash_set('BHW profile updated.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO bhw_profiles (user_id, assigned_purok, contact_number, status) VALUES (?, ?, ?, ?)');
                $stmt->execute([
                    ugnayan_int('user_id'),
                    ugnayan_trim('assigned_purok'),
                    ugnayan_trim('contact_number'),
                    ugnayan_trim('status', 'Active'),
                ]);
                log_action($pdo, ugnayan_user_id(), 'Created BHW Profile', 'Assigned ' . ugnayan_trim('assigned_purok'));
                ugnayan_flash_set('BHW profile created.');
            }
        } elseif ($action === 'delete_bhw_profile') {
            $bhwId = ugnayan_int('bhw_id');
            $pdo->prepare('DELETE FROM bhw_profiles WHERE bhw_id=?')->execute([$bhwId]);
            log_action($pdo, ugnayan_user_id(), 'Deleted BHW Profile', 'BHW profile #' . $bhwId);
            ugnayan_flash_set('BHW profile deleted.');
        } elseif ($action === 'save_household') {
            $householdId = ugnayan_int('household_id');
            $headId = ugnayan_int('household_head_resident_id') ?: null;
            $bhwId = ugnayan_int('assigned_bhw_id') ?: null;

            if ($householdId > 0) {
                $stmt = $pdo->prepare('UPDATE household_profiles SET household_head_resident_id=?, purok=?, address=?, household_number=?, assigned_bhw_id=? WHERE household_id=?');
                $stmt->execute([$headId, ugnayan_trim('purok'), ugnayan_trim('address'), ugnayan_trim('household_number'), $bhwId, $householdId]);
                log_action($pdo, ugnayan_user_id(), 'Updated Household', 'Household #' . $householdId);
                ugnayan_flash_set('Household updated.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO household_profiles (household_head_resident_id, purok, address, household_number, assigned_bhw_id) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$headId, ugnayan_trim('purok'), ugnayan_trim('address'), ugnayan_trim('household_number'), $bhwId]);
                log_action($pdo, ugnayan_user_id(), 'Created Household', ugnayan_trim('household_number'));
                ugnayan_flash_set('Household created.');
            }
        } elseif ($action === 'delete_household') {
            $householdId = ugnayan_int('household_id');
            $pdo->prepare('DELETE FROM household_profiles WHERE household_id=?')->execute([$householdId]);
            log_action($pdo, ugnayan_user_id(), 'Deleted Household', 'Household #' . $householdId);
            ugnayan_flash_set('Household deleted.');
        } elseif ($action === 'save_visit') {
            $visitId = ugnayan_int('visit_id');
            $householdId = ugnayan_int('household_id');
            $bhwId = ugnayan_int('bhw_id') ?: null;
            $nextVisit = ugnayan_trim('next_visit_date') ?: null;

            if ($visitId > 0) {
                $stmt = $pdo->prepare('UPDATE bhw_home_visits SET household_id=?, bhw_id=?, visit_date=?, observations=?, recommendations=?, next_visit_date=? WHERE visit_id=?');
                $stmt->execute([$householdId, $bhwId, ugnayan_trim('visit_date'), ugnayan_trim('observations'), ugnayan_trim('recommendations'), $nextVisit, $visitId]);
                log_action($pdo, ugnayan_user_id(), 'Updated Home Visit', 'Visit #' . $visitId);
                ugnayan_flash_set('Home visit updated.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO bhw_home_visits (household_id, bhw_id, visit_date, observations, recommendations, next_visit_date) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$householdId, $bhwId, ugnayan_trim('visit_date'), ugnayan_trim('observations'), ugnayan_trim('recommendations'), $nextVisit]);
                log_action($pdo, ugnayan_user_id(), 'Created Home Visit', 'Household #' . $householdId);
                ugnayan_flash_set('Home visit recorded.');
            }

            $stmt = $pdo->prepare('SELECT household_head_resident_id FROM household_profiles WHERE household_id=?');
            $stmt->execute([$householdId]);
            $headId = (int) $stmt->fetchColumn();
            if ($headId) {
                $stmt = $pdo->prepare('SELECT user_id FROM residents WHERE resident_id=?');
                $stmt->execute([$headId]);
                $message = 'A BHW home visit has been recorded for your household.';
                if ($nextVisit) {
                    $message .= ' Next visit: ' . date('M d, Y', strtotime($nextVisit)) . '.';
                }
                ugnayan_notify_user($pdo, (int) $stmt->fetchColumn(), 'BHW Home Visit Update', $message, 'request_update');
                ugnayan_activity($pdo, $headId, 'BHW Home Visit Update', $message);
            }
        } elseif ($action === 'delete_visit') {
            $visitId = ugnayan_int('visit_id');
            $pdo->prepare('DELETE FROM bhw_home_visits WHERE visit_id=?')->execute([$visitId]);
            log_action($pdo, ugnayan_user_id(), 'Deleted Home Visit', 'Visit #' . $visitId);
            ugnayan_flash_set('Home visit deleted.');
        } elseif ($action === 'save_priority') {
            $priorityId = ugnayan_int('priority_id');

            if ($priorityId > 0) {
                $stmt = $pdo->prepare('UPDATE priority_groups SET resident_id=?, group_type=?, notes=?, status=? WHERE priority_id=?');
                $stmt->execute([ugnayan_int('resident_id'), ugnayan_trim('group_type'), ugnayan_trim('notes'), ugnayan_trim('status', 'Active'), $priorityId]);
                log_action($pdo, ugnayan_user_id(), 'Updated Priority Group', 'Priority #' . $priorityId);
                ugnayan_flash_set('Priority group updated.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO priority_groups (resident_id, group_type, notes, status) VALUES (?, ?, ?, ?)');
                $stmt->execute([ugnayan_int('resident_id'), ugnayan_trim('group_type'), ugnayan_trim('notes'), ugnayan_trim('status', 'Active')]);
                log_action($pdo, ugnayan_user_id(), 'Created Priority Group', ugnayan_trim('group_type'));
                ugnayan_flash_set('Priority group created.');
            }
        } elseif ($action === 'delete_priority') {
            $priorityId = ugnayan_int('priority_id');
            $pdo->prepare('DELETE FROM priority_groups WHERE priority_id=?')->execute([$priorityId]);
            log_action($pdo, ugnayan_user_id(), 'Deleted Priority Group', 'Priority #' . $priorityId);
            ugnayan_flash_set('Priority group deleted.');
        } elseif ($action === 'save_immunization') {
            $immunizationId = ugnayan_int('immunization_id');

            if ($immunizationId > 0) {
                $stmt = $pdo->prepare('UPDATE immunization_records SET resident_id=?, vaccine_name=?, dose_number=?, date_given=?, next_due_date=?, administered_by=?, remarks=? WHERE immunization_id=?');
                $stmt->execute([ugnayan_int('resident_id'), ugnayan_trim('vaccine_name'), ugnayan_trim('dose_number'), ugnayan_trim('date_given') ?: null, ugnayan_trim('next_due_date') ?: null, ugnayan_user_id(), ugnayan_trim('remarks'), $immunizationId]);
                ugnayan_flash_set('Immunization record updated.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO immunization_records (resident_id, vaccine_name, dose_number, date_given, next_due_date, administered_by, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([ugnayan_int('resident_id'), ugnayan_trim('vaccine_name'), ugnayan_trim('dose_number'), ugnayan_trim('date_given') ?: null, ugnayan_trim('next_due_date') ?: null, ugnayan_user_id(), ugnayan_trim('remarks')]);
                ugnayan_flash_set('Immunization record created.');
            }

            ugnayan_activity($pdo, ugnayan_int('resident_id'), 'Immunization Record', 'Immunization/checkup record updated.');
            log_action($pdo, ugnayan_user_id(), 'Saved Immunization Record', ugnayan_trim('vaccine_name'));
        } elseif ($action === 'delete_immunization') {
            $immunizationId = ugnayan_int('immunization_id');
            $pdo->prepare('DELETE FROM immunization_records WHERE immunization_id=?')->execute([$immunizationId]);
            log_action($pdo, ugnayan_user_id(), 'Deleted Immunization Record', 'Immunization #' . $immunizationId);
            ugnayan_flash_set('Immunization record deleted.');
        }
    } catch (Throwable $e) {
        ugnayan_flash_set('Action failed: ' . $e->getMessage(), 'error');
    }

    ugnayan_redirect_here('#bhw');
}

$flash = ugnayan_flash_get();
$eligibleUsers = $isAdmin ? $pdo->query("SELECT user_id, email, role FROM users WHERE role IN ('admin','staff','bhw') AND status='approved' ORDER BY role, email")->fetchAll() : [];
$approvedResidents = $isAdmin ? $pdo->query("SELECT resident_id, first_name, last_name, purok FROM residents WHERE application_status='Approved' ORDER BY last_name, first_name")->fetchAll() : [];

if ($isAdmin) {
    $bhwProfiles = $pdo->query('SELECT b.*, u.email, u.role FROM bhw_profiles b JOIN users u ON b.user_id=u.user_id ORDER BY b.bhw_id DESC')->fetchAll();
    $households = $pdo->query('SELECT h.*, CONCAT(r.first_name, " ", r.last_name) AS head_name, u.email AS bhw_email FROM household_profiles h LEFT JOIN residents r ON r.resident_id=h.household_head_resident_id LEFT JOIN bhw_profiles b ON b.bhw_id=h.assigned_bhw_id LEFT JOIN users u ON u.user_id=b.user_id ORDER BY h.household_id DESC')->fetchAll();
    $visits = $pdo->query('SELECT v.*, h.household_number, h.address, u.email AS bhw_email FROM bhw_home_visits v JOIN household_profiles h ON h.household_id=v.household_id LEFT JOIN bhw_profiles b ON b.bhw_id=v.bhw_id LEFT JOIN users u ON u.user_id=b.user_id ORDER BY v.visit_date DESC')->fetchAll();
    $priorityGroups = $pdo->query('SELECT p.*, CONCAT(r.first_name, " ", r.last_name) AS resident_name, r.purok FROM priority_groups p JOIN residents r ON r.resident_id=p.resident_id ORDER BY p.priority_id DESC')->fetchAll();
    $immunizations = $pdo->query('SELECT ir.*, CONCAT(r.first_name, " ", r.last_name) AS resident_name, r.purok FROM immunization_records ir JOIN residents r ON r.resident_id=ir.resident_id ORDER BY COALESCE(ir.date_given, ir.next_due_date) DESC')->fetchAll();
} else {
    $stmt = $pdo->prepare('SELECT b.*, u.email, u.role FROM bhw_profiles b JOIN users u ON b.user_id=u.user_id WHERE b.status="Active" AND b.assigned_purok=? ORDER BY b.bhw_id DESC');
    $stmt->execute([$residentPurok]);
    $bhwProfiles = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT h.*, CONCAT(r.first_name, " ", r.last_name) AS head_name, u.email AS bhw_email FROM household_profiles h LEFT JOIN residents r ON r.resident_id=h.household_head_resident_id LEFT JOIN bhw_profiles b ON b.bhw_id=h.assigned_bhw_id LEFT JOIN users u ON u.user_id=b.user_id WHERE h.household_head_resident_id=? ORDER BY h.household_id DESC');
    $stmt->execute([$residentId]);
    $households = $stmt->fetchAll();

    $householdIds = ugnayan_column_int($households, 'household_id');
    if ($householdIds) {
        $placeholders = implode(',', array_fill(0, count($householdIds), '?'));
        $stmt = $pdo->prepare('SELECT v.*, h.household_number, h.address, u.email AS bhw_email FROM bhw_home_visits v JOIN household_profiles h ON h.household_id=v.household_id LEFT JOIN bhw_profiles b ON b.bhw_id=v.bhw_id LEFT JOIN users u ON u.user_id=b.user_id WHERE v.household_id IN (' . $placeholders . ') ORDER BY v.visit_date DESC');
        $stmt->execute($householdIds);
        $visits = $stmt->fetchAll();
    } else {
        $visits = [];
    }

    $stmt = $pdo->prepare('SELECT p.*, CONCAT(r.first_name, " ", r.last_name) AS resident_name, r.purok FROM priority_groups p JOIN residents r ON r.resident_id=p.resident_id WHERE p.resident_id=? ORDER BY p.priority_id DESC');
    $stmt->execute([$residentId]);
    $priorityGroups = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT ir.*, CONCAT(r.first_name, " ", r.last_name) AS resident_name, r.purok FROM immunization_records ir JOIN residents r ON r.resident_id=ir.resident_id WHERE ir.resident_id=? ORDER BY COALESCE(ir.date_given, ir.next_due_date) DESC');
    $stmt->execute([$residentId]);
    $immunizations = $stmt->fetchAll();
}

$totalBhw = count($bhwProfiles);
$totalHouseholds = count($households);
$priorityResidents = ugnayan_count_by($priorityGroups, 'status', 'Active');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UGNAYAN - BHW Module</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/module-9.css">
</head>
<body>
    <?php render_ugnayan_sidebar('bhw', [
        'module_label' => 'Module 9',
        'brand_icon' => 'fa-user-nurse',
    ]); ?>
    <main class="main-content">
        <header class="topbar">
            <div class="topbar-title"><h2>Barangay Health Worker Module</h2><p><?= $isAdmin ? 'CRUD BHW assignments, households, visits, and priority groups' : 'View your assigned BHW and household health records' ?></p></div>
            <div class="topbar-actions"><?php render_ugnayan_topbar_tools($pdo); ?></div>
        </header>
        <section class="module-content">
            <?php if ($flash): ?><div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div><?php endif; ?>

            <div class="stats-grid" id="summary">
                <div class="stat-card"><div class="stat-title">BHW Profiles</div><div class="stat-value"><?= $totalBhw ?></div><div class="stat-subtext"><?= $isAdmin ? 'Registered workers' : 'Assigned to your purok' ?></div></div>
                <div class="stat-card"><div class="stat-title">Households</div><div class="stat-value"><?= $totalHouseholds ?></div><div class="stat-subtext"><?= $isAdmin ? 'Mapped households' : 'Linked households' ?></div></div>
                <div class="stat-card"><div class="stat-title">Priority Groups</div><div class="stat-value"><?= $priorityResidents ?></div><div class="stat-subtext">Active monitoring</div></div>
            </div>

            <?php if ($isAdmin): ?>
                <div class="panel">
                    <div class="panel-header"><h3>BHW Activity Reports</h3></div>
                    <div class="report-grid">
                        <div class="report-tile"><span class="muted">Home Visits</span><strong><?= count($visits) ?></strong></div>
                        <div class="report-tile"><span class="muted">Immunization / Checkup Records</span><strong><?= count($immunizations) ?></strong></div>
                        <div class="report-tile"><span class="muted">Active Priority Residents</span><strong><?= (int) $priorityResidents ?></strong></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($isAdmin): ?>
                <div class="management-grid" id="bhw">
                    <div class="panel">
                        <div class="panel-header"><h3>Create BHW Profile</h3></div>
                        <form method="POST" class="form-grid">
                            <input type="hidden" name="action" value="save_bhw_profile">
                            <div class="input-group full"><label>User</label><select name="user_id" required><?php foreach ($eligibleUsers as $user): ?><option value="<?= (int) $user['user_id'] ?>"><?= htmlspecialchars($user['email'] . ' (' . $user['role'] . ')') ?></option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Assigned Purok</label><input name="assigned_purok" required></div>
                            <div class="input-group"><label>Contact Number</label><input name="contact_number"></div>
                            <div class="input-group"><label>Status</label><select name="status"><?php foreach ($profileStatuses as $status): ?><option><?= htmlspecialchars($status) ?></option><?php endforeach; ?></select></div>
                            <div class="form-actions"><button class="btn-primary"><i class="fa-solid fa-user-plus"></i> Save BHW</button></div>
                        </form>
                    </div>

                    <div class="panel">
                        <div class="panel-header"><h3>Create Household</h3></div>
                        <form method="POST" class="form-grid">
                            <input type="hidden" name="action" value="save_household">
                            <div class="input-group full"><label>Update Existing Household</label><select name="household_id"><option value="0">Create new household</option><?php foreach ($households as $household): ?><option value="<?= (int) $household['household_id'] ?>"><?= htmlspecialchars(($household['household_number'] ?: 'Household #' . $household['household_id']) . ' - ' . $household['address']) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group full"><label>Household Head</label><select name="household_head_resident_id"><option value="">No head selected</option><?php foreach ($approvedResidents as $person): ?><option value="<?= (int) $person['resident_id'] ?>"><?= htmlspecialchars($person['last_name'] . ', ' . $person['first_name'] . ' - ' . $person['purok']) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Purok</label><input name="purok" required></div>
                            <div class="input-group"><label>Household Number</label><input name="household_number"></div>
                            <div class="input-group full"><label>Address</label><textarea name="address" required></textarea></div>
                            <div class="input-group full"><label>Assigned BHW</label><select name="assigned_bhw_id"><option value="">Unassigned</option><?php foreach ($bhwProfiles as $bhw): ?><option value="<?= (int) $bhw['bhw_id'] ?>"><?= htmlspecialchars($bhw['email'] . ' - ' . $bhw['assigned_purok']) ?></option><?php endforeach; ?></select></div>
                            <div class="form-actions"><button class="btn-primary"><i class="fa-solid fa-house"></i> Save Household</button></div>
                        </form>
                    </div>
                </div>

                <div class="management-grid">
                    <div class="panel">
                        <div class="panel-header"><h3>Record Home Visit</h3></div>
                        <form method="POST" class="form-grid">
                            <input type="hidden" name="action" value="save_visit">
                            <div class="input-group full"><label>Update Existing Visit</label><select name="visit_id"><option value="0">Create new visit</option><?php foreach ($visits as $visit): ?><option value="<?= (int) $visit['visit_id'] ?>"><?= htmlspecialchars(date('M d, Y', strtotime($visit['visit_date'])) . ' - ' . ($visit['household_number'] ?: $visit['address'])) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group full"><label>Household</label><select name="household_id" required><?php foreach ($households as $household): ?><option value="<?= (int) $household['household_id'] ?>"><?= htmlspecialchars(($household['household_number'] ?: 'Household #' . $household['household_id']) . ' - ' . $household['address']) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group full"><label>BHW</label><select name="bhw_id"><option value="">Unassigned</option><?php foreach ($bhwProfiles as $bhw): ?><option value="<?= (int) $bhw['bhw_id'] ?>"><?= htmlspecialchars($bhw['email']) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Visit Date</label><input type="datetime-local" name="visit_date" required></div>
                            <div class="input-group"><label>Next Visit</label><input type="date" name="next_visit_date"></div>
                            <div class="input-group full"><label>Observations</label><textarea name="observations"></textarea></div>
                            <div class="input-group full"><label>Recommendations</label><textarea name="recommendations"></textarea></div>
                            <div class="form-actions"><button class="btn-primary"><i class="fa-solid fa-person-walking"></i> Save Visit</button></div>
                        </form>
                    </div>

                    <div class="panel">
                        <div class="panel-header"><h3>Create Priority Group</h3></div>
                        <form method="POST" class="form-grid">
                            <input type="hidden" name="action" value="save_priority">
                            <div class="input-group full"><label>Update Existing Priority Record</label><select name="priority_id"><option value="0">Create new priority record</option><?php foreach ($priorityGroups as $priority): ?><option value="<?= (int) $priority['priority_id'] ?>"><?= htmlspecialchars($priority['resident_name'] . ' - ' . $priority['group_type']) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group full"><label>Resident</label><select name="resident_id" required><?php foreach ($approvedResidents as $person): ?><option value="<?= (int) $person['resident_id'] ?>"><?= htmlspecialchars($person['last_name'] . ', ' . $person['first_name'] . ' - ' . $person['purok']) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Group Type</label><select name="group_type"><?php foreach ($priorityTypes as $type): ?><option><?= htmlspecialchars($type) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Status</label><select name="status"><?php foreach ($profileStatuses as $status): ?><option><?= htmlspecialchars($status) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group full"><label>Notes</label><textarea name="notes"></textarea></div>
                            <div class="form-actions"><button class="btn-primary"><i class="fa-solid fa-star-of-life"></i> Save Priority</button></div>
                        </form>
                    </div>
                </div>

                <div class="management-grid">
                    <div class="panel">
                        <div class="panel-header"><h3>Record Immunization / Checkup</h3></div>
                        <form method="POST" class="form-grid">
                            <input type="hidden" name="action" value="save_immunization">
                            <div class="input-group full"><label>Update Existing Record</label><select name="immunization_id"><option value="0">Create new record</option><?php foreach ($immunizations as $record): ?><option value="<?= (int) $record['immunization_id'] ?>"><?= htmlspecialchars($record['resident_name'] . ' - ' . $record['vaccine_name']) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group full"><label>Resident</label><select name="resident_id" required><?php foreach ($approvedResidents as $person): ?><option value="<?= (int) $person['resident_id'] ?>"><?= htmlspecialchars($person['last_name'] . ', ' . $person['first_name'] . ' - ' . $person['purok']) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Vaccine / Checkup</label><input name="vaccine_name" required></div>
                            <div class="input-group"><label>Dose / Type</label><input name="dose_number"></div>
                            <div class="input-group"><label>Date Given</label><input type="date" name="date_given"></div>
                            <div class="input-group"><label>Next Due</label><input type="date" name="next_due_date"></div>
                            <div class="input-group full"><label>Remarks</label><textarea name="remarks"></textarea></div>
                            <div class="form-actions"><button class="btn-primary"><i class="fa-solid fa-syringe"></i> Save Record</button></div>
                        </form>
                    </div>

                    <div class="panel">
                        <div class="panel-header"><h3>Immunization and Checkup Records</h3></div>
                        <div class="list-group">
                            <?php if (!$immunizations): ?><div class="empty-state">No immunization or checkup records yet.</div><?php endif; ?>
                            <?php foreach (array_slice($immunizations, 0, 8) as $record): ?>
                                <div class="list-item">
                                    <div class="item-info"><div class="item-icon"><i class="fa-solid fa-syringe"></i></div><div><h4><?= htmlspecialchars($record['resident_name']) ?></h4><p><?= htmlspecialchars($record['vaccine_name']) ?> &middot; Next: <?= htmlspecialchars($record['next_due_date'] ?: 'None') ?></p></div></div>
                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="action" value="delete_immunization">
                                        <input type="hidden" name="immunization_id" value="<?= (int) $record['immunization_id'] ?>">
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
                    <div class="panel-header"><h3>BHW Assignments</h3></div>
                    <div class="records-table">
                        <table>
                            <thead><tr><th>BHW</th><th>Purok</th><th>Contact</th><th><?= $isAdmin ? 'Actions' : 'Status' ?></th></tr></thead>
                            <tbody>
                                <?php if (!$bhwProfiles): ?><tr><td colspan="4" class="empty-state">No BHW profiles recorded yet.</td></tr><?php endif; ?>
                                <?php foreach ($bhwProfiles as $bhw): ?>
                                    <tr>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="action" value="save_bhw_profile">
                                                    <input type="hidden" name="bhw_id" value="<?= (int) $bhw['bhw_id'] ?>">
                                                    <select name="user_id"><?php foreach ($eligibleUsers as $user): ?><option value="<?= (int) $user['user_id'] ?>" <?= (int) $bhw['user_id'] === (int) $user['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($user['email']) ?></option><?php endforeach; ?></select>
                                            <?php else: ?>
                                                <strong><?= htmlspecialchars($bhw['email']) ?></strong>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $isAdmin ? '<input name="assigned_purok" value="' . htmlspecialchars($bhw['assigned_purok']) . '">' : htmlspecialchars($bhw['assigned_purok']) ?></td>
                                        <td><?= $isAdmin ? '<input name="contact_number" value="' . htmlspecialchars($bhw['contact_number'] ?? '') . '">' : htmlspecialchars($bhw['contact_number'] ?: 'No contact') ?></td>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                    <select name="status"><?php foreach ($profileStatuses as $status): ?><option <?= $bhw['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option><?php endforeach; ?></select>
                                                    <button class="btn-secondary btn-small">Update</button>
                                                </form>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="action" value="delete_bhw_profile">
                                                    <input type="hidden" name="bhw_id" value="<?= (int) $bhw['bhw_id'] ?>">
                                                    <button class="btn-danger btn-small">Delete</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="<?= ugnayan_status_class($bhw['status']) ?>"><?= htmlspecialchars($bhw['status']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Priority Groups' : 'My Priority Group Records' ?></h3></div>
                    <div class="list-group">
                        <?php if (!$priorityGroups): ?><div class="empty-state">No priority group records.</div><?php endif; ?>
                        <?php foreach ($priorityGroups as $priority): ?>
                            <div class="list-item">
                                <div class="item-info"><div class="item-icon"><i class="fa-solid fa-star-of-life"></i></div><div><h4><?= htmlspecialchars($priority['resident_name']) ?></h4><p><?= htmlspecialchars($priority['group_type']) ?> &middot; <?= htmlspecialchars($priority['notes'] ?? '') ?></p></div></div>
                                <?php if ($isAdmin): ?>
                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="action" value="delete_priority">
                                        <input type="hidden" name="priority_id" value="<?= (int) $priority['priority_id'] ?>">
                                        <button class="btn-danger btn-small">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span class="<?= ugnayan_status_class($priority['status']) ?>"><?= htmlspecialchars($priority['status']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="module-grid">
                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Households' : 'My Household' ?></h3></div>
                    <div class="records-table">
                        <table>
                            <thead><tr><th>Household</th><th>Address</th><th>BHW</th><th><?= $isAdmin ? 'Actions' : 'Purok' ?></th></tr></thead>
                            <tbody>
                                <?php if (!$households): ?><tr><td colspan="4" class="empty-state">No household records.</td></tr><?php endif; ?>
                                <?php foreach ($households as $household): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($household['household_number'] ?: 'Household #' . $household['household_id']) ?><br><span class="muted"><?= htmlspecialchars($household['head_name'] ?: 'No head selected') ?></span></td>
                                        <td><?= htmlspecialchars($household['address']) ?></td>
                                        <td><?= htmlspecialchars($household['bhw_email'] ?: 'Unassigned') ?></td>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="action" value="delete_household">
                                                    <input type="hidden" name="household_id" value="<?= (int) $household['household_id'] ?>">
                                                    <button class="btn-danger btn-small">Delete</button>
                                                </form>
                                            <?php else: ?>
                                                <?= htmlspecialchars($household['purok']) ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Home Visits' : 'My Home Visit History' ?></h3></div>
                    <div class="list-group">
                        <?php if (!$visits): ?><div class="empty-state">No home visit records.</div><?php endif; ?>
                        <?php foreach ($visits as $visit): ?>
                            <div class="list-item">
                                <div class="item-info"><div class="item-icon"><i class="fa-solid fa-person-walking"></i></div><div><h4><?= htmlspecialchars(date('M d, Y h:i A', strtotime($visit['visit_date']))) ?></h4><p><?= htmlspecialchars($visit['household_number'] ?: $visit['address']) ?> &middot; <?= htmlspecialchars($visit['observations'] ?? '') ?></p></div></div>
                                <?php if ($isAdmin): ?>
                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="action" value="delete_visit">
                                        <input type="hidden" name="visit_id" value="<?= (int) $visit['visit_id'] ?>">
                                        <button class="btn-danger btn-small">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted"><?= $visit['next_visit_date'] ? 'Next: ' . htmlspecialchars($visit['next_visit_date']) : 'No next visit' ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php if (!$isAdmin): ?>
                <div class="panel">
                    <div class="panel-header"><h3>My Immunization and Checkup History</h3></div>
                    <div class="list-group">
                        <?php if (!$immunizations): ?><div class="empty-state">No immunization or checkup records yet.</div><?php endif; ?>
                        <?php foreach ($immunizations as $record): ?>
                            <div class="list-item">
                                <div class="item-info"><div class="item-icon"><i class="fa-solid fa-syringe"></i></div><div><h4><?= htmlspecialchars($record['vaccine_name']) ?></h4><p><?= htmlspecialchars($record['dose_number'] ?: 'Record') ?> &middot; Given: <?= htmlspecialchars($record['date_given'] ?: 'N/A') ?> &middot; Next: <?= htmlspecialchars($record['next_due_date'] ?: 'None') ?></p></div></div>
                                <span class="muted"><?= htmlspecialchars($record['remarks'] ?: '') ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
