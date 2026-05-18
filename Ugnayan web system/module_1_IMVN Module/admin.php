<?php
require_once 'includes/auth.php';
require_role(['admin', 'staff']);
require_once 'includes/db.php';

$statusOptions = ['Pending', 'Approved', 'Rejected'];
$activeStatuses = ['Pending', 'Approved'];
$rejectedStatuses = ['Rejected'];
$defaultPuroks = ['Purok 1', 'Purok 2', 'Purok 3', 'Purok 4'];
$dbPuroks = $pdo->query("SELECT DISTINCT purok FROM residents WHERE purok IS NOT NULL AND purok <> ''")->fetchAll(PDO::FETCH_COLUMN);
$purokOptions = array_values(array_unique(array_merge($defaultPuroks, $dbPuroks ?: [])));
natcasesort($purokOptions);
$purokOptions = array_values($purokOptions);

$filterPurok = trim((string) ($_GET['purok'] ?? ''));
$filterStatus = trim((string) ($_GET['status'] ?? ''));

if ($filterPurok !== '' && !in_array($filterPurok, $purokOptions, true)) {
    $filterPurok = '';
}

if ($filterStatus !== '' && !in_array($filterStatus, $statusOptions, true)) {
    $filterStatus = '';
}

function fetch_resident_applications($pdo, $filterPurok, $filterStatus, $statusScope)
{
    if ($filterStatus !== '' && !in_array($filterStatus, $statusScope, true)) {
        return [];
    }

    $where = [];
    $params = [];

    if ($filterPurok !== '') {
        $where[] = 'r.purok = ?';
        $params[] = $filterPurok;
    }

    if ($filterStatus !== '') {
        $where[] = 'r.application_status = ?';
        $params[] = $filterStatus;
    } else {
        $placeholders = implode(', ', array_fill(0, count($statusScope), '?'));
        $where[] = 'r.application_status IN ('.$placeholders.')';
        $params = array_merge($params, $statusScope);
    }

    $sql = 'SELECT r.*, u.email, u.status AS user_status
            FROM residents r
            JOIN users u ON r.user_id = u.user_id
            WHERE '.implode(' AND ', $where).'
            ORDER BY r.resident_id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$residents = fetch_resident_applications($pdo, $filterPurok, $filterStatus, $activeStatuses);
$rejectedResidents = fetch_resident_applications($pdo, $filterPurok, $filterStatus, $rejectedStatuses);
$showResidentsPanel = $filterStatus !== 'Rejected';
$showRejectedPanel = $filterStatus === '' || $filterStatus === 'Rejected';
$hasFilters = $filterPurok !== '' || $filterStatus !== '';

$pending = $pdo->query("SELECT COUNT(*) FROM residents WHERE application_status='Pending'")->fetchColumn();
$approved = $pdo->query("SELECT COUNT(*) FROM residents WHERE application_status='Approved'")->fetchColumn();
$rejected = $pdo->query("SELECT COUNT(*) FROM residents WHERE application_status='Rejected'")->fetchColumn();
$logs = $pdo->query('SELECT a.*, u.email FROM audit_logs a LEFT JOIN users u ON a.user_id=u.user_id ORDER BY a.log_id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
$totalApplications = (int) $pending + (int) $approved + (int) $rejected;
$approvalRate = $totalApplications > 0 ? (int) round(((int) $approved / $totalApplications) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UGNAYAN - Admin Residence</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin.css">
</head>
<body>
    <?php render_ugnayan_sidebar('dashboard', [
        'module_label' => 'Residence',
        'user_name' => $_SESSION['email'] ?? 'Admin',
        'user_status' => ucfirst((string) ($_SESSION['role'] ?? 'Admin')),
    ]); ?>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-title">
                <h2>Integrated Management, Verification & Notification Module</h2>
                <p>Resident verification, reports, notifications, and audit tracking</p>
            </div>
            <div class="topbar-actions">
                <?php render_ugnayan_topbar_tools($pdo, false); ?>
            </div>
        </header>

        <div class="dashboard-container" id="dashboard">
            <section class="admin-hero">
                <div>
                    <span class="hero-kicker">Residence Command Center</span>
                    <h1>Resident Verification Dashboard</h1>
                    <p>Review applications, monitor verification status, notify residents, and track recent admin activity.</p>
                </div>
                <div class="hero-metrics">
                    <div>
                        <strong><?= $totalApplications ?></strong>
                        <span>Total Applications</span>
                    </div>
                    <div>
                        <strong><?= $approvalRate ?>%</strong>
                        <span>Approval Rate</span>
                    </div>
                </div>
            </section>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon pending"><i class="fa-solid fa-hourglass-half"></i></div>
                    <div>
                        <div class="stat-title">Pending Applications</div>
                        <div class="stat-value"><?= $pending ?></div>
                        <div class="stat-subtext">Need review</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon approved"><i class="fa-solid fa-circle-check"></i></div>
                    <div>
                        <div class="stat-title">Approved Residents</div>
                        <div class="stat-value"><?= $approved ?></div>
                        <div class="stat-subtext">Verified accounts</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon rejected"><i class="fa-solid fa-circle-xmark"></i></div>
                    <div>
                        <div class="stat-title">Rejected Registrations</div>
                        <div class="stat-value"><?= $rejected ?></div>
                        <div class="stat-subtext">With reasons</div>
                    </div>
                </div>
            </div>

            <div class="panel" id="residents">
                <div class="panel-header">
                    <div>
                        <h3>Resident Verification System</h3>
                        <p>Filter registrations and open resident records for review.</p>
                    </div>
                </div>

                <form method="GET" class="filter-bar">
                    <div class="filters">
                        <select name="purok" aria-label="Filter by purok">
                            <option value="">All Purok</option>
                            <?php foreach ($purokOptions as $p): ?>
                                <option value="<?= htmlspecialchars($p) ?>" <?= $filterPurok === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <select name="status" aria-label="Filter by status">
                            <option value="">All Status</option>
                            <?php foreach ($statusOptions as $s): ?>
                                <option value="<?= htmlspecialchars($s) ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <button class="btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
                        <?php if ($hasFilters): ?>
                            <a class="btn-outline" href="admin.php"><i class="fa-solid fa-rotate-left"></i> Clear</a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if ($showResidentsPanel): ?>
                    <div class="records-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Purok</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$residents): ?>
                                    <tr><td colspan="5" class="empty-state">No pending or approved registrations match the selected filters.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($residents as $r): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($r['email']) ?></td>
                                        <td><?= htmlspecialchars($r['purok']) ?></td>
                                        <td><span class="status-pill status-<?= strtolower((string) $r['application_status']) ?>"><?= htmlspecialchars($r['application_status']) ?></span></td>
                                        <td><a class="btn-outline" href="view_resident.php?id=<?= $r['resident_id'] ?>"><i class="fa-solid fa-eye"></i> Review</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($showRejectedPanel): ?>
                <div class="panel" id="rejected-registrations">
                    <div class="panel-header">
                        <div>
                            <h3>Rejected Registrations</h3>
                            <p>Rejected applications are separated here with their recorded reason.</p>
                        </div>
                    </div>

                    <div class="records-table rejected-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Purok</th>
                                    <th>Reason</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$rejectedResidents): ?>
                                    <tr><td colspan="5" class="empty-state">No rejected registrations match the selected filters.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($rejectedResidents as $r): ?>
                                    <?php $reason = trim((string) ($r['rejection_reason'] ?? '')); ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($r['email']) ?></td>
                                        <td><?= htmlspecialchars($r['purok']) ?></td>
                                        <td class="rejection-reason"><?= htmlspecialchars($reason !== '' ? $reason : 'No reason recorded.') ?></td>
                                        <td><a class="btn-outline" href="view_resident.php?id=<?= $r['resident_id'] ?>"><i class="fa-solid fa-eye"></i> Review</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <div class="admin-panel-grid">
                <div class="panel" id="reports">
                    <div class="panel-header">
                        <div>
                            <h3>Reports Summary</h3>
                            <p>Quick totals for the residence module.</p>
                        </div>
                    </div>

                    <div class="report-grid">
                        <div class="report-tile"><span>Total applications</span><strong><?= $totalApplications ?></strong></div>
                        <div class="report-tile"><span>Pending</span><strong><?= $pending ?></strong></div>
                        <div class="report-tile"><span>Approved</span><strong><?= $approved ?></strong></div>
                        <div class="report-tile"><span>Rejected registrations</span><strong><?= $rejected ?></strong></div>
                    </div>
                </div>

                <div class="panel" id="notifications">
                    <div class="panel-header">
                        <div>
                            <h3>Bulk Notification</h3>
                            <p>Send an announcement to all approved residents.</p>
                        </div>
                    </div>

                    <form action="actions/send_notification.php" method="POST" class="notice-form">
                        <div class="input-group">
                            <label>Title</label>
                            <input name="title" placeholder="Notification title" required>
                        </div>

                        <div class="input-group">
                            <label>Message</label>
                            <input name="message" placeholder="Message to all approved residents" required>
                        </div>

                        <button class="btn-primary"><i class="fa-solid fa-paper-plane"></i> Send Notification</button>
                    </form>
                </div>
            </div>

            <div class="panel" id="audit">
                <div class="panel-header">
                    <div>
                        <h3>Recent Audit Logs</h3>
                        <p>Latest system actions recorded by UGNAYAN.</p>
                    </div>
                </div>

                <div class="list-group audit-list">
                    <?php if (!$logs): ?><div class="empty-state">No audit logs yet.</div><?php endif; ?>
                    <?php foreach ($logs as $log): ?>
                        <div class="list-item">
                            <div class="item-icon"><i class="fa-solid fa-shield-halved"></i></div>
                            <div>
                                <strong><?= htmlspecialchars($log['action']) ?></strong>
                                <p><?= htmlspecialchars((string) $log['description']) ?></p>
                                <small><?= htmlspecialchars($log['created_at']) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
