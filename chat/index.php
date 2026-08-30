<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/chat.php';

require_login();

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['company', 'freelancer', 'admin'], true)) {
    set_flash('error', 'You do not have permission to access that page.');
    redirect('auth/login.php');
}

$user_id = (int) $_SESSION['user_id'];
update_last_activity($conn, $user_id);

$conversations = get_conversations($conn, $user_id);
$other_id = (int) ($_GET['user_id'] ?? 0);
$partner = null;

if ($other_id > 0) {
    if ($role === 'admin' || can_chat($conn, $user_id, $other_id)) {
        $partner = get_partner_info($conn, $user_id, $other_id);
    }
}

$page_title = 'Messages';
$unread_total = get_unread_count($conn, $user_id);
?>
<?php if ($role === 'admin'): ?>
<?php 
    $admin_no_padding = true;
    require __DIR__ . '/../admin/includes/admin_header.php';
?>
<?php else: ?>
<!DOCTYPE html>
<html lang="<?= e('en') ?>" data-theme>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?> - <?= e('FreelanceHub') ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
            }
        }
    };
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/custom.css')) ?>">
<?php endif; ?>
    <script src="<?= e(base_url('assets/js/emoji-picker.js')) ?>"></script>
    <script src="<?= e(base_url('assets/js/chat-websocket.js')) ?>"></script>
    <style>
        *, *::before, *::after { font-family: 'Inter', system-ui, sans-serif; }
        html { overflow: hidden; }
        body { margin: 0; }

        .chat-layout { height: <?= $role === 'admin' ? 'calc(100vh - 56px)' : 'calc(100vh - 4rem)' ?>; display: flex; }

        /* ===== SIDEBAR ===== */
        .chat-sidebar {
            width: 380px; min-width: 380px;
            display: flex; flex-direction: column;
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(20px);
            border-right: 1px solid rgba(99,102,241,0.08);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .dark .chat-sidebar { background: rgba(15,23,42,0.92); border-right-color: rgba(99,102,241,0.1); }

        .chat-main {
            flex: 1; display: flex; flex-direction: column;
            background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 50%, #e2e8f0 100%);
            min-width: 0;
        }
        .dark .chat-main { background: linear-gradient(180deg, #0f172a 0%, #1e293b 50%, #0f172a 100%); }

        /* ===== CONVERSATION LIST ===== */
        .conv-item {
            display: flex; align-items: center; gap: 14px;
            padding: 16px 20px;
            border-bottom: 1px solid rgba(99,102,241,0.05);
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none; color: inherit;
            position: relative;
        }
        .conv-item:hover { background: rgba(99,102,241,0.04); }
        .conv-item.active {
            background: linear-gradient(135deg, rgba(99,102,241,0.08), rgba(139,92,246,0.06));
            border-left: 3px solid #6366f1;
        }
        .conv-item.active::after {
            content: ''; position: absolute; right: 0; top: 50%; transform: translateY(-50%);
            width: 3px; height: 24px; border-radius: 3px;
            background: linear-gradient(180deg, #6366f1, #a855f7);
        }

        /* ===== AVATARS ===== */
        .avatar {
            width: 50px; height: 50px; border-radius: 16px;
            object-fit: cover; flex-shrink: 0;
            border: 2px solid transparent;
            background: linear-gradient(var(--color-card), var(--color-card)) padding-box,
                        linear-gradient(135deg, #6366f1, #a855f7) border-box;
        }
        .avatar-initials {
            width: 50px; height: 50px; border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 17px; flex-shrink: 0;
            background: linear-gradient(135deg, #6366f1, #a855f7);
            color: white;
        }
        .avatar-sm { width: 38px; height: 38px; border-radius: 12px; font-size: 14px; }
        .avatar-xs { width: 28px; height: 28px; border-radius: 10px; font-size: 11px; }

        .online-dot {
            width: 12px; height: 12px; border-radius: 50%;
            position: absolute; bottom: -1px; right: -1px;
            border: 2.5px solid var(--color-card);
        }
        .online-dot.online { background: #22c55e; box-shadow: 0 0 8px rgba(34,197,94,0.4); }
        .dark .online-dot.online { box-shadow: 0 0 8px rgba(34,197,94,0.6); }
        .online-dot.offline { background: #94a3b8; }

        /* ===== CHAT MESSAGES ===== */
        .chat-messages {
            flex: 1; overflow-y: auto; padding: 28px 24px;
            background: linear-gradient(180deg, #f8fafc 0%, rgba(99,102,241,0.015) 50%, #f1f5f9 100%);
        }
        .dark .chat-messages { background: linear-gradient(180deg, #0f172a 0%, rgba(99,102,241,0.02) 50%, #1e293b 100%); }

        .msg-bubble {
            padding: 14px 20px;
            border-radius: 20px; word-wrap: break-word;
            white-space: pre-wrap; position: relative;
            animation: msgIn 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
            line-height: 1.65;
        }
        .msg-col { max-width: 70%; }
        @media (max-width: 640px) {
            .msg-bubble { padding: 12px 16px; border-radius: 18px; }
            .msg-col { max-width: 88%; }
        }
        @keyframes msgIn {
            from { opacity: 0; transform: translateY(12px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .msg-sent {
            background: linear-gradient(135deg, #6366f1, #7c3aed);
            color: white; border-bottom-right-radius: 6px;
            margin-left: auto;
            box-shadow: 0 4px 20px rgba(99, 102, 241, 0.3);
        }
        .dark .msg-sent { box-shadow: 0 4px 24px rgba(99, 102, 241, 0.4); }
        .msg-received {
            background: white; color: #1e293b;
            border: 1px solid rgba(99,102,241,0.06); border-bottom-left-radius: 6px;
            margin-right: auto;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }
        .dark .msg-received { background: #1e293b; color: #e2e8f0; border-color: rgba(99,102,241,0.1); box-shadow: 0 2px 12px rgba(0,0,0,0.2); }

        .date-separator {
            display: flex; align-items: center; gap: 16px;
            margin: 32px 0; font-size: 12px; font-weight: 600;
            color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .date-separator::before, .date-separator::after {
            content: ''; flex: 1; height: 1px;
            background: linear-gradient(90deg, transparent, rgba(99,102,241,0.1), transparent);
        }

        /* ===== INPUT AREA ===== */
        .chat-input-area {
            border-top: 1px solid rgba(99,102,241,0.08);
            padding: 16px 20px;
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(20px);
        }
        .dark .chat-input-area { background: rgba(15,23,42,0.92); border-top-color: rgba(99,102,241,0.1); }

        .search-box {
            background: rgba(255,255,255,0.6); border: 1px solid rgba(99,102,241,0.08);
            border-radius: 12px; padding: 12px 16px; width: 100%;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(8px);
        }
        .dark .search-box { background: rgba(30,41,59,0.6); border-color: rgba(99,102,241,0.1); }
        .search-box:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
            outline: none; background: white;
        }
        .dark .search-box:focus { background: #1e293b; }

        .send-btn {
            width: 48px; height: 48px; border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #6366f1, #7c3aed);
            color: white; border: none; cursor: pointer;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); flex-shrink: 0;
        }
        .send-btn:hover { transform: scale(1.08); box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4); }
        .send-btn:active { transform: scale(0.95); }
        .send-btn:disabled { opacity: 0.35; cursor: not-allowed; transform: none; box-shadow: none; }

        .empty-state {
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            height: 100%; text-align: center; padding: 40px;
        }

        .unread-badge {
            min-width: 22px; height: 22px; padding: 0 7px;
            border-radius: 11px; font-size: 11px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #6366f1, #7c3aed);
            color: white; box-shadow: 0 2px 8px rgba(99, 102, 241, 0.3);
        }

        /* ===== SCROLLBAR ===== */
        .chat-messages::-webkit-scrollbar,
        .conv-list::-webkit-scrollbar { width: 5px; }
        .chat-messages::-webkit-scrollbar-track,
        .conv-list::-webkit-scrollbar-track { background: transparent; }
        .chat-messages::-webkit-scrollbar-thumb,
        .conv-list::-webkit-scrollbar-thumb {
            background: rgba(99,102,241,0.15); border-radius: 10px;
        }
        .chat-messages::-webkit-scrollbar-thumb:hover,
        .conv-list::-webkit-scrollbar-thumb:hover { background: rgba(99,102,241,0.3); }

        /* ===== TYPING ===== */
        .typing-dots { display: flex; gap: 4px; padding: 6px 0; }
        .typing-dots span {
            width: 7px; height: 7px; border-radius: 50%;
            background: linear-gradient(135deg, #6366f1, #a855f7);
            animation: typingBounce 1.4s infinite ease-in-out;
        }
        .typing-dots span:nth-child(2) { animation-delay: 0.2s; }
        .typing-dots span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes typingBounce {
            0%, 80%, 100% { transform: scale(0.5); opacity: 0.3; }
            40% { transform: scale(1); opacity: 1; }
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .chat-sidebar { position: absolute; width: 100%; min-width: 100%; z-index: 20; height: calc(100vh - 4rem); }
            .chat-sidebar.hidden-mobile { display: none; }
            .chat-main.hidden-mobile { display: none; }
            .chat-main { width: 100%; position: relative; z-index: 10; }
            .msg-col { max-width: 90%; }
            .msg-bubble { padding: 12px 16px; }
        }

        /* ===== ROLE BADGE ===== */
        .role-badge {
            display: inline-flex; align-items: center; gap: 3px;
            padding: 2px 8px; border-radius: 6px;
            font-size: 10px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .role-badge.company { background: rgba(99,102,241,0.1); color: #6366f1; }
        .role-badge.freelancer { background: rgba(16,185,129,0.1); color: #10b981; }
        .role-badge.admin { background: rgba(245,158,11,0.1); color: #f59e0b; }

        .msg-img-attachment {
            border-radius: 12px; max-width: 280px; max-height: 220px;
            object-fit: cover; cursor: pointer;
            transition: transform 0.2s ease;
        }
        .msg-img-attachment:hover { transform: scale(1.03); }

        /* ===== ACTION MENU ===== */
        .msg-action-menu {
            position: fixed; bottom: 0; left: 0; right: 0;
            background: var(--color-card, #fff);
            border-top-left-radius: 20px; border-top-right-radius: 20px;
            padding: 20px; box-shadow: 0 -4px 20px rgba(0,0,0,0.1);
            transform: translateY(100%); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 100;
        }
        .dark .msg-action-menu { background: #1e293b; box-shadow: 0 -4px 20px rgba(0,0,0,0.4); }
        .msg-action-menu.active { transform: translateY(0); }
        .msg-action-backdrop {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.3); z-index: 99;
            opacity: 0; pointer-events: none; transition: opacity 0.3s;
        }
        .msg-action-backdrop.active { opacity: 1; pointer-events: auto; }
        
        .msg-bubble-sent-interactive { cursor: pointer; }
        .msg-deleted-text { font-style: italic; opacity: 0.6; }
<?php if ($role !== 'admin'): ?>
    </style>
</head>
<body class="bg-slate-50 dark:bg-slate-900 text-slate-800 dark:text-slate-200">
<?php else: ?>
    </style>
<?php endif; ?>
<?php if ($role !== 'admin') { require __DIR__ . '/../includes/navbar.php'; } ?>
<div class="chat-layout">
    <!-- ===== SIDEBAR ===== -->
    <div class="chat-sidebar <?= $other_id > 0 ? 'hidden-mobile' : '' ?>" id="convSidebar">
        <div class="p-5" style="border-bottom:1px solid rgba(99,102,241,0.06)">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h1 class="text-xl font-bold" style="color:var(--color-text-primary)"><?= e('Messages') ?></h1>
                    <p class="text-xs mt-0.5" style="color:var(--color-text-muted)"><?= count($conversations) ?> conversations</p>
                </div>
                <?php if ($unread_total > 0): ?>
                    <span class="unread-badge"><?= $unread_total > 99 ? '99+' : $unread_total ?></span>
                <?php endif; ?>
            </div>
            <div class="relative">
                <svg class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" id="convSearch" placeholder="Search conversations..." class="search-box pl-10 pr-4 text-sm" style="color:var(--color-text-primary)">
            </div>
        </div>

        <div class="conv-list flex-1 overflow-y-auto" id="convList">
            <?php if (empty($conversations)): ?>
                <div class="empty-state p-6">
                    <div class="w-20 h-20 rounded-2xl flex items-center justify-center mb-4" style="background:linear-gradient(135deg, rgba(99,102,241,0.08), rgba(139,92,246,0.08))">
                        <svg class="w-10 h-10 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                        </svg>
                    </div>
                    <p class="font-semibold mb-1" style="color:var(--color-text-primary)"><?= e('No active contracts yet.') ?></p>
                    <p class="text-xs" style="color:var(--color-text-muted)"><?= e('Hire a freelancer or get hired to start chatting.') ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($conversations as $conv):
                    $is_active = (int) $conv['other_user_id'] === $other_id;
                    $initial = strtoupper(mb_substr($conv['other_display_name'] ?? $conv['other_username'], 0, 1));
                    $partner_role = $conv['other_role'] ?? '';
                    $time_display = $conv['last_message_time'] ? format_message_time($conv['last_message_time']) : '';
                ?>
                    <a href="<?= e(base_url('chat/index.php?user_id=' . $conv['other_user_id'])) ?>"
                       class="conv-item <?= $is_active ? 'active' : '' ?> relative group"
                       data-name="<?= e(strtolower($conv['other_display_name'] ?? $conv['other_username'])) ?>"
                       data-message="<?= e(strtolower($conv['last_message'] ?? '')) ?>">
                        <div class="relative flex-shrink-0">
                            <?php if (!empty($conv['other_profile_image'])): ?>
                                <img src="<?= e(base_url('uploads/images/' . $conv['other_profile_image'])) ?>" alt="" class="avatar">
                            <?php else: ?>
                                <div class="avatar-initials"><?= $initial ?></div>
                            <?php endif; ?>
                            <span class="online-dot <?= !empty($conv['is_online']) ? 'online' : 'offline' ?>"></span>
                        </div>
                        <div class="flex-1 min-w-0 pr-6">
                            <div class="flex items-center justify-between mb-1">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="font-semibold text-sm truncate" style="color:var(--color-text-primary)"><?= e($conv['other_display_name'] ?? $conv['other_username']) ?></span>
                                    <?php if ($partner_role): ?>
                                        <span class="role-badge <?= $partner_role ?>"><?= $partner_role === 'company' ? 'Co' : ($partner_role === 'freelancer' ? 'Fl' : 'Ad') ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($time_display): ?>
                                    <span class="text-xs flex-shrink-0 ml-2" style="color:var(--color-text-placeholder)"><?= e($time_display) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-xs truncate" style="color:var(--color-text-muted)">
                                    <?= $conv['last_message'] ? e(mb_strimwidth($conv['last_message'], 0, 45, '...')) : '<em>' . e('No messages yet') . '</em>' ?>
                                </span>
                                <?php if ((int) $conv['unread_count'] > 0): ?>
                                    <span class="unread-badge ml-2 flex-shrink-0"><?= (int) $conv['unread_count'] ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 p-2 rounded-lg opacity-0 group-hover:opacity-100 transition-opacity focus:opacity-100 hover:bg-gray-100 dark:hover:bg-slate-800 text-gray-400 hover:text-red-500"
                                onclick="event.preventDefault(); event.stopPropagation(); openListActionMenu(<?= $conv['other_user_id'] ?>, '<?= e(addslashes($conv['other_display_name'] ?? $conv['other_username'])) ?>')" title="Options">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/></svg>
                        </button>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== CHAT MAIN ===== -->
    <div class="chat-main <?= ($other_id > 0 && $partner) ? '' : 'hidden-mobile' ?>" id="chatMain">
        <?php if ($other_id > 0 && $partner): ?>
            <?php $initial = strtoupper(mb_substr($partner['display_name'] ?? $partner['username'], 0, 1)); ?>

            <div class="flex items-center gap-3 px-5 py-3.5" style="background:rgba(255,255,255,0.85);backdrop-filter:blur(20px);border-bottom:1px solid rgba(99,102,241,0.06)">
                <button type="button" class="md:hidden p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" onclick="toggleMobileView()" aria-label="Back">
                    <svg class="w-5 h-5" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <div class="relative flex-shrink-0">
                    <?php if (!empty($partner['profile_image'])): ?>
                        <img src="<?= e(base_url('uploads/images/' . $partner['profile_image'])) ?>" alt="" class="avatar-sm avatar">
                    <?php else: ?>
                        <div class="avatar-sm avatar-initials"><?= $initial ?></div>
                    <?php endif; ?>
                    <span class="online-dot <?= !empty($partner['is_online']) ? 'online' : 'offline' ?>" style="width:10px;height:10px;border-width:2px"></span>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <h2 class="font-semibold text-sm truncate" style="color:var(--color-text-primary)"><?= e($partner['display_name'] ?? $partner['username']) ?></h2>
                        <?php if (!empty($partner['role'])): ?>
                            <span class="role-badge <?= $partner['role'] ?>"><?= e($partner['role']) ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs flex items-center gap-1.5" style="<?= !empty($partner['is_online']) ? 'color:#22c55e' : 'color:var(--color-text-placeholder)' ?>">
                        <span class="w-1.5 h-1.5 rounded-full inline-block" style="background:<?= !empty($partner['is_online']) ? '#22c55e' : '#9ca3af' ?>;box-shadow:<?= !empty($partner['is_online']) ? '0 0 6px rgba(34,197,94,0.5)' : 'none' ?>"></span>
                        <?= !empty($partner['is_online']) ? 'Online now' : 'Offline' ?>
                    </p>
                </div>
                <div class="flex items-center gap-1 relative">
                    <a href="<?= e(base_url('chat/index.php')) ?>" class="p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" style="color:var(--color-text-muted)" title="Refresh">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </a>
                    <button type="button" onclick="document.getElementById('convMenuDropdown').classList.toggle('hidden')" class="p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" style="color:var(--color-text-muted)">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/></svg>
                    </button>
                    <div id="convMenuDropdown" class="hidden absolute right-0 top-full mt-2 w-48 rounded-xl shadow-lg bg-white dark:bg-slate-800 border border-gray-100 dark:border-slate-700 z-50 overflow-hidden">
                        <?php if ($role === 'admin'): ?>
                        <button type="button" onclick="generateTempPassword(<?= $other_id ?>)" class="w-full text-left px-4 py-3 text-sm text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-colors flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                            Generate Temp Password
                        </button>
                        <?php endif; ?>
                        <?php if ($role !== 'admin'): ?>
                            <?php if (!empty($partner['is_blocked']) && !empty($partner['blocked_by_me'])): ?>
                                <button type="button" onclick="showBlockModal('unblock')" class="w-full text-left px-4 py-3 text-sm text-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
                                    Unblock User
                                </button>
                            <?php else: ?>
                                <button type="button" onclick="showBlockModal('block')" class="w-full text-left px-4 py-3 text-sm text-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                    Block User
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                        <button type="button" onclick="showDeleteModal()" class="w-full text-left px-4 py-3 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            Delete Conversation
                        </button>
                    </div>
                </div>
            </div>

            <div class="chat-messages" id="chatMessages">
                <div class="text-center py-8" id="loadingMessages">
                    <div class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full" style="background:rgba(99,102,241,0.06);border:1px solid rgba(99,102,241,0.08)">
                        <div class="w-4 h-4 border-2 border-indigo-500 border-t-transparent rounded-full animate-spin"></div>
                        <span class="text-xs font-medium" style="color:var(--color-text-muted)">Loading messages...</span>
                    </div>
                </div>
            </div>

            <div id="typingIndicator" class="px-5 py-2 hidden" style="background:rgba(255,255,255,0.85);backdrop-filter:blur(20px);border-top:1px solid rgba(99,102,241,0.06)">
                <div class="flex items-center gap-2 text-xs font-medium" style="color:var(--color-text-muted)">
                    <div class="typing-dots"><span></span><span></span><span></span></div>
                    <span id="typingText">typing...</span>
                </div>
            </div>

            <?php if (!empty($partner['is_blocked'])): ?>
                <div class="chat-input-area flex items-center justify-center p-4">
                    <div class="text-sm font-medium px-4 py-2 rounded-xl text-red-600 bg-red-50 dark:bg-red-900/20 border border-red-100 dark:border-red-900/30">
                        You cannot send messages to this user.
                    </div>
                </div>
            <?php else: ?>
                <div class="chat-input-area">
                    <form id="messageForm">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="receiver_id" value="<?= $other_id ?>">
                        <div class="flex items-end gap-2">
                            <button type="button" id="emojiBtn" class="flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center transition-colors" style="color:var(--color-text-muted);background:none;border:none;cursor:pointer;" title="Emoji">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </button>
                            <button type="button" id="attachBtn" class="flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center transition-colors" style="color:var(--color-text-muted);background:none;border:none;cursor:pointer;" title="Attach file">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                            </button>
                            <input type="file" id="fileInput" name="attachment" style="display:none" accept="image/*,.pdf,.docx,.zip,.rar,.txt,.csv,.xlsx,.pptx">
                            <div class="flex-1 min-w-0 flex flex-col relative">
                                <div id="editModeIndicator" class="hidden flex items-center justify-between px-2 pb-1">
                                    <span class="text-xs font-medium text-indigo-600 dark:text-indigo-400">Editing message</span>
                                    <button type="button" onclick="cancelEdit()" class="text-xs text-gray-500 hover:text-red-500 transition-colors">Cancel</button>
                                </div>
                                <textarea id="messageInput" rows="1" placeholder="<?= e('Type a message...') ?>"
                                    class="w-full px-4 py-3 text-sm rounded-2xl resize-none focus:outline-none transition-all"
                                    style="max-height:120px; background:rgba(255,255,255,0.7); border:1px solid rgba(99,102,241,0.08); color:var(--color-text-primary); backdrop-filter:blur(8px);"
                                    oninput="autoResize(this)"></textarea>
                            </div>
                            <button type="submit" class="send-btn" id="sendBtn" disabled>
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                            </button>
                        </div>
                    </form>
                    <div id="filePreview" class="hidden mt-2"></div>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="empty-state">
                <div class="w-28 h-28 rounded-3xl flex items-center justify-center mb-6" style="background:linear-gradient(135deg, rgba(99,102,241,0.06), rgba(139,92,246,0.06))">
                    <svg class="w-14 h-14 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                    </svg>
                </div>
                <h2 class="text-xl font-bold mb-2" style="color:var(--color-text-primary)"><?= e('Your Messages') ?></h2>
                <p class="text-sm max-w-xs" style="color:var(--color-text-muted)"><?= e('Select a person to start chatting') ?></p>
                <div class="mt-6 flex items-center gap-2 text-xs px-4 py-2 rounded-xl" style="color:var(--color-text-placeholder);background:rgba(99,102,241,0.04);border:1px solid rgba(99,102,241,0.06)">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Chat is available after a post is accepted or you're hired
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Bottom Action Menu -->
<div class="msg-action-backdrop" id="msgActionBackdrop" onclick="closeActionMenu()"></div>
<div class="msg-action-menu md:max-w-md md:mx-auto md:bottom-4 md:rounded-2xl" id="msgActionMenu">
    <div class="w-12 h-1.5 bg-gray-300 dark:bg-gray-600 rounded-full mx-auto mb-5"></div>
    <div class="flex flex-col gap-2">
        <button onclick="triggerEdit()" class="flex items-center gap-3 w-full p-4 rounded-xl hover:bg-gray-100 dark:hover:bg-slate-700 transition-colors text-left" style="color:var(--color-text-primary)">
            <svg class="w-5 h-5 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
            <span class="font-medium">Edit Message</span>
        </button>

        <button onclick="triggerDeleteMessage()" class="flex items-center gap-3 w-full p-4 rounded-xl hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors text-left text-red-600">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            <span class="font-medium">Delete Message</span>
        </button>

        <button onclick="closeActionMenu()" class="mt-2 p-3 text-center text-sm font-medium w-full text-gray-500 hover:bg-gray-100 dark:hover:bg-slate-800 rounded-xl transition-colors">Cancel</button>
    </div>
</div>

<!-- List Action Menu -->
<div class="msg-action-backdrop" id="listActionBackdrop" onclick="closeListActionMenu()"></div>
<div class="msg-action-menu md:max-w-md md:mx-auto md:bottom-4 md:rounded-2xl" id="listActionMenu" style="z-index: 101;">
    <div class="w-12 h-1.5 bg-gray-300 dark:bg-gray-600 rounded-full mx-auto mb-5"></div>
    <div class="mb-4 text-center px-4">
        <h3 class="text-base font-semibold truncate" style="color:var(--color-text-primary)">Conversation with <span id="listActionName"></span></h3>
        <p class="text-xs mt-1" style="color:var(--color-text-muted)">Select an action below</p>
    </div>
    <div class="flex flex-col gap-2">
        <button onclick="triggerListDelete()" class="flex items-center gap-3 w-full p-4 rounded-xl hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors text-left text-red-600">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            <span class="font-medium">Delete Conversation</span>
        </button>
        <button onclick="closeListActionMenu()" class="mt-2 p-3 text-center text-sm font-medium w-full text-gray-500 hover:bg-gray-100 dark:hover:bg-slate-800 rounded-xl transition-colors">Cancel</button>
    </div>
</div>

<div id="deleteModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="hideDeleteModal()"></div>
    <div class="relative bg-white dark:bg-slate-900 rounded-2xl w-full max-w-sm p-6 shadow-2xl">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Delete Conversation</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">Delete this conversation? All chat history with this user will be removed from your messages.</p>
        <div class="flex items-center gap-3 justify-end">
            <button type="button" onclick="hideDeleteModal()" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-xl transition-colors">Cancel</button>
            <button type="button" onclick="confirmDeleteConversation()" class="px-4 py-2 text-sm font-medium text-white bg-red-600 hover:bg-red-700 rounded-xl transition-colors">Delete Conversation</button>
        </div>
    </div>
</div>

<div id="blockModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="hideBlockModal()"></div>
    <div class="relative bg-white dark:bg-slate-900 rounded-2xl w-full max-w-sm p-6 shadow-2xl">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2" id="blockModalTitle">Block User</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-6" id="blockModalText">Are you sure you want to block this user?</p>
        <div class="flex items-center gap-3 justify-end">
            <button type="button" onclick="hideBlockModal()" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-xl transition-colors">Cancel</button>
            <button type="button" onclick="confirmBlockUser()" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-colors" id="blockModalBtn">Block User</button>
        </div>
    </div>
</div>

<script>
(function() {
    <?php if ($other_id > 0 && $partner): ?>
    var currentUserId = <?= $user_id ?>;
    var otherUserId = <?= $other_id ?>;
    var csrfToken = '<?= e(csrf_token()) ?>';
    var isAdmin = <?= $role === 'admin' ? 'true' : 'false' ?>;
    var baseUrl = '<?= e(base_url('')) ?>';
    var pollInterval = null;
    var lastMessageIds = '';
    var typingTimeout = null;
    var isTyping = false;
    
    // --- EDIT / DELETE STATE ---
    var editingMessageId = null;
    var actionMenuTargetId = null;
    var actionMenuTargetText = '';

    function escapeHtml(text) {
        if (!text) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(text));
        return d.innerHTML;
    }

    function formatTime(ts) {
        if (!ts) return '';
        var hhmm = ts.substring(11, 16);
        var h = parseInt(hhmm.substring(0, 2), 10);
        var m = hhmm.substring(3, 5);
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return h + ':' + m + ' ' + ampm;
    }

    function formatDateSeparator(ts) {
        if (!ts) return '';
        var d = new Date(ts);
        var today = new Date();
        var yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);
        if (d.toDateString() === today.toDateString()) return 'Today';
        if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    }

    function buildAttachmentHtml(msg) {
        var html = '';
        if (msg.attachments && msg.attachments.length > 0) {
            msg.attachments.forEach(function(file) {
                var ext = (file.file_name.split('.').pop() || '').toLowerCase();
                var isImage = ['jpg','jpeg','png','gif','webp','svg'].indexOf(ext) !== -1;
                var fileUrl = file.file_url || (baseUrl + 'uploads/' + (file.file_path || ''));
                var displaySize = file.file_size_formatted || '';

                html += '<div class="mb-1">';
                if (isImage && fileUrl) {
                    html += '<a href="' + fileUrl + '" target="_blank" class="block rounded-xl overflow-hidden border mb-1" style="border-color:rgba(99,102,241,0.08)">';
                    html += '<img src="' + fileUrl + '" alt="' + escapeHtml(file.file_name) + '" class="msg-img-attachment w-full" loading="lazy">';
                    html += '</a>';
                }
                html += '<a href="' + fileUrl + '" target="_blank" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium transition-colors" style="background:rgba(255,255,255,0.15);color:inherit">';
                html += '<span>' + (isImage ? '🖼️' : '📎') + '</span>';
                html += '<span>' + escapeHtml(file.file_name) + '</span>';
                if (displaySize) html += '<span class="opacity-60">(' + displaySize + ')</span>';
                html += '</a></div>';
            });
        }
        return html;
    }

    function buildMessageHtml(msg) {
        var isSent = parseInt(msg.sender_id) === currentUserId;
        var time = formatTime(msg.created_at);
        var name = msg.sender_username || 'Unknown';
        var isSystem = msg.message_type === 'system';
        var hasAttachments = msg.attachments && msg.attachments.length > 0;

        if (isSystem) {
            return '<div class="text-center my-4"><span class="inline-block px-4 py-1.5 text-xs rounded-full font-medium" style="background:rgba(99,102,241,0.06);color:var(--color-text-muted);border:1px solid rgba(99,102,241,0.08)">' + escapeHtml(msg.message) + '</span></div>';
        }

        var isDeleted = msg.is_deleted == 1;
        var isEdited = msg.is_edited == 1;
        var interactiveAttrs = (isSent && !isDeleted) ? ' onclick="openActionMenu(this, ' + msg.id + ')" oncontextmenu="openActionMenu(this, ' + msg.id + '); return false;" class="mb-4 flex items-end gap-2.5 justify-end msg-bubble-sent-interactive"' : ' class="mb-4 flex items-end gap-2.5 ' + (isSent ? 'justify-end' : 'justify-start') + '"';

        var html = '<div' + interactiveAttrs + ' data-id="' + msg.id + '">';

        if (!isSent) {
            html += '<div class="flex-shrink-0 mb-1">';
            if (msg.sender_profile_image) {
                html += '<img src="<?= e(base_url('uploads/images/')) ?>' + escapeHtml(msg.sender_profile_image) + '" class="avatar-xs" style="width:28px;height:28px;border-radius:10px;object-fit:cover">';
            } else {
                html += '<div class="avatar-xs avatar-initials" style="width:28px;height:28px;font-size:11px;border-radius:10px">' + escapeHtml(name.charAt(0).toUpperCase()) + '</div>';
            }
            html += '</div>';
        }

        html += '<div class="msg-col">';
        if (!isSent) {
            html += '<p class="text-[11px] mb-1.5 ml-1 font-semibold tracking-wide uppercase" style="color:var(--color-text-muted)">' + escapeHtml(name) + '</p>';
        }
        html += '<div class="msg-bubble ' + (isSent ? 'msg-sent' : 'msg-received') + '">';
        
        if (isDeleted) {
            html += '<div class="text-[15px] leading-relaxed msg-deleted-text flex items-center gap-2"><svg class="w-4 h-4 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 5.636a9 9 0 11-12.728 0m12.728 0L5.636 18.364"/></svg>' + escapeHtml(msg.message) + '</div>';
        } else {
            if (hasAttachments) {
                html += buildAttachmentHtml(msg);
            }
            if (msg.message && typeof msg.message === 'string' && msg.message.trim() !== '') {
                html += '<div class="text-[15px] leading-relaxed ' + (hasAttachments ? 'mt-2' : '') + ' msg-text-content">' + escapeHtml(msg.message) + '</div>';
            }
        }

        html += '<div class="flex items-center gap-1.5 mt-2 ' + (isSent ? 'justify-end' : 'justify-start') + '">';
        if (isEdited && !isDeleted) {
            html += '<span class="text-[11px] opacity-60 italic mr-1">(Edited)</span>';
        }
        html += '<span class="text-[11px] opacity-50">' + time + '</span>';
        if (isSent) {
            html += '<span class="text-[11px]" style="color:' + (msg.status === 'read' ? 'rgba(196,181,253,0.9)' : 'rgba(196,181,253,0.5)') + '">' + (msg.status === 'read' ? '\u2713\u2713' : '\u2713') + '</span>';
        }
        html += '</div></div></div></div>';
        return html;
    }

    function loadMessages(scrollToBottom) {
        fetch('<?= e(base_url('api/chat.php')) ?>?action=get_messages&user_id=' + otherUserId, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var loading = document.getElementById('loadingMessages');
            if (loading) loading.style.display = 'none';

            var container = document.getElementById('chatMessages');

            if (!data.messages || data.messages.length === 0) {
                container.innerHTML = '<div class="empty-state py-12"><div class="w-16 h-16 rounded-2xl flex items-center justify-center mb-3" style="background:linear-gradient(135deg, rgba(99,102,241,0.06), rgba(139,92,246,0.06))"><svg class="w-8 h-8 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg></div><p class="text-sm font-medium" style="color:var(--color-text-muted)">No messages yet</p><p class="text-xs mt-1" style="color:var(--color-text-placeholder)">Say hello!</p></div>';
                lastMessageIds = '';
                return;
            }

            var newIds = data.messages.map(function(m) { return m.id + '_' + m.is_deleted + '_' + m.is_edited; }).join(',');
            if (newIds === lastMessageIds) return;
            lastMessageIds = newIds;

            var wasAtBottom = container.scrollTop + container.clientHeight >= container.scrollHeight - 80;
            var prevScrollTop = container.scrollTop;
            var prevScrollHeight = container.scrollHeight;

            var html = '';
            var lastDate = '';
            data.messages.forEach(function(msg) {
                var msgDate = formatDateSeparator(msg.created_at);
                if (msgDate !== lastDate) {
                    html += '<div class="date-separator">' + msgDate + '</div>';
                    lastDate = msgDate;
                }
                html += buildMessageHtml(msg);
            });

            container.innerHTML = html;

            if (scrollToBottom === true) {
                container.scrollTop = container.scrollHeight;
            } else if (wasAtBottom) {
                container.scrollTop = container.scrollHeight;
            } else {
                container.scrollTop = prevScrollTop + (container.scrollHeight - prevScrollHeight);
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?= e(base_url('api/chat.php')) ?>', true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.send(JSON.stringify({ action: 'mark_read', user_id: otherUserId, csrf_token: csrfToken }));
        })
        .catch(function(err) {
            console.error('Chat error:', err);
        });
    }

    function sendTyping(isTypingNow) {
        if (typeof ChatWS !== 'undefined' && ChatWS.isConnected && ChatWS.isConnected() && !ChatWS.isFallback()) {
            ChatWS.send({ action: 'typing', partner_id: otherUserId, is_typing: isTypingNow ? 1 : 0 });
        } else {
            fetch('<?= e(base_url('api/chat.php')) ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action: 'set_typing', partner_id: otherUserId, is_typing: isTypingNow ? 1 : 0, csrf_token: csrfToken })
            }).catch(function() {});
        }
    }

    function showTypingIndicator(partnerName) {
        var el = document.getElementById('typingIndicator');
        if (el) {
            el.classList.remove('hidden');
            document.getElementById('typingText').textContent = partnerName + ' typing...';
        }
    }

    function hideTypingIndicator() {
        var el = document.getElementById('typingIndicator');
        if (el) el.classList.add('hidden');
    }

    function clearSendInput() {
        var input = document.getElementById('messageInput');
        input.value = '';
        input.style.height = 'auto';
        input.style.overflow = 'hidden';
        var btn = document.getElementById('sendBtn');
        btn.disabled = true;
        btn.innerHTML = '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>';
        var fi = document.getElementById('fileInput');
        if (fi) { fi.value = ''; }
        clearFilePreview();
        isTyping = false;
        sendTyping(false);
    }

    function showInlineError(msg) {
        var inputArea = document.querySelector('.chat-input-area');
        if (!inputArea) return;
        var existing = inputArea.querySelector('.inline-error');
        if (existing) existing.remove();
        var el = document.createElement('div');
        el.className = 'inline-error text-xs text-red-500 dark:text-red-400 mt-2 text-center';
        el.textContent = msg;
        inputArea.appendChild(el);
        setTimeout(function() { if (el.parentNode) el.remove(); }, 3000);
    }

    function sendMessage(e) {
        if (e) e.preventDefault();
        var input = document.getElementById('messageInput');
        var message = input.value.trim();
        var fileInput = document.getElementById('fileInput');
        var file = fileInput && fileInput.files.length > 0 ? fileInput.files[0] : null;

        if (!message && !file) {
            // Truly empty — reset button state and abort silently
            btn.innerHTML = '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>';
            btn.disabled = true;
            return;
        }

        var sentMessage = message;
        var sentFile = file;

        var btn = document.getElementById('sendBtn');
        btn.disabled = true;
        btn.innerHTML = '<div class="w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin"></div>';
        input.value = '';
        input.style.height = 'auto';
        input.style.overflow = 'hidden';
        if (fileInput) fileInput.value = '';
        clearFilePreview();
        input.focus();
        
        if (editingMessageId) {
            fetch('<?= e(base_url('api/chat.php')) ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action: 'edit_message', message_id: editingMessageId, message: sentMessage, csrf_token: csrfToken })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    loadMessages(true);
                    cancelEdit();
                } else {
                    showInlineError(data.error || 'Failed to edit message');
                }
            })
            .catch(function(err) {
                showInlineError('Connection error. Please try again.');
            })
            .finally(function() {
                btn.innerHTML = '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>';
                btn.disabled = false;
            });
            return;
        }

        if (sentFile) {
            var formData = new FormData();
            formData.append('action', 'send_message');
            formData.append('receiver_id', otherUserId);
            formData.append('message', sentMessage);
            formData.append('attachment', sentFile);
            formData.append('csrf_token', csrfToken);
            formData.append('message_type', 'file');

            fetch('<?= e(base_url('api/chat.php')) ?>', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(function(r) {
                if (!r.ok) {
                    return r.text().then(function(text) {
                        var msg = 'Failed to send file';
                        try { var j = JSON.parse(text); msg = j.error || j.detail || msg; } catch(e) {}
                        throw new Error(msg);
                    });
                }
                return r.json();
            })
            .then(function(data) {
                if (data.success) {
                    loadMessages(true);
                } else {
                    showInlineError(data.error || 'Failed to send file');
                }
            })
            .catch(function(err) {
                showInlineError(err.message || 'Upload failed. Please try again.');
                console.error('Upload error:', err);
            })
            .finally(function() {
                btn.innerHTML = '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>';
                btn.disabled = false;
            });
        } else if (typeof ChatWS !== 'undefined' && ChatWS.isConnected && ChatWS.isConnected() && !ChatWS.isFallback()) {
            ChatWS.send({
                action: 'message',
                receiver_id: otherUserId,
                message: sentMessage,
                message_type: 'text',
                temp_id: 'tmp_' + Date.now()
            });
            btn.innerHTML = '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>';
            btn.disabled = false;
        } else {
            fetch('<?= e(base_url('api/chat.php')) ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action: 'send_message', receiver_id: otherUserId, message: sentMessage, csrf_token: csrfToken })
            })
            .then(function(r) {
                if (!r.ok) {
                    return r.text().then(function(text) {
                        var msg = 'Failed to send message';
                        try { var j = JSON.parse(text); msg = j.error || j.detail || msg; } catch(e) {}
                        throw new Error(msg);
                    });
                }
                return r.json();
            })
            .then(function(data) {
                if (data.success) {
                    loadMessages(true);
                } else {
                    showInlineError(data.error || 'Failed to send message');
                }
            })
            .catch(function(err) {
                showInlineError(err.message || 'Connection error. Please try again.');
                console.error('Send error:', err);
            })
            .finally(function() {
                btn.innerHTML = '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>';
                btn.disabled = false;
            });
        }
    }

    function autoResize(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 120) + 'px';
        var btn = document.getElementById('sendBtn');
        var fileInput = document.getElementById('fileInput');
        btn.disabled = !el.value.trim() && (!fileInput || fileInput.files.length === 0);

        if (el.value.trim() && !isTyping) {
            isTyping = true;
            sendTyping(true);
        }
        if (typingTimeout) clearTimeout(typingTimeout);
        typingTimeout = setTimeout(function() {
            if (isTyping) {
                isTyping = false;
                sendTyping(false);
            }
        }, 2000);
    }
    window.autoResize = autoResize;

        // EMOJI PICKER ===
    var emojiBtn = document.getElementById('emojiBtn');
    if (emojiBtn) {
        emojiBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (typeof EmojiPicker !== 'undefined' && EmojiPicker.toggle) {
                EmojiPicker.toggle(emojiBtn, function(emoji) {
                    var input = document.getElementById('messageInput');
                    var start = input.selectionStart;
                    var end = input.selectionEnd;
                    var text = input.value;
                    input.value = text.substring(0, start) + emoji + text.substring(end);
                    input.selectionStart = input.selectionEnd = start + emoji.length;
                    input.focus();
                    autoResize(input);
                });
            }
        });
    }

    // === FILE ATTACHMENT ===
    var attachBtn = document.getElementById('attachBtn');
    var fileInput = document.getElementById('fileInput');
    if (attachBtn && fileInput) {
        attachBtn.addEventListener('click', function() {
            fileInput.click();
        });
        fileInput.addEventListener('change', function() {
            var file = this.files[0];
            if (!file) { clearFilePreview(); return; }
            showFilePreview(file);
            document.getElementById('sendBtn').disabled = false;
        });
    }

    function showFilePreview(file) {
        var preview = document.getElementById('filePreview');
        if (!preview) return;
        preview.classList.remove('hidden');
        var isImage = file.type.startsWith('image/');
        var size = (file.size / 1024).toFixed(1) + ' KB';
        if (file.size > 1048576) size = (file.size / 1048576).toFixed(1) + ' MB';

        var html = '<div class="flex items-center gap-3 p-2.5 rounded-xl" style="background:rgba(99,102,241,0.04);border:1px solid rgba(99,102,241,0.08)">';
        if (isImage) {
            html += '<div class="w-10 h-10 rounded-lg overflow-hidden flex-shrink-0" style="background:rgba(99,102,241,0.06)"><img src="' + URL.createObjectURL(file) + '" class="w-full h-full object-cover"></div>';
        } else {
            html += '<div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 text-lg" style="background:linear-gradient(135deg, rgba(99,102,241,0.06), rgba(139,92,246,0.06))">📎</div>';
        }
        html += '<div class="flex-1 min-w-0"><p class="text-xs font-medium truncate" style="color:var(--color-text-primary)">' + escapeHtml(file.name) + '</p><p class="text-xs" style="color:var(--color-text-muted)">' + size + '</p></div>';
        html += '<button type="button" onclick="clearFilePreview()" class="flex-shrink-0 p-1.5 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" style="color:var(--color-text-muted)"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>';
        html += '</div>';
        preview.innerHTML = html;
    }

    window.clearFilePreview = function() {
        var preview = document.getElementById('filePreview');
        if (preview) { preview.classList.add('hidden'); preview.innerHTML = ''; }
        if (fileInput) fileInput.value = '';
        var btn = document.getElementById('sendBtn');
        btn.disabled = !document.getElementById('messageInput').value.trim();
    };

    // === EDIT / DELETE LOGIC ===
    window.openActionMenu = function(el, msgId) {
        actionMenuTargetId = msgId;
        var textNode = el.querySelector('.msg-text-content');
        actionMenuTargetText = textNode ? textNode.textContent : '';
        document.getElementById('msgActionMenu').classList.add('active');
        document.getElementById('msgActionBackdrop').classList.add('active');
    };

    window.closeActionMenu = function() {
        document.getElementById('msgActionMenu').classList.remove('active');
        document.getElementById('msgActionBackdrop').classList.remove('active');
        actionMenuTargetId = null;
        actionMenuTargetText = '';
    };

    window.triggerEdit = function() {
        if (!actionMenuTargetId) return;
        editingMessageId = actionMenuTargetId;
        var input = document.getElementById('messageInput');
        input.value = actionMenuTargetText;
        document.getElementById('editModeIndicator').classList.remove('hidden');
        closeActionMenu();
        autoResize(input);
        input.focus();
    };

    window.triggerDeleteMessage = function() {
        if (!actionMenuTargetId) return;
        var msgId = actionMenuTargetId;
        closeActionMenu();

        if (!confirm('Are you sure you want to delete this message?')) return;

        fetch('<?= e(base_url('api/chat.php')) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: 'delete_message', message_id: msgId, csrf_token: csrfToken })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                // Immediately update the deleted message bubble in-place for instant visual feedback.
                // Use a safe querySelector with properly escaped Tailwind bracket class.
                var bubble = document.querySelector('[data-id="' + msgId + '"]');
                if (bubble) {
                    var inner = bubble.querySelector('.msg-bubble');
                    if (inner) {
                        // Read the timestamp BEFORE overwriting innerHTML.
                        // The Tailwind class text-[11px] must be escaped as text-\[11px\] in querySelector.
                        var timeEl = inner.querySelector('span.text-\\[11px\\]');
                        var timeText = timeEl ? timeEl.textContent : '';

                        inner.innerHTML =
                            '<div class="text-[15px] leading-relaxed msg-deleted-text flex items-center gap-2">' +
                            '<svg class="w-4 h-4 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 5.636a9 9 0 11-12.728 0m12.728 0L5.636 18.364"/></svg>' +
                            'This message was deleted</div>' +
                            '<div class="flex items-center gap-1.5 mt-2 justify-end">' +
                            '<span class="text-[11px] opacity-50">' + timeText + '</span>' +
                            '</div>';

                        // Strip click/context handlers so the deleted bubble can't be tapped again
                        bubble.removeAttribute('onclick');
                        bubble.removeAttribute('oncontextmenu');
                        bubble.classList.remove('msg-bubble-sent-interactive');
                    }
                }
                // Reload in the background to sync the exact server state for both sides
                loadMessages(false);
            } else {
                alert(data.error || 'Failed to delete message. Please try again.');
            }
        })
        .catch(function(err) {
            console.error('Delete error:', err);
            alert('Could not delete message. Please check your connection and try again.');
        });
    };


    window.cancelEdit = function() {
        editingMessageId = null;
        document.getElementById('editModeIndicator').classList.add('hidden');
        var input = document.getElementById('messageInput');
        input.value = '';
        autoResize(input);
    };

    window.showDeleteModal = function() {
        if (document.getElementById('convMenuDropdown')) {
            document.getElementById('convMenuDropdown').classList.add('hidden');
        }
        document.getElementById('deleteModal').classList.remove('hidden');
    };

    window.hideDeleteModal = function() {
        document.getElementById('deleteModal').classList.add('hidden');
    };

    window.confirmDeleteConversation = function() {
        fetch('<?= e(base_url('api/chat.php')) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: 'delete_conversation', partner_id: otherUserId, csrf_token: csrfToken })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                window.location.href = '<?= e(base_url('chat/index.php')) ?>';
            } else {
                alert('Error deleting conversation.');
            }
        });
    };
    
    var currentBlockAction = 'block';
    
    window.showBlockModal = function(action) {
        if (document.getElementById('convMenuDropdown')) {
            document.getElementById('convMenuDropdown').classList.add('hidden');
        }
        currentBlockAction = action;
        if (action === 'unblock') {
            document.getElementById('blockModalTitle').textContent = 'Unblock User';
            document.getElementById('blockModalText').textContent = 'Are you sure you want to unblock this user?';
            document.getElementById('blockModalBtn').textContent = 'Unblock User';
        } else {
            document.getElementById('blockModalTitle').textContent = 'Block User';
            document.getElementById('blockModalText').textContent = 'Are you sure you want to block this user? You will not be able to send or receive messages.';
            document.getElementById('blockModalBtn').textContent = 'Block User';
        }
        document.getElementById('blockModal').classList.remove('hidden');
    };

    window.hideBlockModal = function() {
        document.getElementById('blockModal').classList.add('hidden');
    };

    window.confirmBlockUser = function() {
        var apiAction = currentBlockAction === 'block' ? 'block_user' : 'unblock_user';
        fetch('<?= e(base_url('api/chat.php')) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: apiAction, partner_id: otherUserId, csrf_token: csrfToken })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                window.location.reload();
            } else {
                alert('Error processing request.');
            }
        });
    };

    // === WEBSOCKET EVENTS ===
    if (typeof ChatWS !== 'undefined') {
        ChatWS.init({
            user_id: currentUserId,
            csrf_token: csrfToken,
            ws_url: 'ws://localhost:8080',
            fallback: false
        });

        ChatWS.on('new_message', function(data) {
            if (data.from_user_id === otherUserId) {
                loadMessages(true);
            }
        });

        ChatWS.on('message_sent', function(data) {
            loadMessages(true);
        });

        ChatWS.on('typing', function(data) {
            if (data.user_id === otherUserId) {
                if (data.is_typing) {
                    showTypingIndicator('<?= e($partner['display_name'] ?? $partner['username']) ?>');
                } else {
                    hideTypingIndicator();
                }
            }
        });

        ChatWS.on('user_status', function(data) {
            if (data.user_id === otherUserId) {
                var statusEl = document.querySelector('.chat-main .online-dot');
                if (statusEl) {
                    statusEl.className = 'online-dot ' + (data.is_online ? 'online' : 'offline');
                }
            }
        });
    }

    // === SCROLL MARK READ ===
    var chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        chatMessages.addEventListener('scroll', function() {
            if (this.scrollTop + this.clientHeight >= this.scrollHeight - 100) {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?= e(base_url('api/chat.php')) ?>', true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.send(JSON.stringify({ action: 'mark_read', user_id: otherUserId, csrf_token: csrfToken }));
            }
        });
    }

    // === INIT ===
    loadMessages(true);
    document.getElementById('messageForm').addEventListener('submit', sendMessage);
    document.getElementById('messageInput').addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(e);
        }
    });
    document.getElementById('messageInput').focus();

    pollInterval = setInterval(function() {
        if (typeof ChatWS === 'undefined' || ChatWS.isFallback() || !ChatWS.isConnected()) {
            loadMessages(false);
        }
    }, 2000);

    window.addEventListener('beforeunload', function() {
        if (pollInterval) clearInterval(pollInterval);
        if (typeof ChatWS !== 'undefined' && ChatWS.disconnect) ChatWS.disconnect();
    });
    <?php endif; ?>

    // Search
    var searchInput = document.getElementById('convSearch');
    if (searchInput) {
        var searchTimeout;
        searchInput.addEventListener('input', function() {
            var q = this.value.trim().toLowerCase();
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                document.querySelectorAll('.conv-item').forEach(function(item) {
                    var name = item.getAttribute('data-name') || '';
                    var msg = item.getAttribute('data-message') || '';
                    item.style.display = (q === '' || name.indexOf(q) !== -1 || msg.indexOf(q) !== -1) ? '' : 'none';
                });
            }, 200);
        });
    }

    window.toggleMobileView = function() {
        var sidebar = document.getElementById('convSidebar');
        var main = document.getElementById('chatMain');
        if (sidebar && main) {
            sidebar.classList.toggle('hidden-mobile');
            main.classList.toggle('hidden-mobile');
        }
    };

    // === LIST ACTION MENU LOGIC ===
    window.listActionTargetId = null;

    window.openListActionMenu = function(partnerId, partnerName) {
        window.listActionTargetId = partnerId;
        var nameEl = document.getElementById('listActionName');
        if (nameEl) nameEl.textContent = partnerName;
        document.getElementById('listActionMenu').classList.add('active');
        document.getElementById('listActionBackdrop').classList.add('active');
    };

    window.closeListActionMenu = function() {
        document.getElementById('listActionMenu').classList.remove('active');
        document.getElementById('listActionBackdrop').classList.remove('active');
        window.listActionTargetId = null;
    };

    window.triggerListDelete = function() {
        if (!window.listActionTargetId) return;
        var target = window.listActionTargetId;
        closeListActionMenu();
        
        if (!confirm('Delete this conversation? All chat history with this user will be removed from your messages.')) return;
        
        var csrf = '<?= e(csrf_token()) ?>';
        fetch('<?= e(base_url('api/chat.php')) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: 'delete_conversation', partner_id: target, csrf_token: csrf })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                if (typeof otherUserId !== 'undefined' && target === otherUserId) {
                    window.location.href = '<?= e(base_url('chat/index.php')) ?>';
                } else {
                    window.location.reload();
                }
            }
        });
    };

    window.generateTempPassword = function(userId) {
        var csrf = '<?= e(csrf_token()) ?>';
        fetch('<?= e(base_url('admin/api_recovery.php')) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: 'generate_temp_password', user_id: userId, csrf_token: csrf })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showTempPasswordModal(userId, data.temp_password, csrf);
            } else {
                alert('Error: ' + (data.error || 'Failed to generate temporary password'));
            }
        }).catch(function(err) {
            alert('Error generating temporary password.');
        });
    };

    function showTempPasswordModal(userId, tempPassword, csrf) {
        var existing = document.getElementById('tempPwdModal');
        if (existing) existing.remove();

        var modal = document.createElement('div');
        modal.id = 'tempPwdModal';
        modal.className = 'fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4';
        modal.innerHTML = `
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-sm p-6">
                <h3 class="text-lg font-bold text-slate-800 dark:text-slate-100 mb-4">Temporary Password Generated</h3>
                <div class="bg-slate-50 dark:bg-slate-900 p-4 rounded-lg mb-4 text-center select-all cursor-text border border-slate-200 dark:border-slate-700">
                    <span class="font-mono text-xl text-indigo-600 dark:text-indigo-400 font-bold" id="tempPwdText">${tempPassword}</span>
                </div>
                <p class="text-xs text-slate-500 mb-6 text-center text-red-500 font-semibold">Please communicate this to the user manually.</p>
                <div class="flex flex-col gap-2">
                    <button type="button" id="copyPwdBtn" class="w-full px-4 py-2 bg-indigo-600 text-white hover:bg-indigo-700 rounded-lg font-medium transition-colors shadow-sm shadow-indigo-600/20">
                        Copy Password
                    </button>
                    <button type="button" id="closePwdBtn" class="w-full px-4 py-2 bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-700 dark:text-slate-200 dark:hover:bg-slate-600 rounded-lg font-medium transition-colors mt-2">
                        Done
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        document.getElementById('copyPwdBtn').onclick = function() {
            navigator.clipboard.writeText(tempPassword).then(function() {
                var btn = document.getElementById('copyPwdBtn');
                var orig = btn.innerText;
                btn.innerText = 'Copied!';
                setTimeout(function() { btn.innerText = orig; }, 2000);
            });
        };

        document.getElementById('closePwdBtn').onclick = function() {
            modal.remove();
            if (typeof window.loadMessages === 'function') {
                window.loadMessages();
            } else {
                window.location.reload();
            }
        };
    }
})();
</script>
<?php if ($role !== 'admin'): ?>
</body>
</html>
<?php else: ?>
<?php require __DIR__ . '/../admin/includes/admin_footer.php'; ?>
<?php endif; ?>
