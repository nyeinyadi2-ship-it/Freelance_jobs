<?php
require_once __DIR__ . '/../config/db.php';

try {
    $conn->begin_transaction();

    // 1. Create categories table
    $sql = "CREATE TABLE IF NOT EXISTS `categories` (
      `id` int NOT NULL AUTO_INCREMENT,
      `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $conn->query($sql);

    // 2. Insert default categories
    $default_cats = ['Web Development','Mobile Development','UI/UX Design','Graphic Design','Content Writing','Digital Marketing','Data Science','DevOps','Blockchain','Video & Animation','Translation','Other'];
    $stmt = $conn->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
    foreach ($default_cats as $cat) {
        $stmt->bind_param('s', $cat);
        $stmt->execute();
    }
    $stmt->close();

    // 3. Insert existing categories from jobs table that are not in default list
    $res = $conn->query("SELECT DISTINCT category FROM jobs WHERE category != '' AND category IS NOT NULL");
    if ($res) {
        $stmt = $conn->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
        while ($row = $res->fetch_assoc()) {
            $cat_name = trim($row['category']);
            if ($cat_name !== '') {
                $stmt->bind_param('s', $cat_name);
                $stmt->execute();
            }
        }
        $stmt->close();
    }

    $conn->commit();
    echo "Successfully created categories table and populated it with default and existing categories.\n";

} catch (Exception $e) {
    $conn->rollback();
    echo "Migration failed: " . $e->getMessage() . "\n";
}
