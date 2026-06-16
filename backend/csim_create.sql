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
                                   `no` INT(11) NOT NULL AUTO_INCREMENT,
                                   `part_number` VARCHAR(100) NOT NULL,
                                   `serial_number` VARCHAR(100) NOT NULL,
                                   `brand` VARCHAR(100) DEFAULT NULL,
                                   `description` TEXT DEFAULT NULL,
                                   `quantity` INT(11) DEFAULT 1,
                                   `type` VARCHAR(100) DEFAULT NULL,
                                   `location` VARCHAR(150) DEFAULT NULL,
                                   `remark` TEXT DEFAULT NULL,
                                   `created_by` VARCHAR(100) DEFAULT NULL,
                                   PRIMARY KEY (`no`),
                                   UNIQUE KEY `serial_number` (`serial_number`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Project Inventory
CREATE TABLE `project_inventory` (
                                     `no` INT(11) NOT NULL AUTO_INCREMENT,

    -- NEW REQUIRED FIELDS
                                     `year_awarded` INT(4) DEFAULT NULL,
                                     `project_name` TEXT,
                                     `project_owner` VARCHAR(255),
                                     `end_user` VARCHAR(255),
                                     `contract_no` VARCHAR(150),
                                     `service` VARCHAR(150),
                                     `po_date` DATE,
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Tender Tracker
CREATE TABLE `tender_tracker` (
                                  `no` INT AUTO_INCREMENT PRIMARY KEY,
                                  `tender_name` VARCHAR(150),
                                  `tender_code` VARCHAR(100),
                                  `company` VARCHAR(150),
                                  `submission_date` DATE,
                                  `status` VARCHAR(100),
                                  `remark` TEXT
);

-- 4. Administrator
CREATE TABLE `administrator` (
                                 `username` VARCHAR(100) PRIMARY KEY,
                                 `email` VARCHAR(100),
                                 `password` VARCHAR(255)
);

-- 5. System Admin
CREATE TABLE `system_admin` (
                                `username` VARCHAR(100) PRIMARY KEY,
                                `email` VARCHAR(100),
                                `password` VARCHAR(255)
);

-- 6. User
CREATE TABLE `user` (
                        `username` VARCHAR(100) PRIMARY KEY,
                        `email` VARCHAR(100),
                        `password` VARCHAR(255),
                        `role` VARCHAR(50)
);

-- 7. Activity Logs
CREATE TABLE `activity_logs` (
                                 `id` INT AUTO_INCREMENT PRIMARY KEY,
                                 `username` VARCHAR(100),
                                 `role` VARCHAR(50),
                                 `action_type` VARCHAR(50),
                                 `description` TEXT,
                                 `log_time` DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 8. Contract Documents
CREATE TABLE `contract_documents` (
                                      `id` INT AUTO_INCREMENT PRIMARY KEY,
                                      `contract_id` INT,
                                      `file_name` VARCHAR(255),
                                      `uploaded_by` VARCHAR(100),
                                      `upload_time` DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 9. Contract Files
CREATE TABLE `contract_files` (
                                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                                  `contract_id` INT,
                                  `file_name` VARCHAR(255),
                                  `file_path` VARCHAR(255),
                                  `uploaded_by` VARCHAR(100),
                                  `uploaded_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 10. Stock Out History
CREATE TABLE `stock_out_history` (
                                     `id` INT AUTO_INCREMENT PRIMARY KEY,
                                     `part_number` VARCHAR(100),
                                     `serial_number` VARCHAR(100),
                                     `location` VARCHAR(100),
                                     `remark` TEXT,
                                     `stock_out_by` VARCHAR(100),
                                     `stock_out_time` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `server_inventory` (
                                    `no` INT AUTO_INCREMENT PRIMARY KEY,
                                    `server_name` VARCHAR(150) NOT NULL,
                                    `brand` VARCHAR(100),
                                    `machine_type` VARCHAR(150),
                                    `serial_number` VARCHAR(150) UNIQUE,
                                    `location` VARCHAR(150),
                                    `status` VARCHAR(50),
                                    `remark` TEXT,
                                    `date_testing` DATE,
                                    `tester` VARCHAR(100),
                                    `created_by` VARCHAR(100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE server_stockout_history (
                                         id INT AUTO_INCREMENT PRIMARY KEY,
                                         server_name VARCHAR(150),
                                         machine_type VARCHAR(150),
                                         serial_number VARCHAR(150),
                                         location VARCHAR(150),
                                         status VARCHAR(50),
                                         remark TEXT,
                                         tester VARCHAR(100),
                                         stock_out_by VARCHAR(100),
                                         stock_out_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;




-- ============================================================
-- CROSSROAD INVENTORY SYSTEM UPDATE
-- 1. Append-only additional information for stock-out history
-- 2. Task date range for Preventive Management dashboard bulletin
-- ============================================================

CREATE TABLE IF NOT EXISTS `stockout_additional_information` (
                                                                 `id` int(11) NOT NULL AUTO_INCREMENT,
    `stockout_type` enum('asset','server') NOT NULL,
    `stockout_id` int(11) NOT NULL,
    `additional_information` text NOT NULL,
    `added_by` varchar(100) NOT NULL,
    `added_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_stockout_reference` (`stockout_type`, `stockout_id`),
    KEY `idx_added_at` (`added_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `contract_tasks`
    ADD COLUMN IF NOT EXISTS `task_start_date` date DEFAULT NULL AFTER `task_text`;

ALTER TABLE `contract_tasks`
    ADD COLUMN IF NOT EXISTS `task_end_date` date DEFAULT NULL AFTER `task_start_date`;

ALTER TABLE `contract_tasks`
    ADD COLUMN IF NOT EXISTS `completed_by` varchar(255) DEFAULT NULL;

ALTER TABLE `contract_tasks`
    ADD COLUMN IF NOT EXISTS `completed_at` datetime DEFAULT NULL;
