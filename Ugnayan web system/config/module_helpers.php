<?php
declare(strict_types=1);

function ugnayan_user_id(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

function ugnayan_get_resident(PDO $pdo): ?array
{
    if (!ugnayan_is_resident_role()) {
        return null;
    }

    static $resident = null;

    if ($resident !== null) {
        return $resident;
    }

    $stmt = $pdo->prepare('SELECT r.*, u.email FROM residents r JOIN users u ON r.user_id=u.user_id WHERE r.user_id=? LIMIT 1');
    $stmt->execute([ugnayan_user_id()]);
    $resident = $stmt->fetch() ?: null;

    return $resident;
}

function ugnayan_resident_id(PDO $pdo): ?int
{
    $resident = ugnayan_get_resident($pdo);
    return $resident ? (int) $resident['resident_id'] : null;
}

function ugnayan_require_admin_access(): void
{
    if (!ugnayan_is_admin_role()) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function ugnayan_notify_user(PDO $pdo, ?int $userId, string $title, string $message, string $type = 'announcement'): void
{
    if (!$userId) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $title, $message, $type]);
}

function ugnayan_notify_residents(PDO $pdo, string $title, string $message, string $type = 'announcement', ?string $targetPurok = null): int
{
    if ($targetPurok) {
        $stmt = $pdo->prepare("SELECT u.user_id FROM users u JOIN residents r ON r.user_id=u.user_id WHERE u.role='resident' AND u.status='approved' AND r.application_status='Approved' AND r.purok=?");
        $stmt->execute([$targetPurok]);
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $userIds = $pdo->query("SELECT user_id FROM users WHERE role='resident' AND status='approved'")->fetchAll(PDO::FETCH_COLUMN);
    }

    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)');

    foreach ($userIds as $userId) {
        $stmt->execute([(int) $userId, $title, $message, $type]);
    }

    return count($userIds);
}

function ugnayan_activity(PDO $pdo, ?int $residentId, string $activityType, string $details): void
{
    if (!$residentId) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO activity_history (resident_id, activity_type, details) VALUES (?, ?, ?)');
    $stmt->execute([$residentId, $activityType, $details]);
}

function ugnayan_flash_set(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function ugnayan_flash_get(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function ugnayan_redirect_here(string $anchor = ''): void
{
    $target = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: (string) ($_SERVER['PHP_SELF'] ?? '');
    header('Location: ' . $target . $anchor);
    exit;
}

function ugnayan_status_class(string $status): string
{
    $normalized = strtolower(str_replace(' ', '-', $status));
    return 'status status-' . htmlspecialchars($normalized);
}

function ugnayan_trim(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function ugnayan_int(string $key, int $default = 0): int
{
    return (int) ($_POST[$key] ?? $default);
}

function ugnayan_date_input(?string $dateTime): string
{
    if (!$dateTime) {
        return '';
    }

    return date('Y-m-d\TH:i', strtotime($dateTime));
}

function ugnayan_count_by(array $rows, string $key, string $value): int
{
    $count = 0;

    foreach ($rows as $row) {
        if (($row[$key] ?? null) === $value) {
            $count++;
        }
    }

    return $count;
}

function ugnayan_sum_by(array $rows, string $key): int
{
    $total = 0;

    foreach ($rows as $row) {
        $total += (int) ($row[$key] ?? 0);
    }

    return $total;
}

function ugnayan_column_int(array $rows, string $key): array
{
    $values = [];

    foreach ($rows as $row) {
        if (isset($row[$key])) {
            $values[] = (int) $row[$key];
        }
    }

    return $values;
}

function ugnayan_upload_files(string $field, string $moduleKey, array $allowedExtensions = []): array
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return [];
    }

    $allowedExtensions = $allowedExtensions ?: ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'mp4', 'mov', 'avi'];
    $files = $_FILES[$field];
    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
    $errors = is_array($files['error']) ? $files['error'] : [$files['error']];
    $uploaded = [];
    $root = dirname(__DIR__);
    $relativeDir = 'assets/uploads/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $moduleKey);
    $targetDir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0775, true);
    }

    foreach ($names as $index => $name) {
        if (($errors[$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $name === '') {
            continue;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions, true)) {
            continue;
        }

        $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($name, PATHINFO_FILENAME));
        $fileName = $safeBase . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $target = $targetDir . DIRECTORY_SEPARATOR . $fileName;

        if (move_uploaded_file($tmpNames[$index], $target)) {
            $uploaded[] = [
                'path' => $relativeDir . '/' . $fileName,
                'name' => $name,
                'extension' => $extension,
                'type' => ugnayan_file_type($extension),
            ];
        }
    }

    return $uploaded;
}

function ugnayan_file_type(string $extension): string
{
    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], true)) {
        return 'photo';
    }

    if (in_array($extension, ['mp4', 'mov', 'avi'], true)) {
        return 'video';
    }

    return 'document';
}
