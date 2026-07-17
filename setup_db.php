<?php
// Simple SQL script runner
error_reporting(E_ALL);
ini_set('display_errors', 1);

function execute_sql_file($file_path) {
    if (!file_exists($file_path)) {
        echo "ERROR: SQL file $file_path not found!<br>";
        return false;
    }
    
    $sql = file_get_contents($file_path);
    
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, '');
    if ($conn->connect_error) {
        echo "ERROR: Database connection failed: " . $conn->connect_error . "<br>";
        return false;
    }
    
    // Create database
    if ($conn->query("CREATE DATABASE IF NOT EXISTS freelancejob")) {
        echo "Database 'freelancejob' created or already exists<br>";
    } else {
        echo "ERROR: Failed to create database: " . $conn->error . "<br>";
        $conn->close();
        return false;
    }
    
    // Use database
    $conn->select_db('freelancejob');
    
    // Split SQL by semicolon followed by newline (common pattern in SQL dumps)
    $commands = preg_split('/;\r?\n/', $sql);
    
    $errors = [];
    $created = [];
    $skipped = [];
    $existing_tables = [];
    
    // Get existing tables
    $tables_result = $conn->query("SHOW TABLES");
    if ($tables_result) {
        while ($row = $tables_result->fetch_row()) {
            $existing_tables[] = $row[0];
        }
    }
    
    $total_commands = 0;
    $executed_commands = 0;
    
    foreach ($commands as $command) {
        $command = trim($command);
        if (empty($command)) continue;
        
        $total_commands++;
        
        // Extract table name from CREATE TABLE command
        $table_name = null;
        if (preg_match('/CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?/', $command, $matches)) {
            $table_name = $matches[1];
        }
        
        // Skip if table already exists
        if ($table_name && in_array($table_name, $existing_tables)) {
            $skipped[] = $table_name;
            $executed_commands++;
            continue;
        }
        
        // Execute the command
        if ($conn->query($command)) {
            $executed_commands++;
            if ($table_name) {
                $created[] = $table_name;
                $existing_tables[] = $table_name; // Add to list so subsequent references are skipped
            }
        } else {
            $errors[] = ["Command: "$command"", $conn->error];
        }
    }
    
    $conn->close();
    
    echo "<h1>SQL Script Execution Results</h1>";
    echo "<p>Total commands processed: $total_commands</p>";
    echo "<p>Successfully executed: $executed_commands</p>";
    
    if (!empty($created)) {
        echo "<h2>Created tables:</h2><ul>";
        foreach ($created as $table) {
            echo "<li>$table</li>";
        }
        echo "</ul>";
    }
    
    if (!empty($skipped)) {
        echo "<h2>Skipped tables (already exist):</h2><ul>";
        foreach ($skipped as $table) {
            echo "<li>$table</li>";
        }
        echo "</ul>";
    }
    
    if (!empty($errors)) {
        echo "<h2>Errors:</h2><ul>";
        foreach ($errors as $error) {
            echo "<li><strong>$error[0]</strong> - $error[1]</li>";
        }
        echo "</ul>";
    }
    
    echo "<p><a href=''>Back to index</a></p>";
}

// Check if config/db.php exists
$config_path = __DIR__ . '/config/db.php';
if (file_exists($config_path)) {
    require_once $config_path;
    
    echo "<h1>Setting up database</h1>";
    echo "<p>Running db.sql to create tables...</p>";
    
    if (execute_sql_file(__DIR__ . '/db.sql')) {
        echo "<p style='color:green'>Database setup completed successfully</p>";
    } else {
        echo "<p style='color:red'>Database setup failed</p>";
    }
} else {
    echo "<h1>Error</h1>";
    echo "<p>config/db.php not found at $config_path</p>";
}
?>
