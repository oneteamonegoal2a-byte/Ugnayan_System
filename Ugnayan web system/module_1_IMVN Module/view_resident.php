<?php
require_once 'includes/auth.php';
require_role(['admin', 'staff']);
require_once 'includes/db.php';

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT r.*, u.email, u.status AS user_status, u.created_at AS account_created_at FROM residents r JOIN users u ON r.user_id=u.user_id WHERE r.resident_id=?');
$stmt->execute([$id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    die('Resident not found.');
}

$docs = $pdo->prepare('SELECT * FROM resident_documents WHERE resident_id=? ORDER BY uploaded_at DESC, document_id DESC');
$docs->execute([$id]);
$docs = $docs->fetchAll(PDO::FETCH_ASSOC);

$nameParts = array_filter([
    trim((string) ($r['first_name'] ?? '')),
    trim((string) ($r['middle_name'] ?? '')),
    trim((string) ($r['last_name'] ?? '')),
    trim((string) ($r['suffix'] ?? '')),
]);
$fullName = trim(implode(' ', $nameParts));
$status = (string) ($r['application_status'] ?? 'Pending');
$statusClass = strtolower($status);
$firstInitial = strtoupper(substr((string) ($r['first_name'] ?? $fullName), 0, 1));
$lastInitial = strtoupper(substr((string) ($r['last_name'] ?? ''), 0, 1));
$initials = trim($firstInitial . $lastInitial) ?: 'R';
$profilePhoto = ugnayan_profile_file_url($r['profile_photo'] ?? '');
$rejectionReason = trim((string) ($r['rejection_reason'] ?? ''));
$registeredAt = trim((string) ($r['account_created_at'] ?? '')) ?: 'Not recorded';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Resident</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin.css">
</head>
<body>
    <?php render_ugnayan_sidebar('residents', [
        'module_label' => 'Residence',
        'user_name' => $_SESSION['email'] ?? 'Admin',
        'user_status' => ucfirst((string) ($_SESSION['role'] ?? 'Admin')),
    ]); ?>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-title">
                <h2>Review Resident Application</h2>
                <p>Verify submitted resident details, documents, and account status.</p>
            </div>
            <div class="topbar-actions">
                <?php render_ugnayan_topbar_tools($pdo, false); ?>
            </div>
        </header>

        <div class="module-content review-page">
            <div class="review-toolbar">
                <a class="btn-outline review-back" href="admin.php"><i class="fa-solid fa-arrow-left"></i> Back to residents</a>
                <span class="review-meta">Application ID #<?= (int) $id ?></span>
            </div>

            <section class="review-summary">
                <div class="applicant-hero">
                    <div class="applicant-photo">
                        <?php if ($profilePhoto !== ''): ?>
                            <img src="<?= htmlspecialchars($profilePhoto) ?>" alt="">
                        <?php else: ?>
                            <?= htmlspecialchars($initials) ?>
                        <?php endif; ?>
                    </div>
                    <div class="applicant-title">
                        <span class="status-pill status-<?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($status) ?></span>
                        <h1><?= htmlspecialchars($fullName) ?></h1>
                        <p><?= htmlspecialchars($r['email']) ?></p>
                    </div>
                </div>

                <div class="review-status-card">
                    <span>Submitted documents</span>
                    <strong><?= count($docs) ?></strong>
                    <small><?= htmlspecialchars(ucfirst((string) ($r['user_status'] ?? 'pending'))) ?> user account</small>
                </div>
            </section>

            <section class="review-grid">
                <div class="panel review-section">
                    <div class="panel-header">
                        <div>
                            <h3>Applicant Details</h3>
                            <p>Profile information submitted during registration.</p>
                        </div>
                    </div>

                    <div class="detail-grid">
                        <div class="detail-item">
                            <span>Name</span>
                            <strong><?= htmlspecialchars($fullName) ?></strong>
                        </div>
                        <div class="detail-item">
                            <span>Email</span>
                            <strong><?= htmlspecialchars($r['email']) ?></strong>
                        </div>
                        <div class="detail-item">
                            <span>Contact</span>
                            <strong><?= htmlspecialchars($r['contact_number']) ?></strong>
                        </div>
                        <div class="detail-item">
                            <span>Purok</span>
                            <strong><?= htmlspecialchars($r['purok']) ?></strong>
                        </div>
                        <div class="detail-item">
                            <span>Gender</span>
                            <strong><?= htmlspecialchars($r['gender']) ?></strong>
                        </div>
                        <div class="detail-item">
                            <span>Civil status</span>
                            <strong><?= htmlspecialchars($r['civil_status']) ?></strong>
                        </div>
                        <div class="detail-item">
                            <span>Birth date</span>
                            <strong><?= htmlspecialchars($r['birth_date']) ?></strong>
                        </div>
                        <div class="detail-item">
                            <span>Occupation</span>
                            <strong><?= htmlspecialchars(trim((string) ($r['occupation'] ?? '')) ?: 'Not provided') ?></strong>
                        </div>
                        <div class="detail-item">
                            <span>Registered</span>
                            <strong><?= htmlspecialchars($registeredAt) ?></strong>
                        </div>
                        <div class="detail-item detail-item-wide">
                            <span>Address</span>
                            <strong><?= htmlspecialchars($r['address']) ?></strong>
                        </div>
                    </div>
                </div>

                <aside class="panel decision-panel">
                    <div class="panel-header">
                        <div>
                            <h3>Decision</h3>
                            <p>Approve or reject this resident application.</p>
                        </div>
                    </div>

                    <?php if ($status === 'Rejected'): ?>
                        <div class="decision-note">
                            <span>Recorded rejection reason</span>
                            <p><?= htmlspecialchars($rejectionReason !== '' ? $rejectionReason : 'No reason recorded.') ?></p>
                        </div>
                    <?php endif; ?>

                    <form action="actions/application_action.php" method="POST" class="approve-form">
                        <input type="hidden" name="resident_id" value="<?= (int) $id ?>">
                        <input type="hidden" name="decision" value="approve">
                        <button class="btn-approve decision-button"><i class="fa-solid fa-circle-check"></i> Approve application</button>
                    </form>

                    <form action="actions/application_action.php" method="POST" class="reject-form">
                        <input type="hidden" name="resident_id" value="<?= (int) $id ?>">
                        <input type="hidden" name="decision" value="reject">
                        <div class="input-group">
                            <label for="reason">Rejection reason</label>
                            <textarea id="reason" name="reason" placeholder="State the missing, invalid, or mismatched requirement." required><?= htmlspecialchars($rejectionReason) ?></textarea>
                        </div>
                        <button class="btn-reject decision-button"><i class="fa-solid fa-circle-xmark"></i> Reject application</button>
                    </form>
                </aside>
            </section>

            <section class="panel review-section">
                <div class="panel-header">
                    <div>
                        <h3>Submitted Documents</h3>
                        <p>Open files in a new tab for validation.</p>
                    </div>
                </div>

                <div class="document-review-list">
                    <?php if (!$docs): ?>
                        <div class="empty-state">No documents were uploaded for this application.</div>
                    <?php endif; ?>

                    <?php foreach ($docs as $d): ?>
                        <?php $uploadedAt = trim((string) ($d['uploaded_at'] ?? '')) ?: 'Not recorded'; ?>
                        <a class="document-review-item" href="<?= htmlspecialchars($d['file_path']) ?>" target="_blank" rel="noopener">
                            <span class="document-review-icon"><i class="fa-solid fa-file-lines"></i></span>
                            <span class="document-review-copy">
                                <strong><?= htmlspecialchars($d['document_type']) ?></strong>
                                <small>Uploaded <?= htmlspecialchars($uploadedAt) ?></small>
                            </span>
                            <span class="document-open">View <i class="fa-solid fa-arrow-up-right-from-square"></i></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
