<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/chat.php';

require_login();

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['company', 'freelancer', 'admin'], true)) {
    set_flash('error', __('error.no_permission'));
    redirect('index.php');
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

$page_title = __('chat.title');
$unread_total = get_unread_count($conn, $user_id);
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>" data-theme>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?> - <?= e(__('app.name')) ?></title>
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
        theme: {
            extend: {
                fontFamily: { poppins: ['Poppins', 'sans-serif'] },
            }
        }
    };
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/custom.css')) ?>">
    <style>
        *, *::before, *::after { font-family: 'Poppins', system-ui, sans-serif; }
        html, body { height: 100%; margin: 0; overflow: hidden; }

        /* Layout */
        .chat-layout { height: calc(100vh - 4rem); display: flex; }

        /* Sidebar */
        .chat-sidebar {
            width: 380px; min-width: 380px;
            display: flex; flex-direction: column;
            background: var(--color-card);
            border-right: 1px solid var(--color-border);
            transition: transform 0.3s ease;
        }

        /* Main */
        .chat-main {
            flex: 1; display: flex; flex-direction: column;
            background: var(--color-bg); min-width: 0;
        }

        /* Conversation item */
        .conv-item {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 20px;
            border-bottom: 1px solid var(--color-border);
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            color: inherit;
        }
        .conv-item:hover { background: var(--color-card-hover); }
        .conv-item.active {
            background: linear-gradient(135deg, rgba(99,102,241,0.08), rgba(139,92,246,0.06));
            border-left: 3px solid #6366f1;
        }

        /* Avatar */
        .avatar {
            width: 48px; height: 48px; border-radius: 50%;
            object-fit: cover; flex-shrink: 0;
            border: 2px solid transparent;
            background: linear-gradient(var(--color-card), var(--color-card)) padding-box,
                        linear-gradient(135deg, #6366f1, #a855f7) border-box;
        }
        .avatar-initials {
            width: 48px; height: 48px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 18px; flex-shrink: 0;
            background: linear-gradient(135deg, #6366f1, #a855f7);
            color: white;
        }
        .avatar-sm { width: 36px; height: 36px; font-size: 14px; }
        .avatar-xs { width: 28px; height: 28px; font-size: 11px; }

        /* Online indicator */
        .online-dot {
            width: 12px; height: 12px; border-radius: 50%;
            position: absolute; bottom: 0; right: 0;
            border: 2px solid var(--color-card);
        }
        .online-dot.online { background: #22c55e; }
        .online-dot.offline { background: #9ca3af; }

        /* Messages container */
        .chat-messages {
            flex: 1; overflow-y: auto; padding: 20px;
            background: linear-gradient(180deg, var(--color-bg) 0%, rgba(99,102,241,0.02) 100%);
        }

        /* Message bubble */
        .msg-bubble {
            max-width: 70%; padding: 10px 16px;
            border-radius: 18px; word-wrap: break-word;
            white-space: pre-wrap; position: relative;
            animation: msgIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        @keyframes msgIn {
            from { opacity: 0; transform: translateY(8px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .msg-sent {
            background: linear-gradient(135deg, #6366f1, #7c3aed);
            color: white; border-bottom-right-radius: 6px;
            margin-left: auto;
            box-shadow: 0 2px 12px rgba(99, 102, 241, 0.25);
        }
        .msg-received {
            background: var(--color-card); color: var(--color-text-primary);
            border: 1px solid var(--color-border); border-bottom-left-radius: 6px;
            margin-right: auto;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
        }

        /* Date separator */
        .date-separator {
            display: flex; align-items: center; gap: 12px;
            margin: 20px 0; font-size: 12px; color: var(--color-text-placeholder);
        }
        .date-separator::before, .date-separator::after {
            content: ''; flex: 1; height: 1px;
            background: var(--color-border);
        }

        /* Input area */
        .chat-input-area {
            border-top: 1px solid var(--color-border);
            padding: 16px 20px; background: var(--color-card);
        }

        /* Search */
        .search-box {
            background: var(--color-bg); border: 1px solid var(--color-border);
            border-radius: 12px; padding: 10px 14px;
            transition: all 0.2s ease;
        }
        .search-box:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
            outline: none;
        }

        /* Send button */
        .send-btn {
            width: 44px; height: 44px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #6366f1, #7c3aed);
            color: white; border: none; cursor: pointer;
            transition: all 0.2s ease; flex-shrink: 0;
        }
        .send-btn:hover { transform: scale(1.05); box-shadow: 0 4px 16px rgba(99, 102, 241, 0.35); }
        .send-btn:disabled { opacity: 0.4; cursor: not-allowed; transform: none; box-shadow: none; }

        /* Empty state */
        .empty-state {
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            height: 100%; text-align: center; padding: 40px;
        }

        /* Unread badge */
        .unread-badge {
            min-width: 20px; height: 20px; padding: 0 6px;
            border-radius: 10px; font-size: 11px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #6366f1, #7c3aed);
            color: white;
        }

        /* Scrollbar */
        .chat-messages::-webkit-scrollbar,
        .conv-list::-webkit-scrollbar { width: 5px; }
        .chat-messages::-webkit-scrollbar-track,
        .conv-list::-webkit-scrollbar-track { background: transparent; }
        .chat-messages::-webkit-scrollbar-thumb,
        .conv-list::-webkit-scrollbar-thumb {
            background: var(--color-border); border-radius: 10px;
        }

        /* Typing indicator */
        .typing-dots { display: flex; gap: 3px; padding: 4px 0; }
        .typing-dots span {
            width: 6px; height: 6px; border-radius: 50%;
            background: var(--color-text-placeholder);
            animation: typingBounce 1.4s infinite ease-in-out;
        }
        .typing-dots span:nth-child(2) { animation-delay: 0.2s; }
        .typing-dots span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes typingBounce {
            0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; }
            40% { transform: scale(1); opacity: 1; }
        }

        /* Mobile */
        @media (max-width: 768px) {
            .chat-sidebar { position: absolute; width: 100%; min-width: 100%; z-index: 20; height: calc(100vh - 4rem); }
            .chat-sidebar.hidden-mobile { display: none; }
            .chat-main.hidden-mobile { display: none; }
            .chat-main { width: 100%; position: relative; z-index: 10; }
            .msg-bubble { max-width: 85%; }
        }

        /* Role badge */
        .role-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 2px 8px; border-radius: 6px;
            font-size: 10px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .role-badge.company { background: rgba(99,102,241,0.1); color: #6366f1; }
        .role-badge.freelancer { background: rgba(16,185,129,0.1); color: #10b981; }
        .role-badge.admin { background: rgba(245,158,11,0.1); color: #f59e0b; }
    </style>
</head>
<body class="bg-white dark:bg-slate-900">
<?php require __DIR__ . '/../includes/navbar.php'; ?>

<div class="chat-layout">
    <!-- ===== SIDEBAR ===== -->
    <div class="chat-sidebar <?= $other_id > 0 ? 'hidden-mobile' : '' ?>" id="convSidebar">
        <!-- Header -->
        <div class="p-5" style="border-bottom:1px solid var(--color-border)">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h1 class="text-xl font-bold" style="color:var(--color-text-primary)"><?= e(__('chat.title')) ?></h1>
                    <p class="text-xs mt-0.5" style="color:var(--color-text-muted)"><?= count($conversations) ?> conversations</p>
                </div>
                <?php if ($unread_total > 0): ?>
                    <span class="unread-badge"><?= $unread_total > 99 ? '99+' : $unread_total ?></span>
                <?php endif; ?>
            </div>

            <!-- Search -->
            <div class="relative">
                <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" id="convSearch" placeholder="Search conversations..." class="search-box w-full pl-10 pr-4 text-sm" style="color:var(--color-text-primary)">
            </div>
        </div>

        <!-- Conversation List -->
        <div class="conv-list flex-1 overflow-y-auto" id="convList">
            <?php if (empty($conversations)): ?>
                <div class="empty-state p-6">
                    <div class="w-20 h-20 rounded-full flex items-center justify-center mb-4" style="background:linear-gradient(135deg, rgba(99,102,241,0.1), rgba(139,92,246,0.1))">
                        <svg class="w-10 h-10 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                        </svg>
                    </div>
                    <p class="font-semibold mb-1" style="color:var(--color-text-primary)"><?= e(__('chat.no_contracts')) ?></p>
                    <p class="text-xs" style="color:var(--color-text-muted)"><?= e(__('chat.hire_hint')) ?></p>
                </div>
            <?php else: ?>
                <?php foreach ($conversations as $conv):
                    $is_active = (int) $conv['other_user_id'] === $other_id;
                    $initial = strtoupper(mb_substr($conv['other_display_name'] ?? $conv['other_username'], 0, 1));
                    $partner_role = $conv['other_role'] ?? '';
                    $time_display = $conv['last_message_time'] ? format_message_time($conv['last_message_time']) : '';
                ?>
                    <a href="<?= e(base_url('chat/index.php?user_id=' . $conv['other_user_id'])) ?>"
                       class="conv-item <?= $is_active ? 'active' : '' ?>"
                       data-name="<?= e(strtolower($conv['other_display_name'] ?? $conv['other_username'])) ?>"
                       data-message="<?= e(strtolower($conv['last_message'] ?? '')) ?>">
                        <!-- Avatar -->
                        <div class="relative flex-shrink-0">
                            <?php if (!empty($conv['other_profile_image'])): ?>
                                <img src="<?= e(base_url('uploads/' . $conv['other_profile_image'])) ?>" alt="" class="avatar">
                            <?php else: ?>
                                <div class="avatar-initials"><?= $initial ?></div>
                            <?php endif; ?>
                            <span class="online-dot <?= !empty($conv['is_online']) ? 'online' : 'offline' ?>"></span>
                        </div>

                        <!-- Info -->
                        <div class="flex-1 min-w-0">
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
                                    <?= $conv['last_message'] ? e(mb_strimwidth($conv['last_message'], 0, 45, '...')) : '<em>' . e(__('chat.no_messages')) . '</em>' ?>
                                </span>
                                <?php if ((int) $conv['unread_count'] > 0): ?>
                                    <span class="unread-badge ml-2 flex-shrink-0"><?= (int) $conv['unread_count'] ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== CHAT MAIN ===== -->
    <div class="chat-main <?= ($other_id > 0 && $partner) ? '' : 'hidden-mobile' ?>" id="chatMain">
        <?php if ($other_id > 0 && $partner): ?>
            <?php $initial = strtoupper(mb_substr($partner['display_name'] ?? $partner['username'], 0, 1)); ?>

            <!-- Chat Header -->
            <div class="flex items-center gap-3 px-5 py-3.5" style="background:var(--color-card);border-bottom:1px solid var(--color-border)">
                <!-- Back button (mobile) -->
                <button type="button" class="md:hidden p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" onclick="toggleMobileView()" aria-label="Back">
                    <svg class="w-5 h-5" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </button>

                <!-- Partner avatar -->
                <div class="relative flex-shrink-0">
                    <?php if (!empty($partner['profile_image'])): ?>
                        <img src="<?= e(base_url('uploads/' . $partner['profile_image'])) ?>" alt="" class="avatar-sm avatar">
                    <?php else: ?>
                        <div class="avatar-sm avatar-initials"><?= $initial ?></div>
                    <?php endif; ?>
                    <span class="online-dot <?= !empty($partner['is_online']) ? 'online' : 'offline' ?>" style="width:10px;height:10px;border-width:2px"></span>
                </div>

                <!-- Partner info -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <h2 class="font-semibold text-sm truncate" style="color:var(--color-text-primary)"><?= e($partner['display_name'] ?? $partner['username']) ?></h2>
                        <?php if (!empty($partner['role'])): ?>
                            <span class="role-badge <?= $partner['role'] ?>"><?= e($partner['role']) ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="text-xs flex items-center gap-1.5" style="<?= !empty($partner['is_online']) ? 'color:#22c55e' : 'color:var(--color-text-placeholder)' ?>">
                        <span class="w-1.5 h-1.5 rounded-full" style="background:<?= !empty($partner['is_online']) ? '#22c55e' : '#9ca3af' ?>; display:inline-block"></span>
                        <?= !empty($partner['is_online']) ? 'Online now' : 'Offline' ?>
                    </p>
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-1">
                    <a href="<?= e(base_url('chat/index.php')) ?>" class="p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" style="color:var(--color-text-muted)" title="Refresh">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </a>
                </div>
            </div>

            <!-- Messages Area -->
            <div class="chat-messages" id="chatMessages">
                <div class="text-center py-8" id="loadingMessages">
                    <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full" style="background:var(--color-card);border:1px solid var(--color-border)">
                        <div class="w-4 h-4 border-2 border-indigo-500 border-t-transparent rounded-full animate-spin"></div>
                        <span class="text-xs" style="color:var(--color-text-muted)">Loading messages...</span>
                    </div>
                </div>
            </div>

            <!-- Input Area -->
            <div class="chat-input-area">
                <form id="messageForm">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="receiver_id" value="<?= $other_id ?>">
                    <div class="flex items-end gap-3">
                        <div class="flex-1 min-w-0">
                            <textarea id="messageInput" rows="1" placeholder="<?= e(__('chat.placeholder')) ?>"
                                class="w-full px-4 py-3 text-sm rounded-2xl resize-none focus:outline-none transition-all"
                                style="max-height:120px; background:var(--color-bg); border:1px solid var(--color-border); color:var(--color-text-primary);"
                                oninput="autoResize(this)"
                                onfocus="this.style.borderColor='#6366f1';this.style.boxShadow='0 0 0 3px rgba(99,102,241,0.1)'"
                                onblur="this.style.borderColor='var(--color-border)';this.style.boxShadow='none'"></textarea>
                        </div>
                        <button type="submit" class="send-btn" id="sendBtn" disabled>
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                        </button>
                    </div>
                </form>
            </div>

        <?php else: ?>
            <!-- Empty state -->
            <div class="empty-state">
                <div class="w-28 h-28 rounded-full flex items-center justify-center mb-6" style="background:linear-gradient(135deg, rgba(99,102,241,0.1), rgba(139,92,246,0.1))">
                    <svg class="w-14 h-14 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                    </svg>
                </div>
                <h2 class="text-xl font-bold mb-2" style="color:var(--color-text-primary)"><?= e(__('chat.your_messages')) ?></h2>
                <p class="text-sm max-w-xs" style="color:var(--color-text-muted)"><?= e(__('chat.select_person')) ?></p>
                <div class="mt-6 flex items-center gap-2 text-xs" style="color:var(--color-text-placeholder)">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Chat is available after a proposal is accepted or you're hired
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    // Theme toggle
    var themeToggle = document.getElementById('theme-toggle');
    var html = document.documentElement;
    if (themeToggle) {
        themeToggle.addEventListener('click', function() {
            var isDark = html.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        });
    }

    // Conversation search
    var searchInput = document.getElementById('convSearch');
    if (searchInput) {
        var searchTimeout;
        searchInput.addEventListener('input', function() {
            var q = this.value.trim().toLowerCase();
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                var items = document.querySelectorAll('.conv-item');
                items.forEach(function(item) {
                    var name = item.getAttribute('data-name') || '';
                    var msg = item.getAttribute('data-message') || '';
                    if (q === '' || name.indexOf(q) !== -1 || msg.indexOf(q) !== -1) {
                        item.style.display = '';
                    } else {
                        item.style.display = 'none';
                    }
                });
            }, 200);
        });
    }

    <?php if ($other_id > 0 && $partner): ?>
    var currentUserId = <?= $user_id ?>;
    var otherUserId = <?= $other_id ?>;
    var csrfToken = '<?= e(csrf_token()) ?>';
    var isAdmin = <?= $role === 'admin' ? 'true' : 'false' ?>;
    var pollInterval = null;
    var lastMessageIds = '';
    var lastCount = 0;

    function escapeHtml(text) {
        if (!text) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(text));
        return d.innerHTML;
    }

    function formatTime(ts) {
        if (!ts) return '';
        var t = ts.substring(11, 16);
        var h = parseInt(t.substring(0, 2));
        var m = t.substring(2);
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

    function buildMessageHtml(msg) {
        var isSent = parseInt(msg.sender_id) === currentUserId;
        var time = formatTime(msg.created_at);
        var name = msg.sender_username || 'Unknown';

        var html = '<div class="mb-3 flex items-end gap-2 ' + (isSent ? 'justify-end' : 'justify-start') + '" data-id="' + msg.id + '">';

        if (!isSent) {
            html += '<div class="flex-shrink-0 mb-1">';
            if (msg.sender_profile_image) {
                html += '<img src="<?= e(base_url('uploads/')) ?>' + escapeHtml(msg.sender_profile_image) + '" class="avatar-xs avatar" style="width:28px;height:28px">';
            } else {
                html += '<div class="avatar-xs avatar-initials" style="width:28px;height:28px;font-size:11px">' + escapeHtml(name.charAt(0).toUpperCase()) + '</div>';
            }
            html += '</div>';
        }

        html += '<div class="max-w-[70%]">';
        if (!isSent) {
            html += '<p class="text-xs mb-1 ml-1 font-medium" style="color:var(--color-text-muted)">' + escapeHtml(name) + '</p>';
        }
        html += '<div class="msg-bubble ' + (isSent ? 'msg-sent' : 'msg-received') + '">';
        html += '<div class="text-sm leading-relaxed">' + escapeHtml(msg.message) + '</div>';
        html += '<div class="flex items-center gap-1 mt-1 ' + (isSent ? 'justify-end' : 'justify-start') + '">';
        html += '<span class="text-xs opacity-60">' + time + '</span>';
        if (isSent) {
            if (msg.status === 'read') {
                html += '<span class="text-xs" style="color:rgba(196,181,253,0.9)">&#10003;&#10003;</span>';
            } else {
                html += '<span class="text-xs" style="color:rgba(196,181,253,0.5)">&#10003;</span>';
            }
        }
        html += '</div></div></div></div>';
        return html;
    }

    function loadMessages(scroll) {
        fetch('<?= e(base_url('api/chat.php')) ?>?action=get_messages&user_id=' + otherUserId, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var loading = document.getElementById('loadingMessages');
            if (loading) loading.style.display = 'none';

            if (!data.messages || data.messages.length === 0) {
                var container = document.getElementById('chatMessages');
                container.innerHTML = '<div class="empty-state py-12"><div class="w-16 h-16 rounded-full flex items-center justify-center mb-3" style="background:linear-gradient(135deg, rgba(99,102,241,0.1), rgba(139,92,246,0.1))"><svg class="w-8 h-8 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg></div><p class="text-sm font-medium" style="color:var(--color-text-muted)">No messages yet</p><p class="text-xs mt-1" style="color:var(--color-text-placeholder)">Say hello!</p></div>';
                lastMessageIds = '';
                lastCount = 0;
                return;
            }

            var newIds = data.messages.map(function(m) { return m.id; }).join(',');
            var newCount = data.messages.length;
            if (newIds === lastMessageIds) return;

            lastMessageIds = newIds;
            lastCount = newCount;

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

            var container = document.getElementById('chatMessages');
            var wasAtBottom = container.scrollTop + container.clientHeight >= container.scrollHeight - 50;
            container.innerHTML = html;

            if (scroll !== false || wasAtBottom) {
                container.scrollTop = container.scrollHeight;
            }
        })
        .catch(function(err) {
            console.error('Chat error:', err);
        });
    }

    function sendMessage(e) {
        e.preventDefault();
        var input = document.getElementById('messageInput');
        var message = input.value.trim();
        if (!message) return;

        var btn = document.getElementById('sendBtn');
        btn.disabled = true;
        btn.innerHTML = '<div class="w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin"></div>';

        fetch('<?= e(base_url('api/chat.php')) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: 'send_message', receiver_id: otherUserId, message: message, csrf_token: csrfToken })
        })
        .then(function(r) {
            if (!r.ok) return r.json().then(function(d) { throw new Error(d.error || 'Failed'); });
            return r.json();
        })
        .then(function(data) {
            if (data.success) {
                input.value = '';
                input.style.height = 'auto';
                loadMessages(true);
            } else {
                alert(data.error || 'Failed to send message');
            }
        })
        .catch(function(err) {
            alert(err.message || 'Connection error');
            console.error('Send error:', err);
        })
        .finally(function() {
            btn.innerHTML = '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>';
            btn.disabled = !document.getElementById('messageInput').value.trim();
        });
    }

    function autoResize(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 120) + 'px';
        var btn = document.getElementById('sendBtn');
        btn.disabled = !el.value.trim();
    }
    window.autoResize = autoResize;

    // Init
    loadMessages(true);
    document.getElementById('messageForm').addEventListener('submit', sendMessage);
    document.getElementById('messageInput').addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(e);
        }
    });

    // Poll every 3 seconds
    pollInterval = setInterval(function() { loadMessages(false); }, 3000);

    window.addEventListener('beforeunload', function() {
        if (pollInterval) clearInterval(pollInterval);
    });
    <?php endif; ?>

    // Mobile toggle
    window.toggleMobileView = function() {
        var sidebar = document.getElementById('convSidebar');
        var main = document.getElementById('chatMain');
        if (sidebar && main) {
            sidebar.classList.toggle('hidden-mobile');
            main.classList.toggle('hidden-mobile');
        }
    };
})();
</script>
</body>
</html>
