<?php
$admin_user = current_user();
$admin_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$admin_dir = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$admin_page = match (true) {
    $admin_script === 'admin_dashboard.php' => 'dashboard',
    $admin_script === 'approve_jobs.php' => 'approve',
    $admin_script === 'manage_users.php' || $admin_script === 'view_user.php' => 'users',
    $admin_script === 'manage_skills.php' => 'skills',
    $admin_script === 'categories.php' => 'categories',
    $admin_script === 'notifications.php' => 'notifications',
    default => '',
};

// Get hidden/rejected jobs count for badge
$admin_hidden_count = 0;
try {
    $r = $conn->query("SELECT COUNT(*) AS cnt FROM jobs WHERE status = 'rejected'");
    if ($r) $admin_hidden_count = (int) $r->fetch_assoc()['cnt'];
} catch (mysqli_sql_exception $e) {
}

// Get unread messages count
$admin_unread_count = 0;
try {
    if (function_exists('get_unread_count') && isset($admin_user['id'])) {
        $admin_unread_count = get_unread_count($conn, (int)$admin_user['id']);
    } else {
        $uid = (int)($admin_user['id'] ?? 0);
        $r = $conn->query("SELECT COUNT(*) AS cnt FROM messages WHERE receiver_id = $uid AND status = 'unread'");
        if ($r) $admin_unread_count = (int) $r->fetch_assoc()['cnt'];
    }
} catch (Throwable $e) {
}

$admin_nav = [
    [
        'id' => 'dashboard',
        'label' => 'Dashboard',
        'url' => 'admin/admin_dashboard.php',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>',
    ],
    [
        'id' => 'messages',
        'label' => 'Messages',
        'url' => 'chat/index.php',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>',
        'badge' => $admin_unread_count > 0 ? $admin_unread_count : null,
        'badge_color' => 'indigo',
    ],
    [
        'id' => 'approve',
        'label' => 'Moderate Jobs',
        'url' => 'admin/approve_jobs.php',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>',
        'badge' => $admin_hidden_count > 0 ? $admin_hidden_count : null,
        'badge_color' => 'red',
    ],
    [
        'id' => 'users',
        'label' => __('manage_users'),
        'url' => 'admin/manage_users.php',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>',
    ],
    [
        'id' => 'skills',
        'label' => 'Manage Skills',
        'url' => 'admin/manage_skills.php',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/>',
    ],
    [
        'id' => 'categories',
        'label' => 'Manage Categories',
        'url' => 'admin/categories.php',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>',
    ],
    [
        'id' => 'notifications',
        'label' => 'Notifications',
        'url' => 'admin/notifications.php',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>',
    ],
];
?>

<!-- Navigation -->
<nav class="py-3 flex-1">
    <p class="sidebar-section-title px-5 mb-1.5 text-[10px] font-bold uppercase tracking-widest" style="color:var(--color-text-placeholder)">Navigation</p>
    <?php foreach ($admin_nav as $item):
        $is_active = $admin_page === $item['id'];
    ?>
        <a href="<?= e(base_url($item['url'])) ?>"
            class="sidebar-link <?= $is_active ? 'active' : '' ?>">
            <svg class="w-[18px] h-[18px] flex-shrink-0 <?= $is_active ? 'text-indigo-600 dark:text-indigo-400' : '' ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <?= $item['icon'] ?>
            </svg>
            <span class="sidebar-label flex-1 truncate"><?= e($item['label']) ?></span>
            <?php if (!empty($item['badge'])): ?>
                <?php
                $bc = match ($item['badge_color'] ?? '') {
                    'amber' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
                    'emerald' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
                    'red' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                    default => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
                };
                ?>
                <span class="sidebar-badge <?= $bc ?>"><?= e($item['badge']) ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<!-- Logout -->
<div class="mt-auto p-2 border-t" style="border-color:var(--color-border)">
    <a href="<?= e(base_url('auth/logout.php')) ?>"
        class="sidebar-link text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">
        <svg class="w-[18px] h-[18px] flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
        </svg>
        <span class="sidebar-label sidebar-footer-text"><?= e('Logout') ?></span>
    </a>
</div>
