<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/auth.php';

try {
    // Try to call current_user function
    echo "Testing current_user function...<br>";
    
    $result = current_user();
    
    echo "Function call successful<br>";
    echo "current_user() returned: <pre>" . print_r($result, true) . "</pre>";
    
    if (isset($result['role'])) {
        echo "Role exists in the result: " . $result['role'] . "<br>";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "<br>";
    echo "Stack trace: <pre>" . $e->getTraceAsString() . "</pre>";
    
    // List defined functions in auth.php
    $auth_content = file_get_contents('config/auth.php');
    $functions = [];
    if (preg_match_all('/function (\w+)\(/s', $auth_content, $matches)) {
        $functions = $matches[1];
    }
    
    echo "Functions defined in config/auth.php: " . implode(', ', $functions) . "<br>";
}
?>