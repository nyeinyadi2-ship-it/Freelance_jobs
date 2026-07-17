<?php
require_once __DIR__ . '/config/db.php';

if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed");
}

$conn->query('USE freelancejob');

// Check if freelancers table exists
$tables = $conn->query("SHOW TABLES");
$freelancers_exists = false;
$skills_exists = false;

while ($row = $tables->fetch_row()) {
    if ($row[0] === 'freelancers') {
        $freelancers_exists = true;
    }
    if ($row[0] === 'skills') {
        $skills_exists = true;
    }
}

// Create missing tables if they don't exist
if (!$freelancers_exists) {
    echo "<p style='color:red'>Creating missing freelancers table...</p>";
    $conn->query("CREATE TABLE freelancers (
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
    )");
}

if (!$skills_exists) {
    echo "<p style='color:red'>Creating missing skills table...</p>";
    $conn->query("CREATE TABLE skills (
        id INT PRIMARY KEY AUTO_INCREMENT,
        skill_name VARCHAR(50) UNIQUE NOT NULL
    )");
}

// Insert sample data if needed
$freelancers_count = $conn->query("SELECT COUNT(*) FROM freelancers");
if ($freelancers_count->fetch_row()[0] === 0) {
    $conn->query("INSERT INTO freelancers (user_id, full_name, title, hourly_rate, experience_years, location) VALUES 
        (1, 'John Doe', 'Senior PHP Developer', 75.00, 5, 'New York'),
        (2, 'Jane Smith', 'Frontend Specialist', 65.00, 3, 'San Francisco'),
        (3, 'Bob Johnson', 'Backend Engineer', 80.00, 7, 'Chicago')");
}

$skills_count = $conn->query("SELECT COUNT(*) FROM skills");
if ($skills_count->fetch_row()[0] === 0) {
    $skills = ['PHP', 'JavaScript', 'HTML', 'CSS', 'MySQL'];
    foreach ($skills as $skill_name) {
        $conn->query("INSERT INTO skills (skill_name) VALUES ('$skill_name')");
    }
}

// Assign skills to freelancers
$conn->query("DELETE FROM freelancer_skills");

$skills_result = $conn->query("SELECT id FROM skills LIMIT 3");
$freelancers_result = $conn->query("SELECT id FROM freelancers LIMIT 3");

$freelancer_ids = [];
while ($row = $freelancers_result->fetch_assoc()) {
    $freelancer_ids[] = $row['id'];
}

$skill_ids = [];
while ($row = $skills_result->fetch_assoc()) {
    $skill_ids[] = $row['id'];
}

if (!empty($freelancer_ids) && !empty($skill_ids)) {
    foreach ($freelancer_ids as $freelancer_id) {
        foreach ($skill_ids as $skill_id) {
            $conn->query("INSERT INTO freelancer_skills (freelancer_id, skill_id) VALUES ($freelancer_id, $skill_id)");
        }
    }
}

// Create job_applications table if needed
$job_apps_result = $conn->query("SHOW TABLES LIKE 'job_applications'");
if ($job_apps_result->num_rows === 0) {
    echo "<p style='color:red'>Creating missing job_applications table...</p>";
    $conn->query("CREATE TABLE job_applications (
        id INT PRIMARY KEY AUTO_INCREMENT,
        job_id INT,
        freelancer_id INT,
        status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
        FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE
    )");
}

// Now test the query that's failing
echo "<h3>Testing the query from index.php...</h3>";

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
    echo "<p style='color:green'>SUCCESS: The query executed successfully!</p>";
    echo "<p>Results returned: " . $test_result->num_rows . " rows</p>";
    
    echo "<table border='1' style='width:100%; border-collapse: collapse;'>";
    echo "<tr>";
    while ($headers = $test_result->fetch_field()) {
        echo "<th style='border: 1px solid #ddd; padding: 8px;'>" . $headers->name . "</th>";
    }
    echo "</tr>";
    
    $test_result->data_seek(0);
    while ($row = $test_result->fetch_assoc()) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td style='border: 1px solid #ddd; padding: 8px;'>" . ($value ?? '') . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red'>FAILURE: The query failed!</p>";
    echo "<p>Error: " . ($test_result ? $conn->error : 'No result') . "</p>";
}

$conn->close();

echo "<p><a href='index.php'>Go to homepage (index.php)</a></p>";
?>