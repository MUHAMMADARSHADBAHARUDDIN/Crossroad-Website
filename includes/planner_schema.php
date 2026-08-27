<?php
if(!function_exists('plannerTableExists')){
    function plannerTableExists($mysqli, $tableName){
        $tableName = $mysqli->real_escape_string($tableName);
        $result = $mysqli->query("SHOW TABLES LIKE '$tableName'");
        return ($result && $result->num_rows > 0);
    }
}

if(!function_exists('plannerColumnExists')){
    function plannerColumnExists($mysqli, $tableName, $columnName){
        $tableName = str_replace("`", "", $tableName);
        $columnName = $mysqli->real_escape_string($columnName);
        $result = $mysqli->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
        return ($result && $result->num_rows > 0);
    }
}

if(!function_exists('plannerEnsureColumn')){
    function plannerEnsureColumn($mysqli, $tableName, $columnName, $definition){
        if(plannerColumnExists($mysqli, $tableName, $columnName)){
            return true;
        }

        $tableName = str_replace("`", "", $tableName);
        return (bool)$mysqli->query("ALTER TABLE `$tableName` ADD COLUMN `$columnName` $definition");
    }
}

if(!function_exists('ensurePlannerSchema')){
    function ensurePlannerSchema($mysqli){
        static $done = false;

        if($done || !$mysqli){
            return;
        }

        if(!plannerTableExists($mysqli, "planner_tasks")){
            $mysqli->query("
                CREATE TABLE `planner_tasks` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `title` varchar(180) NOT NULL,
                    `description` text DEFAULT NULL,
                    `person_in_charge` text DEFAULT NULL,
                    `start_date` date NOT NULL,
                    `end_date` date DEFAULT NULL,
                    `task_time` time DEFAULT NULL,
                    `color` varchar(20) DEFAULT '#0d6efd',
                    `created_by` varchar(100) DEFAULT NULL,
                    `created_account_type` varchar(30) DEFAULT NULL,
                    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                    `updated_by` varchar(100) DEFAULT NULL,
                    `updated_at` datetime DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_planner_dates` (`start_date`, `end_date`),
                    KEY `idx_planner_created_by` (`created_by`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        if(plannerTableExists($mysqli, "planner_tasks")){
            plannerEnsureColumn($mysqli, "planner_tasks", "description", "text DEFAULT NULL AFTER `title`");
            plannerEnsureColumn($mysqli, "planner_tasks", "person_in_charge", "text DEFAULT NULL AFTER `description`");
            plannerEnsureColumn($mysqli, "planner_tasks", "end_date", "date DEFAULT NULL AFTER `start_date`");
            plannerEnsureColumn($mysqli, "planner_tasks", "task_time", "time DEFAULT NULL AFTER `end_date`");
            plannerEnsureColumn($mysqli, "planner_tasks", "color", "varchar(20) DEFAULT '#0d6efd' AFTER `end_date`");
            plannerEnsureColumn($mysqli, "planner_tasks", "created_by", "varchar(100) DEFAULT NULL AFTER `color`");
            plannerEnsureColumn($mysqli, "planner_tasks", "created_account_type", "varchar(30) DEFAULT NULL AFTER `created_by`");
            plannerEnsureColumn($mysqli, "planner_tasks", "created_at", "datetime NOT NULL DEFAULT current_timestamp() AFTER `created_account_type`");
            plannerEnsureColumn($mysqli, "planner_tasks", "updated_by", "varchar(100) DEFAULT NULL AFTER `created_at`");
            plannerEnsureColumn($mysqli, "planner_tasks", "updated_at", "datetime DEFAULT NULL AFTER `updated_by`");
            $mysqli->query("UPDATE `planner_tasks` SET `title` = 'Training', `color` = '#e83e8c' WHERE `title` = 'Trainning'");
            $mysqli->query("
                UPDATE `planner_tasks`
                SET `color` = '#ffc107'
                WHERE LOWER(TRIM(`title`)) NOT IN
                    ('pm', 'cm', 'kickoff', 'meeting', 'site assestment', 'training', 'deployment')
                  AND `color` <> '#ffc107'
            ");
        }

        if(!plannerTableExists($mysqli, "planner_holiday_cache")){
            $mysqli->query("
                CREATE TABLE `planner_holiday_cache` (
                    `holiday_year` int(11) NOT NULL,
                    `country_code` varchar(8) NOT NULL DEFAULT 'MY',
                    `data_json` longtext NOT NULL,
                    `fetched_at` datetime NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`holiday_year`, `country_code`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        if(!plannerTableExists($mysqli, "planner_email_reminders")){
            $mysqli->query("
                CREATE TABLE `planner_email_reminders` (
                    `id` bigint(20) NOT NULL AUTO_INCREMENT,
                    `task_id` int(11) NOT NULL,
                    `planner_name` varchar(100) NOT NULL,
                    `recipient_email` varchar(255) NOT NULL,
                    `reminder_type` varchar(30) NOT NULL,
                    `scheduled_for` datetime NOT NULL,
                    `status` varchar(20) NOT NULL DEFAULT 'pending',
                    `attempts` int(11) NOT NULL DEFAULT 0,
                    `provider_response` text DEFAULT NULL,
                    `sent_at` datetime DEFAULT NULL,
                    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                    `updated_at` datetime DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_planner_email_reminder` (`task_id`, `recipient_email`, `reminder_type`),
                    KEY `idx_planner_email_reminder_status` (`status`, `scheduled_for`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        if(!plannerTableExists($mysqli, "planner_telegram_reminders")){
            $mysqli->query("
                CREATE TABLE `planner_telegram_reminders` (
                    `id` bigint(20) NOT NULL AUTO_INCREMENT,
                    `task_id` int(11) NOT NULL,
                    `planner_name` varchar(100) NOT NULL,
                    `recipient_chat_id` varchar(100) NOT NULL,
                    `reminder_type` varchar(30) NOT NULL,
                    `scheduled_for` datetime NOT NULL,
                    `status` varchar(20) NOT NULL DEFAULT 'pending',
                    `attempts` int(11) NOT NULL DEFAULT 0,
                    `provider_response` text DEFAULT NULL,
                    `sent_at` datetime DEFAULT NULL,
                    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                    `updated_at` datetime DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_planner_telegram_reminder` (`task_id`, `recipient_chat_id`, `reminder_type`),
                    KEY `idx_planner_telegram_status` (`status`, `scheduled_for`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        $done = true;
    }
}
?>
