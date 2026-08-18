<?php
$page_title = $page_title ?? 'FreelanceHub';
$admin_user = current_user();
$current_lang = 'en';
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
<html lang="<?= e('en') ?>" data-theme>
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <style>
    *, *::before, *::after { box-sizing: border-box; }
    html, body { overflow-x: clip; }
    body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }

    /* Admin Layout */
    .admin-layout { display: flex; min-height: 100vh; }
    .admin-main {
        flex: 1;
        margin-left: 240px;
        padding-top: 56px;
        transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        min-width: 0;
        overflow-x: hidden;
    }
    .admin-layout.sidebar-collapsed .admin-main { margin-left: 64px; }

    /* Fixed Top Navbar */
    .admin-topbar {
        position: fixed; top: 0; left: 0; right: 0; z-index: 50;
        height: 56px;
        background: #ffffff;
        border-bottom: 1px solid #e5e7eb;
    }
    html.dark .admin-topbar {
        background: #1e293b;
        border-bottom-color: #334155;
    }

    /* Sidebar */
    .admin-sidebar {
        position: fixed; top: 56px; left: 0; bottom: 0; z-index: 40;
        width: 240px;
        display: flex; flex-direction: column;
        background: #ffffff;
        border-right: 1px solid #e5e7eb;
        overflow-y: auto;
        transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1), transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    html.dark .admin-sidebar {
        background: #1e293b;
        border-right-color: #334155;
    }

    .admin-layout.sidebar-collapsed .admin-sidebar { width: 64px; }
    .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-label,
    .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-badge,
    .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-section-title { display: none; }
    .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-link { justify-content: center; padding-left: 0; padding-right: 0; gap: 0; }
    .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-link svg { margin: 0; }

    /* Sidebar Nav Links */
    .sidebar-link {
        display: flex; align-items: center; gap: 10px;
        padding: 8px 12px; margin: 1px 8px;
        border-radius: 6px; font-size: 13px; font-weight: 500;
        color: #64748b; text-decoration: none;
        transition: all 0.15s ease;
    }
    .sidebar-link:hover {
        background: #f1f5f9;
        color: #1e293b;
    }
    html.dark .sidebar-link:hover {
        background: rgba(99, 102, 241, 0.1);
        color: #e2e8f0;
    }
    .sidebar-link.active {
        background: #eef2ff;
        color: #4f46e5;
        font-weight: 600;
    }
    html.dark .sidebar-link.active {
        background: rgba(99, 102, 241, 0.15);
        color: #818cf8;
    }
    .sidebar-link.active svg { color: #4f46e5; }
    html.dark .sidebar-link.active svg { color: #818cf8; }

    /* Sidebar Badge */
    .sidebar-badge {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 18px; height: 18px; padding: 0 5px;
        border-radius: 9px; font-size: 10px; font-weight: 700;
    }

    /* Sidebar Mobile */
    .sidebar-overlay {
        position: fixed; inset: 0; background: rgba(0, 0, 0, 0.4);
        z-index: 35; opacity: 0; pointer-events: none;
        transition: opacity 0.3s ease;
    }
    .sidebar-overlay.active { opacity: 1; pointer-events: auto; }

    @media (max-width: 1023px) {
        .admin-sidebar { transform: translateX(-100%); width: 240px !important; }
        .admin-sidebar.mobile-open { transform: translateX(0); }
        .admin-main { margin-left: 0 !important; }
        .admin-layout.sidebar-collapsed .admin-sidebar { width: 240px !important; }
        .admin-layout.sidebar-collapsed .admin-main { margin-left: 0 !important; }
        .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-label,
        .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-badge,
        .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-section-title { display: block; }
        .admin-layout.sidebar-collapsed .admin-sidebar .sidebar-link { justify-content: flex-start; padding-left: 12px; padding-right: 12px; gap: 10px; }
    }

    /* Scrollbar */
    .admin-sidebar::-webkit-scrollbar { width: 3px; }
    .admin-sidebar::-webkit-scrollbar-track { background: transparent; }
    .admin-sidebar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
    html.dark .admin-sidebar::-webkit-scrollbar-thumb { background: #475569; }

    /* Search Bar */
    .admin-search { position: relative; }
    .admin-search-input {
        width: 100%; max-width: 360px;
        height: 34px;
        background: #f1f5f9;
        border: 1px solid transparent;
        border-radius: 8px;
        padding: 0 12px 0 34px;
        font-size: 13px;
        color: #1e293b;
        outline: none;
        transition: all 0.2s ease;
    }
    html.dark .admin-search-input {
        background: rgba(255,255,255,0.08);
        border-color: rgba(255,255,255,0.1);
        color: #f1f5f9;
    }
    .admin-search-input::placeholder { color: #94a3b8; }
    .admin-search-input:focus {
        background: #ffffff;
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        max-width: 420px;
    }
    html.dark .admin-search-input:focus {
        background: rgba(255,255,255,0.12);
        border-color: #818cf8;
    }
    .admin-search-icon {
        position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
        color: #94a3b8; pointer-events: none;
    }
    .admin-search-kbd {
        position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
        display: flex; align-items: center; gap: 2px;
        font-size: 10px; color: #94a3b8;
        border: 1px solid #e2e8f0;
        border-radius: 4px; padding: 1px 5px;
        line-height: 1;
    }
    html.dark .admin-search-kbd {
        border-color: #475569;
        color: #64748b;
    }

    /* Search Results Dropdown */
    .admin-search-results {
        position: absolute; top: calc(100% + 6px); left: 0; right: 0;
        max-height: 400px; overflow-y: auto;
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        box-shadow: 0 12px 40px rgba(0,0,0,0.12);
        z-index: 100;
        display: none;
    }
    html.dark .admin-search-results {
        background: #1e293b;
        border-color: #334155;
    }
    .admin-search-results.show { display: block; animation: searchFadeIn 0.12s ease; }
    @keyframes searchFadeIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }

    .admin-search-group { padding: 4px 0; }
    .admin-search-group + .admin-search-group { border-top: 1px solid #f1f5f9; }
    html.dark .admin-search-group + .admin-search-group { border-top-color: #334155; }
    .admin-search-group-label {
        padding: 4px 14px 2px;
        font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;
        color: #94a3b8;
    }

    .admin-search-item {
        display: flex; align-items: center; gap: 10px;
        padding: 6px 14px;
        cursor: pointer;
        transition: background 0.1s;
        text-decoration: none; color: inherit;
    }
    .admin-search-item:hover, .admin-search-item.active { background: #f8fafc; }
    html.dark .admin-search-item:hover, html.dark .admin-search-item.active { background: #334155; }
    .admin-search-item-icon {
        width: 30px; height: 30px; border-radius: 7px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .admin-search-item-icon svg { width: 14px; height: 14px; }
    .admin-search-item-text { flex: 1; min-width: 0; }
    .admin-search-item-title { font-size: 13px; font-weight: 600; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    html.dark .admin-search-item-title { color: #f1f5f9; }
    .admin-search-item-subtitle { font-size: 11px; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .admin-search-item-badge {
        padding: 2px 7px; border-radius: 5px;
        font-size: 10px; font-weight: 600; text-transform: capitalize;
        flex-shrink: 0;
    }

    .admin-search-empty { padding: 20px 14px; text-align: center; color: #94a3b8; font-size: 13px; }
    .admin-search-loading { padding: 16px; text-align: center; }
    .admin-search-loading::after {
        content: ''; display: inline-block; width: 18px; height: 18px;
        border: 2px solid #e5e7eb; border-top-color: #6366f1;
        border-radius: 50%; animation: searchSpin 0.6s linear infinite;
    }
    @keyframes searchSpin { to { transform: rotate(360deg); } }

    @media (max-width: 768px) {
        .admin-search-input { max-width: 160px; }
        .admin-search-input:focus { max-width: 200px; }
        .admin-search-kbd { display: none; }
        .admin-search-results { min-width: 280px; }
    }

    /* Topbar action buttons */
    .topbar-btn {
        display: flex; align-items: center; justify-content: center;
        width: 34px; height: 34px;
        border-radius: 8px;
        color: #64748b;
        transition: all 0.15s ease;
        background: transparent;
        border: none; cursor: pointer;
    }
    .topbar-btn:hover { background: #f1f5f9; color: #1e293b; }
    html.dark .topbar-btn:hover { background: rgba(255,255,255,0.08); color: #e2e8f0; }

    /* Notification badge on topbar */
    .notif-badge {
        position: absolute; top: 2px; right: 2px;
        min-width: 16px; height: 16px; padding: 0 4px;
        border-radius: 8px; font-size: 9px; font-weight: 700;
        background: #ef4444; color: #ffffff;
        display: flex; align-items: center; justify-content: center;
        border: 2px solid #ffffff;
    }
    html.dark .notif-badge { border-color: #1e293b; }
    </style>
</head>
<body class="bg-gray-50 dark:bg-slate-900" style="overflow-x: clip;">

<!-- Top Navigation Bar -->
<header class="admin-topbar">
    <div class="flex items-center justify-between h-full px-4 lg:px-5">
        <!-- Left: Sidebar toggle + Logo -->
        <div class="flex items-center gap-2">
            <button id="admin-sidebar-toggle" type="button" class="topbar-btn lg:hidden" aria-label="Toggle sidebar">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
            <button id="sidebar-collapse-btn" type="button" class="topbar-btn hidden lg:flex" aria-label="Collapse sidebar">
                <svg class="w-4 h-4 sidebar-collapse-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
                </svg>
            </button>
            <a href="<?= e(base_url('admin/admin_dashboard.php')) ?>" class="flex items-center gap-2">
                <div class="w-7 h-7 rounded-md bg-indigo-600 flex items-center justify-center">
                    <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                </div>
                <span class="text-base font-bold text-gray-900 dark:text-white tracking-tight hidden sm:inline">FreelanceHub</span>
                <span class="hidden sm:inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">Admin</span>
            </a>
        </div>

        <!-- Center: Spacer -->
        <div class="hidden md:block flex-1 mx-4 max-w-lg"></div>

        <!-- Right: Actions -->
        <div class="flex items-center gap-1">
            <!-- Language Switcher -->
            <div class="relative" id="lang-switcher">
                <button type="button" onclick="event.stopPropagation(); document.getElementById('lang-dropdown').classList.toggle('hidden')" class="topbar-btn" aria-label="Language">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </button>
                <div id="lang-dropdown" class="hidden absolute right-0 mt-2 w-28 rounded-lg shadow-lg border z-50 overflow-hidden" style="background:var(--color-card);border-color:var(--color-border)">
                    <a href="<?= e(lang_switch_url('en')) ?>" class="block px-3 py-2 text-xs font-medium <?= $current_lang === 'en' ? 'text-indigo-600 bg-indigo-50 dark:bg-indigo-900/30' : '' ?>" style="color:<?= $current_lang === 'en' ? '' : 'var(--color-text-secondary)' ?>"><?= e('English') ?></a>
                    <a href="<?= e(lang_switch_url('my')) ?>" class="block px-3 py-2 text-xs font-medium <?= $current_lang === 'my' ? 'text-indigo-600 bg-indigo-50 dark:bg-indigo-900/30' : '' ?>" style="color:<?= $current_lang === 'my' ? '' : 'var(--color-text-secondary)' ?>"><?= e('မြန်မာ') ?></a>
                </div>
            </div>

            <!-- Dark Mode Toggle -->
            <button id="theme-toggle" type="button" class="topbar-btn" aria-label="Toggle theme">
                <svg class="w-4 h-4 dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                </svg>
                <svg class="w-4 h-4 hidden dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
            </button>

            <!-- Notification Bell -->
            <div class="relative notification-container">
                <button type="button" class="topbar-btn notification-toggle relative" aria-label="<?= e('Notifications') ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <?php if ($unread_count > 0): ?>
                        <span class="notif-badge" style="display:flex;"><?= min($unread_count, 99) ?></span>
                    <?php else: ?>
                        <span class="notif-badge" style="display:none;">0</span>
                    <?php endif; ?>
                </button>

                <div class="notification-dropdown absolute right-0 mt-2 w-80 rounded-xl shadow-2xl border z-50 hidden" style="display:none;background:var(--color-card);border-color:var(--color-border)">
                    <div class="px-4 py-3 border-b flex justify-between items-center" style="border-color:var(--color-border)">
                        <span class="font-semibold text-sm" style="color:var(--color-text-primary)"><?= 'Recent Notifications' ?></span>
                        <div class="flex items-center gap-2">
                            <?php if ($unread_count > 0): ?>
                                <button type="button" class="notification-mark-all text-xs text-indigo-600 hover:underline" data-csrf="<?= e(csrf_token()) ?>"><?= 'Mark all as read' ?></button>
                            <?php endif; ?>
                            <a href="<?= e(base_url('admin/notifications.php')) ?>" class="text-xs hover:text-indigo-600" style="color:var(--color-text-muted)"><?= 'View all' ?></a>
                        </div>
                    </div>
                    <div class="max-h-72 overflow-y-auto notification-list">
                        <?php if (empty($recent_notifications)): ?>
                            <div class="p-6 text-center text-sm" style="color:var(--color-text-placeholder)"><?= 'No notifications yet.' ?></div>
                        <?php else: ?>
                            <?php foreach ($recent_notifications as $n): ?>
                                <div class="notification-item flex items-start gap-2 px-4 py-2.5 border-b last:border-0 <?= $n['is_read'] ? '' : 'bg-indigo-50/50 dark:bg-indigo-900/20' ?>" style="border-color:var(--color-border)" data-id="<?= (int) $n['id'] ?>">
                                    <div class="mt-0.5 flex-shrink-0"><?= notification_icon($n['type']) ?></div>
                                    <div class="flex-1 min-w-0">
                                        <a href="<?= e($n['link'] ? base_url($n['link']) : base_url('admin/notifications.php')) ?>" class="block">
                                            <p class="text-xs <?= $n['is_read'] ? '' : 'font-medium' ?>" style="color:<?= $n['is_read'] ? 'var(--color-text-muted)' : 'var(--color-text-primary)' ?>"><?= e($n['message']) ?></p>
                                            <p class="text-[10px] mt-0.5" style="color:var(--color-text-placeholder)"><?= e($n['created_at']) ?></p>
                                        </a>
                                    </div>
                                    <button type="button" class="notification-delete-btn flex-shrink-0 p-1 opacity-0 group-hover:opacity-100 transition-opacity" style="color:var(--color-text-placeholder)" data-id="<?= (int) $n['id'] ?>" data-csrf="<?= e(csrf_token()) ?>" title="<?= 'Delete' ?>">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Admin User -->
            <div class="flex items-center gap-2 pl-1 ml-1 border-l border-gray-200 dark:border-gray-700">
                <?php $admin_img = profile_image_url($admin_user['profile_image'] ?? ''); ?>
                <?php if ($admin_img): ?>
                    <img src="<?= e($admin_img) ?>" alt="" class="w-7 h-7 rounded-full object-cover">
                <?php else: ?>
                    <div class="w-7 h-7 rounded-full bg-indigo-100 dark:bg-indigo-900 flex items-center justify-center text-indigo-600 dark:text-indigo-300 font-bold text-xs">
                        <?= e(_first_char($admin_user['username'] ?? 'A')) ?>
                    </div>
                <?php endif; ?>
                <span class="text-xs font-medium text-gray-700 dark:text-gray-300 hidden md:inline"><?= e($admin_user['username'] ?? '') ?></span>
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
    <?php if (!isset($admin_no_padding) || !$admin_no_padding): ?>
    <div class="p-4 lg:p-6 max-w-[1200px] mx-auto">
    <?php endif; ?>

<?php
$flash = get_flash();
if ($flash):
    $isError = $flash['type'] === 'error';
?>
    <div class="mb-5 px-4 py-3 rounded-lg border flex items-center gap-3 text-sm"
         style="background:<?= $isError ? 'var(--color-flash-error-bg)' : 'var(--color-flash-success-bg)' ?>;color:<?= $isError ? 'var(--color-flash-error-text)' : 'var(--color-flash-success-text)' ?>;border-color:<?= $isError ? 'var(--color-flash-error-border)' : 'var(--color-flash-success-border)' ?>">
        <?php if ($isError): ?>
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        <?php else: ?>
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <?php endif; ?>
        <p class="font-medium"><?= e($flash['message']) ?></p>
    </div>
<?php endif; ?>
