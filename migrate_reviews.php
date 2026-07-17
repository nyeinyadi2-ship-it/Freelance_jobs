<?php
require_once __DIR__ . '/config/db.php';

// Check which tables exist
$tables = $conn->query("SHOW TABLES");
$existing = [];
while ($row = $tables->fetch_row()) { $existing[] = $row[0]; }

echo "Existing tables: " . implode(', ', $existing) . "\n";

// Check freelancers table engine
if (in_array('freelancers', $existing)) {
    $eng = $conn->query("SHOW TABLE STATUS LIKE 'freelancers'")->fetch_assoc();
    echo "freelancers engine: " . $eng['Engine'] . "\n";
}

// Drop old reviews table if it exists but is broken
$conn->query("DROP TABLE IF EXISTS reviews");

$sql = "CREATE TABLE reviews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    assignment_id INT UNIQUE,
    freelancer_id INT NOT NULL,
    company_user_id INT NOT NULL,
    rating TINYINT NOT NULL,
    comment TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reviews_freelancer (freelancer_id),
    INDEX idx_reviews_assignment (assignment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "Reviews table created successfully.\n";
} else {
    echo "Error: " . $conn->error . "\n";
}

$conn->close();
