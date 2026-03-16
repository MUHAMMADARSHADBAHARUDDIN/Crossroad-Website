DROP DATABASE IF EXISTS crossroad_solutions_inventory_management;

CREATE DATABASE crossroad_solutions_inventory_management;

USE crossroad_solutions_inventory_management;

-- =========================
-- 1. asset inventory table
-- =========================
CREATE TABLE asset_inventory (
                                 no INT AUTO_INCREMENT PRIMARY KEY,
                                 part_number VARCHAR(100),
                                 serial_number VARCHAR(100),
                                 brand VARCHAR(100),
                                 description TEXT,
                                 interface VARCHAR(100),
                                 quantity INT,
                                 type VARCHAR(100),
                                 location VARCHAR(150),
                                 remark TEXT
);

-- =========================
-- 2. project inventory table
-- =========================
CREATE TABLE project_inventory (
                                   no INT AUTO_INCREMENT PRIMARY KEY,
                                   name VARCHAR(150),
                                   contract_name VARCHAR(150),
                                   contract_code VARCHAR(100),
                                   contract_start DATE,
                                   contract_end DATE,
                                   location VARCHAR(150),
                                   pic VARCHAR(150),
                                   support_coverage VARCHAR(150),
                                   preventive_management VARCHAR(150),
                                   partner VARCHAR(150),
                                   partner_pic VARCHAR(150),
                                   remark TEXT
);

-- =========================
-- 3. tender tracker table
-- =========================
CREATE TABLE tender_tracker (
                                no INT AUTO_INCREMENT PRIMARY KEY,
                                tender_name VARCHAR(150),
                                tender_code VARCHAR(100),
                                company VARCHAR(150),
                                submission_date DATE,
                                status VARCHAR(100),
                                remark TEXT
);

-- =========================
-- 4. administrator table
-- =========================
CREATE TABLE administrator (
                               username VARCHAR(100) PRIMARY KEY,
                               password VARCHAR(255)
);

-- =========================
-- 5. system admin table
-- =========================
CREATE TABLE system_admin (
                              username VARCHAR(100) PRIMARY KEY,
                              password VARCHAR(255)
);

-- =========================
-- 6. user table
-- =========================
CREATE TABLE user (
                      username VARCHAR(100) PRIMARY KEY,
                      password VARCHAR(255),
                      role VARCHAR(50)
);

-- =========================
-- 7. activity log table
-- =========================
CREATE TABLE activity_logs (
                               id INT AUTO_INCREMENT PRIMARY KEY,
                               username VARCHAR(100),
                               role VARCHAR(50),
                               action_type VARCHAR(50),
                               description TEXT,
                               log_time DATETIME DEFAULT CURRENT_TIMESTAMP
);