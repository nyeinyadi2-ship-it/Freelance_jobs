<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Fixing database structure...<br>";

// Connect to database
require_once __DIR__ . '/config/db.php';

if (!isset($conn) || $conn->connect_error) {
    echo "Database connection failed: " . $conn->connect_error . "<br>";
    exit;
}

// Use database
$conn->query('USE freelancejob');

// Check if freelancers table exists
$freelancers_check = $conn->query("SHOW TABLES LIKE 'freelancers'");
if ($freelancers_check->num_rows === 0) {
    echo "<p>Creating freelancers table from db.sql...</p>";
    
    // Read db.sql and create the table
    $sql = file_get_contents(__DIR__ . '/db.sql');
    
    // Execute table creation commands
    $commands = preg_split('/;\\s*/', $sql);
    foreach ($commands as $command) {
        $command = trim($command);
        if (empty($command) || strpos($command, 'CREATE TABLE') !== 0) continue;
        
        // Check table name
        $table_match = null;
        preg_match('/CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?/', $command, $table_match);
        if (isset($table_match[1])) {
            $table_name = $table_match[1];
            echo "Creating table: $table_name<br>";
            
            // Try to create the table
            if ($conn->query($command)) {
                echo "  - Created successfully<br>";
            } else {
                echo "  - ERROR: " . $conn->error . "<br>";
            }
        }
    }
} else {
    echo "<p>Freelancers table already exists</p>";
}

// Now add missing columns to freelancers table if needed
$columns = ['title', 'hourly_rate', 'experience_years'];

foreach ($columns as $col) {
    $check = $conn->query("SHOW COLUMNS FROM freelancers LIKE '{$col}'");
    if ($check->num_rows === 0) {
        // Add the column with appropriate type
        if ($col === 'title') {
            $sql = "ALTER TABLE freelancers ADD COLUMN title VARCHAR(200) DEFAULT NULL";
        } elseif ($col === 'hourly_rate') {
            $sql = "ALTER TABLE freelancers ADD COLUMN hourly_rate DECIMAL(10,2) DEFAULT NULL";
        } elseif ($col === 'experience_years') {
            $sql = "ALTER TABLE freelancers ADD COLUMN experience_years INT DEFAULT NULL";
        }
        
        if ($conn->query($sql)) {
            echo "<p style='color:green'>Added '{$col}' column to freelancers table successfully</p>";
        } else {
            echo "<p style='color:red'>Failed to add '{$col}' column: " . $conn->error . "</p>";
        }
    } else {
        echo "<p style='color:gray'>Column '{$col}' already exists in freelancers table</p>";
    }
}

$conn->close();

echo "<p><a href='index.php'>Go to homepage</a></p>";
?>
