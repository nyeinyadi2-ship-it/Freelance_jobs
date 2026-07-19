/**
 * Real-time notification badge updater using SSE with AJAX polling fallback
 */
var NotificationSSE = (function() {
    'use strict';

    var eventSource = null;
    var pollInterval = null;
    var userId = null;
    var lastId = 0;

    function init(config) {
        userId = config.user_id || 0;
        if (!userId) return;

        if (window.EventSource) {
            try {
                eventSource = new EventSource('api/sse.php');
                eventSource.onmessage = function(e) {
                    try {
                        var data = JSON.parse(e.data);
                        updateBadge(data.unread_count);
                        if (data.last_id && data.last_id > lastId) {
                            lastId = data.last_id;
                            trigger('new_notification', data);
                        }
                    } catch (err) {}
                };
                eventSource.onerror = function() {
                    // SSE connection failed, fall back to polling
                    if (eventSource) { eventSource.close(); eventSource = null; }
                    startPolling();
                };
                return;
            } catch (e) {}
        }

        // Fallback: AJAX polling
        startPolling();
    }

    function startPolling() {
        if (pollInterval) return;
        pollInterval = setInterval(function() {
            fetch('api/notifications.php?action=get_unread_count', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.count !== undefined) {
                    updateBadge(parseInt(data.count));
                }
            })
            .catch(function() {});
        }, 10000); // Poll every 10 seconds
    }

    function updateBadge(count) {
        var text = count > 99 ? '99+' : (count > 0 ? String(count) : '');
        var show = count > 0;

        // Desktop badge
        var badge = document.getElementById('notifBadge');
        if (badge) {
            badge.textContent = text;
            badge.style.display = show ? 'flex' : 'none';
        }

        // Mobile badge
        var mobileBadge = document.getElementById('notifBadgeMobile');
        if (mobileBadge) {
            mobileBadge.textContent = text;
            mobileBadge.style.display = show ? 'flex' : 'none';
        }
    }

    var listeners = {};
    function trigger(event, data) {
        if (!listeners[event]) return;
        listeners[event].forEach(function(cb) { cb(data); });
    }
    function on(event, callback) {
        if (!listeners[event]) listeners[event] = [];
        listeners[event].push(callback);
    }

    function destroy() {
        if (eventSource) { eventSource.close(); eventSource = null; }
        if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
    }

    return { init: init, on: on, destroy: destroy };
})();
