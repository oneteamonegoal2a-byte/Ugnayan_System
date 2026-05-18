<?php
session_start();

require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'], true)) {
    die('Access denied.');
}

$residentId = (int)$_POST['resident_id'];
$decision = $_POST['decision'];
$reason = trim($_POST['reason'] ?? '');

$stmt = $pdo->prepare('SELECT r.user_id, u.email FROM residents r JOIN users u ON r.user_id=u.user_id WHERE r.resident_id=?');
$stmt->execute([$residentId]);
$resident = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$resident) {
    die('Resident not found.');
}

if ($decision === 'approve') {
    $pdo->prepare("UPDATE residents SET application_status='Approved', rejection_reason=NULL, approved_by=?, approved_at=NOW() WHERE resident_id=?")->execute([$_SESSION['user_id'], $residentId]);
    $pdo->prepare("UPDATE users SET status='approved' WHERE user_id=?")->execute([$resident['user_id']]);
    $title = 'Account Approved';
    $msg = 'Your resident account has been approved. You may now log in.';
    $action = 'Approved Resident';
} else {
    if ($reason === '') {
        $reason = 'Requirements are incomplete or invalid.';
    }

    $pdo->prepare("UPDATE residents SET application_status='Rejected', rejection_reason=? WHERE resident_id=?")->execute([$reason, $residentId]);
    $pdo->prepare("UPDATE users SET status='rejected' WHERE user_id=?")->execute([$resident['user_id']]);
    $title = 'Account Rejected';
    $msg = 'Your resident application was rejected. Reason: '.$reason;
    $action = 'Rejected Resident';
}

$pdo->prepare('INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,"account_status")')->execute([$resident['user_id'], $title, $msg]);
$pdo->prepare('INSERT INTO activity_history (resident_id,activity_type,details) VALUES (?,?,?)')->execute([$residentId, $title, $msg]);

log_action($pdo, $_SESSION['user_id'], $action, $resident['email'].' - '.$msg);

header('Location: ../admin.php');
exit;
?>
