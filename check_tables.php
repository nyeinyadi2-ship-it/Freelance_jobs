<?php
require_once __DIR__ . '/config/db.php';

if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed");
}

// Select database
$conn->query('USE freelancejob');

// Check freelancers table
$check = $conn->query("SHOW TABLES LIKE 'freelancers'");
if ($check->num_rows === 0) {
    echo "<h3>Freelancers table does not exist</h3>";
    echo "Creating table from scratch...";

    $sql = file_get_contents(__DIR__ . '/db.sql');
    $conn->multi_query($sql);
    
    $conn->query("USE freelancejob");
}

// Check what we have
$tables = $conn->query("SHOW TABLES");

$has_freelancers = false;
$has_skills = false;

while ($row = $tables->fetch_row()) {
    if ($row[0] === 'freelancers') {
        $has_freelancers = true;
        echo "<p style='color:green'>Found freelancers table</p>";
    }
    if ($row[0] === 'skills') {
        $has_skills = true;
        echo "<p style='color:green'>Found skills table</p>";
    }
}

if ($has_freelancers) {
    $desc_result = $conn->query("DESCRIBE freelancers");
    if ($desc_result && $desc_result->num_rows > 0) {
        echo "<h3>Freelancers table structure:</h3><ul>";
        while ($row = $desc_result->fetch_assoc()) {
            echo "<li>{$row['Field']} ({$row['Type']})</li>";
        }
        echo "</ul>";
        
        // Check for title column
        $title_check = $conn->query("SHOW COLUMNS FROM freelancers LIKE 'title'");
        if ($title_check->num_rows > 0) {
            echo "<p style='color:green'>FOUND: title column exists</p>";
        } else {
            echo "<p style='color:red'>MISSING: title column NOT found</p>";
        }
    }
}

if ($has_skills) {
    $skills_result = $conn->query("SELECT * FROM skills LIMIT 10");
    if ($skills_result && $skills_result->num_rows > 0) {
        echo "<h3>Skills sample data:</h3><ul>";
        while ($row = $skills_result->fetch_assoc()) {
            echo "<li>{$row['skill_name']}</li>";
        }
        echo "</ul>";
    }
}

$conn->close();
?>