<?php
session_start();

require_once '../includes/db.php';

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$storedPassword = $user['password_hash'] ?? $user['password'] ?? '';

if (!$user || (!password_verify($password, $storedPassword) && $password !== $storedPassword)) {
    die('Invalid email or password. <a href="../login.php">Back</a>');
}

if ($user['status'] !== 'approved') {
    die('Your account is not yet approved. <a href="../login.php">Back</a>');
}

$_SESSION['user_id'] = $user['user_id'];
$_SESSION['email'] = $user['email'];
$_SESSION['role'] = $user['role'];

log_action($pdo, $user['user_id'], 'Login', $user['email'].' logged in.');

if ($user['role'] === 'admin' || $user['role'] === 'staff') {
    header('Location: ../admin.php');
} elseif ($user['role'] === 'bhw') {
    header('Location: ../../module_9_BHW_Module/module_9_BHW_Module/index.php');
} else {
    header('Location: ../resident.php');
}

exit;
?>
