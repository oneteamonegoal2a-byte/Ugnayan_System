<?php
require_once __DIR__ . '/../../module_1_IMVN Module/includes/auth.php';
require_login();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/module_helpers.php';

$isAdmin = ugnayan_is_admin_role();
$resident = ugnayan_get_resident($pdo);
$residentId = $resident ? (int) $resident['resident_id'] : null;
$residentPurok = $resident['purok'] ?? null;
$categories = ['complaint', 'request', 'inquiry', 'feedback', 'others'];
$messageStatuses = ['Open', 'Replied', 'Closed'];
$targetGroups = ['All', 'Residents', 'Staff', 'BHW', 'Specific Purok'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'send_message') {
            if (!$residentId && !$isAdmin) {
                http_response_code(403);
                exit('Access denied.');
            }

            $stmt = $pdo->prepare('INSERT INTO messages (sender_id, receiver_id, category, subject, message, status, is_read) VALUES (?, NULL, ?, ?, ?, "Open", 0)');
            $stmt->execute([ugnayan_user_id(), ugnayan_trim('category', 'inquiry'), ugnayan_trim('subject'), ugnayan_trim('message')]);

            if ($residentId) {
                ugnayan_activity($pdo, $residentId, 'Message Sent', 'Sent message: ' . ugnayan_trim('subject'));
            }

            log_action($pdo, ugnayan_user_id(), 'Sent Message', ugnayan_trim('subject'));
            ugnayan_flash_set('Message sent.');
        } elseif ($action === 'reply_message') {
            ugnayan_require_admin_access();

            $messageId = ugnayan_int('message_id');
            $reply = ugnayan_trim('reply_message');
            $pdo->prepare('INSERT INTO message_replies (message_id, sender_id, reply_message) VALUES (?, ?, ?)')->execute([$messageId, ugnayan_user_id(), $reply]);
            $pdo->prepare('UPDATE messages SET status="Replied", receiver_id=?, is_read=1 WHERE message_id=?')->execute([ugnayan_user_id(), $messageId]);

            $stmt = $pdo->prepare('SELECT m.subject, m.sender_id, r.resident_id FROM messages m LEFT JOIN residents r ON r.user_id=m.sender_id WHERE m.message_id=?');
            $stmt->execute([$messageId]);
            $message = $stmt->fetch();

            if ($message) {
                ugnayan_notify_user($pdo, (int) $message['sender_id'], 'Message Replied', 'Admin replied to "' . $message['subject'] . '": ' . $reply, 'request_update');
                ugnayan_activity($pdo, $message['resident_id'] ? (int) $message['resident_id'] : null, 'Message Replied', 'Admin replied to ' . $message['subject'] . '.');
            }

            log_action($pdo, ugnayan_user_id(), 'Replied to Message', 'Message #' . $messageId);
            ugnayan_flash_set('Reply sent.');
        } elseif ($action === 'update_message_status') {
            ugnayan_require_admin_access();

            $messageId = ugnayan_int('message_id');
            $status = ugnayan_trim('status', 'Open');
            $pdo->prepare('UPDATE messages SET status=?, is_read=1 WHERE message_id=?')->execute([$status, $messageId]);
            log_action($pdo, ugnayan_user_id(), 'Updated Message Status', 'Message #' . $messageId . ' set to ' . $status);
            ugnayan_flash_set('Message status updated.');
        } elseif ($action === 'delete_message') {
            ugnayan_require_admin_access();

            $messageId = ugnayan_int('message_id');
            $pdo->prepare('DELETE FROM messages WHERE message_id=?')->execute([$messageId]);
            log_action($pdo, ugnayan_user_id(), 'Deleted Message', 'Message #' . $messageId);
            ugnayan_flash_set('Message deleted.');
        } elseif ($action === 'save_broadcast') {
            ugnayan_require_admin_access();

            $broadcastId = ugnayan_int('broadcast_id');
            $targetGroup = ugnayan_trim('target_group', 'All');
            $targetPurok = $targetGroup === 'Specific Purok' ? (ugnayan_trim('target_purok') ?: null) : null;

            if ($broadcastId > 0) {
                $stmt = $pdo->prepare('UPDATE broadcast_messages SET title=?, message=?, target_group=?, target_purok=? WHERE broadcast_id=?');
                $stmt->execute([ugnayan_trim('title'), ugnayan_trim('message'), $targetGroup, $targetPurok, $broadcastId]);
                log_action($pdo, ugnayan_user_id(), 'Updated Broadcast', ugnayan_trim('title'));
                ugnayan_flash_set('Broadcast updated.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO broadcast_messages (created_by, title, message, target_group, target_purok) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([ugnayan_user_id(), ugnayan_trim('title'), ugnayan_trim('message'), $targetGroup, $targetPurok]);
                log_action($pdo, ugnayan_user_id(), 'Created Broadcast', ugnayan_trim('title'));
                ugnayan_flash_set('Broadcast created.');
            }

            if (isset($_POST['notify_residents']) && in_array($targetGroup, ['All', 'Residents', 'Specific Purok'], true)) {
                $count = ugnayan_notify_residents($pdo, ugnayan_trim('title'), ugnayan_trim('message'), 'announcement', $targetPurok);
                ugnayan_flash_set('Broadcast saved and notification sent to ' . $count . ' resident(s).');
            }
        } elseif ($action === 'delete_broadcast') {
            ugnayan_require_admin_access();

            $broadcastId = ugnayan_int('broadcast_id');
            $pdo->prepare('DELETE FROM broadcast_messages WHERE broadcast_id=?')->execute([$broadcastId]);
            log_action($pdo, ugnayan_user_id(), 'Deleted Broadcast', 'Broadcast #' . $broadcastId);
            ugnayan_flash_set('Broadcast deleted.');
        } elseif ($action === 'submit_feedback') {
            if (!$residentId) {
                ugnayan_require_admin_access();
            }

            $rating = max(1, min(5, ugnayan_int('rating', 5)));
            $stmt = $pdo->prepare('INSERT INTO feedback (resident_id, rating, comments) VALUES (?, ?, ?)');
            $stmt->execute([$residentId, $rating, ugnayan_trim('comments')]);
            ugnayan_activity($pdo, $residentId, 'Feedback Sent', 'Submitted service feedback.');
            log_action($pdo, ugnayan_user_id(), 'Submitted Feedback', 'Rating ' . $rating);
            ugnayan_flash_set('Feedback submitted.');
        }
    } catch (Throwable $e) {
        ugnayan_flash_set('Action failed: ' . $e->getMessage(), 'error');
    }

    ugnayan_redirect_here('#messages');
}

$flash = ugnayan_flash_get();

if ($isAdmin) {
    $stmt = $pdo->query('SELECT m.*, u.email AS sender_email, CONCAT(r.first_name, " ", r.last_name) AS resident_name FROM messages m JOIN users u ON u.user_id=m.sender_id LEFT JOIN residents r ON r.user_id=m.sender_id ORDER BY m.message_id DESC');
    $broadcasts = $pdo->query('SELECT b.*, u.email AS creator_email FROM broadcast_messages b LEFT JOIN users u ON u.user_id=b.created_by ORDER BY b.broadcast_id DESC')->fetchAll();
    $feedbackList = $pdo->query('SELECT f.*, CONCAT(r.first_name, " ", r.last_name) AS resident_name, r.purok FROM feedback f LEFT JOIN residents r ON r.resident_id=f.resident_id ORDER BY f.feedback_id DESC LIMIT 10')->fetchAll();
} else {
    $stmt = $pdo->prepare('SELECT m.*, u.email AS sender_email, CONCAT(r.first_name, " ", r.last_name) AS resident_name FROM messages m JOIN users u ON u.user_id=m.sender_id LEFT JOIN residents r ON r.user_id=m.sender_id WHERE m.sender_id=? OR m.receiver_id=? ORDER BY m.message_id DESC');
    $stmt->execute([ugnayan_user_id(), ugnayan_user_id()]);

    $stmtBroadcast = $pdo->prepare("SELECT b.*, u.email AS creator_email FROM broadcast_messages b LEFT JOIN users u ON u.user_id=b.created_by WHERE b.target_group IN ('All','Residents') OR (b.target_group='Specific Purok' AND b.target_purok=?) ORDER BY b.broadcast_id DESC");
    $stmtBroadcast->execute([$residentPurok]);
    $broadcasts = $stmtBroadcast->fetchAll();

    $stmtFeedback = $pdo->prepare('SELECT f.*, CONCAT(r.first_name, " ", r.last_name) AS resident_name, r.purok FROM feedback f LEFT JOIN residents r ON r.resident_id=f.resident_id WHERE f.resident_id=? ORDER BY f.feedback_id DESC LIMIT 10');
    $stmtFeedback->execute([$residentId]);
    $feedbackList = $stmtFeedback->fetchAll();
}

$messages = $stmt->fetchAll();
$messageIds = ugnayan_column_int($messages, 'message_id');
$repliesByMessage = [];

if ($messageIds) {
    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
    $replyStmt = $pdo->prepare('SELECT mr.*, u.email AS sender_email FROM message_replies mr JOIN users u ON u.user_id=mr.sender_id WHERE mr.message_id IN (' . $placeholders . ') ORDER BY mr.created_at ASC');
    $replyStmt->execute($messageIds);
    foreach ($replyStmt->fetchAll() as $reply) {
        $repliesByMessage[(int) $reply['message_id']][] = $reply;
    }
}

$totalMessages = count($messages);
$totalBroadcasts = count($broadcasts);
$openMessages = ugnayan_count_by($messages, 'status', 'Open');
$recentMessages = array_slice($messages, 0, 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UGNAYAN - Communication</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/module-10.css">
</head>
<body>
    <?php render_ugnayan_sidebar('communication', [
        'module_label' => 'Module 10',
        'brand_icon' => 'fa-message',
    ]); ?>
    <main class="main-content">
        <header class="topbar">
            <div class="topbar-title"><h2>Communication Module</h2><p><?= $isAdmin ? 'Reply to residents, manage broadcasts, and review feedback' : 'Message the barangay, read replies, and send feedback' ?></p></div>
            <div class="topbar-actions"><?php render_ugnayan_access_pill(); ?></div>
        </header>
        <section class="module-content">
            <?php if ($flash): ?><div class="flash <?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['message']) ?></div><?php endif; ?>

            <div class="stats-grid" id="summary">
                <div class="stat-card"><div class="stat-title">Messages</div><div class="stat-value"><?= $totalMessages ?></div><div class="stat-subtext"><?= $isAdmin ? 'All inbox messages' : 'Your message threads' ?></div></div>
                <div class="stat-card"><div class="stat-title">Open</div><div class="stat-value"><?= $openMessages ?></div><div class="stat-subtext">Need reply</div></div>
                <div class="stat-card"><div class="stat-title">Broadcasts</div><div class="stat-value"><?= $totalBroadcasts ?></div><div class="stat-subtext">Published announcements</div></div>
            </div>

            <div class="management-grid" id="messages">
                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Create Broadcast' : 'Send Message' ?></h3></div>
                    <?php if ($isAdmin): ?>
                        <form method="POST" class="form-grid">
                            <input type="hidden" name="action" value="save_broadcast">
                            <div class="input-group full"><label>Title</label><input name="title" required maxlength="255"></div>
                            <div class="input-group"><label>Target Group</label><select name="target_group"><?php foreach ($targetGroups as $group): ?><option><?= htmlspecialchars($group) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Target Purok</label><input name="target_purok" placeholder="For specific purok"></div>
                            <div class="input-group full"><label>Message</label><textarea name="message" required></textarea></div>
                            <div class="input-group full"><label><input type="checkbox" name="notify_residents" value="1" checked> Notify residents</label></div>
                            <div class="form-actions"><button class="btn-primary"><i class="fa-solid fa-tower-broadcast"></i> Save Broadcast</button></div>
                        </form>
                    <?php else: ?>
                        <form method="POST" class="form-grid">
                            <input type="hidden" name="action" value="send_message">
                            <div class="input-group"><label>Category</label><select name="category"><?php foreach ($categories as $category): ?><option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars(ucwords($category)) ?></option><?php endforeach; ?></select></div>
                            <div class="input-group"><label>Subject</label><input name="subject" required maxlength="255"></div>
                            <div class="input-group full"><label>Message</label><textarea name="message" required></textarea></div>
                            <div class="form-actions"><button class="btn-primary"><i class="fa-solid fa-paper-plane"></i> Send Message</button></div>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Broadcasts' : 'Barangay Broadcasts' ?></h3></div>
                    <div class="records-table">
                        <table>
                            <thead><tr><th>Broadcast</th><th>Target</th><th>Date</th><th><?= $isAdmin ? 'Actions' : 'Source' ?></th></tr></thead>
                            <tbody>
                                <?php if (!$broadcasts): ?><tr><td colspan="4" class="empty-state">No broadcasts yet.</td></tr><?php endif; ?>
                                <?php foreach ($broadcasts as $broadcast): ?>
                                    <tr>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                <form method="POST" class="form-grid">
                                                    <input type="hidden" name="action" value="save_broadcast">
                                                    <input type="hidden" name="broadcast_id" value="<?= (int) $broadcast['broadcast_id'] ?>">
                                                    <div class="input-group full"><input name="title" value="<?= htmlspecialchars($broadcast['title']) ?>" required></div>
                                                    <div class="input-group full"><textarea name="message" required><?= htmlspecialchars($broadcast['message']) ?></textarea></div>
                                            <?php else: ?>
                                                <strong><?= htmlspecialchars($broadcast['title']) ?></strong><br><span class="muted"><?= htmlspecialchars($broadcast['message']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                    <select name="target_group"><?php foreach ($targetGroups as $group): ?><option <?= $broadcast['target_group'] === $group ? 'selected' : '' ?>><?= htmlspecialchars($group) ?></option><?php endforeach; ?></select>
                                                    <input name="target_purok" value="<?= htmlspecialchars($broadcast['target_purok'] ?? '') ?>" placeholder="Purok">
                                            <?php else: ?>
                                                <?= htmlspecialchars($broadcast['target_group']) ?> <?= $broadcast['target_purok'] ? '- ' . htmlspecialchars($broadcast['target_purok']) : '' ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars(date('M d, Y', strtotime($broadcast['created_at']))) ?></td>
                                        <td>
                                            <?php if ($isAdmin): ?>
                                                    <label><input type="checkbox" name="notify_residents" value="1"> Notify</label>
                                                    <div class="row-actions"><button class="btn-secondary btn-small">Update</button>
                                                </form>
                                                        <form method="POST" class="inline-form">
                                                            <input type="hidden" name="action" value="delete_broadcast">
                                                            <input type="hidden" name="broadcast_id" value="<?= (int) $broadcast['broadcast_id'] ?>">
                                                            <button class="btn-danger btn-small">Delete</button>
                                                        </form>
                                                    </div>
                                            <?php else: ?>
                                                <?= htmlspecialchars($broadcast['creator_email'] ?: 'Barangay office') ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header"><h3><?= $isAdmin ? 'Resident Messages' : 'My Messages and Replies' ?></h3></div>
                <div class="records-table">
                    <table>
                        <thead><tr><th>Message</th><th>Sender</th><th>Status</th><th><?= $isAdmin ? 'Admin Action' : 'Replies' ?></th></tr></thead>
                        <tbody>
                            <?php if (!$messages): ?><tr><td colspan="4" class="empty-state">No messages recorded yet.</td></tr><?php endif; ?>
                            <?php foreach ($messages as $message): ?>
                                <tr>
                                    <td><strong class="message-subject"><?= htmlspecialchars($message['subject']) ?></strong><br><span class="muted"><?= htmlspecialchars(ucwords($message['category'])) ?> - <?= htmlspecialchars($message['message']) ?></span><br><span class="muted"><?= htmlspecialchars(date('M d, Y h:i A', strtotime($message['created_at']))) ?></span></td>
                                    <td><?= htmlspecialchars($message['resident_name'] ?: $message['sender_email']) ?></td>
                                    <td><span class="<?= ugnayan_status_class($message['status']) ?>"><?= htmlspecialchars($message['status']) ?></span></td>
                                    <td>
                                        <?php if ($isAdmin): ?>
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="action" value="reply_message">
                                                <input type="hidden" name="message_id" value="<?= (int) $message['message_id'] ?>">
                                                <input name="reply_message" placeholder="Reply" required>
                                                <button class="btn-secondary btn-small">Reply</button>
                                            </form>
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="action" value="update_message_status">
                                                <input type="hidden" name="message_id" value="<?= (int) $message['message_id'] ?>">
                                                <select name="status"><?php foreach ($messageStatuses as $status): ?><option <?= $message['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option><?php endforeach; ?></select>
                                                <button class="btn-secondary btn-small">Status</button>
                                            </form>
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="action" value="delete_message">
                                                <input type="hidden" name="message_id" value="<?= (int) $message['message_id'] ?>">
                                                <button class="btn-danger btn-small">Delete</button>
                                            </form>
                                        <?php else: ?>
                                            <?php if (empty($repliesByMessage[(int) $message['message_id']])): ?>
                                                <span class="muted">No replies yet</span>
                                            <?php else: ?>
                                                <?php foreach ($repliesByMessage[(int) $message['message_id']] as $reply): ?>
                                                    <p><strong><?= htmlspecialchars($reply['sender_email']) ?>:</strong> <?= htmlspecialchars($reply['reply_message']) ?></p>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="module-grid">
                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Recent Messages' : 'Send Feedback' ?></h3></div>
                    <?php if ($isAdmin): ?>
                        <div class="list-group">
                            <?php if (!$recentMessages): ?><div class="empty-state">No messages recorded yet.</div><?php endif; ?>
                            <?php foreach ($recentMessages as $message): ?>
                                <div class="list-item">
                                    <div class="item-info"><div class="item-icon"><i class="fa-solid fa-envelope"></i></div><div><h4 class="message-subject"><?= htmlspecialchars($message['subject']) ?></h4><p><?= htmlspecialchars(ucwords($message['category'])) ?> &middot; <?= $message['is_read'] ? 'Read' : 'Unread' ?></p></div></div>
                                    <span class="<?= ugnayan_status_class($message['status']) ?>"><?= htmlspecialchars($message['status']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <form method="POST" class="form-grid">
                            <input type="hidden" name="action" value="submit_feedback">
                            <div class="input-group"><label>Rating</label><select name="rating"><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= $i ?></option><?php endfor; ?></select></div>
                            <div class="input-group full"><label>Comments</label><textarea name="comments"></textarea></div>
                            <div class="form-actions"><button class="btn-primary"><i class="fa-solid fa-star"></i> Submit Feedback</button></div>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="panel">
                    <div class="panel-header"><h3><?= $isAdmin ? 'Feedback' : 'My Feedback' ?></h3></div>
                    <div class="list-group">
                        <?php if (!$feedbackList): ?><div class="empty-state">No feedback yet.</div><?php endif; ?>
                        <?php foreach ($feedbackList as $feedback): ?>
                            <div class="list-item">
                                <div class="item-info"><div class="item-icon"><i class="fa-solid fa-star"></i></div><div><h4><?= htmlspecialchars($feedback['resident_name'] ?: 'Resident') ?> - <?= (int) $feedback['rating'] ?>/5</h4><p><?= htmlspecialchars($feedback['comments'] ?? '') ?></p></div></div>
                                <span class="muted"><?= htmlspecialchars(date('M d, Y', strtotime($feedback['created_at']))) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
