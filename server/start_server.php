<?php
/**
 * Start the WebSocket Chat Server
 * 
 * Usage: php server/start_server.php
 * Requires: composer install
 * 
 * The server runs on ws://localhost:8080
 * For production, use a process manager like Supervisor.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Freelance\ChatServer;

$port = (int) ($argv[1] ?? 8080);

echo "============================================\n";
echo "  HireWork WebSocket Chat Server\n";
echo "  Starting on ws://localhost:{$port}\n";
echo "============================================\n";

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new ChatServer()
        )
    ),
    $port
);

$server->run();
