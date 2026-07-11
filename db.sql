CREATE DATABASE IF NOT EXISTS freelancejob;
USE freelancejob;

-- 1. Users Table
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    profile_image VARCHAR(255) DEFAULT NULL,
    role ENUM('admin', 'company', 'freelancer') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Companies Table
CREATE TABLE companies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNIQUE,
    phone VARCHAR(20) DEFAULT NULL,
    company_name VARCHAR(100),
    website VARCHAR(255),
    location VARCHAR(255) DEFAULT NULL,
    established_year INT DEFAULT NULL,
    industry VARCHAR(100) DEFAULT NULL,
    company_size VARCHAR(50) DEFAULT NULL,
    description TEXT,
    logo_image VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 3. Freelancers Table
CREATE TABLE freelancers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT UNIQUE,
    phone VARCHAR(20) DEFAULT NULL,
    full_name VARCHAR(100),
    title VARCHAR(200) DEFAULT NULL,
    location VARCHAR(255) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    experience_years INT DEFAULT NULL,
    hourly_rate DECIMAL(10,2) DEFAULT NULL,
    portfolio_url VARCHAR(255),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 4. Skills Table
CREATE TABLE skills (
    id INT PRIMARY KEY AUTO_INCREMENT,
    skill_name VARCHAR(50) UNIQUE NOT NULL
);

-- 5. Freelancer_Skills (Many-to-Many Relationship)
CREATE TABLE freelancer_skills (
    freelancer_id INT,
    skill_id INT,
    PRIMARY KEY (freelancer_id, skill_id),
    FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
);

-- 6. Jobs Table
CREATE TABLE jobs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    company_id INT,
    title VARCHAR(200) NOT NULL,
    category VARCHAR(100) NOT NULL DEFAULT '',
    experience_level ENUM('beginner', 'intermediate', 'expert') DEFAULT 'intermediate',
    gender_requirement ENUM('any', 'male', 'female') DEFAULT 'any',
    description TEXT,
    budget DECIMAL(10, 2),
    deadline DATETIME NULL,
    duration VARCHAR(100) DEFAULT NULL,
    freelancers_needed INT DEFAULT 1,
    visibility ENUM('public', 'private') DEFAULT 'public',
    attachment VARCHAR(255) DEFAULT NULL,
    status ENUM('pending', 'approved', 'rejected', 'completed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

-- 6b. Job_Skills Table (Many-to-Many: Jobs <-> Skills)
CREATE TABLE job_skills (
    job_id INT,
    skill_id INT,
    PRIMARY KEY (job_id, skill_id),
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Job_Applications Table
CREATE TABLE job_applications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    job_id INT,
    freelancer_id INT,
    status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE
);

-- 8. Assignments Table (အလုပ်တာဝန်ပေးအပ်မှု)
CREATE TABLE assignments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    job_id INT UNIQUE,
    freelancer_id INT,
    status ENUM('assigned', 'submitted', 'completed') DEFAULT 'assigned',
    submission_link VARCHAR(255),
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. Notifications Table
CREATE TABLE notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    from_user_id INT DEFAULT NULL,
    type VARCHAR(50) NOT NULL,
    message VARCHAR(500) NOT NULL,
    link VARCHAR(255) DEFAULT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_notifications_user_read (user_id, is_read),
    INDEX idx_notifications_type (type),
    INDEX idx_notifications_created (created_at)
);

-- 9. Payments Table
CREATE TABLE payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    assignment_id INT UNIQUE,
    amount DECIMAL(10, 2),
    status ENUM('pending', 'paid') DEFAULT 'pending',
    paid_at TIMESTAMP NULL,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
);

-- 11. Messages Table (Simple Chat System)
ALTER TABLE users ADD COLUMN last_activity TIMESTAMP NULL AFTER created_at;

CREATE TABLE IF NOT EXISTS messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    message TEXT NOT NULL,
    status ENUM('unread','read') DEFAULT 'unread',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_messages_sender (sender_id),
    INDEX idx_messages_receiver (receiver_id),
    INDEX idx_messages_status (receiver_id, status),
    INDEX idx_messages_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed Data

-- Admin user (email: admin@platform.com, password: admin123)
INSERT INTO users (username, email, password, role) VALUES
('admin', 'admin@platform.com', '$2y$10$fLhKLQuCby5WGCF3wq4z3e7Lox/Y6xggMUdAWPPmaEp6Ui4QT1Xcm', 'admin');

-- Sample skills for freelancer registration
INSERT INTO skills (skill_name) VALUES
('PHP'),
('MySQL'),
('JavaScript'),
('HTML'),
('CSS'),
('Tailwind CSS'),
('Bootstrap'),
('React.js'),
('Vue.js'),
('Node.js'),
('Express.js'),
('Laravel'),
('CodeIgniter'),
('Python'),
('Java'),
('C#'),
('C++'),
('UI/UX Design'),
('Graphic Design'),
('Logo Design'),
('Brand Identity Design'),
('Adobe Photoshop'),
('Adobe Illustrator'),
('Adobe InDesign'),
('Figma'),
('Adobe XD'),
('Canva'),
('Video Editing'),
('Motion Graphics'),
('Animation'),
('Content Writing'),
('Copywriting'),

('Blog Writing'),
('Article Writing'),
('SEO Writing'),
('Translation'),


('Digital Marketing'),
('Social Media Marketing'),

('Email Marketing'),


('E-commerce');