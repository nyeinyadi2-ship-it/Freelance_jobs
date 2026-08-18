<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'freelance_db');

date_default_timezone_set('Asia/Yangon');

class LoggedMysqli extends mysqli {
    public static $queryCount = 0;
    public static $queryTime = 0;
    public static $queries = [];

    #[\ReturnTypeWillChange]
    public function query($query, $resultmode = MYSQLI_STORE_RESULT) {
        $start = microtime(true);
        $res = parent::query($query, $resultmode);
        $time = microtime(true) - $start;
        self::$queryCount++;
        self::$queryTime += $time;
        self::$queries[] = ['q' => $query, 't' => $time];
        return $res;
    }
    #[\ReturnTypeWillChange]
    public function prepare($query) {
        $start = microtime(true);
        $res = parent::prepare($query);
        $time = microtime(true) - $start;
        self::$queryCount++;
        self::$queryTime += $time;
        self::$queries[] = ['q' => $query, 't' => $time];
        return $res;
    }
}

$conn = new LoggedMysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die('Database connection failed: ' . htmlspecialchars($conn->connect_error));
}

$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+06:30'");

$GLOBAL_START = microtime(true);
register_shutdown_function(function() use ($GLOBAL_START) {
    $time = microtime(true) - $GLOBAL_START;
    $log = date('Y-m-d H:i:s') . " | URI: " . ($_SERVER['REQUEST_URI'] ?? 'CLI') . " | Total Time: {$time}s | DB Time: " . LoggedMysqli::$queryTime . "s | Queries: " . LoggedMysqli::$queryCount;
    $slowest = '';
    $maxT = 0;
    foreach (LoggedMysqli::$queries as $q) {
        if ($q['t'] > $maxT) { $maxT = $q['t']; $slowest = $q['q']; }
    }
    $log .= " | Slowest: {$maxT}s => " . substr($slowest, 0, 100) . "...\n";
    file_put_contents(__DIR__ . '/../perf.log', $log, FILE_APPEND);
});
