<?php
echo "Using exec to test current_user()...";

$command = "php -r \"require_once 'config/auth.php';\nif (function_exists('current_user')) {\necho 'current_user exists\n';\n\$result = current_user();\nprint_r(\$result);\n} else {\necho 'current_user does NOT exist\n';\n}\
\"";

exec($command);

echo "<br><br>Checking if config/auth.php is readable...";

if (file_exists('config/auth.php')) {
    echo "✓ config/auth.php exists<br>";
    
    $size = filesize('config/auth.php');
    echo "Size: $size bytes<br>";
    
    $content = file_get_contents('config/auth.php');
    $lines = explode("\n", $content);
    echo "Lines: " . count($lines) . "<br>";
    
    echo "<br>=== Functions in auth.php ===<br>";
    $functions = [];
    foreach ($lines as $line) {
        if (preg_match('/^function (\\w+)\\(/s', $line, $matches)) {
            $functions[] = $matches[1];
        }
    }
    
    echo implode(", ", $functions) . "<br>";
    
    if (in_array('current_user', $functions)) {
        echo "✓ current_user is defined in auth.php<br>";
    } else {
        echo "✗ current_user is NOT defined in auth.php<br>";
    }
} else {
    echo "✗ config/auth.php does not exist!<br>";
}

// Now let's check if the function exists when we try to call it from index.php context
$snippet = "<?php
require_once 'config/db.php';
require_once 'config/auth.php';

// Test if current_user works
\$result = current_user();
echo 'Test successful: ' . print_r(\$result, true);
?>";

// Write to a temp file
file_put_contents('temp_test.php', $snippet);

exec("php temp_test.php");

// Clean up
if (file_exists('temp_test.php')) {
    unlink('temp_test.php');
}

?>