ALTER TABLE `project_inventory`
    ADD COLUMN IF NOT EXISTS `project_code` varchar(50) DEFAULT NULL AFTER `no`;
