<?php
require_once __DIR__ . '/config/db.php';

// Run the original db.sql content to create/recreate tables
$dbContent = file_get_contents(__DIR__ . '/db.sql');

// Execute the SQL commands
$commands = explode(';\r\n', $dbContent);
$errors = [];
$created = [];
$skipped = [];

// First, check what tables exist
$conn->query('USE freelancejob');
$existingTables = [];
$result = $conn->query('SHOW TABLES');
while ($row = $result->fetch_row()) {
    $existingTables[] = $row[0];
}

// Process each CREATE TABLE command
foreach ($commands as $command) {
    $command = trim($command);
    if (empty($command)) continue;
    
    // Check if it's a CREATE TABLE statement
    if (stripos($command, 'CREATE TABLE') === 0) {
        // Extract table name
        $tableMatch = null;
        preg_match('/CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?/', $command, $tableMatch);
        if (isset($tableMatch[1])) {
            $tableName = $tableMatch[1];
            
            if (in_array($tableName, $existingTables)) {
                $skipped[] = $tableName;
                echo "<p style='color:gray'>SKIP: $tableName (already exists)</p>";
            } else {
                if ($conn->query($command)) {
                    $created[] = $tableName;
                    echo "<p style='color:green'>CREATED: $tableName</p>";
                } else {
                    $errors[] = ["Table: $tableName", $conn->error];
                    echo "<p style='color:red'>ERROR: $tableName - " . $conn->error . "</p>";
                }
            }
        }
    }
}

// Show summary
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
        echo "<li><strong>Table: {$error[0]}</strong> - {$error[1]}</li>";
    }
    echo "</ul>";
}

// Run additional commands that might be in the file
foreach ($commands as $command) {
    $command = trim($command);
    if (empty($command)) continue;
    
    // Handle USE statement
    if (stripos($command, 'USE ') === 0) {
        $dbName = str_replace('USE ', '', $command);
        $conn->query("USE $dbName");
    }
    
    // Handle INSERT statements (seed data)
    if (stripos($command, 'INSERT INTO') === 0) {
        $conn->query($command);
    }
}

$conn->close();
echo "<p><a href=''>Go to homepage</a></p>";
?>
