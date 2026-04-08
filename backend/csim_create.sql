-- =================================
-- DATABASE
-- =================================
DROP DATABASE IF EXISTS crossroad_solutions_inventory_management;
CREATE DATABASE crossroad_solutions_inventory_management;
USE crossroad_solutions_inventory_management;

-- =================================
-- TABLES
-- =================================

-- 1. Asset Inventory
CREATE TABLE `asset_inventory` (
                                   `no` int(11) NOT NULL AUTO_INCREMENT,
                                   `part_number` varchar(100) NOT NULL,
                                   `serial_number` varchar(100) NOT NULL,
                                   `brand` varchar(100) DEFAULT NULL,
                                   `description` text DEFAULT NULL,
                                   `quantity` int(11) DEFAULT 1,
                                   `type` varchar(100) DEFAULT NULL,
                                   `location` varchar(150) DEFAULT NULL,
                                   `remark` text DEFAULT NULL,
                                   `created_by` varchar(100) DEFAULT NULL,
                                   PRIMARY KEY (`no`),
                                   UNIQUE KEY `serial_number` (`serial_number`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci

-- 2. Project Inventory
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
                                   remark TEXT,
                                   created_by VARCHAR(100)
);

-- 3. Tender Tracker
CREATE TABLE tender_tracker (
                                no INT AUTO_INCREMENT PRIMARY KEY,
                                tender_name VARCHAR(150),
                                tender_code VARCHAR(100),
                                company VARCHAR(150),
                                submission_date DATE,
                                status VARCHAR(100),
                                remark TEXT
);

-- 4. Administrator
CREATE TABLE administrator (
                               username VARCHAR(100) PRIMARY KEY,
                               email VARCHAR(100),
                               password VARCHAR(255)
);

-- 5. System Admin
CREATE TABLE system_admin (
                              username VARCHAR(100) PRIMARY KEY,
                              email VARCHAR(100),
                              password VARCHAR(255)
);

-- 6. User
CREATE TABLE user (
                      username VARCHAR(100) PRIMARY KEY,
                      email VARCHAR(100),
                      password VARCHAR(255),
                      role VARCHAR(50)
);

-- 7. Activity Logs
CREATE TABLE activity_logs (
                               id INT AUTO_INCREMENT PRIMARY KEY,
                               username VARCHAR(100),
                               role VARCHAR(50),
                               action_type VARCHAR(50),
                               description TEXT,
                               log_time DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE contract_documents (
                                    id INT AUTO_INCREMENT PRIMARY KEY,
                                    contract_id INT,
                                    file_name VARCHAR(255),
                                    uploaded_by VARCHAR(100),
                                    upload_time DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE contract_files (
                                id INT AUTO_INCREMENT PRIMARY KEY,
                                contract_id INT,
                                file_name VARCHAR(255),
                                file_path VARCHAR(255),
                                uploaded_by VARCHAR(100),
                                uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE asset_inventory ADD created_by VARCHAR(100);

CREATE TABLE stock_out_history (
                                   id INT AUTO_INCREMENT PRIMARY KEY,
                                   part_number VARCHAR(100),
                                   serial_number VARCHAR(100),
                                   location VARCHAR(100),
                                   remark TEXT,
                                   stock_out_by VARCHAR(100),
                                   stock_out_time DATETIME DEFAULT CURRENT_TIMESTAMP
);