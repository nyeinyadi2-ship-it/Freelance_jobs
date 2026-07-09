-- Profile System Migration
-- Run after db.sql to add extended profile fields

ALTER TABLE companies
  ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER user_id,
  ADD COLUMN location VARCHAR(100) DEFAULT NULL AFTER website,
  ADD COLUMN established_year INT DEFAULT NULL AFTER location;

ALTER TABLE freelancers
  ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER user_id,
  ADD COLUMN title VARCHAR(200) DEFAULT NULL AFTER full_name,
  ADD COLUMN portfolio_url VARCHAR(255) DEFAULT NULL AFTER title,
  ADD COLUMN location VARCHAR(100) DEFAULT NULL AFTER portfolio_url,
  ADD COLUMN bio TEXT DEFAULT NULL AFTER location,
  ADD COLUMN experience_years INT DEFAULT NULL AFTER bio,
  ADD COLUMN hourly_rate DECIMAL(10,2) DEFAULT NULL AFTER experience_years;
