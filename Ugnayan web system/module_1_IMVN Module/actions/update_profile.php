<?php
session_start();

require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'resident') {
    die('Access denied.');
}

$stmt = $pdo->prepare('SELECT resident_id FROM residents WHERE user_id=?');
$stmt->execute([$_SESSION['user_id']]);
$rid = $stmt->fetchColumn();

if (!$rid) {
    die('Resident profile not found.');
}

$photoPath = null;

if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
        header('Location: ../resident.php?profile_error=photo_upload#profile');
        exit;
    }

    $allowed = ['jpg', 'jpeg', 'png'];
    $extension = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));

    if (!in_array($extension, $allowed, true)) {
        header('Location: ../resident.php?profile_error=photo_type#profile');
        exit;
    }

    $targetDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads';

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0775, true);
    }

    $fileName = uniqid('profile_photo_', true) . '.' . $extension;
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($_FILES['profile_photo']['tmp_name'], $targetPath)) {
        header('Location: ../resident.php?profile_error=photo_upload#profile');
        exit;
    }

    $photoPath = 'assets/uploads/' . $fileName;
}

$pdo->prepare('UPDATE residents SET contact_number=?, address=?, occupation=? WHERE resident_id=?')->execute([
    trim((string) ($_POST['contact_number'] ?? '')),
    trim((string) ($_POST['address'] ?? '')),
    trim((string) ($_POST['occupation'] ?? '')),
    $rid,
]);

if ($photoPath !== null) {
    try {
        $hasProfilePhoto = (bool) $pdo->query("SHOW COLUMNS FROM residents LIKE 'profile_photo'")->fetch();

        if (!$hasProfilePhoto) {
            $pdo->exec('ALTER TABLE residents ADD COLUMN profile_photo VARCHAR(255) NULL AFTER occupation');
        }

        $pdo->prepare('UPDATE residents SET profile_photo=? WHERE resident_id=?')->execute([$photoPath, $rid]);
    } catch (Throwable $e) {
        header('Location: ../resident.php?profile_error=photo_upload#profile');
        exit;
    }
}

$activityDetails = $photoPath !== null
    ? 'Resident updated profile information and profile photo.'
    : 'Resident updated profile information.';

$pdo->prepare('INSERT INTO activity_history (resident_id, activity_type, details) VALUES (?,"Profile Update",?)')->execute([$rid, $activityDetails]);

log_action($pdo, $_SESSION['user_id'], 'Profile Update', 'Resident updated their profile.');

header('Location: ../resident.php?profile_saved=1#profile');
exit;
?>
