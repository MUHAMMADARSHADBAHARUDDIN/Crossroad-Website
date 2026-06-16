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

        $done = true;
    }
}
?>
