<?php
/**
 * Run once to create ALL missing tables: http://localhost/freelancer_job/setup_tables.php
 */
require_once __DIR__ . '/config/db.php';

// All tables from db.sql, in order (respects foreign key dependencies)
$tables = [
    "CREATE TABLE IF NOT EXISTS users (
        id INT PRIMARY KEY AUTO_INCREMENT,
        username VARCHAR(50) NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        profile_image VARCHAR(255) DEFAULT NULL,
        role ENUM('admin', 'company', 'freelancer') NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS companies (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS freelancers (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS skills (
        id INT PRIMARY KEY AUTO_INCREMENT,
        skill_name VARCHAR(50) UNIQUE NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS freelancer_skills (
        freelancer_id INT,
        skill_id INT,
        PRIMARY KEY (freelancer_id, skill_id),
        FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE,
        FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS jobs (
        id INT PRIMARY KEY AUTO_INCREMENT,
        company_id INT,
        title VARCHAR(200) NOT NULL,
        description TEXT,
        budget DECIMAL(10, 2),
        status ENUM('pending', 'approved', 'rejected', 'completed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS job_applications (
        id INT PRIMARY KEY AUTO_INCREMENT,
        job_id INT,
        freelancer_id INT,
        status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
        FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS assignments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        job_id INT UNIQUE,
        freelancer_id INT,
        status ENUM('assigned', 'working', 'submitted', 'completed') DEFAULT 'assigned',
        submission_link VARCHAR(255),
        assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
        FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS notifications (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        from_user_id INT DEFAULT NULL,
        type VARCHAR(50) NOT NULL,
        message VARCHAR(500) NOT NULL,
        link VARCHAR(255) DEFAULT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_notifications_user_read (user_id, is_read),
        INDEX idx_notifications_type (type),
        INDEX idx_notifications_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS payments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        assignment_id INT UNIQUE,
        amount DECIMAL(10, 2),
        status ENUM('pending', 'paid') DEFAULT 'pending',
        paid_at TIMESTAMP NULL,
        FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS messages (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

// Check existing tables
$result = $conn->query("SHOW TABLES");
$existing = [];
while ($row = $result->fetch_row()) {
    $existing[] = $row[0];
}

echo "<h2>Existing tables: " . (empty($existing) ? '(none)' : implode(', ', $existing)) . "</h2>";

foreach ($tables as $sql) {
    preg_match('/`(\w+)`/', $sql, $m);
    $name = $m[1] ?? 'unknown';
    if (in_array($name, $existing)) {
        echo "<p style='color:gray'>SKIP: $name (already exists)</p>";
    } elseif ($conn->query($sql)) {
        echo "<p style='color:green'>CREATED: $name</p>";
    } else {
        echo "<p style='color:red'>ERROR: $name - " . $conn->error . "</p>";
    }
}

// Add last_activity column if missing
$col = $conn->query("SHOW COLUMNS FROM users LIKE 'last_activity'");
if ($col->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN last_activity TIMESTAMP NULL AFTER created_at");
    echo "<p style='color:green'>Added last_activity column to users</p>";
}

// Add account_status column if missing
$col = $conn->query("SHOW COLUMNS FROM users LIKE 'account_status'");
if ($col->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN account_status ENUM('active', 'suspended', 'blocked') DEFAULT 'active' AFTER last_activity");
    echo "<p style='color:green'>Added account_status column to users</p>";
}

// Seed data: admin user (only if not exists)
$check = $conn->query("SELECT id FROM users WHERE email = 'admin@platform.com' LIMIT 1");
if ($check->num_rows === 0) {
    $conn->query("INSERT INTO users (username, email, password, role) VALUES ('admin', 'admin@platform.com', '\$2y\$10\$fLhKLQuCby5WGCF3wq4z3e7Lox/Y6xggMUdAWPPmaEp6Ui4QT1Xcm', 'admin')");
    echo "<p style='color:green'>Created admin user (admin@platform.com / admin123)</p>";
}

// Seed data: skills (only if table is empty)
$check = $conn->query("SELECT COUNT(*) AS cnt FROM skills");
$skill_count = (int) $check->fetch_assoc()['cnt'];
if ($skill_count === 0) {
    $skills = ['PHP','MySQL','JavaScript','HTML','CSS','Tailwind CSS','Bootstrap','React.js','Vue.js','Node.js','Express.js','Laravel','CodeIgniter','Python','Java','C#','C++','UI/UX Design','Graphic Design','Logo Design','Brand Identity Design','Adobe Photoshop','Adobe Illustrator','Adobe InDesign','Figma','Adobe XD','Canva','Video Editing','Motion Graphics','Animation','Content Writing','Copywriting','Blog Writing','Article Writing','SEO Writing','Translation','Digital Marketing','Social Media Marketing','Email Marketing','E-commerce'];
    $placeholders = implode(',', array_fill(0, count($skills), '(?)'));
    $types = str_repeat('s', count($skills));
    $stmt = $conn->prepare("INSERT INTO skills (skill_name) VALUES {$placeholders}");
    $stmt->bind_param($types, ...$skills);
    $stmt->execute();
    $stmt->close();
    echo "<p style='color:green'>Inserted " . count($skills) . " skills</p>";
}

// Show final table list
$result = $conn->query("SHOW TABLES");
echo "<h2>Final tables:</h2><ul>";
while ($row = $result->fetch_row()) {
    echo "<li>{$row[0]}</li>";
}
echo "</ul>";

$conn->close();
echo "<p><a href='admin/admin_dashboard.php'>Go to Admin Dashboard</a></p>";
