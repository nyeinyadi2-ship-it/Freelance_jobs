<?php
require_once __DIR__ . '/config/db.php';

if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->query('USE freelancejob');

// Create a sample freelancer if needed
echo "Creating sample freelancer data...<br>";

// First check if users table has any data
$users_check = $conn->query("SELECT COUNT(*) as count FROM users");
$users_count = $users_check->fetch_assoc()['count'];

if ($users_count === 0) {
    // Create a test user with freelancer role
    $conn->query("INSERT INTO users (username, email, password, role) VALUES ('test_freelancer', 'freelancer@test.com', 'password', 'freelancer')");
    $user_id = $conn->insert_id;
    
    echo "Created test user with ID: $user_id<br>";
    
    // Create freelancer profile
    $conn->query("INSERT INTO freelancers (user_id, full_name, title, hourly_rate, experience_years, location) VALUES ($user_id, 'John Doe', 'Senior PHP Developer', 75.00, 5, 'New York')");
    $freelancer_id = $conn->insert_id;
    
    echo "Created test freelancer with ID: $freelancer_id<br>";
} else {
    // Get existing freelancer or create one
    $freelancer_result = $conn->query("SELECT f.id, f.user_id FROM freelancers f JOIN users u ON f.user_id = u.id WHERE u.role = 'freelancer' LIMIT 1");
    
    if ($freelancer_result->num_rows > 0) {
        $row = $freelancer_result->fetch_assoc();
        $freelancer_id = $row['id'];
        echo "Using existing freelancer ID: $freelancer_id<br>";
    } else {
        // Get any user
        $user_result = $conn->query("SELECT id FROM users WHERE role = 'freelancer' LIMIT 1");
        if ($user_result->num_rows > 0) {
            $user_row = $user_result->fetch_assoc();
            $user_id = $user_row['id'];
            
            $conn->query("INSERT INTO freelancers (user_id, full_name, title, hourly_rate, experience_years, location) VALUES ($user_id, 'Test Freelancer', 'Professional Developer', 50.00, 3, 'Test City')");
            $freelancer_id = $conn->insert_id;
            
            echo "Created test freelancer for existing user ID: $freelancer_id<br>";
        } else {
            echo "<p style='color:red'>No users available to create freelancer profile</p>";
            exit;
        }
    }
}

// Now create some skills if needed
$skills_result = $conn->query("SELECT COUNT(*) as count FROM skills");
$skills_count = $skills_result->fetch_assoc()['count'];

if ($skills_count === 0) {
    $skills = ['PHP', 'JavaScript', 'HTML'];
    foreach ($skills as $skill_name) {
        $conn->query("INSERT INTO skills (skill_name) VALUES ('$skill_name')");
    }
    
    echo "Created sample skills<br>";
}

// Assign skills to the freelancer
$conn->query("DELETE FROM freelancer_skills WHERE freelancer_id = $freelancer_id");

$skills_data = $conn->query("SELECT id FROM skills LIMIT 3");
while ($skill_row = $skills_data->fetch_assoc()) {
    $skill_id = $skill_row['id'];
    $conn->query("INSERT INTO freelancer_skills (freelancer_id, skill_id) VALUES ($freelancer_id, $skill_id)");
}

echo "<p style='color:green'>Sample data created successfully</p>";

$conn->close();

// Test the exact query that's failing in index.php
echo "<h3>Testing the query from index.php:</h3>";

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

if ($test_result && $test_result->num_rows > 0) {
    echo "<p style='color:green'>SUCCESS: The query executed successfully and returned " . $test_result->num_rows . " rows</p>";
    
    // Display results
    echo "<h4>Query Results:</h4><ul>";
    while ($row = $test_result->fetch_assoc()) {
        echo "<li>ID: {$row['id']}, Name: {$row['full_name']}, Title: {$row['title']}, Hourly Rate: {$row['hourly_rate']}, Experience: {$row['experience_years']}, Profile Image: {$row['profile_image']}, Completed Projects: {$row['completed_projects']}, Skills: {$row['skill_count']}</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color:red'>FAILURE: The query failed: " . ($test_result ? $conn->error : "No result set") . "</p>";
}

$conn->close();

echo "<p><a href='index.php'>Go to homepage (index.php)</a> - This will test the query that caused the error</p>";
?>