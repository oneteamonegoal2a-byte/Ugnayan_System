<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/navigation.php';

function require_login()
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . ugnayan_url('module_1_IMVN%20Module/login.php'));
        exit;
    }
}

function require_role($role)
{
    require_login();

    $allowedRoles = (array) $role;

    if (!in_array($_SESSION['role'], $allowedRoles, true)) {
        die('Access denied.');
    }
}
