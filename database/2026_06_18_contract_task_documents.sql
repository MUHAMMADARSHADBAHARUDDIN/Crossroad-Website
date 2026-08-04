ALTER TABLE `project_inventory`
    ADD COLUMN IF NOT EXISTS `end_user` varchar(255) DEFAULT NULL AFTER `account_manager`;

CREATE TABLE IF NOT EXISTS `contract_task_documents` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `contract_id` int(11) NOT NULL,
    `task_id` int(11) NOT NULL,
    `file_name` varchar(255) NOT NULL,
    `original_file_name` varchar(255) DEFAULT NULL,
    `uploaded_by` varchar(100) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_contract_task_documents_contract` (`contract_id`),
    KEY `idx_contract_task_documents_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
