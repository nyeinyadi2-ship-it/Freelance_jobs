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
    <script src="<?= e(base_url('assets/js/emoji-picker.js')) ?>"></script>
    <script src="<?= e(base_url('assets/js/chat-websocket.js')) ?>"></script>
    <style>
        *, *::before, *::after { font-family: 'Poppins', system-ui, sans-serif; }
        html, body { height: 100%; margin: 0; overflow: hidden; }

        .chat-layout { height: calc(100vh - 4rem); display: flex; }

        .chat-sidebar {
            width: 460px; min-width: 460px;
            display: flex; flex-direction: column;
            background: var(--color-card);
            border-right: 1px solid var(--color-border);
            transition: transform 0.3s ease;
        }

        .chat-main {
            flex: 1; display: flex; flex-direction: column;
            background: var(--color-bg); min-width: 0;
        }

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

        .online-dot {
            width: 12px; height: 12px; border-radius: 50%;
            position: absolute; bottom: 0; right: 0;
            border: 2px solid var(--color-card);
        }
        .online-dot.online { background: #22c55e; }
        .online-dot.offline { background: #9ca3af; }

        .chat-messages {
            flex: 1; overflow-y: auto; padding: 20px;
            background: linear-gradient(180deg, var(--color-bg) 0%, rgba(99,102,241,0.02) 100%);
        }

        .msg-bubble {
            max-width: 75%; padding: 10px 16px;
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

        .date-separator {
            display: flex; align-items: center; gap: 12px;
            margin: 20px 0; font-size: 12px; color: var(--color-text-placeholder);
        }
        .date-separator::before, .date-separator::after {
            content: ''; flex: 1; height: 1px;
            background: var(--color-border);
        }

        .chat-input-area {
            border-top: 1px solid var(--color-border);
            padding: 16px 20px; background: var(--color-card);
        }

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

        .send-btn {
            width: 44px; height: 44px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #6366f1, #7c3aed);
            color: white; border: none; cursor: pointer;
            transition: all 0.2s ease; flex-shrink: 0;
        }
        .send-btn:hover { transform: scale(1.05); box-shadow: 0 4px 16px rgba(99, 102, 241, 0.35); }
        .send-btn:disabled { opacity: 0.4; cursor: not-allowed; transform: none; box-shadow: none; }

        .empty-state {
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            height: 100%; text-align: center; padding: 40px;
        }

        .unread-badge {
            min-width: 20px; height: 20px; padding: 0 6px;
            border-radius: 10px; font-size: 11px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #6366f1, #7c3aed);
            color: white;
        }

        .chat-messages::-webkit-scrollbar,
        .conv-list::-webkit-scrollbar { width: 5px; }
        .chat-messages::-webkit-scrollbar-track,
        .conv-list::-webkit-scrollbar-track { background: transparent; }
        .chat-messages::-webkit-scrollbar-thumb,
        .conv-list::-webkit-scrollbar-thumb {
            background: var(--color-border); border-radius: 10px;
        }

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

        @media (max-width: 1024px) {
            .chat-sidebar { width: 380px; min-width: 380px; }
        }

        @media (max-width: 768px) {
            .chat-sidebar { position: absolute; width: 100%; min-width: 100%; z-index: 20; height: calc(100vh - 4rem); }
            .chat-sidebar.hidden-mobile { display: none; }
            .chat-main.hidden-mobile { display: none; }
            .chat-main { width: 100%; position: relative; z-index: 10; }
            .msg-bubble { max-width: 88%; }
        }

        .role-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 2px 8px; border-radius: 6px;
            font-size: 10px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .role-badge.company { background: rgba(99,102,241,0.1); color: #6366f1; }
        .role-badge.freelancer { background: rgba(16,185,129,0.1); color: #10b981; }
        .role-badge.admin { background: rgba(245,158,11,0.1); color: #f59e0b; }

        .msg-img-attachment {
            border-radius: 12px; max-width: 260px; max-height: 200px;
            object-fit: cover; cursor: pointer;
            transition: transform 0.2s ease;
        }
        .msg-img-attachment:hover { transform: scale(1.02); }
    </style>
</head>
<body class="bg-white dark:bg-slate-900">
<?php require __DIR__ . '/../includes/navbar.php'; ?>

<div class="chat-layout">
    <!-- ===== SIDEBAR ===== -->
    <div class="chat-sidebar <?= $other_id > 0 ? 'hidden-mobile' : '' ?>" id="convSidebar">
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
            <div class="relative">
                <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" id="convSearch" placeholder="Search conversations..." class="search-box w-full pl-10 pr-4 text-sm" style="color:var(--color-text-primary)">
            </div>
        </div>

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
                        <div class="relative flex-shrink-0">
                            <?php if (!empty($conv['other_profile_image'])): ?>
                                <img src="<?= e(base_url('uploads/' . $conv['other_profile_image'])) ?>" alt="" class="avatar">
                            <?php else: ?>
                                <div class="avatar-initials"><?= $initial ?></div>
                            <?php endif; ?>
                            <span class="online-dot <?= !empty($conv['is_online']) ? 'online' : 'offline' ?>"></span>
                        </div>
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

            <div class="flex items-center gap-3 px-5 py-3.5" style="background:var(--color-card);border-bottom:1px solid var(--color-border)">
                <button type="button" class="md:hidden p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" onclick="toggleMobileView()" aria-label="Back">
                    <svg class="w-5 h-5" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <div class="relative flex-shrink-0">
                    <?php if (!empty($partner['profile_image'])): ?>
                        <img src="<?= e(base_url('uploads/' . $partner['profile_image'])) ?>" alt="" class="avatar-sm avatar">
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
                        <span class="w-1.5 h-1.5 rounded-full inline-block" style="background:<?= !empty($partner['is_online']) ? '#22c55e' : '#9ca3af' ?>"></span>
                        <?= !empty($partner['is_online']) ? 'Online now' : 'Offline' ?>
                    </p>
                </div>
                <div class="flex items-center gap-1">
                    <a href="<?= e(base_url('chat/index.php')) ?>" class="p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" style="color:var(--color-text-muted)" title="Refresh">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </a>
                </div>
            </div>

            <div class="chat-messages" id="chatMessages">
                <div class="text-center py-8" id="loadingMessages">
                    <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full" style="background:var(--color-card);border:1px solid var(--color-border)">
                        <div class="w-4 h-4 border-2 border-indigo-500 border-t-transparent rounded-full animate-spin"></div>
                        <span class="text-xs" style="color:var(--color-text-muted)">Loading messages...</span>
                    </div>
                </div>
            </div>

            <div id="typingIndicator" class="px-5 py-2 hidden" style="background:var(--color-card);border-top:1px solid var(--color-border)">
                <div class="flex items-center gap-2 text-xs" style="color:var(--color-text-muted)">
                    <div class="typing-dots"><span></span><span></span><span></span></div>
                    <span id="typingText">typing...</span>
                </div>
            </div>

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
                        <div class="flex-1 min-w-0">
                            <textarea id="messageInput" rows="1" placeholder="<?= e(__('chat.placeholder')) ?>"
                                class="w-full px-4 py-3 text-sm rounded-2xl resize-none focus:outline-none transition-all"
                                style="max-height:120px; background:var(--color-bg); border:1px solid var(--color-border); color:var(--color-text-primary);"
                                oninput="autoResize(this)"></textarea>
                        </div>
                        <button type="submit" class="send-btn" id="sendBtn">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                        </button>
                    </div>
                </form>
                <div id="filePreview" class="hidden mt-2"></div>
            </div>

        <?php else: ?>
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
    <?php if ($other_id > 0 && $partner): ?>
    var currentUserId = <?= $user_id ?>;
    var otherUserId = <?= $other_id ?>;
    var csrfToken = '<?= e(csrf_token()) ?>';
    var isAdmin = <?= $role === 'admin' ? 'true' : 'false' ?>;
    var pollInterval = null;
    var lastMessageIds = '';
    var typingTimeout = null;
    var isTyping = false;

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
                    html += '<a href="' + fileUrl + '" target="_blank" class="block rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700 mb-1">';
                    html += '<img src="' + fileUrl + '" alt="' + escapeHtml(file.file_name) + '" class="msg-img-attachment w-full" loading="lazy">';
                    html += '</a>';
                }
                html += '<a href="' + fileUrl + '" target="_blank" class="inline-flex items-center gap-1.5 px-2 py-1 rounded-lg text-xs font-medium transition-colors" style="background:rgba(255,255,255,0.15);color:inherit">';
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
            return '<div class="text-center my-3"><span class="inline-block px-3 py-1 text-xs rounded-full" style="background:var(--color-card);color:var(--color-text-muted);border:1px solid var(--color-border)">' + escapeHtml(msg.message) + '</span></div>';
        }

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

        html += '<div class="max-w-[75%]">';
        if (!isSent) {
            html += '<p class="text-xs mb-1 ml-1 font-medium" style="color:var(--color-text-muted)">' + escapeHtml(name) + '</p>';
        }
        html += '<div class="msg-bubble ' + (isSent ? 'msg-sent' : 'msg-received') + '">';
        if (hasAttachments) {
            html += buildAttachmentHtml(msg);
        }
        if (msg.message) {
            html += '<div class="text-sm leading-relaxed ' + (hasAttachments ? 'mt-1' : '') + '">' + escapeHtml(msg.message) + '</div>';
        }
        html += '<div class="flex items-center gap-1 mt-1 ' + (isSent ? 'justify-end' : 'justify-start') + '">';
        html += '<span class="text-xs opacity-60">' + time + '</span>';
        if (isSent) {
            html += '<span class="text-xs" style="color:' + (msg.status === 'read' ? 'rgba(196,181,253,0.9)' : 'rgba(196,181,253,0.5)') + '">' + (msg.status === 'read' ? '\u2713\u2713' : '\u2713') + '</span>';
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
                container.innerHTML = '<div class="empty-state py-12"><div class="w-16 h-16 rounded-full flex items-center justify-center mb-3" style="background:linear-gradient(135deg, rgba(99,102,241,0.1), rgba(139,92,246,0.1))"><svg class="w-8 h-8 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg></div><p class="text-sm font-medium" style="color:var(--color-text-muted)">No messages yet</p><p class="text-xs mt-1" style="color:var(--color-text-placeholder)">Say hello!</p></div>';
                lastMessageIds = '';
                return;
            }

            var newIds = data.messages.map(function(m) { return m.id; }).join(',');
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

            // Mark as read
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
        var btn = document.getElementById('sendBtn');
        btn.disabled = true;
        btn.innerHTML = '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>';
        var fi = document.getElementById('fileInput');
        if (fi) { fi.value = ''; }
        clearFilePreview();
    }

    function sendMessage(e) {
        if (e) e.preventDefault();
        var input = document.getElementById('messageInput');
        var message = input.value.trim();
        var fileInput = document.getElementById('fileInput');
        var file = fileInput && fileInput.files.length > 0 ? fileInput.files[0] : null;

        if (!message && !file) return;

        var btn = document.getElementById('sendBtn');
        btn.disabled = true;
        btn.innerHTML = '<div class="w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin"></div>';

        if (file) {
            var formData = new FormData();
            formData.append('action', 'send_message');
            formData.append('receiver_id', otherUserId);
            formData.append('message', message);
            formData.append('attachment', file);
            formData.append('csrf_token', csrfToken);
            formData.append('message_type', 'file');

            fetch('<?= e(base_url('api/chat.php')) ?>', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    clearSendInput();
                    loadMessages(true);
                } else {
                    alert(data.error || 'Failed to send file');
                }
            })
            .catch(function(err) {
                console.error('Upload error:', err);
            })
            .finally(function() {
                btn.innerHTML = '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>';
            });
        } else if (typeof ChatWS !== 'undefined' && ChatWS.isConnected && ChatWS.isConnected() && !ChatWS.isFallback()) {
            ChatWS.send({
                action: 'message',
                receiver_id: otherUserId,
                message: message,
                message_type: 'text',
                temp_id: 'tmp_' + Date.now()
            });
            clearSendInput();
        } else {
            fetch('<?= e(base_url('api/chat.php')) ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action: 'send_message', receiver_id: otherUserId, message: message, csrf_token: csrfToken })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    clearSendInput();
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

    // === EMOJI PICKER ===
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

        var html = '<div class="flex items-center gap-3 p-2 rounded-xl" style="background:var(--color-bg);border:1px solid var(--color-border)">';
        if (isImage) {
            html += '<div class="w-10 h-10 rounded-lg overflow-hidden flex-shrink-0 bg-gray-100"><img src="' + URL.createObjectURL(file) + '" class="w-full h-full object-cover"></div>';
        } else {
            html += '<div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 text-lg" style="background:linear-gradient(135deg, rgba(99,102,241,0.1), rgba(139,92,246,0.1))">📎</div>';
        }
        html += '<div class="flex-1 min-w-0"><p class="text-xs font-medium truncate" style="color:var(--color-text-primary)">' + escapeHtml(file.name) + '</p><p class="text-xs" style="color:var(--color-text-muted)">' + size + '</p></div>';
        html += '<button type="button" onclick="clearFilePreview()" class="flex-shrink-0 p-1 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors" style="color:var(--color-text-muted)"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>';
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
})();
</script>
</body>
</html>
