<?php
$admin_user = current_user();
$admin_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$admin_dir = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$admin_page = match(true) {
    $admin_script === 'admin_dashboard.php' => 'dashboard',
    $admin_script === 'approve_jobs.php' => 'approve',
    $admin_script === 'manage_users.php' || $admin_script === 'view_user.php' => 'users',
    $admin_dir === 'chat' && $admin_script === 'index.php' => 'messages',
    default => 'dashboard'
};

// Get pending jobs count for badge
$admin_pending_count = 0;
try {
    $r = $conn->query("SELECT COUNT(*) AS cnt FROM jobs WHERE status = 'pending'");
    if ($r) $admin_pending_count = (int) $r->fetch_assoc()['cnt'];
} catch (mysqli_sql_exception $e) {}

$admin_nav = [
    [
        'id' => 'dashboard',
        'label' => __('nav.dashboard'),
        'url' => 'admin/admin_dashboard.php',
        'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
    ],
    [
        'id' => 'approve',
        'label' => __('nav.approve_jobs'),
        'url' => 'admin/approve_jobs.php',
        'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
        'badge' => $admin_pending_count > 0 ? $admin_pending_count : null,
    ],
    [
        'id' => 'users',
        'label' => __('nav.manage_users'),
        'url' => 'admin/manage_users.php',
        'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
    ],
    [
        'id' => 'messages',
        'label' => __('nav.messages'),
        'url' => 'chat/index.php',
        'icon' => 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
        'badge' => ($chat_unread ?? 0) > 0 ? min($chat_unread, 99) : null,
    ],
];
?>
<!-- Admin Sidebar Navigation -->
<div class="py-4 px-3">
    <!-- Admin Profile -->
    <div class="flex items-center gap-3 px-3 py-3 mb-4 rounded-xl" style="background:var(--color-bg)">
        <?php $admin_img = profile_image_url($admin_user['profile_image'] ?? ''); ?>
        <?php if ($admin_img): ?>
            <img src="<?= e($admin_img) ?>" alt="" class="w-10 h-10 rounded-full object-cover border-2 border-indigo-200 dark:border-indigo-800">
        <?php else: ?>
            <div class="w-10 h-10 rounded-full bg-indigo-100 dark:bg-indigo-900 flex items-center justify-center text-indigo-600 dark:text-indigo-300 font-bold text-sm border-2 border-indigo-200 dark:border-indigo-800 flex-shrink-0">
                <?= e(_first_char($admin_user['username'] ?? 'A')) ?>
            </div>
        <?php endif; ?>
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold truncate" style="color:var(--color-text-primary)"><?= e($admin_user['username'] ?? '') ?></p>
            <p class="text-xs font-medium text-indigo-600 dark:text-indigo-400"><?= e(__('role.admin')) ?></p>
        </div>
    </div>

    <!-- Nav Links -->
    <nav class="space-y-1">
        <?php foreach ($admin_nav as $item):
            $is_active = $admin_page === $item['id'];
            $active_classes = 'bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 font-semibold';
            $inactive_classes = 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 font-medium';
        ?>
            <a href="<?= e(base_url($item['url'])) ?>"
               class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm transition-all duration-200 <?= $is_active ? $active_classes : $inactive_classes ?>"
               style="<?= $is_active ? '' : 'color:var(--color-text-secondary)' ?>">
                <svg class="w-5 h-5 flex-shrink-0 <?= $is_active ? 'text-indigo-600 dark:text-indigo-400' : '' ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="<?= $item['icon'] ?>"/>
                </svg>
                <span class="flex-1 truncate"><?= e($item['label']) ?></span>
                <?php if (!empty($item['badge'])): ?>
                    <span class="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 text-xs font-bold rounded-full
                        <?= $item['id'] === 'approve' ? 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300' : 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300' ?>">
                        <?= e($item['badge']) ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
</div>

<!-- Logout at bottom -->
<div class="mt-auto p-3 border-t" style="border-color:var(--color-border)">
    <a href="<?= e(base_url('logout.php')) ?>"
       class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-all duration-200">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
        </svg>
        <?= e(__('nav.logout')) ?>
    </a>
</div>
