<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if index.php includes auth.php correctly

$index_path = 'index.php';
$auth_path = 'config/auth.php';

if (!file_exists($index_path)) {
    echo "FATAL: index.php does not exist!<br>";
    exit;
}

if (!file_exists($auth_path)) {
    echo "FATAL: config/auth.php does not exist!<br>";
    exit;
}

echo "=== Checking index.php ===<br>";

$index_content = file_get_contents($index_path);

// Show first 20 lines
$lines = explode("\n", $index_content);
echo "First 20 lines of index.php:<br><pre>";
for ($i = 0; $i < min(20, count($lines)); $i++) {
    echo ($i + 1) . ": " . $lines[$i] . "<br>";
}
echo "</pre>";

echo "<br>=== Checking for auth.php inclusion ===<br>";

if (strpos($index_content, "require_once __DIR__ . '/config/auth.php'") !== false) {
    echo "✓ auth.php IS included in index.php<br>";
} elseif (strpos($index_content, "require_once __DIR__ . '/config/auth.php'") !== false) {
    echo "✓ auth.php IS included in index.php (double quotes version)<br>";
} else {
    echo "✗ auth.php is NOT included in index.php<br>";
}

echo "<br>=== Checking for current_user function usage ===<br>";

if (strpos($index_content, "\$currentUser = current_user();") !== false) {
    echo "✓ current_user() IS used in index.php<br>";
} else {
    echo "✗ current_user() is NOT used in index.php<br>";
}

echo "<br>=== Testing if we can load auth.php ===<br>";

// Try to require auth.php
try {
    require_once $auth_path;
    echo "✓ auth.php loaded successfully<br>";
    
    // Now check if current_user function exists
    if (function_exists('current_user')) {
        echo "✓ current_user() function exists<br>";
        
        // Try to call it
        \$result = current_user();
        echo "✓ current_user() function can be called<br>";
        echo "Result: <pre>" . print_r(\$result, true) . "</pre><br>";
    } else {
        echo "✗ current_user() function does NOT exist<br>";
        
        // List all functions in auth.php
        $auth_content = file_get_contents($auth_path);
        $functions = [];
        if (preg_match_all('/function (\w+)\(/s', $auth_content, $matches)) {
            $functions = $matches[1];
        }
        
        echo "Functions found in auth.php: " . implode(', ', $functions) . "<br>";
    }
} catch (Exception $e) {
    echo "✗ FATAL ERROR loading auth.php: " . $e->getMessage() . "<br>";
    echo "Stack trace: " . $e->getTraceAsString() . "<br>";
}

echo "\n=== All checks completed ===<br>";
?>