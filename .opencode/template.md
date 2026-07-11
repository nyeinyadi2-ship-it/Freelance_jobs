<template>
## Objective
- Implement a complete Real-Time Chat and Notification System (Ratchet PHP WebSocket + AJAX fallback) with typing indicator, emoji picker, file attachments, and real-time notifications — integrated into the existing PHP/MySQL/Tailwind freelance job portal without breaking existing features.

## Important Details
- Existing codebase already has AJAX-based chat (config/chat.php, api/chat.php, chat/index.php) and notifications (config/notifications.php, api/notifications.php, notifications.php) with 3s/30s polling
- New chat features: WebSocket (Ratchet), typing indicator, emoji picker, file attachment upload, read/seen status, online/offline, last seen
- New notification features: SSE real-time, missing notification types, dashboard widgets
- WebSocket server uses Ratchet PHP (via Composer); AJAX polling fallback for Windows dev
- Theme: blue/purple/white gradient, glassmorphism cards, Messenger-style UI, mobile responsive

## Work State
### Completed
- **Database Migration** (`db_chat_enhancement.sql`): new tables (message_attachments, conversations, typing_status, notification_reads), new columns (last_activity, is_online, last_seen, message_type, message_meta)
- **config/chat.php**: enhanced with send_message_with_attachment(), upload_chat_attachment(), get_message_attachments(), set/get_typing_status(), get_last_seen_text(), get_messages_enhanced(), get_or_create_conversation_id(), update_user_online_status(), chat_file_url(), chat_file_icon()
- **api/chat.php**: enhanced with file upload (multipart/form-data), typing indicator endpoints, enhanced message fetching with attachments, notification mark_read endpoint
- **config/notifications.php**: added 9 new notification types (payment_released, escrow_funded, refund_completed, withdrawal_approved, withdrawal_rejected, admin_announcement, review_received, work_submitted, revision_requested) with icons and labels; added create_admin_announcement() function
- **WebSocket Server** (`server/`): composer.json, ChatServer.php (Ratchet PHP), start_server.php, start_chat_server.bat for production real-time messaging
- **WebSocket Client** (`assets/js/chat-websocket.js`): WS client with AJAX polling fallback, reconnect logic, typing events, user status broadcasts
- **Emoji Picker** (`assets/js/emoji-picker.js`): lightweight no-dependency emoji picker with 300+ emojis
- **Chat UI** (`chat/index.php`): Messenger/WhatsApp-style with emoji picker button, file attachment upload with preview, typing indicator display, WebSocket + AJAX dual transport, attachment rendering (images inline, other files as download links), system message support, auto-scroll, mark-read on scroll
- **SSE Real-Time Notifications** (`api/sse.php`): Server-Sent Events endpoint for live notification badge updates
- **Notification SSE Client** (`assets/js/notification-sse.js`): SSE listener with AJAX polling fallback, badge auto-update
- **Navbar Integration** (`includes/navbar.php`): SSE init, reduced polling to 15s fallback
- **Freelancer Layout** (`includes/freelancer_footer.php`): SSE init for all freelancer pages
- **Notification Triggers**: work_submitted (my_tasks.php), revision_requested + work_approved + payment_released (view_applications.php) — all wire proper notification types
- **Admin Dashboard Widget** (`admin/admin_dashboard.php`): Recent Notifications card
- **Freelancer Dashboard**: existing Notifications tab with mark-all-read

### Active
- (none — core feature set complete)

### Blocked
- Escrow, refund, withdrawal, review systems not implemented in codebase; notification triggers for these types will be added when those features are built
- Admin announcement form page not created (function exists; needs admin UI)

## Next Move
1. [If needed] Verify all files have no PHP syntax errors
2. [If needed] Test chat UI by opening chat/index.php in browser
3. [Future] Create admin announcement form (admin/announcements.php)
4. [Future] Wire escrow_funded, refund_completed, withdrawal_approved/rejected, review_received when those features are built

## Relevant Files
- `config/chat.php` (enhanced): attachment upload, typing indicator, conversation management, online status functions
- `api/chat.php` (enhanced): file upload, typing set/get, enhanced message fetch, notification mark_read
- `config/notifications.php` (enhanced): 9 new notification types with icons/labels, create_admin_announcement()
- `db_chat_enhancement.sql`: new tables (message_attachments, conversations, typing_status, notification_reads), columns (last_activity, is_online, last_seen, message_type, message_meta)
- `chat/index.php` (enhanced): full Messenger/WhatsApp-style chat with emoji picker, file upload, typing indicator, WebSocket + AJAX dual transport
- `api/sse.php` (new): Server-Sent Events endpoint for real-time notification badge updates
- `server/ChatServer.php` (new): Ratchet PHP WebSocket server handler
- `server/start_server.php` (new): WebSocket server launcher
- `assets/js/chat-websocket.js` (new): WebSocket client with AJAX fallback
- `assets/js/emoji-picker.js` (new): Lightweight emoji picker
- `assets/js/notification-sse.js` (new): SSE client with polling fallback
- `includes/navbar.php` (enhanced): SSE initialization, reduced polling
- `includes/freelancer_footer.php` (enhanced): SSE initialization
- `company/view_applications.php` (enhanced): revision_requested action + notification, improved payment notification types
- `admin/admin_dashboard.php` (enhanced): Recent Notifications widget
- `freelancer/my_tasks.php`: already had work_submitted notification
- `freelancer/dashboard.php`: existing Notifications tab with list
</template>
