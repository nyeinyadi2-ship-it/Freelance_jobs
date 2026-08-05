<?php
/**
 * Server-Sent Events endpoint for real-time notification badge updates
 * 
 * The client listens to this stream and updates the notification badge
 * when new notifications arrive. Falls back to polling if SSE is not supported.
 * 
 * Usage (JS):
 *   var evtSource = new EventSource('api/sse.php');
 *   evtSource.onmessage = function(e) { var data = JSON.parse(e.data); ... };
 */

require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', '86400');
    ini_set('session.cookie_lifetime', '0');
    session_start();
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$lastCheck = $_SERVER['HTTP_LAST_EVENT_ID'] ?? 0;

if ($userId === 0) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Access-Control-Allow-Origin: *');
    echo "data: " . json_encode(['error' => 'Not authenticated']) . "\n\n";
    ob_flush(); flush();
    exit;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('X-Accel-Buffering: no');

// Disable output buffering
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);
while (ob_get_level() > 0) { ob_end_clean(); }

$lastNotificationId = (int) $lastCheck;

while (true) {
    // Check for new unread notifications
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM notifications n
        WHERE n.user_id = ?
        AND n.id NOT IN (
            SELECT nr.notification_id FROM notification_reads nr WHERE nr.user_id = ?
        )
    ");
    $stmt->bind_param('ii', $userId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    $unreadCount = (int) ($row['total'] ?? 0);

    // Get latest notification ID
    $stmt2 = $conn->prepare("SELECT MAX(id) as max_id FROM notifications WHERE user_id = ?");
    $stmt2->bind_param('i', $userId);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    $row2 = $res2->fetch_assoc();
    $stmt2->close();

    $latestId = (int) ($row2['max_id'] ?? 0);

    $data = json_encode([
        'unread_count' => $unreadCount,
        'last_id' => $latestId,
        'timestamp' => time(),
    ]);

    echo "id: {$latestId}\n";
    echo "data: {$data}\n\n";
    ob_flush(); flush();

    // Check if client disconnected
    if (connection_aborted()) break;

    // Wait 5 seconds before next check
    sleep(5);
}
