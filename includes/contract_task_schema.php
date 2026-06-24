<?php
if(!function_exists('contractTaskSchemaTableExists')){
    function contractTaskSchemaTableExists($mysqli, $tableName){
        $tableName = $mysqli->real_escape_string($tableName);
        $result = $mysqli->query("SHOW TABLES LIKE '$tableName'");
        return ($result && $result->num_rows > 0);
    }
}

if(!function_exists('contractTaskSchemaColumnExists')){
    function contractTaskSchemaColumnExists($mysqli, $tableName, $columnName){
        $tableName = str_replace("`", "", $tableName);
        $columnName = $mysqli->real_escape_string($columnName);
        $result = $mysqli->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
        return ($result && $result->num_rows > 0);
    }
}

if(!function_exists('ensureContractTaskCompletionSchema')){
    function ensureContractTaskCompletionSchema($mysqli){
        static $done = false;

        if($done || !$mysqli || !contractTaskSchemaTableExists($mysqli, "contract_tasks")){
            return;
        }

        if(!contractTaskSchemaColumnExists($mysqli, "contract_tasks", "completed_by")){
            $mysqli->query("
                ALTER TABLE `contract_tasks`
                ADD COLUMN `completed_by` varchar(255) DEFAULT NULL
            ");
        }

        if(!contractTaskSchemaColumnExists($mysqli, "contract_tasks", "completed_at")){
            $mysqli->query("
                ALTER TABLE `contract_tasks`
                ADD COLUMN `completed_at` datetime DEFAULT NULL
            ");
        }

        if(!contractTaskSchemaColumnExists($mysqli, "contract_tasks", "claim_amount")){
            $mysqli->query("
                ALTER TABLE `contract_tasks`
                ADD COLUMN `claim_amount` decimal(15,2) DEFAULT NULL
            ");
        }

        $done = true;
    }
}

if(!function_exists('ensureContractTaskDocumentSchema')){
    function ensureContractTaskDocumentSchema($mysqli){
        static $done = false;

        if($done || !$mysqli){
            return;
        }

        $mysqli->query("
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        if(!contractTaskSchemaColumnExists($mysqli, "contract_task_documents", "original_file_name")){
            $mysqli->query("
                ALTER TABLE `contract_task_documents`
                ADD COLUMN `original_file_name` varchar(255) DEFAULT NULL AFTER `file_name`
            ");
        }

        $done = true;
    }
}
?>
