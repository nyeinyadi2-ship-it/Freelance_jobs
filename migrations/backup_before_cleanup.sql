-- Database Backup
-- Tables: escrow_transactions, notification_reads

DROP TABLE IF EXISTS `escrow_transactions`;
CREATE TABLE `escrow_transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `payment_id` int NOT NULL,
  `assignment_id` int NOT NULL,
  `company_id` int NOT NULL,
  `freelancer_id` int NOT NULL,
  `transaction_type` enum('fund','release','refund','cancel') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('pending','completed','failed') DEFAULT 'pending',
  `notes` text,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_escrow_trans_payment` (`payment_id`),
  KEY `idx_escrow_trans_assignment` (`assignment_id`),
  KEY `idx_escrow_trans_company` (`company_id`),
  KEY `idx_escrow_trans_freelancer` (`freelancer_id`),
  KEY `idx_escrow_trans_type` (`transaction_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- No data found for escrow_transactions

DROP TABLE IF EXISTS `notification_reads`;
CREATE TABLE `notification_reads` (
  `user_id` int NOT NULL,
  `notification_id` int NOT NULL,
  `read_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`notification_id`),
  KEY `notification_id` (`notification_id`),
  CONSTRAINT `notification_reads_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notification_reads_ibfk_2` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- No data found for notification_reads

