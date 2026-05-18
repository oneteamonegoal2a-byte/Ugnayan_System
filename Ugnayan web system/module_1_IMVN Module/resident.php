<?php
require_once 'includes/auth.php';
require_role('resident');
require_once 'includes/db.php';

$stmt = $pdo->prepare('SELECT r.*, u.email FROM residents r JOIN users u ON r.user_id=u.user_id WHERE r.user_id=?');
$stmt->execute([$_SESSION['user_id']]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    die('Resident profile not found.');
}

$notes = $pdo->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY notification_id DESC');
$notes->execute([$_SESSION['user_id']]);
$notes = $notes->fetchAll(PDO::FETCH_ASSOC);

$acts = $pdo->prepare('SELECT * FROM activity_history WHERE resident_id=? ORDER BY activity_id DESC');
$acts->execute([$r['resident_id']]);
$acts = $acts->fetchAll(PDO::FETCH_ASSOC);

$profilePhotoPath = (string) ($r['profile_photo'] ?? '');
$profilePhotoUrl = ugnayan_profile_file_url($profilePhotoPath);

$fullName = trim($r['first_name'].' '.$r['last_name']);
$profileInitial = strtoupper(substr($fullName !== '' ? $fullName : $r['email'], 0, 1));
$unreadNotifications = 0;

foreach ($notes as $note) {
    if (empty($note['is_read'])) {
        $unreadNotifications++;
    }
}

$profileFields = ['contact_number', 'address', 'occupation'];
$completedFields = 0;

foreach ($profileFields as $field) {
    if (trim((string) ($r[$field] ?? '')) !== '') {
        $completedFields++;
    }
}

$profileCompletion = (int) round(($completedFields / count($profileFields)) * 100);
$profileMessage = isset($_GET['profile_saved']) ? 'Profile updated successfully.' : '';
$profileError = (string) ($_GET['profile_error'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UGNAYAN - Resident Residence</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/resident.css">
</head>
<body>
    <?php render_ugnayan_sidebar('dashboard', [
        'module_label' => 'Residence',
        'user_name' => $r['first_name'] . ' ' . $r['last_name'],
        'user_status' => $r['application_status'],
        'avatar' => $r['first_name'],
    ]); ?>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-title">
                <h2>Barangay San Juan Resident Portal</h2>
                <p>Dashboard, notifications, activity history, and profile details</p>
            </div>
            <div class="topbar-actions">
                <?php render_ugnayan_topbar_tools($pdo, false); ?>
            </div>
        </header>

        <div class="dashboard-container">
            <section class="welcome-card">
                <div class="welcome-text">
                    <span class="dashboard-kicker">Resident Dashboard</span>
                    <p>Welcome back,</p>
                    <h1><?= htmlspecialchars($fullName) ?></h1>
                    <div class="welcome-badges">
                        <span class="verified-badge"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($r['application_status']) ?> Resident</span>
                        <span class="location-badge"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($r['purok']) ?></span>
                    </div>
                </div>
                <a class="welcome-profile-link" href="#profile" aria-label="Edit resident profile">
                    <span class="welcome-profile-photo">
                        <?php if ($profilePhotoUrl !== ''): ?>
                            <img src="<?= htmlspecialchars($profilePhotoUrl) ?>" alt="">
                        <?php else: ?>
                            <?= htmlspecialchars($profileInitial) ?>
                        <?php endif; ?>
                    </span>
                    <span>Edit Profile</span>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </section>

            <div class="resident-stats-grid">
                <div class="stat-card">
                    <div class="stat-icon icon-red"><i class="fa-solid fa-id-card"></i></div>
                    <div>
                        <div class="stat-title">Profile Completion</div>
                        <div class="stat-value"><?= $profileCompletion ?>%</div>
                        <div class="stat-subtext">Basic contact details</div>
                        <div class="completion-track" aria-hidden="true"><span style="width: <?= $profileCompletion ?>%"></span></div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon icon-blue"><i class="fa-solid fa-bell"></i></div>
                    <div>
                        <div class="stat-title">Unread Notices</div>
                        <div class="stat-value"><?= $unreadNotifications ?></div>
                        <div class="stat-subtext">Barangay updates</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon icon-green"><i class="fa-solid fa-clock-rotate-left"></i></div>
                    <div>
                        <div class="stat-title">Activity Records</div>
                        <div class="stat-value"><?= count($acts) ?></div>
                        <div class="stat-subtext">Recent account actions</div>
                    </div>
                </div>
            </div>

            <div class="panel profile-panel" id="profile">
                <div class="panel-header">
                    <div>
                        <h3>My Profile</h3>
                        <p>Update your contact information and resident photo.</p>
                    </div>
                </div>

                <?php if ($profileMessage): ?><div class="flash success"><?= htmlspecialchars($profileMessage) ?></div><?php endif; ?>
                <?php if ($profileError): ?>
                    <div class="flash error">
                        <?= $profileError === 'photo_type' ? 'Please upload a JPG or PNG profile photo.' : 'Profile photo could not be uploaded.' ?>
                    </div>
                <?php endif; ?>

                <div class="profile-layout">
                    <aside class="profile-summary">
                        <div class="profile-photo-large">
                            <?php if ($profilePhotoUrl !== ''): ?>
                                <img src="<?= htmlspecialchars($profilePhotoUrl) ?>" alt="">
                            <?php else: ?>
                                <?= htmlspecialchars($profileInitial) ?>
                            <?php endif; ?>
                        </div>
                        <div class="profile-summary-copy">
                            <h4><?= htmlspecialchars($fullName) ?></h4>
                            <p><?= htmlspecialchars($r['email']) ?></p>
                            <span class="status-chip"><?= htmlspecialchars($r['application_status']) ?> - <?= htmlspecialchars($r['purok']) ?></span>
                        </div>
                    </aside>

                    <form action="actions/update_profile.php" method="POST" enctype="multipart/form-data" class="profile-form">
                        <div class="input-group">
                            <label for="profile_photo">Profile Picture</label>
                            <input id="profile_photo" type="file" name="profile_photo" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                        </div>

                        <div class="input-group">
                            <label for="contact_number">Contact Number</label>
                            <input id="contact_number" name="contact_number" type="tel" autocomplete="tel" value="<?= htmlspecialchars($r['contact_number']) ?>" required>
                        </div>

                        <div class="input-group full">
                            <label for="address">Address</label>
                            <input id="address" name="address" autocomplete="street-address" value="<?= htmlspecialchars($r['address']) ?>" required>
                        </div>

                        <div class="input-group">
                            <label for="occupation">Occupation</label>
                            <input id="occupation" name="occupation" autocomplete="organization-title" value="<?= htmlspecialchars((string) $r['occupation']) ?>">
                        </div>

                        <div class="input-group">
                            <label for="email">Email Address</label>
                            <input id="email" type="email" value="<?= htmlspecialchars($r['email']) ?>" disabled>
                        </div>

                        <div class="form-actions full">
                            <button class="btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="resident-dashboard-grid">
                <div class="panel" id="notifications">
                    <div class="panel-header">
                        <div>
                            <h3>Notifications</h3>
                            <p>Latest barangay messages for your account.</p>
                        </div>
                    </div>

                    <div class="list-group">
                        <?php if (!$notes): ?><div class="empty-state">No notifications yet.</div><?php endif; ?>
                        <?php foreach ($notes as $n): ?>
                            <div class="list-item notification-item <?= empty($n['is_read']) ? 'is-unread' : '' ?>">
                                <div class="item-icon"><i class="fa-solid fa-bell"></i></div>
                                <div class="item-body">
                                    <strong><?= htmlspecialchars($n['title']) ?></strong>
                                    <p><?= htmlspecialchars($n['message']) ?></p>
                                    <small><?= htmlspecialchars($n['created_at']) ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="panel" id="activity">
                    <div class="panel-header">
                        <div>
                            <h3>Activity History</h3>
                            <p>Recent profile and account actions.</p>
                        </div>
                    </div>

                    <div class="list-group">
                        <?php if (!$acts): ?><div class="empty-state">No account activity yet.</div><?php endif; ?>
                        <?php foreach ($acts as $a): ?>
                            <div class="list-item activity-item">
                                <div class="item-icon"><i class="fa-solid fa-file-circle-check"></i></div>
                                <div class="item-body">
                                    <strong><?= htmlspecialchars($a['activity_type']) ?></strong>
                                    <p><?= htmlspecialchars($a['details']) ?></p>
                                    <small><?= htmlspecialchars($a['created_at']) ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
