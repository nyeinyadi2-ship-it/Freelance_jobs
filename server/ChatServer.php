<?php
/**
 * Ratchet WebSocket Chat Server for FreelanceHub
 * 
 * To run: php server/start_server.php
 * Requires: composer install (cboden/ratchet, react/event-loop)
 */

namespace Freelance;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class ChatServer implements MessageComponentInterface
{
    /** @var \SplObjectStorage */
    protected $clients;

    /** @var array<int, \Ratchet\ConnectionInterface> userId => connection */
    protected $userConnections;

    /** @var \mysqli */
    protected $db;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        $this->userConnections = [];
        $this->initDatabase();
        echo "[ChatServer] Server initialized\n";
    }

    protected function initDatabase(): void
    {
        require_once __DIR__ . '/../config/db.php';
        global $conn;
        $this->db = $conn;
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn);
        $conn->user_id = null;
        echo "[ChatServer] New connection: {$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $data = json_decode($msg, true);
        if (!$data || !isset($data['action'])) return;

        switch ($data['action']) {
            case 'auth':
                $this->handleAuth($from, $data);
                break;
            case 'message':
                $this->handleMessage($from, $data);
                break;
            case 'typing':
                $this->handleTyping($from, $data);
                break;
            case 'mark_read':
                $this->handleMarkRead($from, $data);
                break;
            case 'ping':
                $this->sendTo($from, ['action' => 'pong', 'server_time' => time()]);
                break;
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $this->clients->detach($conn);

        if ($conn->user_id !== null) {
            $userId = $conn->user_id;
            unset($this->userConnections[$userId]);

            // Update user offline status
            $stmt = $this->db->prepare("UPDATE users SET is_online = 0, last_seen = NOW() WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();

            // Notify all connected users about offline status
            $this->broadcastStatus($userId, false);
            echo "[ChatServer] User {$userId} disconnected\n";
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo "[ChatServer] Error: {$e->getMessage()}\n";
        $conn->close();
    }

    protected function handleAuth(ConnectionInterface $conn, array $data): void
    {
        $userId = (int) ($data['user_id'] ?? 0);
        $token = $data['token'] ?? '';

        // Simple token verification using session-like mechanism
        session_id($token);
        session_start();

        if (!isset($_SESSION['user_id']) || (int) $_SESSION['user_id'] !== $userId) {
            $this->sendTo($conn, ['action' => 'auth_error', 'message' => 'Invalid session']);
            session_write_close();
            return;
        }
        session_write_close();

        $conn->user_id = $userId;
        $this->userConnections[$userId] = $conn;

        // Update online status
        $stmt = $this->db->prepare("UPDATE users SET is_online = 1, last_activity = NOW(), last_seen = NOW() WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();

        $this->sendTo($conn, ['action' => 'auth_ok', 'user_id' => $userId]);
        $this->broadcastStatus($userId, true);

        echo "[ChatServer] User {$userId} authenticated\n";
    }

    protected function handleMessage(ConnectionInterface $from, array $data): void
    {
        $senderId = $from->user_id;
        $receiverId = (int) ($data['receiver_id'] ?? 0);
        $message = trim($data['message'] ?? '');
        $messageType = $data['message_type'] ?? 'text';
        $tempId = $data['temp_id'] ?? null;

        if (!$senderId || !$receiverId || ($message === '' && $messageType === 'text')) return;

        // Verify can chat
        require_once __DIR__ . '/../config/chat.php';
        if (!can_chat($this->db, $senderId, $receiverId)) {
            $this->sendTo($from, ['action' => 'error', 'message' => 'Cannot chat with this user']);
            return;
        }

        // Save message
        $messageId = send_message_with_attachment($this->db, $senderId, $receiverId, $message, null, $messageType);

        if ($messageId) {
            $msgs = get_messages_enhanced($this->db, $senderId, $receiverId, 0, 1);
            $saved = $msgs[0] ?? null;

            // Send to sender with temp_id mapping
            $this->sendTo($from, [
                'action' => 'message_sent',
                'message' => $saved,
                'temp_id' => $tempId,
                'message_id' => $messageId,
            ]);

            // Send to receiver
            if (isset($this->userConnections[$receiverId])) {
                $this->sendTo($this->userConnections[$receiverId], [
                    'action' => 'new_message',
                    'message' => $saved,
                    'from_user_id' => $senderId,
                ]);
            }
        }
    }

    protected function handleTyping(ConnectionInterface $from, array $data): void
    {
        $userId = $from->user_id;
        $partnerId = (int) ($data['partner_id'] ?? 0);
        $isTyping = !empty($data['is_typing']);

        if (!$userId || !$partnerId) return;

        require_once __DIR__ . '/../config/chat.php';
        set_typing_status($this->db, $userId, $partnerId, $isTyping);

        if (isset($this->userConnections[$partnerId])) {
            $this->sendTo($this->userConnections[$partnerId], [
                'action' => 'typing',
                'user_id' => $userId,
                'is_typing' => $isTyping,
            ]);
        }
    }

    protected function handleMarkRead(ConnectionInterface $from, array $data): void
    {
        $userId = $from->user_id;
        $partnerId = (int) ($data['partner_id'] ?? 0);

        if (!$userId || !$partnerId) return;

        require_once __DIR__ . '/../config/chat.php';
        mark_as_read($this->db, $userId, $partnerId);

        if (isset($this->userConnections[$partnerId])) {
            $this->sendTo($this->userConnections[$partnerId], [
                'action' => 'messages_read',
                'user_id' => $userId,
            ]);
        }
    }

    protected function broadcastStatus(int $userId, bool $online): void
    {
        $payload = [
            'action' => 'user_status',
            'user_id' => $userId,
            'is_online' => $online,
            'last_seen' => date('Y-m-d H:i:s'),
        ];

        foreach ($this->userConnections as $id => $conn) {
            if ($id !== $userId) {
                $this->sendTo($conn, $payload);
            }
        }
    }

    protected function sendTo(ConnectionInterface $conn, array $data): void
    {
        $conn->send(json_encode($data));
    }
}
