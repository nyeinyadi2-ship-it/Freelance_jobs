---
description: Create and run a database migration script for FreelanceHub. Generates a PHP migration file with existence guards, runs it via CLI, and updates database_full.sql.
---

# New Migration

Create a migration script for the FreelanceHub database (`freelance_db`).

## Arguments

$ARGUMENTS — Description of the migration (e.g., "add deadline column to milestones table", "create escrow_transactions table", "change assignments status ENUM to include rejected").

## Procedure

### 1. Create the migration file

Create `migrations/migrate_<short_name>.php` with this template:

```php
<?php
/**
 * Migration: <DESCRIPTION>
 * Created: <DATE>
 */

require_once __DIR__ . '/../config/db.php';

echo "=== <DESCRIPTION> ===\n";

// --- CHECK EXISTENCE FIRST ---
// MySQL 8.x does NOT support IF NOT EXISTS in ADD COLUMN.
// Always check before altering.

// For adding a column:
$check = $conn->query("SHOW COLUMNS FROM <table> LIKE '<column>'");
if ($check && $check->num_rows === 0) {
    $conn->query("ALTER TABLE <table> ADD COLUMN <column> <type> <position>");
    if ($conn->error) {
        echo "[ERROR] " . $conn->error . "\n";
    } else {
        echo "[OK] Added column '<column>'.\n";
    }
} else {
    echo "[SKIP] Column '<column>' already exists.\n";
}

// For adding an ENUM value:
$check = $conn->query("SHOW COLUMNS FROM <table> LIKE '<column>'");
if ($check && $check->num_rows > 0) {
    $row = $check->fetch_assoc();
    $type = $row['Type']; // e.g. "enum('a','b','c')"
    if (strpos($type, "'<new_value>'") === false) {
        // Get current enum values, append new one
        preg_match_all("/'([^']+)'/", $type, $matches);
        $values = $matches[1];
        $values[] = '<new_value>';
        $enum = "enum('" . implode("','", $values) . "')";
        $default = $row['Default'] ? " DEFAULT '{$row['Default']}'" : '';
        $null = $row['Null'] === 'YES' ? ' NULL' : ' NOT NULL';
        $conn->query("ALTER TABLE <table> MODIFY COLUMN <column> {$enum}{$null}{$default}");
        echo "[OK] Added '<new_value>' to <column> ENUM.\n";
    } else {
        echo "[SKIP] '<new_value>' already in <column> ENUM.\n";
    }
}

// For creating a table:
$table_check = $conn->query("SHOW TABLES LIKE '<table>'");
if ($table_check && $table_check->num_rows === 0) {
    $conn->query("CREATE TABLE <table> (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ...
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Created table '<table>'.\n";
} else {
    echo "[SKIP] Table '<table>' already exists.\n"
}

// For removing a unique constraint:
$indexes = $conn->query("SHOW INDEX FROM <table> WHERE Column_name = '<column>' AND Non_unique = 0");
if ($indexes && $indexes->num_rows > 0) {
    while ($idx = $indexes->fetch_assoc()) {
        $conn->query("ALTER TABLE <table> DROP INDEX `{$idx['Key_name']}`");
        echo "[OK] Dropped unique index '{$idx['Key_name']}'.\n";
    }
} else {
    echo "[SKIP] No unique index on <column>.\n";
}

echo "=== Done ===\n";
$conn->close();
```

### 2. Run the migration

```bash
/c/wamp64/bin/php/php8.2.29/php.exe migrations/migrate_<short_name>.php
```

### 3. Update database_full.sql

Add the migration's SQL to `migrations/database_full.sql` under a new section with `IF NOT EXISTS` guards where possible. This file is the canonical schema reference.

### 4. Verify

- Check migration output for `[OK]` or `[SKIP]` messages
- If any `[ERROR]` messages, investigate before proceeding
- Verify the change in the database if needed

## Key rules

- **Always check existence before ALTER** — MySQL 8.x does not support `IF NOT EXISTS` for `ADD COLUMN`
- **UNIQUE constraint names are not deterministic** — use `SHOW INDEX WHERE Column_name = 'x' AND Non_unique = 0` to find ALL unique indexes
- **No FOREIGN KEY constraints** — MySQL error #1824 on this project; validate referentially in PHP code
- **`require_once __DIR__ . '/../config/db.php'`** — migration files live in `migrations/`, one level down from project root
- **`skills` table uses MyISAM** — cannot be referenced by FK constraints
- **Actual DB name is `freelance_db`** — `config/db.php` defines it; `db.sql` may say `freelancejob` (stale)
