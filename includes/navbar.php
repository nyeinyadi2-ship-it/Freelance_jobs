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
    if (in_array($role, ['company', 'freelancer', 'admin'], true)) {
        require_once __DIR__ . '/../config/chat.php';
        $chat_unread = get_unread_count($conn, (int) $user['user_id']);
    }
}
$current_lang = current_lang();
$is_logged_in = $role && in_array($role, ['company', 'freelancer'], true);

$current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$is_home = ($current_script === 'index.php');
$base = base_url('');

$home_links = [
    ['label' => 'Home', 'href' => $is_home ? '#' : $base . 'index.php', 'anchor' => ''],
    ['label' => 'Find Jobs', 'href' => $is_home ? '#find-jobs' : $base . 'index.php#find-jobs', 'anchor' => 'find-jobs'],
    ['label' => 'Freelancers', 'href' => $is_home ? '#freelancers' : $base . 'index.php#freelancers', 'anchor' => 'freelancers'],
    ['label' => 'Categories', 'href' => $is_home ? '#categories' : $base . 'index.php#categories', 'anchor' => 'categories'],
    ['label' => 'About', 'href' => $is_home ? '#why-us' : $base . 'index.php#why-us', 'anchor' => 'why-us'],
];

$role_links = [];
if ($is_logged_in) {
    if ($role === 'company') {
        $role_links = [
            ['label' => __('nav.dashboard'), 'href' => base_url('company/dashboard.php'), 'icon' => 'home', 'page' => 'dashboard'],
            ['label' => __('nav.post_job'), 'href' => base_url('company/post_job.php'), 'icon' => 'plus', 'page' => 'post_job'],
            ['label' => __('nav.my_jobs'), 'href' => base_url('company/manage_jobs.php'), 'icon' => 'briefcase', 'page' => 'manage_jobs'],
        ];
    } elseif ($role === 'freelancer') {
        $role_links = [
            ['label' => __('nav.dashboard'), 'href' => base_url('freelancer/dashboard.php'), 'icon' => 'home', 'page' => 'dashboard'],
            ['label' => __('nav.browse_jobs'), 'href' => base_url('freelancer/browse_jobs.php'), 'icon' => 'search', 'page' => 'browse_jobs'],
            ['label' => __('nav.my_tasks'), 'href' => base_url('freelancer/my_tasks.php'), 'icon' => 'clipboard', 'page' => 'my_tasks'],
            ['label' => 'Portfolio', 'href' => base_url('freelancer/portfolio.php'), 'icon' => 'briefcase', 'page' => 'portfolio'],
        ];
    }
}
?>
<style>
/* ===== GLASS NAVBAR ===== */
.nb{position:sticky;top:0;z-index:50;background:rgba(255,255,255,.82);backdrop-filter:blur(24px) saturate(180%);-webkit-backdrop-filter:blur(24px) saturate(180%);border-bottom:1px solid rgba(0,0,0,.05);transition:box-shadow .3s}
html.dark .nb{background:rgba(15,23,42,.85);border-bottom-color:rgba(255,255,255,.06)}
.nb.sh{box-shadow:0 1px 3px rgba(0,0,0,.06),0 8px 32px rgba(0,0,0,.04)}
html.dark .nb.sh{box-shadow:0 1px 3px rgba(0,0,0,.2),0 8px 32px rgba(0,0,0,.15)}

/* Nav links */
.nl{position:relative;padding:.5rem .875rem;font-size:.8125rem;font-weight:500;color:#64748b;border-radius:10px;text-decoration:none;white-space:nowrap;transition:all .2s cubic-bezier(.4,0,.2,1)}
html.dark .nl{color:#94a3b8}
.nl:hover{color:#4f46e5;background:rgba(99,102,241,.06)}
html.dark .nl:hover{color:#818cf8;background:rgba(99,102,241,.1)}
.nl.on{color:#4f46e5;background:rgba(99,102,241,.08);font-weight:600}
html.dark .nl.on{color:#818cf8;background:rgba(99,102,241,.12)}
.nl-bar{position:absolute;bottom:2px;left:50%;transform:translateX(-50%);width:0;height:2px;background:linear-gradient(90deg,#6366f1,#8b5cf6);border-radius:2px;transition:width .25s cubic-bezier(.4,0,.2,1)}
.nl:hover .nl-bar,.nl.on .nl-bar{width:55%}

/* Icon buttons */
.ni{display:flex;align-items:center;justify-content:center;width:2.25rem;height:2.25rem;border-radius:10px;color:#64748b;transition:all .2s cubic-bezier(.4,0,.2,1);cursor:pointer;background:none;border:none}
html.dark .ni{color:#94a3b8}
.ni:hover{color:#4f46e5;background:rgba(99,102,241,.07)}
html.dark .ni:hover{color:#818cf8;background:rgba(99,102,241,.12)}
.ni:active{transform:scale(.95)}
.badge{min-width:16px;height:16px;display:flex;align-items:center;justify-content:center;padding:0 4px;font-size:9px;font-weight:700;color:#fff;border-radius:9999px;border:2px solid #fff;line-height:1}
html.dark .badge{border-color:#0f172a}

/* Profile */
.np{display:flex;align-items:center;gap:.5rem;padding:.2rem .5rem .2rem .2rem;border-radius:12px;transition:all .2s;cursor:pointer}
.np:hover{background:rgba(99,102,241,.05)}
html.dark .np:hover{background:rgba(99,102,241,.1)}
.na{width:2.125rem;height:2.125rem;border-radius:9px;object-fit:cover;border:2px solid transparent;background-image:linear-gradient(#fff,#fff),linear-gradient(135deg,#6366f1,#8b5cf6);background-origin:border-box;background-clip:padding-box,border-box;transition:all .2s}
html.dark .na{background-image:linear-gradient(#1e293b,#1e293b),linear-gradient(135deg,#6366f1,#8b5cf6)}
.np:hover .na{transform:scale(1.06);box-shadow:0 0 0 3px rgba(99,102,241,.12)}

/* Dropdowns */
.dd{position:absolute;right:0;margin-top:.5rem;min-width:220px;background:#fff;border:1px solid rgba(0,0,0,.06);border-radius:14px;box-shadow:0 16px 48px rgba(0,0,0,.1),0 0 0 1px rgba(0,0,0,.02);opacity:0;visibility:hidden;transform:translateY(-6px) scale(.97);transition:all .18s cubic-bezier(.16,1,.3,1);z-index:100;overflow:hidden}
html.dark .dd{background:#1e293b;border-color:rgba(255,255,255,.07);box-shadow:0 16px 48px rgba(0,0,0,.35)}
.dd.show{opacity:1;visibility:visible;transform:translateY(0) scale(1)}
.di{display:flex;align-items:center;gap:.625rem;padding:.5625rem .875rem;font-size:.8125rem;font-weight:500;color:#475569;text-decoration:none;transition:all .12s}
html.dark .di{color:#cbd5e1}
.di:hover{background:rgba(99,102,241,.06);color:#4f46e5}
html.dark .di:hover{background:rgba(99,102,241,.1);color:#818cf8}

/* CTA */
.nc{display:inline-flex;align-items:center;justify-content:center;padding:.5rem 1.125rem;font-size:.8125rem;font-weight:600;color:#fff;background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:10px;text-decoration:none;transition:all .2s;box-shadow:0 2px 8px rgba(99,102,241,.2)}
.nc:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(99,102,241,.3)}
.nc:active{transform:translateY(0)}

/* Mobile */
.mo{position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(4px);z-index:99;opacity:0;visibility:hidden;transition:all .3s}
.mo.show{opacity:1;visibility:visible}
.mp{position:fixed;top:0;right:0;bottom:0;width:min(320px,85vw);background:#fff;z-index:100;transform:translateX(100%);transition:transform .3s cubic-bezier(.16,1,.3,1);overflow-y:auto;box-shadow:-8px 0 32px rgba(0,0,0,.12)}
html.dark .mp{background:#0f172a}
.mp.show{transform:translateX(0)}
.ml{display:flex;align-items:center;gap:.625rem;padding:.75rem 1.125rem;font-size:.875rem;font-weight:500;color:#475569;text-decoration:none;border-radius:10px;margin:2px .75rem;transition:all .15s}
html.dark .ml{color:#cbd5e1}
.ml:hover{background:rgba(99,102,241,.06);color:#4f46e5}
html.dark .ml:hover{background:rgba(99,102,241,.1);color:#818cf8}
.ml.on{background:rgba(99,102,241,.08);color:#4f46e5;font-weight:600}
html.dark .ml.on{background:rgba(99,102,241,.12);color:#818cf8}
</style>

<nav class="nb" id="main-nav">
    <div class="mx-auto px-4 sm:px-6 lg:px-8 max-w-7xl">
        <div class="flex items-center justify-between h-[60px]">

            <!-- Logo -->
            <a href="<?= e(base_url('index.php')) ?>" class="flex items-center gap-2.5 flex-shrink-0 group">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center transition-all duration-300 group-hover:scale-105 group-hover:shadow-lg group-hover:shadow-indigo-500/20" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">
                    <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </div>
                <span class="text-[15px] font-bold hidden sm:inline" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text"><?= e(__('app.name')) ?></span>
            </a>

            <!-- Desktop Links -->
            <div class="hidden lg:flex items-center gap-0.5">
                <?php foreach ($home_links as $i => $link): ?>
                    <?php
                    $active = false;
                    if ($is_home && $i === 0) $active = true;
                    elseif (!$is_home && $link['anchor'] !== '') {
                        $pc = pathinfo($current_script, PATHINFO_FILENAME);
                        if (in_array($pc, ['browse_jobs','view_job','skill_jobs','my_tasks','dashboard']) && $link['anchor'] === 'find-jobs') $active = true;
                    }
                    ?>
                    <a href="<?= e($link['href']) ?>" class="nl <?= $active ? 'on' : '' ?>"><?= e($link['label']) ?><span class="nl-bar"></span></a>
                <?php endforeach; ?>
            </div>

            <!-- Right -->
            <div class="flex items-center gap-0.5">
                <?php if ($is_logged_in): ?>
                    <!-- Chat -->
                    <a href="<?= e(base_url('chat/index.php')) ?>" class="ni relative" title="<?= e(__('nav.messages')) ?>">
                        <svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                        <?php if ($chat_unread > 0): ?><span class="absolute top-[2px] right-[2px] nb" style="background:#10b981;border-color:#fff"><?= min($chat_unread,99) ?></span><?php endif; ?>
                    </a>

                    <!-- Notifications -->
                    <div class="relative notification-container">
                        <button type="button" class="notification-toggle ni relative" aria-label="<?= e(__('notif.title')) ?>">
                            <svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                            <?php if ($unread_count > 0): ?><span id="notifBadge" class="badge" style="position:absolute;top:2px;right:2px;background:#ef4444;border-color:#fff"><?= min($unread_count,99) ?></span><?php endif; ?>
                        </button>
                        <div class="dd notification-dropdown" style="width:360px">
                            <div class="p-3.5 flex items-center justify-between" style="border-bottom:1px solid rgba(0,0,0,.05)">
                                <span class="font-semibold text-[13px]" style="color:#1e293b"><?= __('notif.recent') ?></span>
                                <div class="flex items-center gap-2">
                                    <?php if ($unread_count > 0): ?><button type="button" class="notification-mark-all text-[11px] font-semibold text-indigo-600 hover:text-indigo-700" data-csrf="<?= e(csrf_token()) ?>"><?= __('notif.mark_all_read') ?></button><?php endif; ?>
                                    <a href="<?= e(base_url('notifications.php')) ?>" class="text-[11px] font-medium" style="color:#94a3b8">View all</a>
                                </div>
                            </div>
                            <div class="max-h-[320px] overflow-y-auto notification-list">
                                <?php if (empty($recent_notifications)): ?>
                                    <div class="p-8 text-center" style="color:#94a3b8">
                                        <svg class="w-9 h-9 mx-auto mb-2 opacity-25" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                                        <p class="text-xs font-medium"><?= __('notif.no_notifications') ?></p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($recent_notifications as $n): ?>
                                        <div class="notification-item flex items-start gap-2.5 px-3.5 py-3 transition-colors <?= $n['is_read'] ? '' : 'bg-indigo-50/50 dark:bg-indigo-900/15' ?>" style="border-bottom:1px solid rgba(0,0,0,.03)" data-id="<?= (int) $n['id'] ?>">
                                            <div class="mt-0.5 flex-shrink-0"><?= notification_icon($n['type']) ?></div>
                                            <div class="flex-1 min-w-0">
                                                <a href="<?= e($n['link'] ? base_url($n['link']) : base_url('notifications.php')) ?>" class="block notif-link" data-id="<?= (int) $n['id'] ?>" data-csrf="<?= e(csrf_token()) ?>" data-url="<?= e($n['link'] ? base_url($n['link']) : base_url('notifications.php')) ?>">
                                                    <p class="text-[12.5px] leading-relaxed <?= $n['is_read'] ? '' : 'font-medium' ?>" style="color:<?= $n['is_read'] ? '#94a3b8' : '#1e293b' ?>"><?= e($n['message']) ?></p>
                                                    <p class="text-[10.5px] mt-0.5" style="color:#cbd5e1"><?= e($n['created_at']) ?></p>
                                                </a>
                                            </div>
                                            <button type="button" class="notification-delete-btn flex-shrink-0 p-1 rounded-md opacity-0 hover:opacity-100 transition-all hover:bg-red-50 dark:hover:bg-red-900/20" style="color:#94a3b8" data-id="<?= (int) $n['id'] ?>" data-csrf="<?= e(csrf_token()) ?>" title="<?= __('notif.delete') ?>">
                                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Language -->
                    <div class="relative" id="lang-switcher">
                        <button type="button" class="ni gap-1 !w-auto !px-2" onclick="event.stopPropagation();document.getElementById('lang-dropdown').classList.toggle('hidden')" aria-label="Language">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span class="text-[11px] font-semibold hidden sm:inline"><?= $current_lang === 'my' ? 'မြန်မာ' : 'EN' ?></span>
                        </button>
                        <div id="lang-dropdown" class="dd hidden" style="min-width:130px">
                            <a href="<?= e(lang_switch_url('en')) ?>" class="di <?= $current_lang === 'en' ? 'text-indigo-600 bg-indigo-50/50' : '' ?>"><span class="text-sm">🇬🇧</span> English</a>
                            <a href="<?= e(lang_switch_url('my')) ?>" class="di <?= $current_lang === 'my' ? 'text-indigo-600 bg-indigo-50/50' : '' ?>"><span class="text-sm">🇲🇲</span> မြန်မာ</a>
                        </div>
                    </div>

                    <!-- Theme -->
                    <button id="theme-toggle" type="button" class="ni" aria-label="Toggle theme">
                        <svg class="w-[18px] h-[18px] dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                        <svg class="w-[18px] h-[18px] hidden dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    </button>

                    <!-- Divider -->
                    <div class="w-px h-5 mx-1 hidden sm:block" style="background:rgba(0,0,0,.07)"></div>

                    <!-- Profile -->
                    <div class="relative" id="profile-dropdown-container">
                        <button type="button" id="profile-dropdown-toggle" class="np">
                            <?php if (!empty($user['logo_image'])): ?>
                                <img src="<?= e(base_url('uploads/' . $user['logo_image'])) ?>" alt="" class="na object-contain">
                            <?php elseif ($imgUrl = profile_image_url($user['profile_image'])): ?>
                                <img src="<?= e($imgUrl) ?>" alt="" class="na">
                            <?php else: ?>
                                <div class="na flex items-center justify-center text-xs font-bold" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none"><?= e(_first_char($user['username'])) ?></div>
                            <?php endif; ?>
                            <span class="text-[13px] font-medium hidden sm:inline" style="color:#334155"><?= e($user['username']) ?></span>
                            <svg class="w-3.5 h-3.5 hidden sm:block transition-transform duration-200" id="profile-chevron" style="color:#94a3b8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div id="profile-dropdown-menu" class="dd" style="min-width:200px">
                            <div class="p-3" style="border-bottom:1px solid rgba(0,0,0,.05)">
                                <p class="text-[13px] font-semibold" style="color:#1e293b"><?= e($user['username']) ?></p>
                                <p class="text-[11px] mt-0.5 truncate" style="color:#94a3b8"><?= e($user['email']) ?></p>
                            </div>
                            <?php
                            $profileLink = $role === 'company' ? 'company/profile.php' : 'freelancer/profile.php';
                            $dashboardLink = $role === 'company' ? 'company/dashboard.php' : 'freelancer/dashboard.php';
                            ?>
                            <div class="py-1">
                                <a href="<?= e(base_url($profileLink)) ?>" class="di"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>My Profile</a>
                                <a href="<?= e(base_url($dashboardLink)) ?>" class="di"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>Dashboard</a>
                                <?php foreach ($role_links as $rl): ?>
                                    <a href="<?= e($rl['href']) ?>" class="di">
                                        <?php if ($rl['icon'] === 'plus'): ?><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                                        <?php elseif ($rl['icon'] === 'briefcase'): ?><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                        <?php elseif ($rl['icon'] === 'search'): ?><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                        <?php elseif ($rl['icon'] === 'clipboard'): ?><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                        <?php endif; ?><?= e($rl['label']) ?>
                                    </a>
                                <?php endforeach; ?>
                                <a href="<?= e(base_url('chat/index.php')) ?>" class="di">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>Messages
                                    <?php if ($chat_unread > 0): ?><span class="ml-auto text-[10px] font-bold px-1.5 py-0.5 rounded-full text-white" style="background:#10b981"><?= min($chat_unread,99) ?></span><?php endif; ?>
                                </a>
                            </div>
                            <div style="border-top:1px solid rgba(0,0,0,.05)">
                                <a href="<?= e(base_url('logout.php')) ?>" class="di text-red-600 hover:text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>Logout</a>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <?php if ($is_home): ?>
                        <button type="button" onclick="document.getElementById('loginModal').classList.remove('hidden')" class="nl font-semibold hidden sm:inline-flex">Login</button>
                    <?php else: ?>
                        <a href="<?= e(base_url('login.php')) ?>" class="nl font-semibold hidden sm:inline-flex">Login</a>
                    <?php endif; ?>
                    <a href="<?= e(base_url('register.php')) ?>" class="nc hidden sm:inline-flex">Register</a>
                <?php endif; ?>

                <!-- Mobile -->
                <button id="mobile-toggle" class="lg:hidden ni" aria-label="Menu">
                    <svg class="w-5 h-5 ham" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    <svg class="w-5 h-5 cls hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
    </div>
</nav>

<!-- Mobile -->
<div id="mobile-overlay" class="mo" onclick="closeMobileMenu()"></div>
<div id="mobile-panel" class="mp">
    <div class="flex items-center justify-between p-4" style="border-bottom:1px solid rgba(0,0,0,.06)">
        <a href="<?= e(base_url('index.php')) ?>" class="flex items-center gap-2">
            <div class="w-7 h-7 rounded-lg flex items-center justify-center" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)"><svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg></div>
            <span class="text-sm font-bold" style="color:#1e293b"><?= e(__('app.name')) ?></span>
        </a>
        <button onclick="closeMobileMenu()" class="p-2 rounded-lg transition-colors hover:bg-gray-100 dark:hover:bg-gray-800" style="color:#64748b"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <div class="py-2">
        <?php foreach ($home_links as $i => $link): ?>
            <?php $active = ($is_home && $i === 0); ?>
            <a href="<?= e($link['href']) ?>" class="ml <?= $active ? 'on' : '' ?>" onclick="closeMobileMenu()"><?= e($link['label']) ?></a>
        <?php endforeach; ?>

        <?php if ($is_logged_in): ?>
            <div class="mt-3 pt-2 mx-3" style="border-top:1px solid rgba(0,0,0,.06)"><p class="px-4 py-1.5 text-[10px] font-bold uppercase tracking-widest" style="color:#94a3b8">Dashboard</p></div>
            <?php foreach ($role_links as $link): ?>
                <a href="<?= e($link['href']) ?>" class="ml <?= basename($_SERVER['SCRIPT_NAME'] ?? '', '.php') === $link['page'] ? 'on' : '' ?>">
                    <?php if ($link['icon'] === 'home'): ?><svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                    <?php elseif ($link['icon'] === 'plus'): ?><svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    <?php elseif ($link['icon'] === 'briefcase'): ?><svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <?php elseif ($link['icon'] === 'search'): ?><svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <?php elseif ($link['icon'] === 'clipboard'): ?><svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    <?php endif; ?><?= e($link['label']) ?>
                </a>
            <?php endforeach; ?>

            <div class="mt-3 pt-2 mx-3" style="border-top:1px solid rgba(0,0,0,.06)"><p class="px-4 py-1.5 text-[10px] font-bold uppercase tracking-widest" style="color:#94a3b8">Account</p></div>
            <a href="<?= e(base_url('chat/index.php')) ?>" class="ml"><svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>Messages<?php if ($chat_unread > 0): ?><span class="ml-auto text-[10px] font-bold px-1.5 py-0.5 rounded-full text-white" style="background:#10b981"><?= min($chat_unread,99) ?></span><?php endif; ?></a>
            <a href="<?= e(base_url('notifications.php')) ?>" class="ml"><svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>Notifications<?php if ($unread_count > 0): ?><span id="notifBadgeMobile" class="ml-auto badge" style="background:#ef4444"><?= min($unread_count,99) ?></span><?php endif; ?></a>
            <?php $pm = $role === 'company' ? 'company/profile.php' : 'freelancer/profile.php'; ?>
            <a href="<?= e(base_url($pm)) ?>" class="ml"><svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>My Profile</a>
            <a href="<?= e(base_url('logout.php')) ?>" class="ml text-red-600 dark:text-red-400"><svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>Logout</a>
        <?php else: ?>
            <div class="mt-3 pt-2 mx-3 space-y-2" style="border-top:1px solid rgba(0,0,0,.06)">
                <?php if ($is_home): ?>
                    <button type="button" onclick="document.getElementById('loginModal').classList.remove('hidden');closeMobileMenu()" class="block w-full text-left px-4 py-2.5 text-sm font-semibold rounded-xl transition-colors" style="color:#4f46e5;background:rgba(99,102,241,.06)">Login</button>
                <?php else: ?>
                    <a href="<?= e(base_url('login.php')) ?>" class="ml font-semibold" style="color:#4f46e5">Login</a>
                <?php endif; ?>
                <a href="<?= e(base_url('register.php')) ?>" class="block px-4 py-2.5 text-sm font-semibold text-white text-center rounded-xl" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">Register</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="<?= e(base_url('assets/js/notification-sse.js')) ?>"></script>
<script>
(function(){
    var nav=document.getElementById('main-nav');
    if(nav)window.addEventListener('scroll',function(){nav.classList.toggle('sh',window.scrollY>10)},{passive:true});

    window.closeMobileMenu=function(){
        document.getElementById('mobile-overlay')?.classList.remove('show');
        document.getElementById('mobile-panel')?.classList.remove('show');
        document.querySelector('.ham')?.classList.remove('hidden');
        document.querySelector('.cls')?.classList.add('hidden');
        document.body.style.overflow='';
    };

    var mt=document.getElementById('mobile-toggle');
    if(mt)mt.addEventListener('click',function(){
        var p=document.getElementById('mobile-panel'),open=p?.classList.contains('show');
        if(open){closeMobileMenu()}else{
            document.getElementById('mobile-overlay')?.classList.add('show');
            p?.classList.add('show');
            document.querySelector('.ham')?.classList.add('hidden');
            document.querySelector('.cls')?.classList.remove('hidden');
            document.body.style.overflow='hidden';
        }
    });

    document.querySelectorAll('.notification-container').forEach(function(c){
        var t=c.querySelector('.notification-toggle'),d=c.querySelector('.notification-dropdown');
        if(t&&d)t.addEventListener('click',function(e){e.stopPropagation();var o=d.classList.contains('show');closeAll();if(!o)d.classList.add('show')});
    });

    var pt=document.getElementById('profile-dropdown-toggle'),pm=document.getElementById('profile-dropdown-menu'),pc=document.getElementById('profile-chevron');
    if(pt&&pm)pt.addEventListener('click',function(e){e.stopPropagation();var o=pm.classList.contains('show');closeAll();if(!o){pm.classList.add('show');if(pc)pc.style.transform='rotate(180deg)'}});

    function closeAll(){
        document.querySelectorAll('.dd').forEach(function(d){d.classList.remove('show')});
        document.getElementById('lang-dropdown')?.classList.add('hidden');
        if(pc)pc.style.transform='';
    }
    document.addEventListener('click',closeAll);

    document.querySelectorAll('.notification-mark-all').forEach(function(b){b.addEventListener('click',function(e){e.preventDefault();fetch('<?= e(base_url("api/notifications.php")) ?>',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'mark_all_read',csrf_token:this.getAttribute('data-csrf')})}).then(function(r){return r.json()}).then(function(d){if(d.success){document.querySelectorAll('.notification-item').forEach(function(i){i.classList.remove('bg-indigo-50/50','dark:bg-indigo-900/15');var m=i.querySelector('p');if(m){m.classList.remove('font-medium');m.style.color=''}});['notifBadge','notifBadgeMobile'].forEach(function(id){var bd=document.getElementById(id);if(bd)bd.style.display='none'});var mb=document.querySelector('.notification-mark-all');if(mb)mb.style.display='none'}}).catch(function(){})})});

    document.querySelectorAll('.notification-delete-btn').forEach(function(b){b.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();var id=this.getAttribute('data-id'),cs=this.getAttribute('data-csrf'),it=this.closest('.notification-item');fetch('<?= e(base_url("api/notifications.php")) ?>',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'delete',notification_id:parseInt(id),csrf_token:cs})}).then(function(r){return r.json()}).then(function(d){if(d.success&&it){it.style.transition='opacity .2s,transform .2s';it.style.opacity='0';it.style.transform='translateX(16px)';setTimeout(function(){it.remove()},200);var t=d.count>99?'99+':d.count;var s=d.count>0?'flex':'none';['notifBadge','notifBadgeMobile'].forEach(function(nid){var bd=document.getElementById(nid);if(bd){bd.textContent=t;bd.style.display=s}})}}).catch(function(){})})});

    document.querySelectorAll('.notif-link').forEach(function(l){l.addEventListener('click',function(e){var id=parseInt(this.getAttribute('data-id')),cs=this.getAttribute('data-csrf'),ur=this.getAttribute('data-url'),it=this.closest('.notification-item');if(id>0&&it&&it.classList.contains('bg-indigo-50/50')){e.preventDefault();fetch('<?= e(base_url("api/notifications.php")) ?>',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'mark_read',notification_id:id,csrf_token:cs})}).then(function(r){return r.json()}).then(function(d){if(d.success){it.classList.remove('bg-indigo-50/50','dark:bg-indigo-900/15');var m=it.querySelector('p');if(m)m.classList.remove('font-medium');var t=d.count>99?'99+':d.count;var s=d.count>0?'flex':'none';['notifBadge','notifBadgeMobile'].forEach(function(nid){var bd=document.getElementById(nid);if(bd){bd.textContent=t;bd.style.display=s}})}}window.location.href=ur}).catch(function(){window.location.href=ur})}})});

    if(typeof NotificationSSE!=='undefined')NotificationSSE.init({user_id:<?= (int)($user['user_id'] ?? 0) ?>});

    setInterval(function(){fetch('<?= e(base_url("api/notifications.php")) ?>?action=count',{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json()}).then(function(d){var t=d.count>99?'99+':d.count;var s=d.count>0?'flex':'none';['notifBadge','notifBadgeMobile'].forEach(function(id){var b=document.getElementById(id);if(b){b.textContent=t;b.style.display=s}})}).catch(function(){})},15000);
    setInterval(function(){fetch('<?= e(base_url("api/chat.php")) ?>?action=get_unread_count',{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json()}).then(function(d){document.querySelectorAll('.badge[style*="10b981"]').forEach(function(b){b.textContent=d.count>99?'99+':d.count;b.style.display=d.count>0?'flex':'none'})}).catch(function(){})},15000);
})();
</script>
