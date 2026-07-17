<?php
require_once __DIR__ . '/config/db.php';

if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Select database
$conn->query('USE freelancejob');

// Create the freelancers table with all required columns
$create_freelancers = "CREATE TABLE IF NOT EXISTS freelancers (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($create_freelancers)) {
    echo "<p style='color:green'>Freelancers table created successfully</p>";
} else {
    echo "<p style='color:red'>Failed to create freelancers table: " . $conn->error . "</p>";
}

// Check if the table was created
$check = $conn->query("DESCRIBE freelancers");
if ($check->num_rows > 0) {
    echo "<h3>Freelancers table structure:</h3><ul>";
    while ($row = $check->fetch_assoc()) {
        echo "<li>Field: " . $row['Field'] . ", Type: " . $row['Type'] . "</li>";
    }
    echo "</ul>";

    // Check if required columns exist
    $required_columns = ['title', 'hourly_rate', 'experience_years'];
    $found_columns = [];
    $missing_columns = [];

    foreach ($required_columns as $col) {
        $col_check = $conn->query("SHOW COLUMNS FROM freelancers LIKE '$col'");
        if ($col_check->num_rows > 0) {
            $found_columns[] = $col;
        } else {
            $missing_columns[] = $col;
        }
    }

    if (!empty($missing_columns)) {
        echo "<h3>Missing columns that need to be added:</h3><ul>";
        foreach ($missing_columns as $col) {
            echo "<li>$col</li>";
        }
        echo "</ul>";

        // Add missing columns
        foreach ($missing_columns as $col) {
            if ($col === 'title') {
                $alter_sql = "ALTER TABLE freelancers ADD COLUMN title VARCHAR(200) DEFAULT NULL";
            } elseif ($col === 'hourly_rate') {
                $alter_sql = "ALTER TABLE freelancers ADD COLUMN hourly_rate DECIMAL(10,2) DEFAULT NULL";
            } elseif ($col === 'experience_years') {
                $alter_sql = "ALTER TABLE freelancers ADD COLUMN experience_years INT DEFAULT NULL";
            }

            if ($conn->query($alter_sql)) {
                echo "<p style='color:green'>Added $col column to freelancers table</p>";
            } else {
                echo "<p style='color:red'>Failed to add $col column: " . $conn->error . "</p>";
            }
        }
    } else {
        echo "<p style='color:green'>All required columns exist in freelancers table</p>";

        // Now test the query that's failing in index.php
        echo "<h3>Testing the query from index.php:</h3>";
        $test_query = "
            SELECT f.id, f.full_name, f.title, f.hourly_rate, f.experience_years, u.profile_image,
                   (SELECT COUNT(*) FROM job_applications WHERE freelancer_id = f.id AND status = 'accepted') AS completed_projects,
                   (SELECT COUNT(*) FROM freelancer_skills WHERE freelancer_id = f.id) AS skill_count
            FROM freelancers f
            JOIN users u ON f.user_id = u.id
            ORDER BY completed_projects DESC, f.experience_years DESC
            LIMIT 4
        ";

        $test_result = $conn->query($test_query);
        if ($test_result && $test_result->num_rows > 0) {
            echo "<p style='color:green'>SUCCESS: The query executed successfully and returned " . $test_result->num_rows . " rows</p>";
        } else {
            echo "<p style='color:red'>FAILURE: The query failed: " . ($test_result ? $conn->error : "No result set") . "</p>";
        }
    }
}

$conn->close();

// Now try to run index.php to see if it works
echo "<p><a href='index.php'>Go to homepage (index.php)</a></p>";
?>