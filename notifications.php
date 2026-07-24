<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/notifications.php';

require_login();

$user = current_user();
$user_id = (int) $user['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $nid = (int) ($_POST['notification_id'] ?? 0);
        if ($nid > 0) {
            mark_notification_read($conn, $nid, $user_id);
        }
    } elseif ($action === 'mark_all_read') {
        mark_all_notifications_read($conn, $user_id);
    } elseif ($action === 'delete') {
        $nid = (int) ($_POST['notification_id'] ?? 0);
        if ($nid > 0) {
            delete_notification($conn, $nid, $user_id);
        }
    } elseif ($action === 'delete_all') {
        delete_all_notifications($conn, $user_id);
    }

    redirect('notifications.php');
}

$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'unread', 'read'], true)) {
    $filter = 'all';
}

$notifications = get_notifications_filtered($conn, $user_id, $filter, 50);
$unread_count = get_unread_notification_count($conn, $user_id);
$type_counts = get_notification_count_by_type($conn, $user_id);

$page_title = 'Notifications';
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-3xl mx-auto">
    <div class="flex flex-wrap justify-between items-center gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold" style="color:var(--color-text-primary)"><?= 'Notifications' ?></h1>
            <?php if ($unread_count > 0): ?>
                <p class="text-sm mt-1" style="color:var(--color-text-muted)"><?= $unread_count ?> unread notification<?= $unread_count !== 1 ? 's' : '' ?></p>
            <?php endif; ?>
        </div>
        <div class="flex items-center gap-2">
            <?php if ($unread_count > 0): ?>
                <form method="POST" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">
                        <?= 'Mark all as read' ?>
                    </button>
                </form>
            <?php endif; ?>
            <?php if (!empty($notifications)): ?>
                <form method="POST" class="inline" onsubmit="return confirm('<?= e('Delete all notifications? This cannot be undone.') ?>')">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_all">
                    <button type="submit" class="text-sm text-red-500 hover:text-red-700 font-medium">
                        <?= 'Delete all' ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="flex gap-1 mb-6 p-1 rounded-lg w-fit" style="background:var(--color-card-hover)">
        <?php
        $filters = [
            'all' => 'All',
            'unread' => 'Unread',
            'read' => 'Read',
        ];
        foreach ($filters as $key => $label):
            $count = $key === 'unread' ? $unread_count : ($key === 'all' ? array_sum($type_counts) : null);
        ?>
            <a href="<?= e(base_url('notifications.php?filter=' . $key)) ?>"
               class="px-4 py-2 text-sm font-medium rounded-md transition-colors
                       <?= $filter === $key ? 'text-indigo-600 shadow-sm' : '' ?>"
               style="<?= $filter === $key ? 'background:var(--color-card)' : 'color:var(--color-text-muted)' ?>">
                <?= $label ?>
                <?php if ($count !== null && $count > 0): ?>
                    <span class="ml-1 px-1.5 py-0.5 text-xs rounded-full <?= $filter === $key ? 'text-indigo-600' : '' ?>"
                          style="<?= $filter === $key ? 'background:rgba(99,102,241,0.15)' : 'background:var(--color-card-hover);color:var(--color-text-muted)' ?>">
                        <?= $count ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($notifications)): ?>
        <div class="card text-center py-12">
            <div class="mb-4">
                <svg class="w-16 h-16 mx-auto text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
                </svg>
            </div>
            <p class="text-lg" style="color:var(--color-text-muted)"><?= $filter === 'unread' ? e('You\'re all caught up!') : e('No notifications to show.') ?></p>
        </div>
    <?php else: ?>
        <div class="space-y-2">
            <?php foreach ($notifications as $n): ?>
                <div class="card flex items-start gap-3 transition-all duration-200 <?= $n['is_read'] ? '' : 'border-l-4 border-l-indigo-500' ?>"
                     id="notif-<?= (int) $n['id'] ?>"
                     style="<?= $n['is_read'] ? '' : 'background:rgba(99,102,241,0.1)' ?>">
                    <!-- Notification Icon -->
                    <div class="mt-1 flex-shrink-0">
                        <?= notification_icon($n['type']) ?>
                    </div>

                    <!-- Unread dot -->
                    <?php if (!$n['is_read']): ?>
                        <div class="mt-2 w-2 h-2 rounded-full bg-indigo-500 flex-shrink-0 unread-dot"></div>
                    <?php else: ?>
                        <div class="mt-2 w-2 h-2 flex-shrink-0"></div>
                    <?php endif; ?>

                    <!-- Content -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-0.5">
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full <?= $n['is_read'] ? '' : 'text-indigo-700' ?>"
                                  style="<?= $n['is_read'] ? 'background:var(--color-card-hover);color:var(--color-text-muted)' : 'background:rgba(99,102,241,0.15)' ?>">
                                <?= e(notification_type_label($n['type'])) ?>
                            </span>
                        </div>
                        <p class="text-sm <?= $n['is_read'] ? '' : 'font-medium' ?>"
                           style="<?= $n['is_read'] ? 'color:var(--color-text-muted)' : 'color:var(--color-text-primary)' ?>"><?= e($n['message']) ?></p>
                        <p class="text-xs mt-1" style="color:var(--color-text-placeholder)"><?= e($n['created_at']) ?></p>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <?php if ($n['link']): ?>
                            <?php if (!$n['is_read']): ?>
                                <button type="button" class="text-sm text-indigo-600 hover:underline whitespace-nowrap view-notif-btn"
                                        data-id="<?= (int) $n['id'] ?>"
                                        data-url="<?= e(base_url($n['link'])) ?>"
                                        data-csrf="<?= e(csrf_token()) ?>">
                                    <?= 'View all' ?>
                                </button>
                            <?php else: ?>
                                <a href="<?= e(base_url($n['link'])) ?>" class="text-sm text-indigo-600 hover:underline whitespace-nowrap">
                                    <?= 'View all' ?>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!$n['is_read']): ?>
                            <button type="button" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium whitespace-nowrap mark-read-btn"
                                    data-id="<?= (int) $n['id'] ?>"
                                    data-csrf="<?= e(csrf_token()) ?>"
                                    title="<?= e('Mark as Read') ?>">
                                <?= 'Mark as Read' ?>
                            </button>
                        <?php endif; ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
                            <button type="submit" class="text-sm text-red-400 hover:text-red-600 whitespace-nowrap" title="<?= e('Delete') ?>">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
(function() {
    var csrfToken = '<?= e(csrf_token()) ?>';
    var apiUrl = '<?= e(base_url("api/notifications.php")) ?>';

    // Mark a single notification as read via AJAX and update badge
    function markAsRead(notificationId, csrf, callback) {
        fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                action: 'mark_read',
                notification_id: notificationId,
                csrf_token: csrf
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                updateBadge(data.count);
            }
            if (callback) callback();
        })
        .catch(function() {
            if (callback) callback();
        });
    }

    // Update the notification badge count
    function updateBadge(count) {
        var badge = document.querySelector('.notification-badge');
        if (badge) {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        }
    }

    // View button: mark as read then redirect
    document.querySelectorAll('.view-notif-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = parseInt(this.getAttribute('data-id'));
            var url = this.getAttribute('data-url');
            var csrf = this.getAttribute('data-csrf');
            markAsRead(id, csrf, function() {
                window.location.href = url;
            });
        });
    });

    // Mark Read button: mark as read and update UI inline
    document.querySelectorAll('.mark-read-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = parseInt(this.getAttribute('data-id'));
            var csrf = this.getAttribute('data-csrf');
            var self = this;
            markAsRead(id, csrf, function() {
                // Update the notification row UI
                var row = document.getElementById('notif-' + id);
                if (row) {
                    row.classList.remove('border-l-4', 'border-l-indigo-500');
                    row.style.background = '';
                    var dot = row.querySelector('.unread-dot');
                    if (dot) dot.remove();
                }
                self.remove();
            });
        });
    });

    // Poll for new notifications every 30 seconds
    function pollNotifications() {
        fetch(apiUrl + '?action=count', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            updateBadge(data.count);
        })
        .catch(function() {});
    }

    setInterval(pollNotifications, 30000);
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
