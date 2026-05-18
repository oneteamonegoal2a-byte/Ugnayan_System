<?php
declare(strict_types=1);

function ugnayan_base_url(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $markers = [
        '/module_1_IMVN%20Module/',
        '/module_1_IMVN Module/',
        '/module_2_Complaint_System/',
        '/module_3_Calamity_Announcements/',
        '/module_4_Scheduling_Module/',
        '/module_5_Item_Management/',
        '/module_6_Document_Request/',
        '/module_7_Incident_Log/',
        '/module_8_Healthcare_Module/',
        '/module_9_BHW_Module/',
        '/module_10_Communication_Module/',
    ];

    foreach ($markers as $marker) {
        $position = strpos($script, $marker);
        if ($position !== false) {
            return rtrim(substr($script, 0, $position), '/') . '/';
        }
    }

    $directory = str_replace('\\', '/', dirname($script));
    return rtrim($directory === '/' ? '' : $directory, '/') . '/';
}

function ugnayan_url(string $path): string
{
    return ugnayan_base_url() . ltrim($path, '/');
}

function ugnayan_role(): string
{
    return (string) ($_SESSION['role'] ?? 'guest');
}

function ugnayan_is_resident_role(): bool
{
    return ugnayan_role() === 'resident';
}

function ugnayan_is_admin_role(): bool
{
    return in_array(ugnayan_role(), ['admin', 'staff', 'bhw'], true);
}

function ugnayan_sidebar_items(): array
{
    if (ugnayan_is_resident_role()) {
        return [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fa-border-all', 'url' => 'module_1_IMVN%20Module/resident.php'],
            ['key' => 'complaints', 'label' => 'Complaints', 'icon' => 'fa-triangle-exclamation', 'url' => 'module_2_Complaint_System/index.php'],
            ['key' => 'calamity', 'label' => 'Alerts & Calamity', 'icon' => 'fa-bullhorn', 'url' => 'module_3_Calamity_Announcements/module_3_Calamity_Announcements/index.php'],
            ['key' => 'scheduling', 'label' => 'Events & Schedule', 'icon' => 'fa-calendar-days', 'url' => 'module_4_Scheduling_Module/module_4_Scheduling_Module/index.php'],
            ['key' => 'items', 'label' => 'Borrow Items', 'icon' => 'fa-box-open', 'url' => 'module_5_Item_Management/module_5_Item_Management/index.php'],
            ['key' => 'documents', 'label' => 'Document Requests', 'icon' => 'fa-file-lines', 'url' => 'module_6_Document_Request/module_6_Document_Request/index.php'],
            ['key' => 'incidents', 'label' => 'Incident Log', 'icon' => 'fa-book-open', 'url' => 'module_7_Incident_Log/module_7_Incident_Log/index.php'],
            ['key' => 'healthcare', 'label' => 'Healthcare', 'icon' => 'fa-heart-pulse', 'url' => 'module_8_Healthcare_Module/module_8_Healthcare_Module/index.php'],
            ['key' => 'bhw', 'label' => 'BHW Info', 'icon' => 'fa-user-nurse', 'url' => 'module_9_BHW_Module/module_9_BHW_Module/index.php'],
            ['key' => 'communication', 'label' => 'Messages', 'icon' => 'fa-message', 'url' => 'module_10_Communication_Module/module_10_Communication_Module/index.php'],
        ];
    }

    return [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fa-border-all', 'url' => 'module_1_IMVN%20Module/admin.php'],
        ['key' => 'residents', 'label' => 'Residents', 'icon' => 'fa-users', 'url' => 'module_1_IMVN%20Module/admin.php#residents'],
        ['key' => 'complaints', 'label' => 'Complaints', 'icon' => 'fa-triangle-exclamation', 'url' => 'module_2_Complaint_System/index.php'],
        ['key' => 'calamity', 'label' => 'Calamity Alerts', 'icon' => 'fa-bullhorn', 'url' => 'module_3_Calamity_Announcements/module_3_Calamity_Announcements/index.php'],
        ['key' => 'scheduling', 'label' => 'Scheduling', 'icon' => 'fa-calendar-days', 'url' => 'module_4_Scheduling_Module/module_4_Scheduling_Module/index.php'],
        ['key' => 'items', 'label' => 'Item Management', 'icon' => 'fa-boxes-stacked', 'url' => 'module_5_Item_Management/module_5_Item_Management/index.php'],
        ['key' => 'documents', 'label' => 'Document Requests', 'icon' => 'fa-file-lines', 'url' => 'module_6_Document_Request/module_6_Document_Request/index.php'],
        ['key' => 'incidents', 'label' => 'Incident Log', 'icon' => 'fa-book-open', 'url' => 'module_7_Incident_Log/module_7_Incident_Log/index.php'],
        ['key' => 'healthcare', 'label' => 'Healthcare', 'icon' => 'fa-heart-pulse', 'url' => 'module_8_Healthcare_Module/module_8_Healthcare_Module/index.php'],
        ['key' => 'bhw', 'label' => 'BHW Module', 'icon' => 'fa-user-nurse', 'url' => 'module_9_BHW_Module/module_9_BHW_Module/index.php'],
        ['key' => 'communication', 'label' => 'Communication', 'icon' => 'fa-message', 'url' => 'module_10_Communication_Module/module_10_Communication_Module/index.php'],
    ];
}

function render_ugnayan_sidebar(string $activeKey, array $options = []): void
{
    $portalLabel = $options['portal_label'] ?? (ugnayan_is_resident_role() ? 'Resident Portal' : 'Admin Portal');
    $moduleLabel = $options['module_label'] ?? 'UGNAYAN';
    $brandIcon = $options['brand_icon'] ?? (ugnayan_is_resident_role() ? 'fa-house-chimney' : 'fa-shield-halved');
    $userName = $options['user_name'] ?? ($_SESSION['email'] ?? (ugnayan_is_resident_role() ? 'Resident' : 'Admin'));
    $userStatus = $options['user_status'] ?? (ugnayan_is_resident_role() ? 'Approved' : 'Administrator');
    $avatar = strtoupper(substr((string) ($options['avatar'] ?? $userName), 0, 1));
    ?>
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-icon"><i class="fa-solid <?= htmlspecialchars($brandIcon) ?>"></i></div>
            <div class="brand-text">
                <h1>UGNAYAN</h1>
                <p><?= htmlspecialchars($portalLabel) ?></p>
                <span class="sub-text"><?= htmlspecialchars($moduleLabel) ?></span>
            </div>
        </div>

        <ul class="nav-menu">
            <?php foreach (ugnayan_sidebar_items() as $item): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $activeKey === $item['key'] ? 'active' : '' ?>" href="<?= htmlspecialchars(ugnayan_url($item['url'])) ?>">
                        <i class="fa-solid <?= htmlspecialchars($item['icon']) ?>"></i>
                        <?= htmlspecialchars($item['label']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="user-profile-bottom sidebar-footer">
            <div class="user-info-sidebar">
                <div class="avatar"><?= htmlspecialchars($avatar) ?></div>
                <div class="user-details">
                    <h4><?= htmlspecialchars((string) $userName) ?></h4>
                    <span class="status"><?= htmlspecialchars((string) $userStatus) ?></span>
                </div>
            </div>

            <a href="<?= htmlspecialchars(ugnayan_url('module_1_IMVN%20Module/actions/logout.php')) ?>" class="logout-btn"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
        </div>
    </aside>
    <?php
}

function render_ugnayan_access_pill(): void
{
    $label = ugnayan_is_admin_role() ? 'Admin access' : 'Resident view only';
    echo '<span class="access-pill">' . htmlspecialchars($label) . '</span>';
}

function ugnayan_admin_notification_count(PDO $pdo): int
{
    try {
        $stmt = $pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0) +
                (SELECT COUNT(*) FROM residents WHERE application_status='Pending') +
                (SELECT COUNT(*) FROM complaints WHERE status='Pending') +
                (SELECT COUNT(*) FROM item_borrow_requests WHERE status='Pending') +
                (SELECT COUNT(*) FROM document_requests WHERE status='Pending') +
                (SELECT COUNT(*) FROM health_appointments WHERE status='Pending') +
                (SELECT COUNT(*) FROM messages WHERE status='Open') AS total"
        );
        $stmt->execute([(int) ($_SESSION['user_id'] ?? 0)]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function ugnayan_profile_file_url(?string $path): string
{
    $path = trim((string) $path);

    if ($path === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    $path = ltrim($path, '/');

    if (strpos($path, 'module_1_IMVN') === 0) {
        return ugnayan_url($path);
    }

    return ugnayan_url('module_1_IMVN%20Module/' . $path);
}

function ugnayan_resident_profile_summary(PDO $pdo): array
{
    static $summary = null;

    if ($summary !== null) {
        return $summary;
    }

    $fallbackName = (string) ($_SESSION['email'] ?? 'Resident');
    $summary = [
        'name' => $fallbackName,
        'status' => 'Resident',
        'initial' => strtoupper(substr($fallbackName, 0, 1)),
        'photo_url' => '',
    ];

    if (!ugnayan_is_resident_role() || empty($_SESSION['user_id'])) {
        return $summary;
    }

    try {
        $hasProfilePhoto = (bool) $pdo->query("SHOW COLUMNS FROM residents LIKE 'profile_photo'")->fetch();
        $photoSelect = $hasProfilePhoto ? ', r.profile_photo' : ", '' AS profile_photo";
        $stmt = $pdo->prepare(
            "SELECT r.first_name, r.last_name, r.application_status{$photoSelect}
             FROM residents r
             WHERE r.user_id = ?
             LIMIT 1"
        );
        $stmt->execute([(int) $_SESSION['user_id']]);
        $resident = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($resident) {
            $name = trim((string) ($resident['first_name'] . ' ' . $resident['last_name']));
            $summary = [
                'name' => $name !== '' ? $name : $fallbackName,
                'status' => (string) ($resident['application_status'] ?? 'Resident'),
                'initial' => strtoupper(substr($name !== '' ? $name : $fallbackName, 0, 1)),
                'photo_url' => ugnayan_profile_file_url($resident['profile_photo'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        return $summary;
    }

    return $summary;
}

function render_ugnayan_topbar_tools(PDO $pdo, bool $includeAccessPill = true): void
{
    if (ugnayan_is_admin_role()) {
        $count = ugnayan_admin_notification_count($pdo);
        ?>
        <a class="notification-bell" href="<?= htmlspecialchars(ugnayan_url('module_1_IMVN%20Module/admin.php#notifications')) ?>" title="Admin notifications">
            <i class="fa-solid fa-bell"></i>
            <?php if ($count > 0): ?><span class="notification-badge"><?= $count > 99 ? '99+' : (int) $count ?></span><?php endif; ?>
        </a>
        <?php
    } elseif (ugnayan_is_resident_role()) {
        $profile = ugnayan_resident_profile_summary($pdo);
        ?>
        <a class="profile-shortcut" href="<?= htmlspecialchars(ugnayan_url('module_1_IMVN%20Module/resident.php#profile')) ?>" title="My Profile">
            <span class="profile-shortcut-avatar">
                <?php if ($profile['photo_url'] !== ''): ?>
                    <img src="<?= htmlspecialchars($profile['photo_url']) ?>" alt="">
                <?php else: ?>
                    <?= htmlspecialchars($profile['initial']) ?>
                <?php endif; ?>
            </span>
            <span class="profile-shortcut-copy">
                <strong>My Profile</strong>
                <small><?= htmlspecialchars($profile['status']) ?></small>
            </span>
        </a>
        <?php
    }

    if ($includeAccessPill) {
        render_ugnayan_access_pill();
    }
}
