<?php
require_once __DIR__ . '/../config/db.php';

echo "Starting wallet_transactions migration...\n";

$query = "
CREATE TABLE IF NOT EXISTS wallet_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'deposit',
    payment_method VARCHAR(50) NOT NULL,
    transaction_id VARCHAR(100) DEFAULT NULL,
    status ENUM('pending','completed','failed') DEFAULT 'completed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_wallet_trans_user (user_id),
    INDEX idx_wallet_trans_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

if ($conn->query($query) === TRUE) {
    echo "Table 'wallet_transactions' created or already exists successfully.\n";
} else {
    echo "Error creating table: " . $conn->error . "\n";
}
