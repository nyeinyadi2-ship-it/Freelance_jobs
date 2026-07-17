<?php
// Test script to debug the current_user() issue

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/db.php';
require_once 'config/auth.php';

try {
    echo "Testing current_user() function...<br>";
    
    // Current user state - exactly as in index.php
    $currentUser = current_user();
    
    echo "current_user() returned: " . print_r($currentUser, true) . "<br>";
    
    if (isset($currentUser['role'])) {
        echo "Role found: " . $currentUser['role'] . "<br>";
    } else {
        echo "Role not found in current_user result<br>";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "<br>";
    echo "Stack trace: " . $e->getTraceAsString();
}
?>