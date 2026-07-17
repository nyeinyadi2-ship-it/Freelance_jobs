-- Migration: Escrow to Direct Payment System
-- Run this AFTER backing up the database.
-- Replaces escrow-based payment system with a direct payment workflow.

-- 1. Drop old escrow-based payment_history table
DROP TABLE IF EXISTS payment_history;

-- 2. Alter payments table: remove escrow columns, add payment_method
ALTER TABLE payments
  DROP COLUMN escrow_status,
  DROP COLUMN funded_at,
  DROP COLUMN released_at,
  DROP COLUMN refunded_at,
  ADD COLUMN payment_method VARCHAR(50) DEFAULT NULL AFTER amount,
  MODIFY COLUMN status ENUM('pending', 'paid', 'completed', 'refunded') DEFAULT 'pending';

-- 3. Update existing records: map old escrow statuses to new statuses
UPDATE payments SET status = 'paid' WHERE status = 'paid' OR status = 'pending';
-- Note: after migration, pending + no escrow = waiting for payment
-- All existing funded/released payments become 'paid' to keep things simple
