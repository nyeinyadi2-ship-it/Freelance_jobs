<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/chat.php';
require_once __DIR__ . '/../config/notifications.php';

require_role('admin');

$page_title = 'Password Recovery';

// Fetch recovery requests
$requests = [];
try {
    $r = $conn->query("
        SELECT u.id, u.username, u.email,
               (SELECT created_at FROM messages WHERE sender_id = u.id AND JSON_EXTRACT(message_meta, '$.is_recovery_request') = true ORDER BY id DESC LIMIT 1) as request_date,
               (SELECT message_meta FROM messages WHERE sender_id = u.id AND JSON_EXTRACT(message_meta, '$.is_recovery_request') = true ORDER BY id DESC LIMIT 1) as latest_meta
        FROM users u
        WHERE EXISTS (SELECT 1 FROM messages WHERE sender_id = u.id AND JSON_EXTRACT(message_meta, '$.is_recovery_request') = true)
        ORDER BY request_date DESC
    ");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $meta = [];
            if (!empty($row['latest_meta'])) {
                $meta = json_decode($row['latest_meta'], true) ?? [];
            }
            $row['status'] = $meta['status'] ?? 'Pending';
            $requests[] = $row;
        }
    } else {
        set_flash('error', 'Database query failed: ' . $conn->error);
    }
} catch (Throwable $e) {
    set_flash('error', 'Error retrieving requests: ' . $e->getMessage());
}

// Action handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf()) {
        set_flash('error', 'Invalid request.');
        redirect('admin/password_recovery.php');
    }
    
    $action = $_POST['action'];
    $target_user_id = (int) ($_POST['user_id'] ?? 0);
    $admin_id = (int) $_SESSION['user_id'];
    
    if ($action === 'resolve' && $target_user_id > 0) {
        // Find latest temp password and invalidate it
        $stmt = $conn->prepare("SELECT id, message_meta FROM messages WHERE (sender_id = ? OR receiver_id = ?) AND message_meta LIKE '%temp_password_hash%' ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('ii', $target_user_id, $target_user_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($res && !empty($res['message_meta'])) {
            $meta = json_decode($res['message_meta'], true);
            $meta['invalidated'] = true;
            $new_meta = json_encode($meta);
            
            $stmt = $conn->prepare("UPDATE messages SET message_meta = ? WHERE id = ?");
            $stmt->bind_param('si', $new_meta, $res['id']);
            $stmt->execute();
            $stmt->close();
        }
        
        // Also send a system message to indicate resolution
        $msg = "Password recovery request has been marked as resolved by Admin.";
        $meta = json_encode(["is_system" => true]);
        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, message_type, message_meta) VALUES (?, ?, ?, 'system', ?)");
        $stmt->bind_param('iiss', $admin_id, $target_user_id, $msg, $meta);
        $stmt->execute();
        $msg_id = $stmt->insert_id;
        $stmt->close();
        
        $u1 = min($target_user_id, $admin_id);
        $u2 = max($target_user_id, $admin_id);
        $stmt = $conn->prepare('INSERT INTO conversations (user_one_id, user_two_id, last_message_id, last_activity) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE last_message_id = VALUES(last_message_id), last_activity = NOW()');
        $stmt->bind_param('iii', $u1, $u2, $msg_id);
        $stmt->execute();
        $stmt->close();
        
        set_flash('success', 'Recovery request resolved.');
        redirect('admin/password_recovery.php');
    }
}

require __DIR__ . '/includes/admin_header.php';
?>

<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-slate-800 dark:text-slate-100">Password Recovery Requests</h1>
        <p class="text-sm text-slate-500 mt-1">Manage user requests for account password recovery</p>
    </div>
</div>

<?php if ($f = get_flash()): ?>
    <div class="mb-4 p-4 rounded-lg <?= $f['type'] === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
        <?= e($f['message']) ?>
    </div>
<?php endif; ?>

<div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl overflow-hidden shadow-sm">
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-700 text-xs uppercase tracking-wider text-slate-500 dark:text-slate-400 font-semibold">
                    <th class="px-6 py-4">User</th>
                    <th class="px-6 py-4">Request Date</th>
                    <th class="px-6 py-4">Status</th>
                    <th class="px-6 py-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/50">
                <?php if (empty($requests)): ?>
                <tr>
                    <td colspan="4" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">
                        No password recovery requests found.
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($requests as $req): ?>
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center text-indigo-600 dark:text-indigo-400 font-bold uppercase text-sm">
                                    <?= e(substr($req['username'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="font-medium text-slate-800 dark:text-slate-200"><?= e($req['username']) ?></div>
                                    <div class="text-xs text-slate-500"><?= e($req['email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                            <?= date('M j, Y g:i A', strtotime($req['request_date'])) ?>
                        </td>
                        <td class="px-6 py-4">
                            <?php 
                                $status_color = match($req['status']) {
                                    'Pending' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
                                    'In Progress' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                                    'Temporary Password Generated' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400',
                                    'Resolved' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
                                    'Expired' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
                                    default => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
                                };
                            ?>
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium <?= $status_color ?>">
                                <?= e($req['status']) ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <a href="<?= e(base_url('chat/index.php?user_id=' . $req['id'])) ?>" class="px-3 py-1.5 bg-indigo-50 text-indigo-600 hover:bg-indigo-100 dark:bg-indigo-900/30 dark:text-indigo-400 dark:hover:bg-indigo-900/50 rounded-lg text-sm font-medium transition-colors">
                                    Open Chat
                                </a>
                                <?php if ($req['status'] !== 'Resolved'): ?>
                                <button type="button" onclick="generateTempPassword(<?= $req['id'] ?>)" class="px-3 py-1.5 bg-amber-50 text-amber-600 hover:bg-amber-100 dark:bg-amber-900/30 dark:text-amber-400 dark:hover:bg-amber-900/50 rounded-lg text-sm font-medium transition-colors">
                                    Generate Temporary Password
                                </button>
                                <?php endif; ?>

                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>

<script>
function generateTempPassword(userId) {
    if (!confirm("Generate a new temporary password for this user? This will overwrite their current password.")) {
        return;
    }
    
    fetch('<?= e(base_url('admin/api_recovery.php')) ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ action: 'generate_temp_password', user_id: userId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Show password to admin
            prompt("Temporary Password Generated successfully! Please copy and send it to the user:", data.temp_password);
            window.location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(err => {
        alert('An error occurred during password generation.');
    });
}
</script>
