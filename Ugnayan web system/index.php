<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/navigation.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . ugnayan_url('module_1_IMVN%20Module/login.php'));
    exit;
}

if (ugnayan_is_resident_role()) {
    header('Location: ' . ugnayan_url('module_1_IMVN%20Module/resident.php'));
    exit;
}

if (ugnayan_role() === 'bhw') {
    header('Location: ' . ugnayan_url('module_9_BHW_Module/module_9_BHW_Module/index.php'));
    exit;
}

header('Location: ' . ugnayan_url('module_1_IMVN%20Module/admin.php'));
exit;
