<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

echo "<h2>Logo Debug Tool</h2>";

// Check companies table
$result = $conn->query("SHOW TABLES LIKE 'companies'");
if ($result->num_rows === 0) {
    echo "<p style='color:red'>ERROR: companies table does not exist!</p>";
    echo "<p>Run <a href='setup_tables.php'>setup_tables.php</a> first.</p>";
    exit;
}

$result = $conn->query("SELECT c.id, c.user_id, c.company_name, c.logo_image, c.profile_image, u.username, u.email, u.profile_image AS user_profile_image FROM companies c JOIN users u ON c.user_id = u.id");
echo "<h3>Companies:</h3>";
if ($result->num_rows === 0) {
    echo "<p>No companies found. Register a company first.</p>";
} else {
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>";
    echo "<tr><th>ID</th><th>User</th><th>Company</th><th>logo_image DB value</th><th>File exists?</th><th>Generated URL</th></tr>";
    while ($row = $result->fetch_assoc()) {
        $logo = $row['logo_image'];
        $file_exists = $logo ? file_exists(__DIR__ . '/uploads/' . $logo) : false;
        $url = $logo ? base_url('uploads/' . $logo) : '(no logo)';
        $file_class = $logo ? ($file_exists ? 'color:green' : 'color:red') : 'color:gray';
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['username']} ({$row['email']})</td>";
        echo "<td>{$row['company_name']}</td>";
        echo "<td>" . ($logo ? htmlspecialchars($logo) : '<em>NULL - no logo uploaded</em>') . "</td>";
        echo "<td style='{$file_class}'>" . ($logo ? ($file_exists ? 'YES' : 'NO - FILE MISSING') : '-') . "</td>";
        echo "<td>" . ($logo ? htmlspecialchars($url) : '-') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// List uploaded files
echo "<h3>Files in uploads/:</h3>";
$files = glob(__DIR__ . '/uploads/img_*');
if (empty($files)) {
    echo "<p>No image files found.</p>";
} else {
    echo "<ul>";
    foreach ($files as $f) {
        echo "<li>" . basename($f) . " (" . number_format(filesize($f)) . " bytes)</li>";
    }
    echo "</ul>";
}

$conn->close();
echo "<p><a href='company/profile.php'>Go to Company Profile</a></p>";
