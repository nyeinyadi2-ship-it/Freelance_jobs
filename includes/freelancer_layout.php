<?php
/**
 * Shared layout for all freelancer pages.
 * Set $page_title before including. Content goes between this file and freelancer_footer.php.
 */
require_once __DIR__ . '/freelancer_init.php';
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>" data-theme>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?> - HireWork</title>
    <script>(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme:dark)').matches))document.documentElement.classList.add('dark');})();</script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{poppins:['Poppins','sans-serif']}}}};</script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/custom.css')) ?>">
    <style>
        *,*::before,*::after{font-family:'Poppins',system-ui,sans-serif;}
        ::selection{background:rgba(99,102,241,0.2);}
        :root{--gp:linear-gradient(135deg,#4f46e5,#7c3aed);--gs:linear-gradient(135deg,#059669,#10b981);--gw:linear-gradient(135deg,#d97706,#f59e0b);--gi:linear-gradient(135deg,#0284c7,#0ea5e9);--gr:linear-gradient(135deg,#e11d48,#f43f5e);}
        .glass{background:rgba(255,255,255,0.72);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.5);box-shadow:0 8px 32px rgba(99,102,241,0.06);}
        html.dark .glass{background:rgba(30,41,59,0.72);border-color:rgba(255,255,255,0.08);box-shadow:0 8px 32px rgba(0,0,0,0.3);}
        .stat-card{position:relative;overflow:hidden;border-radius:20px;transition:transform .35s cubic-bezier(.4,0,.2,1),box-shadow .35s ease;}
        .stat-card:hover{transform:translateY(-6px);box-shadow:0 20px 50px rgba(0,0,0,0.15);}
        .hover-lift{transition:transform .3s cubic-bezier(.4,0,.2,1),box-shadow .3s ease;}
        .hover-lift:hover{transform:translateY(-4px);box-shadow:0 16px 40px rgba(79,70,229,0.12);}
        .badge-skill{transition:all .2s ease;}
        .badge-skill:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(79,70,229,0.25);}
        .dash-tab{cursor:pointer;transition:all .25s ease;position:relative;white-space:nowrap;font-weight:500;}
        .dash-tab::after{content:'';position:absolute;bottom:0;left:50%;transform:translateX(-50%);width:0;height:3px;background:var(--gp);transition:width .3s cubic-bezier(.16,1,.3,1);border-radius:3px 3px 0 0;}
        .dash-tab:hover::after,.dash-tab.active::after{width:80%;}
        .dash-tab.active{color:#4f46e5!important;}
        .dash-section{display:none;animation:fadeSlideIn .45s cubic-bezier(.16,1,.3,1);}
        .dash-section.active{display:block;}
        @keyframes fadeSlideIn{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
        .profile-banner{position:relative;overflow:hidden;background:linear-gradient(135deg,#312e81 0%,#4f46e5 35%,#7c3aed 65%,#a855f7 100%);}
        .profile-banner::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 20% 50%,rgba(255,255,255,0.12) 0%,transparent 60%);pointer-events:none;}
        .profile-banner::after{content:'';position:absolute;top:-80px;right:-40px;width:300px;height:300px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,0.08) 0%,transparent 70%);pointer-events:none;}
        .tab-scroll{-webkit-mask-image:linear-gradient(to right,transparent 0%,black 12px,black calc(100% - 12px),transparent 100%);mask-image:linear-gradient(to right,transparent 0%,black 12px,black calc(100% - 12px),transparent 100%);}
        .scrollbar-thin::-webkit-scrollbar{height:4px;width:4px;}
        .scrollbar-thin::-webkit-scrollbar-track{background:transparent;}
        .scrollbar-thin::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:4px;}
        .dark .scrollbar-thin::-webkit-scrollbar-thumb{background:#475569;}
        .progress-bar{background:var(--gp);border-radius:99px;transition:width 1s cubic-bezier(.4,0,.2,1);}
        .reveal{opacity:0;transform:translateY(24px);transition:opacity .6s ease,transform .6s ease;}
        .reveal.visible{opacity:1;transform:translateY(0);}
        .reveal-d1{transition-delay:.08s;}.reveal-d2{transition-delay:.16s;}.reveal-d3{transition-delay:.24s;}.reveal-d4{transition-delay:.32s;}
        .btn-grad{background:linear-gradient(135deg,#6366f1,#8b5cf6);transition:all .3s ease;position:relative;overflow:hidden;}
        .btn-grad::before{content:'';position:absolute;top:0;left:-100%;width:100%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,0.2),transparent);transition:left .5s ease;}
        .btn-grad:hover::before{left:100%;}
        .btn-grad:hover{transform:translateY(-2px);box-shadow:0 12px 35px rgba(99,102,241,0.35);}
    </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-white to-indigo-50/30 dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950/30 min-h-screen" style="color:var(--color-text-primary)">

<!-- ===== STICKY NAVBAR ===== -->
<nav class="fixed top-0 left-0 right-0 z-50" style="background:rgba(255,255,255,0.8);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border-bottom:1px solid rgba(255,255,255,0.3);transition:all .3s ease" id="fl-nav">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
            <!-- Logo -->
            <a href="<?= e(base_url('index.php')) ?>" class="flex items-center gap-2.5 group">
                <div class="w-9 h-9 bg-gradient-to-br from-primary-500 to-accent-500 rounded-xl flex items-center justify-center shadow-lg shadow-primary-500/30 group-hover:shadow-primary-500/50 transition-shadow">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </div>
                <span class="text-lg font-bold bg-gradient-to-r from-primary-600 to-accent-600 bg-clip-text text-transparent hidden sm:block">HireWork</span>
            </a>

            <!-- Center Nav Links -->
            <div class="hidden md:flex items-center gap-1">
                <?php
                $fl_nav = [
                    ['id'=>'dashboard','label'=>'Dashboard','url'=>'freelancer/dashboard.php','icon'=>'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
                    ['id'=>'browse','label'=>'Browse Jobs','url'=>'freelancer/browse_jobs.php','icon'=>'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z'],
                    ['id'=>'tasks','label'=>'My Tasks','url'=>'freelancer/my_tasks.php','icon'=>'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
                    ['id'=>'profile','label'=>'Profile','url'=>'freelancer/profile.php','icon'=>'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
                ];
                foreach ($fl_nav as $n): ?>
                    <a href="<?= e(base_url($n['url'])) ?>" class="flex items-center gap-1.5 px-3.5 py-2 text-sm font-medium rounded-xl transition-all <?= $fl_active === $n['id'] ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800' ?>">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $n['icon'] ?>"/></svg>
                        <?= $n['label'] ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Right Actions -->
            <div class="flex items-center gap-2">
                <!-- Messages -->
                <a href="<?= e(base_url('chat/index.php')) ?>" class="relative p-2.5 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" title="Messages">
                    <svg class="w-5 h-5 text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                    <?php if ($fl_chat_unread > 0): ?>
                        <span class="absolute top-1 right-1 w-4 h-4 bg-emerald-500 rounded-full text-white text-[9px] font-bold flex items-center justify-center shadow-sm"><?= min($fl_chat_unread, 9) ?></span>
                    <?php endif; ?>
                </a>

                <!-- Notifications -->
                <div class="relative" id="fl-notif-wrap">
                    <button type="button" class="relative p-2.5 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" onclick="event.stopPropagation();document.getElementById('fl-notif-dd').classList.toggle('hidden')">
                        <svg class="w-5 h-5 text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                        <?php if ($fl_notif_count > 0): ?>
                            <span class="absolute top-1 right-1 w-4 h-4 bg-red-500 rounded-full text-white text-[9px] font-bold flex items-center justify-center shadow-sm"><?= min($fl_notif_count, 9) ?></span>
                        <?php endif; ?>
                    </button>
                    <div id="fl-notif-dd" class="hidden absolute right-0 mt-2 w-80 rounded-2xl shadow-2xl border z-50 overflow-hidden" style="background:var(--color-card);border-color:var(--color-border)">
                        <div class="p-4 border-b" style="border-color:var(--color-border)">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-sm" style="color:var(--color-text-primary)">Notifications</span>
                                <?php if ($fl_notif_count > 0): ?>
                                    <button onclick="markAllFlNotif()" class="text-xs text-primary-600 hover:underline font-medium">Mark all read</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="max-h-80 overflow-y-auto">
                            <?php if (empty($fl_recent_notifs)): ?>
                                <div class="p-8 text-center text-sm" style="color:var(--color-text-placeholder)">No notifications yet</div>
                            <?php else: foreach ($fl_recent_notifs as $n): ?>
                                <a href="<?= e($n['link'] ? base_url($n['link']) : '#') ?>" class="flex items-start gap-3 px-4 py-3 border-b last:border-0 transition-colors hover:bg-gray-50 dark:hover:bg-gray-800/50 <?= $n['is_read'] ? '' : 'bg-primary-50/50 dark:bg-primary-900/20' ?>" style="border-color:var(--color-border)">
                                    <div class="mt-0.5 flex-shrink-0"><?= notification_icon($n['type']) ?></div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs <?= $n['is_read'] ? '' : 'font-semibold' ?>" style="color:var(--color-text-primary)"><?= e($n['message']) ?></p>
                                        <p class="text-[10px] mt-0.5" style="color:var(--color-text-placeholder)"><?= e($n['created_at']) ?></p>
                                    </div>
                                </a>
                            <?php endforeach; endif; ?>
                        </div>
                        <a href="<?= e(base_url('notifications.php')) ?>" class="block p-3 text-center text-xs font-semibold text-primary-600 hover:bg-gray-50 dark:hover:bg-gray-800/50 border-t" style="border-color:var(--color-border)">View All Notifications</a>
                    </div>
                </div>

                <!-- Dark Mode -->
                <button type="button" id="fl-theme-toggle" class="p-2.5 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" aria-label="Toggle theme">
                    <svg class="w-5 h-5 text-gray-500 dark:text-gray-400 dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                    <svg class="w-5 h-5 text-gray-500 dark:text-gray-400 hidden dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                </button>

                <!-- User Dropdown -->
                <div class="relative" id="fl-user-wrap">
                    <button type="button" class="flex items-center gap-2 p-1.5 pr-3 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" onclick="event.stopPropagation();document.getElementById('fl-user-dd').classList.toggle('hidden')">
                        <?php $fl_img = profile_image_url($fl_profile['profile_image']); ?>
                        <?php if ($fl_img): ?>
                            <img src="<?= e($fl_img) ?>" alt="" class="w-8 h-8 rounded-xl object-cover">
                        <?php else: ?>
                            <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-primary-500 to-accent-500 flex items-center justify-center text-white text-xs font-bold"><?= strtoupper(mb_substr($fl_profile['full_name'] ?? $fl_profile['username'] ?? 'U', 0, 1)) ?></div>
                        <?php endif; ?>
                        <span class="text-sm font-medium hidden sm:block" style="color:var(--color-text-primary)"><?= e(mb_strimwidth($fl_profile['full_name'] ?? $fl_profile['username'], 0, 12, '...')) ?></span>
                        <svg class="w-4 h-4 text-gray-400 hidden sm:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div id="fl-user-dd" class="hidden absolute right-0 mt-2 w-56 rounded-2xl shadow-2xl border z-50 overflow-hidden" style="background:var(--color-card);border-color:var(--color-border)">
                        <div class="p-4 border-b" style="border-color:var(--color-border)">
                            <p class="font-semibold text-sm" style="color:var(--color-text-primary)"><?= e($fl_profile['full_name'] ?? $fl_profile['username']) ?></p>
                            <p class="text-xs" style="color:var(--color-text-muted)"><?= e($fl_profile['email']) ?></p>
                        </div>
                        <div class="py-1">
                            <a href="<?= e(base_url('freelancer/dashboard.php')) ?>" class="flex items-center gap-2.5 px-4 py-2.5 text-sm transition-colors hover:bg-gray-50 dark:hover:bg-gray-800/50" style="color:var(--color-text-secondary)"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>Dashboard</a>
                            <a href="<?= e(base_url('freelancer/profile.php')) ?>" class="flex items-center gap-2.5 px-4 py-2.5 text-sm transition-colors hover:bg-gray-50 dark:hover:bg-gray-800/50" style="color:var(--color-text-secondary)"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>Profile</a>
                            <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="flex items-center gap-2.5 px-4 py-2.5 text-sm transition-colors hover:bg-gray-50 dark:hover:bg-gray-800/50" style="color:var(--color-text-secondary)"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>Browse Jobs</a>
                            <a href="<?= e(base_url('freelancer/my_tasks.php')) ?>" class="flex items-center gap-2.5 px-4 py-2.5 text-sm transition-colors hover:bg-gray-50 dark:hover:bg-gray-800/50" style="color:var(--color-text-secondary)"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>My Tasks</a>
                            <a href="<?= e(base_url('chat/index.php')) ?>" class="flex items-center gap-2.5 px-4 py-2.5 text-sm transition-colors hover:bg-gray-50 dark:hover:bg-gray-800/50" style="color:var(--color-text-secondary)"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>Messages</a>
                        </div>
                        <div class="border-t py-1" style="border-color:var(--color-border)">
                            <a href="<?= e(base_url('logout.php')) ?>" class="flex items-center gap-2.5 px-4 py-2.5 text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>Logout</a>
                        </div>
                    </div>
                </div>

                <!-- Mobile Toggle -->
                <button id="fl-mobile-toggle" class="md:hidden p-2.5 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                    <svg class="w-5 h-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Mobile Menu -->
    <div id="fl-mobile-menu" class="hidden md:hidden border-t" style="border-color:var(--color-border);background:var(--color-card)">
        <div class="px-4 py-3 space-y-1">
            <?php foreach ($fl_nav as $n): ?>
                <a href="<?= e(base_url($n['url'])) ?>" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-medium transition-colors <?= $fl_active === $n['id'] ? 'bg-primary-50 dark:bg-primary-900/30 text-primary-600' : 'hover:bg-gray-100 dark:hover:bg-gray-800' ?>" style="color:<?= $fl_active === $n['id'] ? '' : 'var(--color-text-secondary)' ?>">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $n['icon'] ?>"/></svg>
                    <?= $n['label'] ?>
                </a>
            <?php endforeach; ?>
            <a href="<?= e(base_url('chat/index.php')) ?>" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" style="color:var(--color-text-secondary)">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                Messages
                <?php if ($fl_chat_unread > 0): ?>
                    <span class="ml-auto w-5 h-5 bg-emerald-500 rounded-full text-white text-[10px] font-bold flex items-center justify-center"><?= min($fl_chat_unread, 99) ?></span>
                <?php endif; ?>
            </a>
        </div>
    </div>
</nav>

<!-- Main Content -->
<main class="pt-20 pb-8 min-h-screen">
<div id="fl-page-content">
