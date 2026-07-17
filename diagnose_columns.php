<?php
// Simple database fixer script
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Fixing database...<br>";

require_once __DIR__ . '/config/db.php';

if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->query('USE freelancejob');

// Check if freelancers table exists
echo "Checking freelancers table...<br>";
$result = $conn->query("SHOW TABLES LIKE 'freelancers'");

if ($result->num_rows === 0) {
    echo "Creating freelancers table...<br>";
    
    // Create freelancers table with all required columns
    $create_sql = "CREATE TABLE freelancers (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT UNIQUE,
        phone VARCHAR(20) DEFAULT NULL,
        full_name VARCHAR(100),
        title VARCHAR(200) DEFAULT NULL,
        location VARCHAR(255) DEFAULT NULL,
        bio TEXT DEFAULT NULL,
        experience_years INT DEFAULT NULL,
        hourly_rate DECIMAL(10,2) DEFAULT NULL,
        portfolio_url VARCHAR(255),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    
    if ($conn->query($create_sql)) {
        echo "<p style='color:green'>Successfully created freelancers table</p>";
    } else {
        echo "<p style='color:red'>Failed to create freelancers table: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color:gray'>Freelancers table already exists</p>";
}

// Check table structure
$desc_result = $conn->query("DESCRIBE freelancers");
if ($desc_result && $desc_result->num_rows > 0) {
    echo "<h3>Current freelancers table structure:</h3><ul>";
    while ($row = $desc_result->fetch_assoc()) {
        echo "<li>Field: " . $row['Field'] . ", Type: " . $row['Type'] . "</li>";
    }
    echo "</ul>";

    // Check if the 'title' column exists
    $title_col = $conn->query("SHOW COLUMNS FROM freelancers LIKE 'title'");
    if ($title_col->num_rows === 0) {
        echo "<p style='color:red'>ERROR: 'title' column does not exist in freelancers table</p>";
        echo "<p>The query 'SELECT f.title FROM freelancers f' will fail with the error: Unknown column 'f.title'</p>";
    } else {
        echo "<p style='color:green'>Column 'title' exists in freelancers table</p>";
    }
} else {
    echo "<p style='color:red'>Could not retrieve freelancers table structure</p>";
}

$conn->close();

// Try to run a test to see if the problematic query works
echo "<p><a href='index.php'>Go to homepage (index.php)</a> - This will test the query that caused the error</p>";
?>