/**
 * FreelanceHub WebSocket Chat Client
 * Connects to Ratchet PHP WebSocket server with AJAX fallback
 */
var ChatWS = (function() {
    'use strict';

    var ws = null;
    var wsUrl = null;
    var userId = null;
    var csrfToken = null;
    var connected = false;
    var reconnectTimer = null;
    var reconnectAttempts = 0;
    var maxReconnect = 10;
    var listeners = {};
    var useFallback = false;
    var pingInterval = null;

    function init(config) {
        userId = config.user_id;
        csrfToken = config.csrf_token;
        wsUrl = config.ws_url || 'ws://localhost:8080';
        useFallback = config.fallback || false;

        if (!useFallback && window.WebSocket) {
            connect();
        } else {
            console.log('[ChatWS] Using AJAX fallback');
            useFallback = true;
        }
    }

    function connect() {
        if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) return;

        try {
            ws = new WebSocket(wsUrl);
        } catch (e) {
            console.error('[ChatWS] Connection failed:', e);
            fallback();
            return;
        }

        ws.onopen = function() {
            console.log('[ChatWS] Connected');
            connected = true;
            reconnectAttempts = 0;

            // Authenticate
            send({ action: 'auth', user_id: userId, token: getSessionId() });

            // Start ping
            if (pingInterval) clearInterval(pingInterval);
            pingInterval = setInterval(function() {
                send({ action: 'ping' });
            }, 30000);

            trigger('connected');
        };

        ws.onmessage = function(e) {
            try {
                var data = JSON.parse(e.data);
                handleMessage(data);
            } catch (err) {
                console.error('[ChatWS] Parse error:', err);
            }
        };

        ws.onclose = function() {
            console.log('[ChatWS] Disconnected');
            connected = false;
            if (pingInterval) { clearInterval(pingInterval); pingInterval = null; }
            trigger('disconnected');
            scheduleReconnect();
        };

        ws.onerror = function(err) {
            console.error('[ChatWS] Error:', err);
        };
    }

    function handleMessage(data) {
        switch (data.action) {
            case 'auth_ok':
                console.log('[ChatWS] Authenticated as user ' + data.user_id);
                trigger('authenticated', data);
                break;
            case 'auth_error':
                console.error('[ChatWS] Auth failed:', data.message);
                fallback();
                break;
            case 'new_message':
                trigger('new_message', data);
                break;
            case 'message_sent':
                trigger('message_sent', data);
                break;
            case 'typing':
                trigger('typing', data);
                break;
            case 'user_status':
                trigger('user_status', data);
                break;
            case 'messages_read':
                trigger('messages_read', data);
                break;
            case 'pong':
                break;
            default:
                console.log('[ChatWS] Unknown message:', data);
        }
    }

    function send(data) {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify(data));
            return true;
        }
        return false;
    }

    function getSessionId() {
        // Extract PHP session ID from cookie
        var match = document.cookie.match(/PHPSESSID=([^;]+)/);
        return match ? match[1] : '';
    }

    function scheduleReconnect() {
        if (reconnectAttempts >= maxReconnect) {
            console.log('[ChatWS] Max reconnection attempts reached, using fallback');
            fallback();
            return;
        }
        var delay = Math.min(1000 * Math.pow(2, reconnectAttempts), 30000);
        reconnectAttempts++;
        console.log('[ChatWS] Reconnecting in ' + delay + 'ms (attempt ' + reconnectAttempts + ')');
        if (reconnectTimer) clearTimeout(reconnectTimer);
        reconnectTimer = setTimeout(function() { connect(); }, delay);
    }

    function fallback() {
        useFallback = true;
        connected = false;
        if (ws) { ws.onclose = null; ws.close(); ws = null; }
        trigger('fallback');
    }

    // Event system
    function on(event, callback) {
        if (!listeners[event]) listeners[event] = [];
        listeners[event].push(callback);
    }

    function off(event, callback) {
        if (!listeners[event]) return;
        listeners[event] = listeners[event].filter(function(cb) { return cb !== callback; });
    }

    function trigger(event, data) {
        if (!listeners[event]) return;
        listeners[event].forEach(function(cb) {
            try { cb(data); } catch(e) { console.error('[ChatWS] Listener error:', e); }
        });
    }

    function isConnected() { return connected; }
    function isFallback() { return useFallback; }

    function disconnect() {
        if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
        if (pingInterval) { clearInterval(pingInterval); pingInterval = null; }
        if (ws) { ws.onclose = null; ws.close(); ws = null; }
        connected = false;
    }

    return {
        init: init,
        send: send,
        on: on,
        off: off,
        isConnected: isConnected,
        isFallback: isFallback,
        disconnect: disconnect
    };
})();
