<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

require_role('admin');

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

    redirect('admin/notifications.php');
}

$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'unread', 'read'], true)) {
    $filter = 'all';
}

$notifications = get_notifications_filtered($conn, $user_id, $filter, 50);
$unread_count = get_unread_notification_count($conn, $user_id);
$type_counts = get_notification_count_by_type($conn, $user_id);

$page_title = 'Notifications';
require __DIR__ . '/includes/admin_header.php';
?>

<!-- Page Header -->
<div class="mb-5 admin-fade">
    <div class="flex flex-wrap justify-between items-center gap-3">
        <div>
            <h1 class="text-xl font-bold" style="color:var(--color-text-primary)"><?= 'Notifications' ?></h1>
            <?php if ($unread_count > 0): ?>
                <p class="text-sm mt-0.5" style="color:var(--color-text-muted)"><?= $unread_count ?> unread notification<?= $unread_count !== 1 ? 's' : '' ?></p>
            <?php else: ?>
                <p class="text-sm mt-0.5" style="color:var(--color-text-muted)">You're all caught up.</p>
            <?php endif; ?>
        </div>
        <div class="flex items-center gap-2">
            <?php if ($unread_count > 0): ?>
                <form method="POST" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                        <?= 'Mark all as read' ?>
                    </button>
                </form>
            <?php endif; ?>
            <?php if (!empty($notifications)): ?>
                <form method="POST" class="inline" onsubmit="return confirm('Delete all notifications? This cannot be undone.')">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_all">
                    <button type="submit" class="text-xs text-red-500 hover:text-red-700 font-medium">
                        <?= 'Delete all' ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Filter Tabs -->
<div class="flex gap-1 mb-5 p-1 rounded-lg w-fit admin-fade" style="background:var(--color-card-hover)">
    <?php
    $filters = [
        'all' => 'All',
        'unread' => 'Unread',
        'read' => 'Read',
    ];
    foreach ($filters as $key => $label):
        $count = $key === 'unread' ? $unread_count : ($key === 'all' ? array_sum($type_counts) : null);
    ?>
        <a href="<?= e(base_url('admin/notifications.php?filter=' . $key)) ?>"
           class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors
                   <?= $filter === $key ? 'text-indigo-600 shadow-sm' : '' ?>"
           style="<?= $filter === $key ? 'background:var(--color-card)' : 'color:var(--color-text-muted)' ?>">
            <?= $label ?>
            <?php if ($count !== null && $count > 0): ?>
                <span class="ml-1 px-1 py-0.5 text-[10px] rounded-full <?= $filter === $key ? 'text-indigo-600' : '' ?>"
                      style="<?= $filter === $key ? 'background:rgba(99,102,241,0.15)' : 'background:var(--color-card-hover);color:var(--color-text-muted)' ?>">
                    <?= $count ?>
                </span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if (empty($notifications)): ?>
    <div class="card text-center py-10 admin-fade">
        <div class="mb-3">
            <svg class="w-12 h-12 mx-auto text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
            </svg>
        </div>
        <p class="text-sm" style="color:var(--color-text-muted)"><?= $filter === 'unread' ? e('You\'re all caught up!') : e('No notifications to show.') ?></p>
    </div>
<?php else: ?>
    <div class="space-y-1.5">
        <?php foreach ($notifications as $n): ?>
            <div class="card flex items-start gap-2.5 transition-all duration-200 admin-fade <?= $n['is_read'] ? '' : 'border-l-[3px] border-l-indigo-500' ?>"
                 id="notif-<?= (int) $n['id'] ?>"
                 style="<?= $n['is_read'] ? '' : 'background:rgba(99,102,241,0.04)' ?>">
                <!-- Notification Icon or Avatar -->
                <?php if (!empty($n['sender_name'])): ?>
                    <div class="mt-0.5 flex-shrink-0">
                        <?php if (!empty($n['sender_image'])): ?>
                            <img src="<?= e(base_url('uploads/profiles/' . $n['sender_image'])) ?>" alt="Avatar" class="w-8 h-8 rounded-full object-cover shadow-sm">
                        <?php else: ?>
                            <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center text-indigo-600 dark:text-indigo-400 font-bold text-xs shadow-sm"><?= strtoupper(substr($n['sender_name'], 0, 1)) ?></div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="mt-0.5 flex-shrink-0 w-8 h-8 flex items-center justify-center rounded-full bg-gray-50 dark:bg-gray-800">
                        <?= notification_icon($n['type']) ?>
                    </div>
                <?php endif; ?>

                <!-- Content -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-1.5 mb-1">
                        <?php if (!empty($n['sender_name'])): ?>
                            <span class="font-semibold text-gray-900 dark:text-white text-[13px]"><?= e($n['sender_name']) ?></span>
                        <?php endif; ?>
                        <span class="inline-flex items-center px-1.5 py-0.5 text-[10px] font-medium rounded <?= $n['is_read'] ? '' : 'text-indigo-700' ?>"
                              style="<?= $n['is_read'] ? 'background:var(--color-card-hover);color:var(--color-text-muted)' : 'background:rgba(99,102,241,0.12)' ?>">
                            <?= e(notification_type_label($n['type'])) ?>
                        </span>
                    </div>
                    <p class="text-sm <?= $n['is_read'] ? '' : 'font-medium' ?>"
                       style="<?= $n['is_read'] ? 'color:var(--color-text-muted)' : 'color:var(--color-text-primary)' ?>"><?= e($n['message']) ?></p>
                    <p class="text-[10px] mt-0.5" style="color:var(--color-text-placeholder)"><?= e($n['created_at']) ?></p>
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-1 flex-shrink-0">
                    <?php if ($n['link']): ?>
                        <a href="<?= e(base_url($n['link'])) ?>" class="text-xs text-indigo-600 hover:underline whitespace-nowrap">
                            <?= 'View' ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!$n['is_read']): ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="mark_read">
                            <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
                            <button type="submit" class="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" style="color:var(--color-text-muted)" title="<?= e(__('notif.dismiss')) ?>">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            </button>
                        </form>
                    <?php endif; ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
                        <button type="submit" class="p-1 rounded hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors" style="color:var(--color-text-placeholder)" title="<?= e('Delete') ?>">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
(function() {
    var els = document.querySelectorAll('.admin-fade');
    els.forEach(function(el) { el.classList.add('animate'); });
    var obs = new IntersectionObserver(function(entries) {
        entries.forEach(function(e) { if (e.isIntersecting) e.target.classList.add('visible'); });
    }, { threshold: 0.1 });
    els.forEach(function(el) { obs.observe(el); });
})();
</script>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
