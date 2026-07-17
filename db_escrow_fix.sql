-- Add missing columns to payments table for escrow functionality
ALTER TABLE payments
    ADD COLUMN company_id INT AFTER status,
    ADD COLUMN freelancer_id INT AFTER company_id,
    ADD COLUMN escrow_status ENUM('pending', 'funded', 'released', 'refunded') DEFAULT 'pending' AFTER freelancer_id,
    ADD COLUMN funded_at TIMESTAMP NULL AFTER escrow_status,
    ADD COLUMN released_at TIMESTAMP NULL AFTER funded_at,
    ADD COLUMN refunded_at TIMESTAMP NULL AFTER released_at,
    ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER paid_at,
    ADD FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    ADD FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE;
