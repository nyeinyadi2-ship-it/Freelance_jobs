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
$is_logged_in = $role && in_array($role, ['company', 'freelancer'], true);

$current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$is_home = ($current_script === 'index.php');
$base = base_url('');

$home_links = [];
if ($role === 'company') {
    $home_links = [
        ['label' => 'Home', 'href' => base_url('index.php'), 'anchor' => ''],
        ['label' => 'Find Freelancers', 'href' => base_url('company/find_freelancers.php'), 'anchor' => 'find_freelancers'],
        ['label' => 'My Jobs', 'href' => base_url('company/manage_jobs.php'), 'anchor' => 'manage_jobs'],
        ['label' => 'About', 'href' => base_url('company/about.php'), 'anchor' => 'about'],
    ];
} elseif ($role === 'freelancer') {
    $home_links = [
        ['label' => 'Home', 'href' => base_url('index.php'), 'anchor' => ''],
        ['label' => 'Find Jobs', 'href' => base_url('freelancer/browse_jobs.php'), 'anchor' => 'find-jobs'],
        ['label' => 'Freelancers', 'href' => base_url('company/find_freelancers.php'), 'anchor' => 'find_freelancers'],
        ['label' => 'About', 'href' => base_url('index.php'), 'anchor' => ''],
    ];
} else {
    $home_links = [
        ['label' => 'Home', 'href' => $is_home ? '#' : base_url('index.php'), 'anchor' => ''],
        ['label' => 'Find Jobs', 'href' => $is_home ? '#find-jobs' : base_url('index.php#find-jobs'), 'anchor' => 'find-jobs'],
        ['label' => 'Freelancers', 'href' => $is_home ? '#freelancers' : base_url('index.php#freelancers'), 'anchor' => 'freelancers'],
        ['label' => 'About', 'href' => $is_home ? '#why-us' : base_url('index.php#why-us'), 'anchor' => 'why-us'],
    ];
}

// Fetch all skills for the Skills dropdown
$nav_skills = [];
if (!isset($_SESSION['nav_skills'])) {
    $nav_skills_r = $conn->query("SELECT id, skill_name FROM skills ORDER BY skill_name");
    if ($nav_skills_r) {
        while ($row = $nav_skills_r->fetch_assoc()) {
            $nav_skills[] = $row;
        }
    }
    $_SESSION['nav_skills'] = $nav_skills;
} else {
    $nav_skills = $_SESSION['nav_skills'];
}

$role_links = [];
if ($is_logged_in) {
    if ($role === 'company') {
        $role_links = [
            ['label' => 'Dashboard', 'href' => base_url('company/dashboard.php'), 'icon' => 'home', 'page' => 'dashboard'],
            ['label' => 'Post Job', 'href' => base_url('company/post_job.php'), 'icon' => 'plus', 'page' => 'post_job'],
            ['label' => 'My Jobs', 'href' => base_url('company/manage_jobs.php'), 'icon' => 'briefcase', 'page' => 'manage_jobs'],
            ['label' => 'Wallet', 'href' => base_url('company/wallet.php'), 'icon' => 'credit-card', 'page' => 'wallet'],
        ];
    } elseif ($role === 'freelancer') {
        $role_links = [
            ['label' => 'Dashboard', 'href' => base_url('freelancer/dashboard.php'), 'icon' => 'home', 'page' => 'dashboard'],
            ['label' => 'Browse Jobs', 'href' => base_url('freelancer/browse_jobs.php'), 'icon' => 'search', 'page' => 'browse_jobs'],
            ['label' => 'My Tasks', 'href' => base_url('freelancer/my_tasks.php'), 'icon' => 'clipboard', 'page' => 'my_tasks'],
            ['label' => 'Trial Task', 'href' => base_url('freelancer/test_assignments.php'), 'icon' => 'document-text', 'page' => 'test_assignments'],
            ['label' => 'Transactions', 'href' => base_url('freelancer/transactions.php'), 'icon' => 'document-text', 'page' => 'transactions'],
            ['label' => 'Payment Info', 'href' => base_url('freelancer/payment_settings.php'), 'icon' => 'credit-card', 'page' => 'payment_settings'],
        ];
    }
}
?>
<style>
    /* ===== GLASS NAVBAR ===== */
    .nb {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        width: 100%;
        z-index: 50;
        background: rgba(255, 255, 255, .82);
        backdrop-filter: blur(24px) saturate(180%);
        -webkit-backdrop-filter: blur(24px) saturate(180%);
        border-bottom: 1px solid rgba(0, 0, 0, .05);
        transition: box-shadow .3s
    }

    html.dark .nb {
        background: rgba(15, 23, 42, .85);
        border-bottom-color: rgba(255, 255, 255, .06)
    }

    .nb.sh {
        box-shadow: 0 1px 3px rgba(0, 0, 0, .06), 0 8px 32px rgba(0, 0, 0, .04)
    }

    html.dark .nb.sh {
        box-shadow: 0 1px 3px rgba(0, 0, 0, .2), 0 8px 32px rgba(0, 0, 0, .15)
    }

    /* Nav links */
    .nl {
        position: relative;
        padding: .5rem .875rem;
        font-size: .8125rem;
        font-weight: 500;
        color: #64748b;
        border-radius: 10px;
        text-decoration: none;
        white-space: nowrap;
        transition: all .2s cubic-bezier(.4, 0, .2, 1)
    }

    html.dark .nl {
        color: #94a3b8
    }

    .nl:hover {
        color: #4f46e5;
        background: rgba(99, 102, 241, .06)
    }

    html.dark .nl:hover {
        color: #818cf8;
        background: rgba(99, 102, 241, .1)
    }

    .nl.on {
        color: #4f46e5;
        background: rgba(99, 102, 241, .08);
        font-weight: 600
    }

    html.dark .nl.on {
        color: #818cf8;
        background: rgba(99, 102, 241, .12)
    }

    .nl-bar {
        position: absolute;
        bottom: 2px;
        left: 50%;
        transform: translateX(-50%);
        width: 0;
        height: 2px;
        background: linear-gradient(90deg, #6366f1, #8b5cf6);
        border-radius: 2px;
        transition: width .25s cubic-bezier(.4, 0, .2, 1)
    }

    .nl:hover .nl-bar,
    .nl.on .nl-bar {
        width: 55%
    }

    /* Icon buttons */
    .ni {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 2.25rem;
        height: 2.25rem;
        border-radius: 10px;
        color: #64748b;
        transition: all .2s cubic-bezier(.4, 0, .2, 1);
        cursor: pointer;
        background: none;
        border: none
    }

    html.dark .ni {
        color: #94a3b8
    }

    .ni:hover {
        color: #4f46e5;
        background: rgba(99, 102, 241, .07)
    }

    html.dark .ni:hover {
        color: #818cf8;
        background: rgba(99, 102, 241, .12)
    }

    .ni:active {
        transform: scale(.95)
    }

    .badge {
        min-width: 16px;
        height: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 4px;
        font-size: 9px;
        font-weight: 700;
        color: #fff;
        border-radius: 9999px;
        border: 2px solid #fff;
        line-height: 1
    }

    html.dark .badge {
        border-color: #0f172a
    }

    /* Profile */
    .np {
        display: flex;
        align-items: center;
        gap: .5rem;
        padding: .2rem .5rem .2rem .2rem;
        border-radius: 12px;
        transition: all .2s;
        cursor: pointer
    }

    .np:hover {
        background: rgba(99, 102, 241, .05)
    }

    html.dark .np:hover {
        background: rgba(99, 102, 241, .1)
    }

    .na {
        width: 2.125rem;
        height: 2.125rem;
        border-radius: 9px;
        object-fit: cover;
        border: 2px solid transparent;
        background-image: linear-gradient(#fff, #fff), linear-gradient(135deg, #6366f1, #8b5cf6);
        background-origin: border-box;
        background-clip: padding-box, border-box;
        transition: all .2s
    }

    html.dark .na {
        background-image: linear-gradient(#1e293b, #1e293b), linear-gradient(135deg, #6366f1, #8b5cf6)
    }

    .np:hover .na {
        transform: scale(1.06);
        box-shadow: 0 0 0 3px rgba(99, 102, 241, .12)
    }

    /* Dropdowns */
    .dd {
        position: absolute;
        right: 0;
        margin-top: .5rem;
        min-width: 220px;
        background: #fff;
        border: 1px solid rgba(0, 0, 0, .06);
        border-radius: 14px;
        box-shadow: 0 16px 48px rgba(0, 0, 0, .1), 0 0 0 1px rgba(0, 0, 0, .02);
        opacity: 0;
        visibility: hidden;
        transform: translateY(-6px) scale(.97);
        transition: all .18s cubic-bezier(.16, 1, .3, 1);
        z-index: 100;
        overflow: hidden
    }

    html.dark .dd {
        background: #1e293b;
        border-color: rgba(255, 255, 255, .07);
        box-shadow: 0 16px 48px rgba(0, 0, 0, .35)
    }

    .dd.show {
        opacity: 1;
        visibility: visible;
        transform: translateY(0) scale(1)
    }

    .di {
        display: flex;
        align-items: center;
        gap: .625rem;
        padding: .5625rem .875rem;
        font-size: .8125rem;
        font-weight: 500;
        color: #475569;
        text-decoration: none;
        transition: all .12s
    }

    html.dark .di {
        color: #cbd5e1
    }

    .di:hover {
        background: rgba(99, 102, 241, .06);
        color: #4f46e5
    }

    html.dark .di:hover {
        background: rgba(99, 102, 241, .1);
        color: #818cf8
    }

    /* CTA */
    .nc {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: .5rem 1.125rem;
        font-size: .8125rem;
        font-weight: 600;
        color: #fff;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        border-radius: 10px;
        text-decoration: none;
        transition: all .2s;
        box-shadow: 0 2px 8px rgba(99, 102, 241, .2)
    }

    .nc:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 16px rgba(99, 102, 241, .3)
    }

    .nc:active {
        transform: translateY(0)
    }
</style>

<nav class="nb" id="main-nav">
    <div class="mx-auto px-4 sm:px-6 lg:px-8 max-w-7xl">
        <div class="flex items-center justify-between h-[60px]">

            <!-- Logo -->
            <a href="<?= e($role === 'company' ? base_url('index.php') : base_url('index.php')) ?>" class="flex items-center gap-2.5 flex-shrink-0 group">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center transition-all duration-300 group-hover:scale-105 group-hover:shadow-lg group-hover:shadow-indigo-500/20" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">
                    <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                </div>
                <span class="text-[15px] font-bold hidden sm:inline" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text"><?= e('FreelanceHub') ?></span>
            </a>

            <!-- Desktop Links -->
            <div class="flex items-center gap-0.5 flex-wrap">
                <?php foreach ($home_links as $i => $link): ?>
                    <?php
                    $active = false;
                    $pc = pathinfo($current_script, PATHINFO_FILENAME);
                    if ($role === 'company') {
                        if ($i === 0 && in_array($pc, ['index'])) $active = true;
                        elseif ($link['anchor'] === 'find_freelancers' && $pc === 'find_freelancers') $active = true;
                        elseif ($link['anchor'] === 'manage_jobs' && in_array($pc, ['manage_jobs', 'post_job', 'edit_job', 'view_job', 'view_applications'])) $active = true;
                        elseif ($link['anchor'] === 'about' && $pc === 'about') $active = true;
                    } else {
                        if ($is_home && $i === 0) $active = true;
                        elseif (!$is_home && $link['anchor'] !== '') {
                            if (in_array($pc, ['browse_jobs', 'view_job', 'skill_jobs', 'my_tasks', 'dashboard']) && $link['anchor'] === 'find-jobs') $active = true;
                        }
                    }
                    ?>
                    <a href="<?= e($link['href']) ?>" class="nl <?= $active ? 'on' : '' ?>"><?= e($link['label']) ?><span class="nl-bar"></span></a>
                <?php endforeach; ?>

                <?php if ($role === 'freelancer'): ?>
                <!-- Skills Dropdown -->
                <div class="relative" id="skills-dropdown-wrap">
                    <button type="button" id="skills-dropdown-toggle" class="nl <?= (!$is_home && $current_script === 'skill_jobs.php') ? 'on' : '' ?>" style="display:inline-flex;align-items:center;gap:4px;">
                        Skills
                        <svg class="w-3 h-3 transition-transform duration-200 hidden" id="skills-chevron" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                        <span class="nl-bar"></span>
                    </button>
                    <div id="skills-dropdown-menu" class="dd" style="min-width:220px;max-height:360px;overflow-y:auto;">
                        <div class="p-3" style="border-bottom:1px solid rgba(0,0,0,.05)">
                            <span class="font-semibold text-[13px]" style="color:#1e293b">Browse by Skill</span>
                        </div>
                        <?php foreach ($nav_skills as $sk): ?>
                            <a href="<?= e(base_url('freelancer/skill_jobs.php?skill=' . urlencode($sk['skill_name']))) ?>" class="di">
                                <svg class="w-3.5 h-3.5 text-indigo-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                                <?= e($sk['skill_name']) ?>
                            </a>
                        <?php endforeach; ?>
                        <?php if (empty($nav_skills)): ?>
                            <div class="p-4 text-center text-xs" style="color:#94a3b8">No skills available</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right -->
            <div class="flex items-center gap-0.5">
                <?php if ($is_logged_in): ?>
                    <!-- Chat -->
                    <a href="<?= e(base_url('chat/index.php')) ?>" class="ni relative" title="<?= e('Messages') ?>">
                        <svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                        </svg>
                        <span id="chatBadge" class="badge" style="position:absolute;top:2px;right:2px;background:#10b981;border-color:#fff;display:<?= $chat_unread > 0 ? 'flex' : 'none' ?>"><?= $chat_unread > 99 ? '99+' : $chat_unread ?></span>
                    </a>

                    <!-- Notifications -->
                    <div class="relative notification-container">
                        <button type="button" class="notification-toggle ni relative" aria-label="<?= e('Notifications') ?>">
                            <svg class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                            </svg>
                            <?php if ($unread_count > 0): ?><span id="notifBadge" class="badge" style="position:absolute;top:2px;right:2px;background:#ef4444;border-color:#fff"><?= min($unread_count, 99) ?></span><?php endif; ?>
                        </button>
                        <div class="dd notification-dropdown" style="width:360px">
                            <div class="p-3.5 flex items-center justify-between" style="border-bottom:1px solid rgba(0,0,0,.05)">
                                <span class="font-semibold text-[13px]" style="color:#1e293b"><?= 'Recent Notifications' ?></span>
                                <div class="flex items-center gap-2">
                                    <?php if ($unread_count > 0): ?><button type="button" class="notification-mark-all text-[11px] font-semibold text-indigo-600 hover:text-indigo-700" data-csrf="<?= e(csrf_token()) ?>"><?= 'Mark all as read' ?></button><?php endif; ?>
                                    <a href="<?= e(base_url('notifications.php')) ?>" class="text-[11px] font-medium" style="color:#94a3b8">View all</a>
                                </div>
                            </div>
                            <div class="max-h-[320px] overflow-y-auto notification-list">
                                <?php if (empty($recent_notifications)): ?>
                                    <div class="p-8 text-center" style="color:#94a3b8">
                                        <svg class="w-9 h-9 mx-auto mb-2 opacity-25" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                                        </svg>
                                        <p class="text-xs font-medium"><?= 'No notifications yet.' ?></p>
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
                                            <button type="button" class="notification-delete-btn flex-shrink-0 p-1 rounded-md opacity-0 hover:opacity-100 transition-all hover:bg-red-50 dark:hover:bg-red-900/20" style="color:#94a3b8" data-id="<?= (int) $n['id'] ?>" data-csrf="<?= e(csrf_token()) ?>" title="<?= 'Delete' ?>">
                                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Theme -->
                    <button id="theme-toggle" type="button" class="ni" aria-label="Toggle theme">
                        <svg class="w-[18px] h-[18px] dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                        </svg>
                        <svg class="w-[18px] h-[18px] hidden dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                    </button>

                    <!-- Divider -->
                    <div class="w-px h-5 mx-1 hidden sm:block" style="background:rgba(0,0,0,.07)"></div>

                    <!-- Profile -->
                    <div class="relative" id="profile-dropdown-container">
                        <button type="button" id="profile-dropdown-toggle" class="np">
                            <?php if (!empty($user['logo_image'])): ?>
                                <img src="<?= e(base_url('uploads/images/' . $user['logo_image'])) ?>" alt="" class="na object-contain">
                            <?php elseif ($imgUrl = profile_image_url($user['profile_image'])): ?>
                                <img src="<?= e($imgUrl) ?>" alt="" class="na">
                            <?php else: ?>
                                <div class="na flex items-center justify-center text-xs font-bold" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none"><?= e(_first_char($user['username'])) ?></div>
                            <?php endif; ?>
                            <span class="text-[13px] font-medium hidden sm:inline" style="color:#334155"><?= e($user['username']) ?></span>
                            <svg class="w-3.5 h-3.5 hidden sm:block transition-transform duration-200" id="profile-chevron" style="color:#94a3b8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
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
                                <a href="<?= e(base_url($profileLink)) ?>" class="di"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>My Profile</a>
                                <a href="<?= e(base_url($dashboardLink)) ?>" class="di"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                                    </svg>Dashboard</a>
                                <?php foreach ($role_links as $rl): ?>
                                    <a href="<?= e($rl['href']) ?>" class="di">
                                        <?php if ($rl['icon'] === 'plus'): ?><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                                            </svg>
                                        <?php elseif ($rl['icon'] === 'briefcase'): ?><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                            </svg>
                                        <?php elseif ($rl['icon'] === 'search'): ?><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                            </svg>
                                        <?php elseif ($rl['icon'] === 'clipboard'): ?><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                            </svg>
                                        <?php elseif ($rl['icon'] === 'credit-card'): ?><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                                            </svg>
                                        <?php elseif ($rl['icon'] === 'document-text'): ?><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                            <?php endif; ?><?= e($rl['label']) ?>
                                    </a>
                                <?php endforeach; ?>
                                <a href="<?= e(base_url('chat/index.php')) ?>" class="di">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                    </svg>Messages
                                    <span id="chatBadgeDropdown" class="ml-auto text-[10px] font-bold px-1.5 py-0.5 rounded-full text-white" style="background:#10b981;display:<?= $chat_unread > 0 ? 'inline-flex' : 'none' ?>"><?= $chat_unread > 99 ? '99+' : $chat_unread ?></span>
                                </a>
                            </div>
                            <div style="border-top:1px solid rgba(0,0,0,.05)">
                                <a href="<?= e(base_url('auth/logout.php')) ?>" class="di text-red-600 hover:text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                                    </svg>Logout</a>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <?php if ($is_home): ?>
                        <button type="button" onclick="document.getElementById('loginModal').classList.remove('hidden')" class="nl font-semibold">Login</button>
                    <?php else: ?>
                        <a href="<?= e(base_url('auth/login.php')) ?>" class="nl font-semibold">Login</a>
                    <?php endif; ?>
                    <a href="<?= e(base_url('auth/register.php')) ?>" class="nc">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<script src="<?= e(base_url('assets/js/notification-sse.js')) ?>" defer></script>
<script>
    (function() {
        /* ===== Scroll shadow ===== */
        var nav = document.getElementById('main-nav');
        if (nav) window.addEventListener('scroll', function() {
            nav.classList.toggle('sh', window.scrollY > 10)
        }, {
            passive: true
        });

        /* ===== Close all dropdowns ===== */
        function closeAllDropdowns() {
            document.querySelectorAll('.dd.show').forEach(function(d) {
                d.classList.remove('show')
            });
            var ch = document.getElementById('profile-chevron');
            if (ch) ch.style.transform = '';
            var sch = document.getElementById('skills-chevron');
            if (sch) sch.style.transform = '';
        }

        /* ===== Profile dropdown ===== */
        var profileBtn = document.getElementById('profile-dropdown-toggle');
        var profileMenu = document.getElementById('profile-dropdown-menu');
        var profileChevron = document.getElementById('profile-chevron');

        if (profileBtn && profileMenu) {
            profileBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var isOpen = profileMenu.classList.contains('show');
                closeAllDropdowns();
                if (!isOpen) {
                    profileMenu.classList.add('show');
                    if (profileChevron) profileChevron.style.transform = 'rotate(180deg)';
                }
            });
        }

        /* ===== Skills dropdown ===== */
        var skillsBtn = document.getElementById('skills-dropdown-toggle');
        var skillsMenu = document.getElementById('skills-dropdown-menu');
        var skillsChevron = document.getElementById('skills-chevron');

        if (skillsBtn && skillsMenu) {
            skillsBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var isOpen = skillsMenu.classList.contains('show');
                closeAllDropdowns();
                if (!isOpen) {
                    skillsMenu.classList.add('show');
                    if (skillsChevron) skillsChevron.style.transform = 'rotate(180deg)';
                }
            });
        }

        /* ===== Notification dropdown ===== */
        document.querySelectorAll('.notification-container').forEach(function(container) {
            var toggleBtn = container.querySelector('.notification-toggle');
            var dropdown = container.querySelector('.notification-dropdown');
            if (toggleBtn && dropdown) {
                toggleBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var isOpen = dropdown.classList.contains('show');
                    closeAllDropdowns();
                    if (!isOpen) dropdown.classList.add('show');
                });
            }
        });

        /* ===== Click outside to close ===== */
        document.addEventListener('click', function(e) {
            var anyOpen = document.querySelector('.dd.show');
            if (anyOpen && !anyOpen.contains(e.target)) {
                var inToggle = e.target.closest('#profile-dropdown-toggle') || e.target.closest('.notification-toggle') || e.target.closest('#skills-dropdown-toggle');
                if (!inToggle) closeAllDropdowns();
            }
        });

        /* ===== Theme toggle ===== */
        var themeToggle = document.getElementById('theme-toggle');
        if (themeToggle) {
            themeToggle.addEventListener('click', function() {
                document.documentElement.classList.toggle('dark');
                localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
            });
        }

        /* ===== Notification: mark all read ===== */
        document.querySelectorAll('.notification-mark-all').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var csrf = this.getAttribute('data-csrf');
                fetch('<?= e(base_url("api/notifications.php")) ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'mark_all_read',
                        csrf_token: csrf
                    })
                }).then(function(r) {
                    return r.json()
                }).then(function(d) {
                    if (!d.success) return;
                    document.querySelectorAll('.notification-item').forEach(function(item) {
                        item.classList.remove('bg-indigo-50/50', 'dark:bg-indigo-900/15');
                        var m = item.querySelector('p');
                        if (m) {
                            m.classList.remove('font-medium');
                            m.style.color = '';
                        }
                    });
                    ['notifBadge', 'notifBadgeMobile'].forEach(function(id) {
                        var b = document.getElementById(id);
                        if (b) b.style.display = 'none';
                    });
                    btn.style.display = 'none';
                }).catch(function() {});
            });
        });

        /* ===== Notification: delete ===== */
        document.querySelectorAll('.notification-delete-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var id = parseInt(this.getAttribute('data-id'));
                var csrf = this.getAttribute('data-csrf');
                var item = this.closest('.notification-item');
                fetch('<?= e(base_url("api/notifications.php")) ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'delete',
                        notification_id: id,
                        csrf_token: csrf
                    })
                }).then(function(r) {
                    return r.json()
                }).then(function(d) {
                    if (!d.success || !item) return;
                    item.style.transition = 'opacity .2s,transform .2s';
                    item.style.opacity = '0';
                    item.style.transform = 'translateX(16px)';
                    setTimeout(function() {
                        item.remove()
                    }, 200);
                    var t = d.count > 99 ? '99+' : d.count;
                    var s = d.count > 0 ? 'flex' : 'none';
                    ['notifBadge', 'notifBadgeMobile'].forEach(function(nid) {
                        var b = document.getElementById(nid);
                        if (b) {
                            b.textContent = t;
                            b.style.display = s;
                        }
                    });
                }).catch(function() {});
            });
        });

        /* ===== Notification: click to mark read & navigate ===== */
        document.querySelectorAll('.notif-link').forEach(function(link) {
            link.addEventListener('click', function(e) {
                var id = parseInt(this.getAttribute('data-id'));
                var csrf = this.getAttribute('data-csrf');
                var url = this.getAttribute('data-url');
                var item = this.closest('.notification-item');
                if (id > 0 && item && item.classList.contains('bg-indigo-50/50')) {
                    e.preventDefault();
                    fetch('<?= e(base_url("api/notifications.php")) ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            action: 'mark_read',
                            notification_id: id,
                            csrf_token: csrf
                        })
                    }).then(function(r) {
                        return r.json()
                    }).then(function(d) {
                        if (d.success) {
                            item.classList.remove('bg-indigo-50/50', 'dark:bg-indigo-900/15');
                            var m = item.querySelector('p');
                            if (m) m.classList.remove('font-medium');
                            var t = d.count > 99 ? '99+' : d.count;
                            var s = d.count > 0 ? 'flex' : 'none';
                            ['notifBadge', 'notifBadgeMobile'].forEach(function(nid) {
                                var b = document.getElementById(nid);
                                if (b) {
                                    b.textContent = t;
                                    b.style.display = s;
                                }
                            });
                        }
                    }).catch(function() {});
                    window.location.href = url;
                }
            });
        });

        /* ===== SSE notifications (badge updated by notification-sse.js) ===== */
        if (typeof NotificationSSE !== 'undefined') NotificationSSE.init({
            user_id: <?= (int)($user['user_id'] ?? 0) ?>
        });

        /* ===== Polling for chat unread count ===== */
        setInterval(function() {
            fetch('<?= e(base_url("api/chat.php")) ?>?action=get_unread_count', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.json()
                }).then(function(d) {
                    var t = d.count > 99 ? '99+' : String(d.count);
                    var s = d.count > 0 ? 'flex' : 'none';
                    var s2 = d.count > 0 ? 'inline-flex' : 'none';
                    var cb = document.getElementById('chatBadge');
                    if (cb) { cb.textContent = t; cb.style.display = s; }
                    var cbd = document.getElementById('chatBadgeDropdown');
                    if (cbd) { cbd.textContent = t; cbd.style.display = s2; }
                    var cbm = document.getElementById('chatBadgeMobile');
                    if (cbm) { cbm.textContent = t; cbm.style.display = s2; }
                }).catch(function() {});
        }, 15000);

        /* ===== Fixed Navbar Padding ===== */
        function setNbPadding() {
            var nav = document.querySelector('.nb');
            if (nav && document.body) document.body.style.paddingTop = nav.offsetHeight + 'px';
        }
        setNbPadding();
        document.addEventListener('DOMContentLoaded', setNbPadding);
        window.addEventListener('load', setNbPadding);
        window.addEventListener('resize', setNbPadding);
    })();
</script>