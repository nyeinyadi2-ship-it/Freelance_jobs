<?php
$page_title = $page_title ?? __('app.name');
$admin_user = current_user();
$current_lang = current_lang();
$unread_count = 0;
$recent_notifications = [];
$chat_unread = 0;

if (isset($admin_user['user_id'])) {
    require_once __DIR__ . '/../../config/notifications.php';
    $unread_count = get_unread_notification_count($conn, (int) $admin_user['user_id']);
    $recent_notifications = get_notifications($conn, (int) $admin_user['user_id'], 5);
    require_once __DIR__ . '/../../config/chat.php';
    $chat_unread = get_unread_count($conn, (int) $admin_user['user_id']);
}
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>" data-theme>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?></title>
    <script>
    (function(){
        var t = localStorage.getItem('theme');
        if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        darkMode: 'class',
    };
    </script>
    <link rel="stylesheet" href="<?= e(base_url('assets/css/custom.css')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
    body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }

    /* Admin Layout */
    .admin-layout { display: flex; min-height: 100vh; }
    .admin-main { flex: 1; margin-left: 260px; padding-top: 64px; transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
    .admin-layout.sidebar-collapsed .admin-main { margin-left: 72px; }

    /* Fixed Top Navbar */
    .admin-topbar {
        position: fixed; top: 0; left: 0; right: 0; z-index: 50;
        height: 64px;
        background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4338ca 100%);
        border-bottom: 1px solid rgba(99, 102, 241, 0.2);
        backdrop-filter: blur(12px);
        box-shadow: 0 4px 24px rgba(0, 0, 0, 0.15);
    }

    /* Sidebar */
    .admin-sidebar {
        position: fixed; top: 64px; left: 0; bottom: 0; z-index: 40;
        width: 260px;
        background: #ffffff;
        border-right: 1px solid #e5e7eb;
        overflow-y: auto;
        transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1), transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 2px 0 8px rgba(0, 0, 0, 0.04);
    }
    html.dark .admin-sidebar {
        background: #1e293b;
        border-right-color: #334155;
        box-shadow: 2px 0 8px rgba(0, 0, 0, 0.2);
    }

    .admin-layout.sidebar-collapsed .admin-sidebar { width: 72px; }
    .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-label,
    .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-badge,
    .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-profile-text,
    .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-section-title { display: none; }
    .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-link { justify-content: center; padding-left: 0; padding-right: 0; }
    .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-link svg { margin: 0; }
    .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-profile { justify-content: center; }
    .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-profile-avatar { margin: 0; }
    .admin-layout.sidebar-collapsed .sidebar-footer-text { display: none; }

    /* Sidebar Nav Links */
    .sidebar-link {
        display: flex; align-items: center; gap: 12px;
        padding: 10px 16px; margin: 2px 8px;
        border-radius: 10px; font-size: 14px; font-weight: 500;
        color: #64748b; text-decoration: none;
        transition: all 0.2s ease;
    }
    .sidebar-link:hover {
        background: linear-gradient(135deg, #eef2ff, #e0e7ff);
        color: #4f46e5;
        transform: translateX(2px);
    }
    html.dark .sidebar-link:hover {
        background: linear-gradient(135deg, rgba(99, 102, 241, 0.15), rgba(124, 58, 237, 0.1));
        color: #818cf8;
    }
    .sidebar-link.active {
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        color: #ffffff;
        box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
    }
    .sidebar-link.active svg { color: #ffffff; }

    /* Sidebar Badge */
    .sidebar-badge {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 20px; height: 20px; padding: 0 6px;
        border-radius: 10px; font-size: 11px; font-weight: 700;
    }

    /* Sidebar Mobile */
    .sidebar-overlay {
        position: fixed; inset: 0; background: rgba(0, 0, 0, 0.5);
        z-index: 35; opacity: 0; pointer-events: none;
        transition: opacity 0.3s ease;
    }
    .sidebar-overlay.active { opacity: 1; pointer-events: auto; }

    @media (max-width: 1023px) {
        .admin-sidebar { transform: translateX(-100%); width: 260px !important; }
        .admin-sidebar.mobile-open { transform: translateX(0); }
        .admin-main { margin-left: 0 !important; }
        .admin-layout.sidebar-collapsed .admin-sidebar { width: 260px !important; }
        .admin-layout.sidebar-collapsed .admin-main { margin-left: 0 !important; }
        .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-label,
        .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-badge,
        .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-profile-text,
        .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-section-title { display: block; }
        .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-link { justify-content: flex-start; padding-left: 16px; padding-right: 16px; }
        .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-link svg { margin: 0; }
        .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-profile { justify-content: flex-start; }
        .admin-layout.sidebar-collapsed .sidebar-footer-text { display: block; }
    }

    /* Scrollbar */
    .admin-sidebar::-webkit-scrollbar { width: 4px; }
    .admin-sidebar::-webkit-scrollbar-track { background: transparent; }
    .admin-sidebar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    html.dark .admin-sidebar::-webkit-scrollbar-thumb { background: #475569; }

    /* Search Bar */
    .admin-search { position: relative; }
    .admin-search-input {
        width: 100%; max-width: 420px;
        height: 38px;
        background: rgba(255,255,255,0.12);
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 10px;
        padding: 0 14px 0 38px;
        font-size: 13px;
        color: #ffffff;
        outline: none;
        transition: all 0.25s ease;
    }
    .admin-search-input::placeholder { color: rgba(255,255,255,0.5); }
    .admin-search-input:focus {
        background: rgba(255,255,255,0.2);
        border-color: rgba(255,255,255,0.35);
        box-shadow: 0 0 0 3px rgba(255,255,255,0.1);
        max-width: 520px;
    }
    .admin-search-icon {
        position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
        color: rgba(255,255,255,0.5); pointer-events: none;
    }
    .admin-search-kbd {
        position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
        display: flex; align-items: center; gap: 2px;
        font-size: 11px; color: rgba(255,255,255,0.4);
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 4px; padding: 1px 5px;
        line-height: 1;
    }

    /* Search Results Dropdown */
    .admin-search-results {
        position: absolute; top: calc(100% + 8px); left: 0; right: 0;
        max-height: 420px; overflow-y: auto;
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.2), 0 0 0 1px rgba(0,0,0,0.05);
        z-index: 100;
        display: none;
    }
    html.dark .admin-search-results {
        background: #1e293b;
        border-color: #334155;
    }
    .admin-search-results.show { display: block; animation: searchFadeIn 0.15s ease; }
    @keyframes searchFadeIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }

    .admin-search-group { padding: 6px 0; }
    .admin-search-group + .admin-search-group { border-top: 1px solid #f1f5f9; }
    html.dark .admin-search-group + .admin-search-group { border-top-color: #334155; }
    .admin-search-group-label {
        padding: 6px 16px 2px;
        font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em;
        color: #94a3b8;
    }

    .admin-search-item {
        display: flex; align-items: center; gap: 12px;
        padding: 8px 16px;
        cursor: pointer;
        transition: background 0.1s;
        text-decoration: none; color: inherit;
    }
    .admin-search-item:hover, .admin-search-item.active {
        background: #f1f5f9;
    }
    html.dark .admin-search-item:hover, html.dark .admin-search-item.active {
        background: #334155;
    }
    .admin-search-item-icon {
        width: 34px; height: 34px; border-radius: 9px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .admin-search-item-icon svg { width: 16px; height: 16px; }
    .admin-search-item-text { flex: 1; min-width: 0; }
    .admin-search-item-title { font-size: 13px; font-weight: 600; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    html.dark .admin-search-item-title { color: #f1f5f9; }
    .admin-search-item-subtitle { font-size: 11px; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .admin-search-item-badge {
        padding: 2px 8px; border-radius: 6px;
        font-size: 10px; font-weight: 700; text-transform: capitalize;
        flex-shrink: 0;
    }

    .admin-search-empty { padding: 24px 16px; text-align: center; color: #94a3b8; font-size: 13px; }
    .admin-search-loading { padding: 16px; text-align: center; }
    .admin-search-loading::after {
        content: ''; display: inline-block; width: 20px; height: 20px;
        border: 2px solid #e5e7eb; border-top-color: #6366f1;
        border-radius: 50%; animation: searchSpin 0.6s linear infinite;
    }
    @keyframes searchSpin { to { transform: rotate(360deg); } }

    @media (max-width: 768px) {
        .admin-search-input { max-width: 180px; }
        .admin-search-input:focus { max-width: 240px; }
        .admin-search-kbd { display: none; }
        .admin-search-results { min-width: 300px; }
    }
    </style>
</head>
<body class="bg-gray-50 dark:bg-slate-900">

<!-- Top Navigation Bar -->
<header class="admin-topbar">
    <div class="flex items-center justify-between h-full px-4 lg:px-6">
        <!-- Left: Sidebar toggle + Logo -->
        <div class="flex items-center gap-3">
            <button id="admin-sidebar-toggle" type="button" class="p-2 rounded-lg text-white/70 hover:text-white hover:bg-white/10 transition-colors" aria-label="Toggle sidebar">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
            <button id="sidebar-collapse-btn" type="button" class="hidden lg:flex p-2 rounded-lg text-white/70 hover:text-white hover:bg-white/10 transition-colors" aria-label="Collapse sidebar">
                <svg class="w-5 h-5 sidebar-collapse-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
                </svg>
            </button>
            <a href="<?= e(base_url('admin/admin_dashboard.php')) ?>" class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-white/15 flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                </div>
                <span class="text-lg font-bold text-white tracking-tight hidden sm:inline"><?= e(__('app.name')) ?></span>
                <span class="hidden sm:inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider bg-white/15 text-white/90">Admin</span>
            </a>
        </div>

        <!-- Center: Search Bar -->
        <div class="admin-search hidden md:block flex-1 mx-6">
            <div class="relative">
                <svg class="admin-search-icon w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text" id="admin-search-input" class="admin-search-input" placeholder="Search jobs, clients, freelancers..." autocomplete="off" spellcheck="false">
                <div class="admin-search-kbd" id="admin-search-kbd">/</div>
                <div class="admin-search-results" id="admin-search-results"></div>
            </div>
        </div>

        <!-- Right: Actions -->
        <div class="flex items-center gap-2">
            <!-- Language Switcher -->
            <div class="relative" id="lang-switcher">
                <button type="button" onclick="event.stopPropagation(); document.getElementById('lang-dropdown').classList.toggle('hidden')" class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-sm font-medium text-white/80 hover:text-white hover:bg-white/10 transition-colors" style="min-width:2.25rem">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span class="hidden sm:inline"><?= $current_lang === 'my' ? 'မြန်မာ' : 'English' ?></span>
                </button>
                <div id="lang-dropdown" class="hidden absolute right-0 mt-2 w-28 rounded-xl shadow-xl border z-50 overflow-hidden" style="background:var(--color-card);border-color:var(--color-border)">
                    <a href="<?= e(lang_switch_url('en')) ?>" class="block px-3 py-2 text-sm font-medium <?= $current_lang === 'en' ? 'text-indigo-600 bg-indigo-50 dark:bg-indigo-900/30' : '' ?>" style="color:<?= $current_lang === 'en' ? '' : 'var(--color-text-secondary)' ?>"><?= e(__('lang.en')) ?></a>
                    <a href="<?= e(lang_switch_url('my')) ?>" class="block px-3 py-2 text-sm font-medium <?= $current_lang === 'my' ? 'text-indigo-600 bg-indigo-50 dark:bg-indigo-900/30' : '' ?>" style="color:<?= $current_lang === 'my' ? '' : 'var(--color-text-secondary)' ?>"><?= e(__('lang.my')) ?></a>
                </div>
            </div>

            <!-- Dark Mode Toggle -->
            <button id="theme-toggle" type="button" class="p-2 rounded-lg text-white/70 hover:text-white hover:bg-white/10 transition-colors" aria-label="Toggle theme">
                <svg class="w-5 h-5 dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                </svg>
                <svg class="w-5 h-5 hidden dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
            </button>

            <!-- Notification Bell -->
            <div class="relative notification-container">
                <button type="button" class="notification-toggle relative p-2 rounded-lg text-white/70 hover:text-white hover:bg-white/10 transition-colors" aria-label="<?= e(__('notif.title')) ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <?php if ($unread_count > 0): ?>
                        <span class="notification-badge absolute -top-0.5 -right-0.5 bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[18px] h-[18px] flex items-center justify-center px-1 ring-2 ring-indigo-700" style="display:flex;"><?= min($unread_count, 99) ?></span>
                    <?php else: ?>
                        <span class="notification-badge absolute -top-0.5 -right-0.5 bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[18px] h-[18px] items-center justify-center px-1 ring-2 ring-indigo-700" style="display:none;">0</span>
                    <?php endif; ?>
                </button>

                <div class="notification-dropdown absolute right-0 mt-2 w-80 rounded-xl shadow-2xl border z-50 hidden" style="display:none;background:var(--color-card);border-color:var(--color-border)">
                    <div class="p-4 border-b flex justify-between items-center" style="border-color:var(--color-border)">
                        <span class="font-semibold text-sm" style="color:var(--color-text-primary)"><?= __('notif.recent') ?></span>
                        <div class="flex items-center gap-2">
                            <?php if ($unread_count > 0): ?>
                                <button type="button" class="notification-mark-all text-xs text-indigo-600 hover:underline" data-csrf="<?= e(csrf_token()) ?>"><?= __('notif.mark_all_read') ?></button>
                            <?php endif; ?>
                            <a href="<?= e(base_url('admin/notifications.php')) ?>" class="text-xs hover:text-indigo-600" style="color:var(--color-text-muted)"><?= __('notif.view_all') ?></a>
                        </div>
                    </div>
                    <div class="max-h-80 overflow-y-auto notification-list">
                        <?php if (empty($recent_notifications)): ?>
                            <div class="p-6 text-center text-sm" style="color:var(--color-text-placeholder)"><?= __('notif.no_notifications') ?></div>
                        <?php else: ?>
                            <?php foreach ($recent_notifications as $n): ?>
                                <div class="notification-item flex items-start gap-2 px-4 py-3 border-b last:border-0 <?= $n['is_read'] ? '' : 'bg-indigo-50/50 dark:bg-indigo-900/20' ?>" style="border-color:var(--color-border)" data-id="<?= (int) $n['id'] ?>">
                                    <div class="mt-0.5 flex-shrink-0"><?= notification_icon($n['type']) ?></div>
                                    <div class="flex-1 min-w-0">
                                        <a href="<?= e($n['link'] ? base_url($n['link']) : base_url('admin/notifications.php')) ?>" class="block">
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

            <!-- Admin User -->
            <div class="flex items-center gap-2.5 pl-2 ml-1 border-l border-white/20">
                <?php $admin_img = profile_image_url($admin_user['profile_image'] ?? ''); ?>
                <?php if ($admin_img): ?>
                    <img src="<?= e($admin_img) ?>" alt="" class="w-8 h-8 rounded-full object-cover ring-2 ring-white/30">
                <?php else: ?>
                    <div class="w-8 h-8 rounded-full bg-white/15 flex items-center justify-center text-white font-bold text-sm ring-2 ring-white/30">
                        <?= e(_first_char($admin_user['username'] ?? 'A')) ?>
                    </div>
                <?php endif; ?>
                <span class="text-sm font-medium text-white/90 hidden md:inline"><?= e($admin_user['username'] ?? '') ?></span>
            </div>
        </div>
    </div>
</header>

<!-- Layout Wrapper -->
<div class="admin-layout" id="admin-layout">

<!-- Sidebar Overlay (mobile) -->
<div id="admin-sidebar-overlay" class="sidebar-overlay lg:hidden"></div>

<!-- Sidebar -->
<aside id="admin-sidebar" class="admin-sidebar">
    <?php require __DIR__ . '/admin_sidebar.php'; ?>
</aside>

<!-- Main Content -->
<main class="admin-main">
    <div class="p-4 lg:p-8 max-w-7xl mx-auto">

<?php
$flash = get_flash();
if ($flash):
    $isError = $flash['type'] === 'error';
?>
    <div class="mb-6 p-4 rounded-xl border flex items-center gap-3 shadow-sm animate-fadeIn"
         style="background:<?= $isError ? 'var(--color-flash-error-bg)' : 'var(--color-flash-success-bg)' ?>;color:<?= $isError ? 'var(--color-flash-error-text)' : 'var(--color-flash-success-text)' ?>;border-color:<?= $isError ? 'var(--color-flash-error-border)' : 'var(--color-flash-success-border)' ?>">
        <?php if ($isError): ?>
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        <?php else: ?>
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <?php endif; ?>
        <p class="text-sm font-medium"><?= e($flash['message']) ?></p>
    </div>
<?php endif; ?>
