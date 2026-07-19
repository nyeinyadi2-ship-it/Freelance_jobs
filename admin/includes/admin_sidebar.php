<?php
$admin_user = current_user();
$admin_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$admin_dir = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$admin_page = match (true) {
    $admin_script === 'admin_dashboard.php' => 'dashboard',
    $admin_script === 'approve_jobs.php' => 'approve',
    $admin_script === 'manage_users.php' || $admin_script === 'view_user.php' => 'users',
    $admin_script === 'manage_skills.php' => 'skills',
    $admin_script === 'notifications.php' => 'notifications',
};

// Get hidden/rejected jobs count for badge
$admin_hidden_count = 0;
try {
    $r = $conn->query("SELECT COUNT(*) AS cnt FROM jobs WHERE status = 'rejected'");
    if ($r) $admin_hidden_count = (int) $r->fetch_assoc()['cnt'];
} catch (mysqli_sql_exception $e) {
}

$admin_nav = [
    [
        'id' => 'dashboard',
        'label' => __('nav.dashboard'),
        'url' => 'admin/admin_dashboard.php',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>',
    ],
    [
        'id' => 'approve',
        'label' => 'Moderate Jobs',
        'url' => 'admin/approve_jobs.php',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>',
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
        'id' => 'notifications',
        'label' => __('notif.title'),
        'url' => 'admin/notifications.php',
        'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>',
    ],

];
?>

<!-- Profile Section -->
<!-- <div class="p-4 border-b" style="border-color:var(--color-border)">
    <div class="sidebar-profile flex items-center gap-3">
        <?php $admin_img = profile_image_url($admin_user['profile_image'] ?? ''); ?>
        <?php if ($admin_img): ?>
            <img src="<?= e($admin_img) ?>" alt="" class="sidebar-profile-avatar w-10 h-10 rounded-full object-cover ring-2 ring-indigo-100 dark:ring-indigo-900 flex-shrink-0">
        <?php else: ?>
            <div class="sidebar-profile-avatar w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white font-bold text-sm flex-shrink-0 ring-2 ring-indigo-100 dark:ring-indigo-900">
                <?= e(_first_char($admin_user['username'] ?? 'A')) ?>
            </div>
        <?php endif; ?>
        <div class="sidebar-profile-text min-w-0 flex-1">
            <p class="text-sm font-semibold truncate" style="color:var(--color-text-primary)"><?= e($admin_user['username'] ?? '') ?></p>
            <p class="text-xs font-medium text-indigo-600 dark:text-indigo-400"><?= e(__('role.admin')) ?></p>
        </div>
    </div>
</div> -->

<!-- Navigation -->
<nav class="py-3 flex-1">
    <p class="sidebar-section-title px-5 mb-2 text-[10px] font-bold uppercase tracking-widest" style="color:var(--color-text-placeholder)">Navigation</p>
    <?php foreach ($admin_nav as $item):
        $is_active = $admin_page === $item['id'];
    ?>
        <a href="<?= e(base_url($item['url'])) ?>"
            class="sidebar-link <?= $is_active ? 'active' : '' ?>">
            <svg class="w-5 h-5 flex-shrink-0 <?= $is_active ? 'text-white' : '' ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
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
<div class="mt-auto p-3 border-t" style="border-color:var(--color-border)">
    <a href="<?= e(base_url('logout.php')) ?>"
        class="sidebar-link text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">
        <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
        </svg>
        <span class="sidebar-label sidebar-footer-text"><?= e(__('nav.logout')) ?></span>
    </a>
</div>