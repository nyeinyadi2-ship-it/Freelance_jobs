<?php
require_once __DIR__ . '/config/db.php';

if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->query('USE freelancejob');

// Clean up: drop and recreate tables
echo "<h2>Resetting database...<br></h2>";

// Drop tables if they exist
$conn->query("DROP TABLE IF EXISTS freelancers");
$conn->query("DROP TABLE IF EXISTS users");
$conn->query("DROP TABLE IF EXISTS skills");
$conn->query("DROP TABLE IF EXISTS job_applications");

// Recreate all tables from db.sql
echo "<h2>Creating tables from db.sql...<br></h2>";
$conn->query("USE freelancejob");

// Users table
$conn->query("CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    profile_image VARCHAR(255) DEFAULT NULL,
    role ENUM('admin', 'company', 'freelancer') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

echo "<p style='color:green'>Created users table</p>";

// Freelancers table
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

echo "<p style='color:green'>Created freelancers table</p>";

// Skills table
$conn->query("CREATE TABLE skills (
    id INT PRIMARY KEY AUTO_INCREMENT,
    skill_name VARCHAR(50) UNIQUE NOT NULL
)");

echo "<p style='color:green'>Created skills table</p>";

// Job applications table
$conn->query("CREATE TABLE job_applications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    job_id INT,
    freelancer_id INT,
    status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE
)");

echo "<p style='color:green'>Created job_applications table</p>";

// Companies table
$conn->query("CREATE TABLE companies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNIQUE,
    phone VARCHAR(20) DEFAULT NULL,
    company_name VARCHAR(100),
    website VARCHAR(255),
    location VARCHAR(255) DEFAULT NULL,
    established_year INT DEFAULT NULL,
    description TEXT,
    logo_image VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

echo "<p style='color:green'>Created companies table</p>";

// Jobs table
$conn->query("CREATE TABLE jobs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT,
    title VARCHAR(200) NOT NULL,
    category VARCHAR(100) NOT NULL DEFAULT '',
    experience_level ENUM('beginner', 'intermediate', 'expert') DEFAULT 'intermediate',
    gender_requirement ENUM('any', 'male', 'female') DEFAULT 'any',
    description TEXT,
    budget DECIMAL(10, 2),
    deadline DATETIME NULL,
    duration VARCHAR(100) DEFAULT NULL,
    freelancers_needed INT DEFAULT 1,
    visibility ENUM('public', 'private') DEFAULT 'public',
    attachment VARCHAR(255) DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected', 'completed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
)");

echo "<p style='color:green'>Created jobs table</p>";

// Freelancer_skills table
$conn->query("CREATE TABLE freelancer_skills (
    freelancer_id INT,
    skill_id INT,
    PRIMARY KEY (freelancer_id, skill_id),
    FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
)");

echo "<p style='color:green'>Created freelancer_skills table</p>";

// Jobs skills table
$conn->query("CREATE TABLE job_skills (
    job_id INT,
    skill_id INT,
    PRIMARY KEY (job_id, skill_id),
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
)");

echo "<p style='color:green'>Created job_skills table</p>";

$conn->query("CREATE TABLE IF NOT EXISTS messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    message TEXT NOT NULL,
    status ENUM('unread','read') DEFAULT 'unread',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_messages_sender (sender_id),
    INDEX idx_messages_receiver (receiver_id),
    INDEX idx_messages_status (receiver_id, status),
    INDEX idx_messages_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

echo "<p style='color:green'>Created messages table</p>";

// jobs Table creates automatically

// Insert sample data
echo "<h2>Inserting sample data...<br></h2>";

// Users
$conn->query("INSERT INTO users (username, email, password, role) VALUES 
    ('admin', 'admin@platform.com', '\$2y\$10\$fLhKLQuCby5WGCF3wq4z3e7Lox/Y6xggMUdAWPPmaEp6Ui4QT1Xcm', 'admin'),
    ('john_doe', 'freelancer1@platform.com', 'password', 'freelancer'),
    ('jane_smith', 'freelancer2@platform.com', 'password', 'freelancer'),
    ('bob_wilson', 'freelancer3@platform.com', 'password', 'freelancer'),
    ('acme_corp', 'company1@platform.com', 'password', 'company'),
    ('tech_startup', 'company2@platform.com', 'password', 'company')");

echo "<p style='color:green'>Inserted 6 users</p>";

// Skills
$conn->query("INSERT INTO skills (skill_name) VALUES 
    ('PHP'),
    ('JavaScript'),
    ('HTML'),
    ('CSS'),
    ('MySQL'),
    ('React.js'),
    ('Node.js'),
    ('Python'),
    ('UI/UX Design'),
    ('Content Writing')");

echo "<p style='color:green'>Inserted 10 skills</p>";

// Companies
$conn->query("INSERT INTO companies (user_id, company_name, logo_image, phone) VALUES 
    (5, 'Acme Corporation', 'logo1.png', '+1234567890'),
    (6, 'Tech Startup Inc', 'logo2.png', '+1987654321')");

echo "<p style='color:green'>Inserted 2 companies</p>";

// Freelancers
$conn->query("INSERT INTO freelancers (user_id, full_name, title, hourly_rate, experience_years, location, bio) VALUES 
    (1, 'John Doe', 'Senior PHP Developer', 75.00, 5, 'New York', 'Experienced full-stack developer'),
    (2, 'Jane Smith', 'Frontend Specialist', 65.00, 3, 'San Francisco', 'React and Vue expert'),
    (3, 'Bob Wilson', 'Backend Engineer', 80.00, 7, 'Chicago', 'Node.js and Python specialist')");

echo "<p style='color:green'>Inserted 3 freelancers</p>";

// Job applications
$conn->query("INSERT INTO job_applications (job_id, freelancer_id, status) VALUES 
    (1, 1, 'accepted'),
    (1, 2, 'pending'),
    (2, 3, 'accepted'),
    (3, 1, 'accepted')");

echo "<p style='color:green'>Inserted 4 job applications</p>";

// Freelancer_skills relationship
$skills = [1, 2, 3]; // PHP, JavaScript, HTML
foreach ($skills as $skill_id) {
    $conn->query("INSERT INTO freelancer_skills (freelancer_id, skill_id) VALUES (1, $skill_id)");
    $conn->query("INSERT INTO freelancer_skills (freelancer_id, skill_id) VALUES (2, $skill_id)");
    $conn->query("INSERT INTO freelancer_skills (freelancer_id, skill_id) VALUES (3, $skill_id)");
}

echo "<p style='color:green'>Assigned skills to freelancers</p>";

// Now test the query from index.php
function debug_query($query, $description) {
    echo "<h3>$description<br></h3>";
    echo "<p>Query: <code>" . substr($query, 0, 200) . "</code></p>";
    $result = $GLOBALS['conn']->query($query);
    if ($result) {
        if ($result->num_rows > 0) {
            echo "<p style='color:green'>SUCCESS: Query returned " . $result->num_rows . " rows</p>";
            echo "<table border='1' style='width:100%; border-collapse: collapse;'>";
            echo "<tr>";
            $fields = $result->fetch_fields();
            if ($fields) {
                foreach ($fields as $field) {
                    echo "<th style='border: 1px solid #ddd; padding: 8px;'>" . $field->name . "</th>";
                }
            }
            echo "</tr>";
            $result->data_seek(0);
            while ($row = $result->fetch_assoc()) {
                echo "<tr>";
                foreach ($row as $value) {
                    echo "<td style='border: 1px solid #ddd; padding: 8px;'>" . ($value ?? '') . "</td>";
                }
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p style='color:gray'>Query executed but returned no rows</p>";
        }
    } else {
        echo "<p style='color:red'>FAILURE: Query failed with error: " . $GLOBALS['conn']->error . "</p>";
    }
    echo "<hr>";
}

echo "<h2>Testing the query that was failing in index.php</h2>";

$test_query = "
    SELECT f.id, f.full_name, f.title, f.hourly_rate, f.experience_years, u.profile_image,
           (SELECT COUNT(*) FROM job_applications WHERE freelancer_id = f.id AND status = 'accepted') AS completed_projects,
           (SELECT COUNT(*) FROM freelancer_skills WHERE freelancer_id = f.id) AS skill_count
    FROM freelancers f
    JOIN users u ON f.user_id = u.id
    ORDER BY completed_projects DESC, f.experience_years DESC
    LIMIT 4
";

debug_query($test_query, "Testing the index.php query");

$conn->close();

echo "<p><a href='index.php'>Go to homepage (index.php)</a></p>";
?>