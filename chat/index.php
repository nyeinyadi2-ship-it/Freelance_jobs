<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/chat.php';

require_login();

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['company', 'freelancer'], true)) {
    set_flash('error', __('error.no_permission'));
    redirect('index.php');
}

$user_id = (int) $_SESSION['user_id'];
update_last_activity($conn, $user_id);

$conversations = get_conversations($conn, $user_id);
$other_id = (int) ($_GET['user_id'] ?? 0);
$partner = null;

if ($other_id > 0 && can_chat($conn, $user_id, $other_id)) {
    $partner = get_partner_info($conn, $user_id, $other_id);
}

$page_title = __('chat.title');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?> - <?= __('app.name') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= e(base_url('assets/css/custom.css')) ?>">
    <style>
        html, body { height: 100%; margin: 0; }
        .chat-page { height: calc(100vh - 4rem); display: flex; overflow: hidden; }
        .conv-sidebar { width: 340px; min-width: 340px; display: flex; flex-direction: column; border-right: 1px solid var(--color-border); background: var(--color-card); }
        .conv-list { flex: 1; overflow-y: auto; }
        .chat-main { flex: 1; display: flex; flex-direction: column; background: var(--color-bg); min-width: 0; }
        .chat-messages { flex: 1; overflow-y: auto; padding: 1rem; }
        .chat-input-area { border-top: 1px solid var(--color-border); padding: 0.75rem 1rem; background: var(--color-card); }
        .bubble { max-width: 75%; padding: 0.5rem 1rem; border-radius: 1rem; word-wrap: break-word; white-space: pre-wrap; }
        .bubble.sent { background: #4f46e5; color: #fff; border-bottom-right-radius: 0.25rem; margin-left: auto; }
        .bubble.received { background: var(--color-card); color: var(--color-text-primary); border: 1px solid var(--color-border); border-bottom-left-radius: 0.25rem; margin-right: auto; }
        .conv-item { padding: 0.875rem 1rem; border-bottom: 1px solid var(--color-border); cursor: pointer; transition: background 0.15s; }
        .conv-item:hover { background: var(--color-card-hover); }
        .conv-item.active { background: rgba(99,102,241,0.1); }
        @media (max-width: 768px) {
            .conv-sidebar { width: 100%; min-width: 100%; }
            .conv-sidebar.hidden-mobile { display: none; }
            .chat-main.hidden-mobile { display: none; }
            .chat-main { width: 100%; }
            .bubble { max-width: 85%; }
        }
        .empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: var(--color-text-placeholder); text-align: center; padding: 2rem; }
        .online-dot { width: 10px; height: 10px; border-radius: 50%; border: 2px solid #fff; position: absolute; bottom: 0; right: 0; }
    </style>
</head>
<body>
<?php require __DIR__ . '/../includes/navbar.php'; ?>

<div class="chat-page">
    <!-- Sidebar -->
    <div class="conv-sidebar<?= $other_id > 0 ? ' hidden-mobile' : '' ?>" id="convSidebar">
        <div class="p-4" style="border-bottom:1px solid var(--color-border)">
            <h1 class="text-lg font-bold" style="color:var(--color-text-primary)"><?= __('chat.title') ?></h1>
             <p class="text-xs mt-1" style="color:var(--color-text-placeholder)"><?= count($conversations) ?> <?= __('chat.active_contracts') ?></p>
        </div>
        <div class="conv-list" id="convList">
            <?php if (empty($conversations)): ?>
                <div class="empty-state p-6">
                    <svg class="w-16 h-16 mb-3" style="color:var(--color-text-placeholder)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg>
                    <p style="color:var(--color-text-muted)"><?= __('chat.no_contracts') ?></p>
                    <p class="text-xs mt-1" style="color:var(--color-text-placeholder)"><?= __('chat.hire_hint') ?></p>
                </div>
             <?php else: ?>
                <?php foreach ($conversations as $conv):
                    $is_active = (int) $conv['other_user_id'] === $other_id;
                ?>
                    <a href="<?= e(base_url('chat/index.php?user_id=' . $conv['other_user_id'])) ?>" class="conv-item <?= $is_active ? 'active' : '' ?> block no-underline" style="color:var(--color-text-primary)">
                        <div class="flex items-center gap-3">
                            <div class="relative flex-shrink-0">
                                <?php if ($conv['other_profile_image']): ?>
                                    <img src="<?= e(base_url('uploads/' . $conv['other_profile_image'])) ?>" alt="" class="w-10 h-10 rounded-full object-cover" style="border:1px solid var(--color-border)">
                                <?php else: ?>
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm" style="background:rgba(99,102,241,0.15);color:#4338ca;border:1px solid var(--color-border)">
                                        <?= e(strtoupper(mb_substr($conv['other_display_name'] ?? $conv['other_username'], 0, 1))) ?>
                                    </div>
                                <?php endif; ?>
                                <span class="online-dot <?= !empty($conv['is_online']) ? 'bg-green-500' : 'bg-gray-400' ?>"></span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between">
                                    <span class="font-medium text-sm block truncate"><?= e($conv['other_display_name'] ?? $conv['other_username']) ?></span>
                                    <?php if ((int) $conv['unread_count'] > 0): ?>
                                        <span class="ml-2 bg-indigo-600 text-white text-xs font-bold px-1.5 py-0.5 rounded-full min-w-[18px] text-center"><?= (int) $conv['unread_count'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-xs truncate" style="<?= $conv['last_message'] ? 'color:var(--color-text-muted)' : 'color:var(--color-text-placeholder);font-style:italic' ?>"><?= $conv['last_message'] ? e(mb_substr($conv['last_message'], 0, 40)) : __('chat.no_messages') ?></span>
                                    <span class="text-xs" style="<?= !empty($conv['is_online']) ? 'color:#16a34a' : 'color:var(--color-text-placeholder)' ?>"><?= !empty($conv['is_online']) ? __('chat.online') : __('chat.offline') ?></span>
                                </div>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Chat Main -->
    <div class="chat-main<?= $other_id > 0 && $partner ? '' : ' hidden-mobile' ?>" id="chatMain">
        <?php if ($other_id > 0 && $partner): ?>
            <!-- Header -->
            <div class="flex items-center gap-3 px-4 py-3 shadow-sm" style="background:var(--color-card);border-bottom:1px solid var(--color-border)">
                <button type="button" class="md:hidden p-1" style="color:var(--color-text-muted)" onclick="toggleMobileView()" aria-label="Back">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
                </button>
                <div class="relative flex-shrink-0">
                    <?php if ($partner['profile_image']): ?>
                        <img src="<?= e(base_url('uploads/' . $partner['profile_image'])) ?>" alt="" class="w-9 h-9 rounded-full object-cover" style="border:1px solid var(--color-border)">
                    <?php else: ?>
                        <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-sm" style="background:rgba(99,102,241,0.15);color:#4338ca;border:1px solid var(--color-border)">
                            <?= e(strtoupper(mb_substr($partner['display_name'] ?? $partner['username'], 0, 1))) ?>
                        </div>
                    <?php endif; ?>
                    <span class="online-dot <?= !empty($partner['is_online']) ? 'bg-green-500' : 'bg-gray-400' ?>"></span>
                </div>
                <div class="flex-1 min-w-0">
                    <h2 class="font-semibold text-sm truncate" style="color:var(--color-text-primary)"><?= e($partner['display_name'] ?? $partner['username']) ?></h2>
                    <p class="text-xs" style="<?= !empty($partner['is_online']) ? 'color:#16a34a' : 'color:var(--color-text-placeholder)' ?>"><?= !empty($partner['is_online']) ? __('chat.online') : __('chat.offline') ?></p>
                </div>
                <a href="<?= e(base_url('chat/index.php')) ?>" class="p-2 rounded-lg" style="color:var(--color-text-placeholder)" title="Refresh">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                </a>
            </div>

            <!-- Messages -->
            <div class="chat-messages" id="chatMessages">
                <div class="text-center py-4" id="loadingMessages">
                    <div class="inline-block w-5 h-5 border-2 border-indigo-600 border-t-transparent rounded-full animate-spin"></div>
                    <p class="text-xs mt-1" style="color:var(--color-text-placeholder)"><?= __('chat.loading') ?></p>
                </div>
            </div>

            <!-- Input -->
            <div class="chat-input-area">
                <form id="messageForm">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="receiver_id" value="<?= $other_id ?>">
                    <div class="flex items-end gap-2">
                        <div class="flex-1 min-w-0">
                            <textarea id="messageInput" rows="1" placeholder="<?= e(__('chat.placeholder')) ?>" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg resize-none focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" style="max-height: 120px;" oninput="autoResize(this)"></textarea>
                        </div>
                        <button type="submit" class="flex-shrink-0 bg-indigo-600 text-white p-2.5 rounded-lg hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed" id="sendBtn" disabled>
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" /></svg>
                        </button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <svg class="w-20 h-20 mb-4" style="color:var(--color-text-placeholder)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg>
                <h2 class="text-xl font-semibold mb-1" style="color:var(--color-text-placeholder)"><?= __('chat.your_messages') ?></h2>
                <p class="text-sm" style="color:var(--color-text-placeholder)"><?= __('chat.select_person') ?></p>
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
    var pollInterval = null;
    var messageIds = [];

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
                container.innerHTML = '<div class="empty-msg empty-state py-8"><p class="text-sm" style="color:var(--color-text-placeholder)"><?= e(__('chat.no_messages')) ?>. Say hello!</p></div>';
                messageIds = [];
                return;
            }

            var newIds = data.messages.map(function(m) { return m.id; });
            var same = messageIds.length === newIds.length && messageIds.every(function(v, i) { return v === newIds[i]; });
            if (same) return;

            messageIds = newIds;
            var html = '';
            data.messages.forEach(function(msg) {
                var isSent = parseInt(msg.sender_id) === currentUserId;
                var time = msg.created_at || '';
                var timeStr = time.substring(11, 16);
                var name = msg.sender_username || 'Unknown';

                html += '<div class="mb-3" data-id="' + msg.id + '">';
                html += '<div class="flex items-end gap-2 ' + (isSent ? 'justify-end' : 'justify-start') + '">';
                if (!isSent) {
                    html += '<div class="flex-shrink-0 mb-1">';
                    if (msg.sender_profile_image) {
                        html += '<img src="<?= e(base_url('uploads/')) ?>' + escapeHtml(msg.sender_profile_image) + '" class="w-6 h-6 rounded-full object-cover" style="border:1px solid var(--color-border)">';
                    } else {
                        html += '<div class="w-6 h-6 rounded-full flex items-center justify-center font-bold text-xs" style="background:rgba(99,102,241,0.15);color:#4338ca">' + escapeHtml(name.charAt(0).toUpperCase()) + '</div>';
                    }
                    html += '</div>';
                }
                html += '<div class="max-w-[85%]">';
                if (!isSent) {
                    html += '<p class="text-xs mb-0.5 ml-1" style="color:var(--color-text-placeholder)">' + escapeHtml(name) + '</p>';
                }
                html += '<div class="bubble ' + (isSent ? 'sent' : 'received') + '">';
                html += '<div class="text-sm leading-relaxed">' + escapeHtml(msg.message) + '</div>';
                html += '<div class="flex justify-end items-center gap-1 mt-0.5">';
                    html += '<span class="text-xs" style="' + (isSent ? 'color:rgba(199,210,254,0.8)' : 'color:var(--color-text-placeholder)') + '">' + timeStr + '</span>';
                if (isSent) {
                    html += '<span class="text-xs ' + (msg.status === 'read' ? 'text-indigo-200' : 'text-indigo-300') + '">' + (msg.status === 'read' ? '&#10003;&#10003;' : '&#10003;') + '</span>';
                }
                html += '</div></div></div></div></div>';
            });

            var container = document.getElementById('chatMessages');
            container.innerHTML = html;
            if (scroll !== false) {
                container.scrollTop = container.scrollHeight;
            }
        })
        .catch(function(err) {
            console.error('Chat load error:', err);
        });
    }

    function showError(msg) {
        var container = document.getElementById('chatMessages');
        var err = document.getElementById('chatError');
        if (!err) {
            err = document.createElement('div');
            err.id = 'chatError';
            err.className = 'text-center py-2 px-3 text-xs rounded-lg mb-2';
            err.style.cssText = 'background:rgba(239,68,68,0.1);color:var(--color-error);display:none;';
            container.parentNode.insertBefore(err, container);
        }
        err.textContent = msg;
        err.style.display = 'block';
        setTimeout(function() { err.style.display = 'none'; }, 5000);
    }

    function sendMessage(e) {
        e.preventDefault();
        var input = document.getElementById('messageInput');
        var message = input.value.trim();
        if (!message) return;

        var btn = document.getElementById('sendBtn');
        btn.disabled = true;
        btn.innerHTML = '<div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div>';

        fetch('<?= e(base_url('api/chat.php')) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: 'send_message', receiver_id: otherUserId, message: message, csrf_token: csrfToken })
        })
        .then(function(r) {
            if (!r.ok) { return r.json().then(function(d) { throw new Error(d.error || 'Request failed'); }); }
            return r.json();
        })
        .then(function(data) {
            if (data.success) {
                input.value = '';
                input.style.height = 'auto';
                btn.disabled = true;
                loadMessages(true);
            } else {
                showError(data.error || 'Failed to send message');
            }
        })
        .catch(function(err) {
            showError(err.message || 'Connection error. Please try again.');
            console.error('Chat send error:', err);
        })
        .finally(function() {
            btn.innerHTML = '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" /></svg>';
        });
    }

    function autoResize(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 120) + 'px';
        var btn = document.getElementById('sendBtn');
        btn.disabled = !el.value.trim();
    }
    window.autoResize = autoResize;

    function escapeHtml(text) {
        if (!text) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(text));
        return d.innerHTML;
    }

    // Init
    loadMessages(true);
    document.getElementById('messageForm').addEventListener('submit', sendMessage);
    document.getElementById('messageInput').addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(e);
        }
    });
    document.getElementById('messageInput').addEventListener('input', function() {
        var btn = document.getElementById('sendBtn');
        btn.disabled = !this.value.trim();
    });

    // Poll
    pollInterval = setInterval(function() { loadMessages(false); }, 3000);

    window.addEventListener('beforeunload', function() {
        if (pollInterval) clearInterval(pollInterval);
    });
    <?php endif; ?>

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
