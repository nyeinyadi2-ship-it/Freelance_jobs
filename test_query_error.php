<?php
require_once __DIR__ . '/config/db.php';

if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->query('USE freelancejob');

// Check if freelancers table exists
echo "Checking freelancers table...<br>";
$result = $conn->query("SHOW TABLES LIKE 'freelancers'");

if ($result->num_rows === 0) {
    echo "<p style='color:red'>ERROR: freelancers table does not exist</p>";
    echo "<p>This is why the query in index.php is failing with 'Unknown column f.title'</p>";
    exit;
} else {
    echo "<p style='color:green'>Freelancers table exists</p>";
}

// Get the exact structure
$desc_result = $conn->query("DESCRIBE freelancers");
if ($desc_result && $desc_result->num_rows > 0) {
    echo "<h3>Freelancers table structure:</h3><ul>";
    while ($row = $desc_result->fetch_assoc()) {
        echo "<li>Field: '{$row['Field']}' (Type: {$row['Type']}, Null: {$row['Null']})</li>";
    }
    echo "</ul>";

    // Check what columns are missing for the query
    $query_columns = ['id', 'full_name', 'title', 'hourly_rate', 'experience_years', 'profile_image'];
    $existing_columns = [];
    $missing_columns = [];

    foreach ($query_columns as $col) {
        if ($col === 'profile_image') {
            $table_to_check = 'users';
            $alt_col = "user_id";
        } else {
            $table_to_check = 'freelancers';
        }
        
        $check_query = $col === 'profile_image' 
            ? "SHOW COLUMNS FROM users LIKE 'profile_image'" 
            : "SHOW COLUMNS FROM freelancers LIKE '{$col}'";
            
        $col_check = $conn->query($check_query);
        if ($col_check->num_rows > 0) {
            $existing_columns[] = $col;
        } else {
            $missing_columns[] = $col;
        }
    }

    echo "<h3>Columns analysis for index.php query:</h3>";
    echo "<p><strong>Existing columns in query:</strong> " . implode(', ', $existing_columns) . "</p>";
    echo "<p><strong>Missing columns in query:</strong> " . implode(', ', $missing_columns) . "</p>";

    if (!empty($missing_columns)) {
        echo "<p style='color:red'>These missing columns are causing the 'Unknown column f.title' error!</p>";
    }
} else {
    echo "<p style='color:red'>Could not retrieve freelancers table structure</p>";
}

$conn->close();

// Now test the exact query from index.php to confirm the error
echo "<h3>Testing the exact query from index.php:</h3>";

require_once __DIR__ . '/config/db.php';
$conn->query('USE freelancejob');

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

if ($test_result) {
    if ($test_result->num_rows > 0) {
        echo "<p style='color:green'>SUCCESS: Query executed successfully</p>";
        echo "<p style='color:green'>Returned " . $test_result->num_rows . " rows</p>";
    } else {
        echo "<p style='color:gray'>Query executed but returned no rows</p>";
    }
} else {
    echo "<p style='color:red'>FAILURE: Query failed with error: " . $conn->error . "</p>";
    echo "<p>This confirms the 'Unknown column f.title' error</p>";
}

$conn->close();

echo "<p><a href='index.php'>Go to homepage (index.php)</a> - This will test the query that caused the error</p>";
?>