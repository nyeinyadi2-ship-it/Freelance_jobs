<?php
/**
 * One-time migration: Add industry & company_size columns to companies table.
 * Run this script once from the browser or CLI, then delete it.
 */
require_once __DIR__ . '/config/db.php';

$messages = [];

// Helper to check column existence
function column_exists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $count = (int) $stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $count > 0;
}

// Add industry column
if (!column_exists($conn, 'companies', 'industry')) {
    $conn->query("ALTER TABLE companies ADD COLUMN industry VARCHAR(100) DEFAULT NULL AFTER established_year");
    $messages[] = ['type' => 'success', 'text' => '✅ Added column: companies.industry'];
} else {
    $messages[] = ['type' => 'info', 'text' => 'ℹ️ Column already exists: companies.industry'];
}

// Add company_size column
if (!column_exists($conn, 'companies', 'company_size')) {
    $conn->query("ALTER TABLE companies ADD COLUMN company_size VARCHAR(50) DEFAULT NULL AFTER industry");
    $messages[] = ['type' => 'success', 'text' => '✅ Added column: companies.company_size'];
} else {
    $messages[] = ['type' => 'info', 'text' => 'ℹ️ Column already exists: companies.company_size'];
}

// Also add phone to companies if missing (it exists in schema but let's be safe)
if (!column_exists($conn, 'companies', 'phone')) {
    $conn->query("ALTER TABLE companies ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER user_id");
    $messages[] = ['type' => 'success', 'text' => '✅ Added column: companies.phone'];
} else {
    $messages[] = ['type' => 'info', 'text' => 'ℹ️ Column already exists: companies.phone'];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Migration — Company Fields</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 640px; margin: 3rem auto; padding: 1rem; }
  .msg { padding: .75rem 1rem; border-radius: 8px; margin-bottom: .5rem; font-size: .95rem; }
  .success { background: #dcfce7; color: #166534; }
  .info    { background: #dbeafe; color: #1e40af; }
  .error   { background: #fee2e2; color: #991b1b; }
  h1 { font-size: 1.4rem; margin-bottom: 1.5rem; }
  a { display:inline-block; margin-top:1.5rem; padding:.6rem 1.2rem; background:#4f46e5; color:#fff; border-radius:8px; text-decoration:none; }
</style>
</head>
<body>
<h1>Database Migration: Company Fields</h1>
<?php foreach ($messages as $m): ?>
  <div class="msg <?= $m['type'] ?>"><?= htmlspecialchars($m['text']) ?></div>
<?php endforeach; ?>
<p style="margin-top:1rem;color:#555;font-size:.9rem;">
  Migration complete. You may delete <code>migrate_company_fields.php</code> from the server.
</p>
<a href="index.php">← Back to Site</a>
</body>
</html>
