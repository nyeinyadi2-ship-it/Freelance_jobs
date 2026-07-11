/**
 * Lightweight Emoji Picker for HireWork Chat
 * No external dependencies
 */
var EmojiPicker = (function() {
    'use strict';

    var EMOJIS = [
        '😀','😃','😄','😁','😅','😂','🤣','😊','😇','🙂','🙃','😉','😌','😍','🥰','😘',
        '😗','😙','😚','😋','😛','😝','😜','🤪','🤨','🧐','🤓','😎','🥸','🤩','🥳','😏',
        '😒','😞','😔','😟','😕','🙁','☹️','😣','😖','😫','😩','🥺','😢','😭','😤','😠',
        '😡','🤬','🤯','😳','🥵','🥶','😱','😨','😰','😥','😓','🤗','🤔','🤭','🤫','🤥',
        '😶','😐','😑','😬','🙄','😯','😦','😧','😮','😲','🥱','😴','🤤','😪','😵','🤐',
        '🥴','🤢','🤮','🤧','😷','🤒','🤕','🤑','🤠','😈','👿','👹','👺','🤡','💩','👻',
        '💀','☠️','👽','👾','🤖','🎃','😺','😸','😹','😻','😼','😽','🙀','😿','😾',
        '👍','👍🏻','👍🏼','👍🏽','👍🏾','👍🏿','👎','👎🏻','👎🏼','👎🏽','👎🏾','👎🏿',
        '👊','👊🏻','👊🏼','👊🏽','👊🏾','👊🏿','✊','✊🏻','✊🏼','✊🏽','✊🏾','✊🏿',
        '🤛','🤛🏻','🤛🏼','🤛🏽','🤛🏾','🤛🏿','🤜','🤜🏻','🤜🏼','🤜🏽','🤜🏾','🤜🏿',
        '👏','👏🏻','👏🏼','👏🏽','👏🏾','👏🏿','🙌','🙌🏻','🙌🏼','🙌🏽','🙌🏾','🙌🏿',
        '🤲','🤲🏻','🤲🏼','🤲🏽','🤲🏾','🤲🏿','🤝','🙏','🙏🏻','🙏🏼','🙏🏽','🙏🏾','🙏🏿',
        '❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💕','💞','💓','💗','💖','💘','💝',
        '💟','❣️','💌','💋','👋','🤚','🖐️','✋','🖖','👌','🤌','🤏','✌️','🤞','🤟','🤘',
        '🤙','👈','👉','👆','🖕','👇','☝️','✍️','🖐️','💅','🫵','🫶','🫱','🫲','🫳','🫴',
        '🎉','🎊','🎈','🎁','🎀','🪄','✨','🌟','⭐','🌈','🔥','💯','💪','🦾','🦵','🦿',
        '👍','👎','👌','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','🖕','👇','☝️','✍️','🤳',
        '💪','🦵','🦶','👂','🦻','👃','🧠','🦷','🦴','👀','👁️','👅','👄','💋','🫂',
    ];

    var pickerContainer = null;
    var isOpen = false;
    var onSelectCallback = null;

    function createPicker() {
        var container = document.createElement('div');
        container.id = 'emoji-picker';
        container.className = 'emoji-picker';
        container.style.cssText = 'display:none;position:absolute;bottom:60px;left:0;z-index:100;background:var(--color-card);border:1px solid var(--color-border);border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,0.15);width:320px;max-height:260px;overflow:hidden;';

        var header = document.createElement('div');
        header.style.cssText = 'padding:10px 14px;border-bottom:1px solid var(--color-border);font-size:13px;font-weight:600;color:var(--color-text-primary);display:flex;justify-content:space-between;align-items:center;';
        header.innerHTML = '<span>Emoji</span><button type="button" id="emoji-close" style="background:none;border:none;cursor:pointer;color:var(--color-text-muted);padding:2px;"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>';
        container.appendChild(header);

        var grid = document.createElement('div');
        grid.style.cssText = 'display:flex;flex-wrap:wrap;gap:2px;padding:8px;overflow-y:auto;max-height:200px;';

        EMOJIS.forEach(function(emoji) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = emoji;
            btn.className = 'emoji-btn';
            btn.style.cssText = 'width:36px;height:36px;display:flex;align-items:center;justify-content:center;font-size:20px;cursor:pointer;border:none;background:none;border-radius:8px;transition:background 0.15s;';
            btn.addEventListener('mouseenter', function() { this.style.background = 'var(--color-card-hover)'; });
            btn.addEventListener('mouseleave', function() { this.style.background = 'none'; });
            btn.addEventListener('click', function() {
                if (onSelectCallback) onSelectCallback(emoji);
                close();
            });
            grid.appendChild(btn);
        });

        container.appendChild(grid);
        document.body.appendChild(container);
        pickerContainer = container;

        // Close button
        document.getElementById('emoji-close').addEventListener('click', close);

        // Close on outside click
        document.addEventListener('click', function(e) {
            if (pickerContainer && !pickerContainer.contains(e.target) && !e.target.closest('#emoji-btn')) {
                close();
            }
        });
    }

    function toggle(anchorEl, callback) {
        if (!pickerContainer) createPicker();
        if (!anchorEl) return;

        if (isOpen) {
            close();
            return;
        }

        onSelectCallback = callback || null;
        isOpen = true;

        var rect = anchorEl.getBoundingClientRect();
        pickerContainer.style.display = 'block';
        pickerContainer.style.bottom = (window.innerHeight - rect.top + 10) + 'px';
        pickerContainer.style.left = rect.left + 'px';
    }

    function close() {
        if (pickerContainer) pickerContainer.style.display = 'none';
        isOpen = false;
    }

    return {
        toggle: toggle,
        close: close
    };
})();
