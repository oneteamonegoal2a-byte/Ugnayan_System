<?php
session_start();

require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'], true)) {
    die('Access denied.');
}

$title = trim($_POST['title']);
$message = trim($_POST['message']);
$users = $pdo->query("SELECT user_id FROM users WHERE role='resident' AND status='approved'")->fetchAll(PDO::FETCH_COLUMN);
$stmt = $pdo->prepare('INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,"announcement")');

foreach ($users as $uid) {
    $stmt->execute([$uid, $title, $message]);
}

log_action($pdo, $_SESSION['user_id'], 'Bulk Notification', 'Sent notification to '.count($users).' approved residents.');

header('Location: ../admin.php#notifications');
exit;
?>
