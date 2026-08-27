ALTER TABLE `project_inventory`
    ADD COLUMN IF NOT EXISTS `notification_email` varchar(255) DEFAULT NULL AFTER `contract_end`;

ALTER TABLE `contract_tasks`
    ADD COLUMN IF NOT EXISTS `notification_email` varchar(255) DEFAULT NULL;
