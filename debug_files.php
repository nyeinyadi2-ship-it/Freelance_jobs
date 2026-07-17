<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$files_to_check = [
    'config/db.php',
    'config/auth.php',
    'config/upload.php',
    'config/lang.php'
];

$functions_in_auth = [];

for ($i = 0; $i < count($files_to_check); $i++) {
    $file = $files_to_check[$i];
    echo "=== Checking $file ===<br>";
    
    if (!file_exists($file)) {
        echo "FATAL: File does not exist!<br>";
        continue;
    }
    
    // Use exec to run PHP code from the file
    $php_code = "require_once('$file');";
    \$result = exec("php -r '$php_code'", $output, $return_var);
    
    if ($return_var == 0) {
        echo "File loaded successfully<br>";
        
        // Now check what functions are defined
        if ($file == 'config/auth.php') {
            $content = file_get_contents($file);
            
            // Extract function names
            if (preg_match_all('/function (\\w+)\\(/s', $content, $matches)) {
                $functions = $matches[1];
                echo "Functions defined in $file: " . implode(', ', $functions) . "<br>";
                $functions_in_auth = $functions;
            }
        }
    } else {
        echo "FATAL: File failed to load! Error: <br>";
        foreach ($output as $line) {
            echo $line . "<br>";
        }
    }
}

// Now specifically check if current_user is defined
system("echo '<br>=== Testing current_user() function ==='");
exec("php -r 'require_once 'config/auth.php'; if (function_exists('current_user')) { echo 'current_user() function exists!'; } else { echo 'current_user() function does NOT exist!'; }'")

?>