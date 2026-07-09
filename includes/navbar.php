<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/notifications.php';

$user = current_user();
$role = $user['role'] ?? null;
$unread_count = 0;
$recent_notifications = [];
$chat_unread = 0;

if ($role && isset($user['user_id'])) {
    $unread_count = get_unread_notification_count($conn, (int) $user['user_id']);
    $recent_notifications = get_notifications($conn, (int) $user['user_id'], 5);
    if (in_array($role, ['company', 'freelancer'], true)) {
        require_once __DIR__ . '/../config/chat.php';
        $chat_unread = get_unread_count($conn, (int) $user['user_id']);
    }
}
$current_lang = current_lang();
$is_dark = isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark';
?>
<nav class="shadow-sm border-b" style="background:var(--color-nav);border-color:var(--color-border)">
    <div class="container mx-auto px-4 max-w-6xl">
        <div class="flex items-center justify-between h-16">
            <a href="<?= e(base_url('index.php')) ?>" class="text-xl font-bold text-indigo-600"><?= e(__('app.name')) ?></a>

            <div class="flex items-center gap-3">
                <?php if ($role === 'admin'): ?>
                    <a href="<?= e(base_url('admin/admin_dashboard.php')) ?>" class="text-sm font-medium hover:text-indigo-600" style="color:var(--color-text-secondary)"><?= e(__('nav.dashboard')) ?></a>
                    <a href="<?= e(base_url('admin/approve_jobs.php')) ?>" class="text-sm font-medium hover:text-indigo-600" style="color:var(--color-text-secondary)"><?= e(__('nav.approve_jobs')) ?></a>
                    <a href="<?= e(base_url('admin/manage_users.php')) ?>" class="text-sm font-medium hover:text-indigo-600" style="color:var(--color-text-secondary)"><?= e(__('nav.manage_users')) ?></a>
                <?php elseif ($role === 'company'): ?>
                    <a href="<?= e(base_url('company/dashboard.php')) ?>" class="text-sm font-medium hover:text-indigo-600" style="color:var(--color-text-secondary)"><?= e(__('nav.dashboard')) ?></a>
                    <a href="<?= e(base_url('company/profile.php')) ?>" class="text-sm font-medium hover:text-indigo-600" style="color:var(--color-text-secondary)"><?= e(__('nav.profile')) ?></a>
                    <a href="<?= e(base_url('company/post_job.php')) ?>" class="text-sm font-medium hover:text-indigo-600" style="color:var(--color-text-secondary)"><?= e(__('nav.post_job')) ?></a>
                    <a href="<?= e(base_url('company/manage_jobs.php')) ?>" class="text-sm font-medium hover:text-indigo-600" style="color:var(--color-text-secondary)"><?= e(__('nav.my_jobs')) ?></a>
                    <a href="<?= e(base_url('chat/index.php')) ?>" class="text-sm font-medium hover:text-indigo-600 relative" style="color:var(--color-text-secondary)">
                        <?= e(__('nav.messages')) ?>
                        <?php if ($chat_unread > 0): ?>
                            <span class="absolute -top-2 -right-4 bg-green-500 text-white text-xs font-bold rounded-full min-w-[16px] h-[16px] flex items-center justify-center px-1" style="font-size:10px;"><?= min($chat_unread, 99) ?></span>
                        <?php endif; ?>
                    </a>
                <?php elseif ($role === 'freelancer'): ?>
                    <a href="<?= e(base_url('freelancer/dashboard.php')) ?>" class="text-sm font-medium hover:text-indigo-600" style="color:var(--color-text-secondary)"><?= e(__('nav.dashboard')) ?></a>
                    <a href="<?= e(base_url('freelancer/profile.php')) ?>" class="text-sm font-medium hover:text-indigo-600" style="color:var(--color-text-secondary)"><?= e(__('nav.profile')) ?></a>
                    <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="text-sm font-medium hover:text-indigo-600" style="color:var(--color-text-secondary)"><?= e(__('nav.browse_jobs')) ?></a>
                    <a href="<?= e(base_url('freelancer/my_tasks.php')) ?>" class="text-sm font-medium hover:text-indigo-600" style="color:var(--color-text-secondary)"><?= e(__('nav.my_tasks')) ?></a>
                    <a href="<?= e(base_url('chat/index.php')) ?>" class="text-sm font-medium hover:text-indigo-600 relative" style="color:var(--color-text-secondary)">
                        <?= e(__('nav.messages')) ?>
                        <?php if ($chat_unread > 0): ?>
                            <span class="absolute -top-2 -right-4 bg-green-500 text-white text-xs font-bold rounded-full min-w-[16px] h-[16px] flex items-center justify-center px-1" style="font-size:10px;"><?= min($chat_unread, 99) ?></span>
                        <?php endif; ?>
                    </a>
                <?php else: ?>
                    <a href="<?= e(base_url('login.php')) ?>" class="text-sm font-medium hover:text-indigo-600" style="color:var(--color-text-secondary)"><?= e(__('nav.login')) ?></a>
                    <a href="<?= e(base_url('register.php')) ?>" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 text-sm font-medium"><?= e(__('nav.register')) ?></a>
                <?php endif; ?>

                <?php if ($role): ?>
                    <!-- Language Switcher -->
                    <div class="relative" id="lang-switcher">
                        <button type="button" onclick="document.getElementById('lang-dropdown').classList.toggle('hidden')" class="flex items-center gap-1 px-2 py-1 rounded text-sm font-medium border" style="color:var(--color-text-secondary);border-color:var(--color-border);background:var(--color-card)">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span class="hidden sm:inline"><?= $current_lang === 'my' ? 'MY' : 'EN' ?></span>
                        </button>
                        <div id="lang-dropdown" class="hidden absolute right-0 mt-1 w-28 rounded-lg shadow-lg border z-50" style="background:var(--color-card);border-color:var(--color-border)">
                            <a href="<?= e(lang_switch_url('en')) ?>" class="block px-3 py-2 text-sm font-medium <?= $current_lang === 'en' ? 'text-indigo-600' : '' ?>" style="color:<?= $current_lang === 'en' ? '' : 'var(--color-text-secondary)' ?>"><?= e(__('lang.en')) ?></a>
                            <a href="<?= e(lang_switch_url('my')) ?>" class="block px-3 py-2 text-sm font-medium <?= $current_lang === 'my' ? 'text-indigo-600' : '' ?>" style="color:<?= $current_lang === 'my' ? '' : 'var(--color-text-secondary)' ?>"><?= e(__('lang.my')) ?></a>
                        </div>
                    </div>

                    <!-- Dark Mode Toggle -->
                    <button id="theme-toggle" type="button" class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" style="color:var(--color-text-secondary)" aria-label="Toggle theme">
                        <svg class="w-5 h-5 dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                        </svg>
                        <svg class="w-5 h-5 hidden dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </button>

                    <!-- Notification Bell -->
                    <div class="relative notification-container">
                        <button type="button" class="notification-toggle relative p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none transition-colors" style="color:var(--color-text-secondary)" aria-label="<?= e(__('notif.title')) ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                            </svg>
                            <?php if ($unread_count > 0): ?>
                                <span class="notification-badge absolute -top-1.5 -right-1.5 bg-red-500 text-white text-xs font-bold rounded-full min-w-[18px] h-[18px] flex items-center justify-center px-1" style="display:flex;"><?= min($unread_count, 99) ?></span>
                            <?php else: ?>
                                <span class="notification-badge absolute -top-1.5 -right-1.5 bg-red-500 text-white text-xs font-bold rounded-full min-w-[18px] h-[18px] items-center justify-center px-1" style="display:none;">0</span>
                            <?php endif; ?>
                        </button>

                        <div class="notification-dropdown absolute right-0 mt-2 w-80 rounded-lg shadow-lg border z-50 hidden" style="display:none;">
                            <div class="p-3 border-b flex justify-between items-center" style="border-color:var(--color-border)">
                                <span class="font-semibold text-sm" style="color:var(--color-text-primary)"><?= __('notif.recent') ?></span>
                                <div class="flex items-center gap-2">
                                    <?php if ($unread_count > 0): ?>
                                        <button type="button" class="notification-mark-all text-xs text-indigo-600 hover:underline" data-csrf="<?= e(csrf_token()) ?>"><?= __('notif.mark_all_read') ?></button>
                                    <?php endif; ?>
                                    <a href="<?= e(base_url('notifications.php')) ?>" class="text-xs hover:text-indigo-600" style="color:var(--color-text-muted)"><?= __('notif.view_all') ?></a>
                                </div>
                            </div>
                            <div class="max-h-80 overflow-y-auto notification-list">
                                <?php if (empty($recent_notifications)): ?>
                                    <div class="p-6 text-center text-sm" style="color:var(--color-text-placeholder)"><?= __('notif.no_notifications') ?></div>
                                <?php else: ?>
                                    <?php foreach ($recent_notifications as $n): ?>
                                        <div class="notification-item flex items-start gap-2 px-3 py-2.5 border-b last:border-0 <?= $n['is_read'] ? '' : 'bg-indigo-50/50 dark:bg-indigo-900/20' ?>" style="border-color:var(--color-border)" data-id="<?= (int) $n['id'] ?>">
                                            <div class="mt-0.5 flex-shrink-0"><?= notification_icon($n['type']) ?></div>
                                            <div class="flex-1 min-w-0">
                                                <a href="<?= e($n['link'] ? base_url($n['link']) : base_url('notifications.php')) ?>" class="block">
                                                    <p class="text-sm <?= $n['is_read'] ? '' : 'font-medium' ?>" style="color:<?= $n['is_read'] ? 'var(--color-text-muted)' : 'var(--color-text-primary)' ?>"><?= e($n['message']) ?></p>
                                                    <p class="text-xs mt-0.5" style="color:var(--color-text-placeholder)"><?= e($n['created_at']) ?></p>
                                                </a>
                                            </div>
                                            <button type="button" class="notification-delete-btn flex-shrink-0 p-1 opacity-0 group-hover:opacity-100 transition-opacity" style="color:var(--color-text-placeholder)" data-id="<?= (int) $n['id'] ?>" data-csrf="<?= e(csrf_token()) ?>" title="<?= __('notif.delete') ?>">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- User Info -->
                    <?php $profileLink = $role === 'company' ? 'company/profile.php' : ($role === 'freelancer' ? 'freelancer/profile.php' : '#'); ?>
                    <a href="<?= e(base_url($profileLink)) ?>" class="flex items-center gap-2 hover:opacity-80 transition-opacity">
                        <?php $imgUrl = profile_image_url($user['profile_image']); ?>
                        <?php if ($imgUrl): ?>
                            <img src="<?= e($imgUrl) ?>" alt="" class="w-8 h-8 rounded-full object-cover border" style="border-color:var(--color-border)">
                        <?php else: ?>
                            <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900 flex items-center justify-center text-indigo-600 dark:text-indigo-300 font-bold text-sm border" style="border-color:var(--color-border)">
                                <?= e(_first_char($user['username'])) ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($user['logo_image']): ?>
                            <img src="<?= e(base_url('uploads/' . $user['logo_image'])) ?>" alt="" class="h-8 w-auto max-w-[80px] object-contain">
                        <?php endif; ?>
                        <span class="text-sm hidden sm:inline" style="color:var(--color-text-muted)"><?= e($user['username']) ?></span>
                    </a>
                    <a href="<?= e(base_url('logout.php')) ?>" class="text-sm font-medium text-red-500 hover:text-red-600"><?= e(__('nav.logout')) ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<script>
(function() {
    // Notification dropdowns
    var containers = document.querySelectorAll('.notification-container');
    containers.forEach(function(container) {
        var toggle = container.querySelector('.notification-toggle');
        var dropdown = container.querySelector('.notification-dropdown');
        if (toggle && dropdown) {
            toggle.addEventListener('click', function(e) {
                e.stopPropagation();
                var isOpen = dropdown.style.display === 'block';
                document.querySelectorAll('.notification-dropdown').forEach(function(d) { d.style.display = 'none'; });
                if (!isOpen) dropdown.style.display = 'block';
            });
        }
    });
    document.addEventListener('click', function() {
        document.querySelectorAll('.notification-dropdown').forEach(function(d) { d.style.display = 'none'; });
        document.getElementById('lang-dropdown')?.classList.add('hidden');
    });

    // Mark all as read
    document.querySelectorAll('.notification-mark-all').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            fetch('<?= e(base_url("api/notifications.php")) ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action: 'mark_all_read', csrf_token: this.getAttribute('data-csrf') })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    document.querySelectorAll('.notification-item').forEach(function(item) {
                        item.classList.remove('bg-indigo-50/50', 'dark:bg-indigo-900/20');
                        var msg = item.querySelector('p');
                        if (msg) { msg.classList.remove('font-medium'); msg.style.color = ''; }
                    });
                    var badge = document.querySelector('.notification-badge');
                    if (badge) { badge.style.display = 'none'; }
                    var markAllBtn = document.querySelector('.notification-mark-all');
                    if (markAllBtn) { markAllBtn.style.display = 'none'; }
                }
            })
            .catch(function() {});
        });
    });

    // Delete notification
    document.querySelectorAll('.notification-delete-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var id = this.getAttribute('data-id');
            var csrf = this.getAttribute('data-csrf');
            var item = this.closest('.notification-item');
            fetch('<?= e(base_url("api/notifications.php")) ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action: 'delete', notification_id: parseInt(id), csrf_token: csrf })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && item) {
                    item.style.transition = 'opacity 0.2s, max-height 0.2s';
                    item.style.opacity = '0';
                    item.style.maxHeight = '0';
                    item.style.overflow = 'hidden';
                    setTimeout(function() { item.remove(); }, 200);
                    var badge = document.querySelector('.notification-badge');
                    if (badge) {
                        badge.textContent = data.count > 99 ? '99+' : data.count;
                        badge.style.display = data.count > 0 ? 'flex' : 'none';
                    }
                }
            })
            .catch(function() {});
        });
    });

    // Notification polling
    setInterval(function() {
        fetch('<?= e(base_url("api/notifications.php")) ?>?action=count', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var badge = document.querySelector('.notification-badge');
            if (badge) {
                badge.textContent = data.count > 99 ? '99+' : data.count;
                badge.style.display = data.count > 0 ? 'flex' : 'none';
            }
        })
        .catch(function() {});
    }, 30000);

    // Chat unread polling
    var chatBadge = document.querySelector('.chat-unread-badge');
    if (chatBadge) {
        setInterval(function() {
            fetch('<?= e(base_url("api/chat.php")) ?>?action=get_unread_count', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var badge = document.querySelector('.chat-unread-badge');
                if (badge) {
                    badge.textContent = data.count > 99 ? '99+' : data.count;
                    badge.style.display = data.count > 0 ? 'flex' : 'none';
                }
            })
            .catch(function() {});
        }, 15000);
    }
})();
</script>
