<?php
require_once __DIR__ . "/date_helpers.php";

if(!function_exists('inventoryReportTableExists')){
    function inventoryReportTableExists($mysqli, $tableName){
        $tableName = $mysqli->real_escape_string($tableName);
        $result = $mysqli->query("SHOW TABLES LIKE '$tableName'");
        return ($result && $result->num_rows > 0);
    }
}

if(!function_exists('inventoryReportColumnExists')){
    function inventoryReportColumnExists($mysqli, $tableName, $columnName){
        $tableName = str_replace("`", "", $tableName);
        $columnName = $mysqli->real_escape_string($columnName);
        $result = $mysqli->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
        return ($result && $result->num_rows > 0);
    }
}

if(!function_exists('inventoryReportEnsureColumn')){
    function inventoryReportEnsureColumn($mysqli, $tableName, $columnName, $definition){
        if(inventoryReportColumnExists($mysqli, $tableName, $columnName)){
            return true;
        }

        $tableName = str_replace("`", "", $tableName);
        return (bool)$mysqli->query("ALTER TABLE `$tableName` ADD COLUMN `$columnName` $definition");
    }
}

if(!function_exists('ensureInventoryReportSchema')){
    function ensureInventoryReportSchema($mysqli){
        static $done = false;

        if($done || !$mysqli){
            return;
        }

        inventoryReportEnsureColumn($mysqli, "asset_inventory", "received_by", "varchar(100) DEFAULT NULL AFTER `created_by`");
        inventoryReportEnsureColumn($mysqli, "server_inventory", "received_by", "varchar(100) DEFAULT NULL AFTER `created_by`");
        inventoryReportEnsureColumn($mysqli, "stock_out_history", "ticket_number", "varchar(100) DEFAULT NULL AFTER `serial_number`");
        inventoryReportEnsureColumn($mysqli, "stock_out_history", "quantity", "int(11) DEFAULT 1 AFTER `remark`");
        inventoryReportEnsureColumn($mysqli, "server_stockout_history", "ticket_number", "varchar(100) DEFAULT NULL AFTER `serial_number`");
        inventoryReportEnsureColumn($mysqli, "server_stockout_history", "quantity", "int(11) DEFAULT 1 AFTER `tester`");

        if(!inventoryReportTableExists($mysqli, "asset_stockin_history")){
            $mysqli->query("
                CREATE TABLE `asset_stockin_history` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `asset_inventory_id` int(11) DEFAULT NULL,
                    `part_number` varchar(100) DEFAULT NULL,
                    `serial_number` varchar(100) DEFAULT NULL,
                    `brand` varchar(100) DEFAULT NULL,
                    `description` text DEFAULT NULL,
                    `quantity` int(11) DEFAULT 1,
                    `location` varchar(150) DEFAULT NULL,
                    `remark` text DEFAULT NULL,
                    `stock_in_by` varchar(100) DEFAULT NULL,
                    `received_by` varchar(100) DEFAULT NULL,
                    `date_received` date DEFAULT NULL,
                    `stock_in_date` datetime NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`),
                    KEY `idx_asset_stockin_date` (`stock_in_date`),
                    KEY `idx_asset_stockin_inventory` (`asset_inventory_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        if(!inventoryReportTableExists($mysqli, "server_stockin_history")){
            $mysqli->query("
                CREATE TABLE `server_stockin_history` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `server_inventory_id` int(11) DEFAULT NULL,
                    `server_name` varchar(150) DEFAULT NULL,
                    `brand` varchar(100) DEFAULT NULL,
                    `machine_type` varchar(150) DEFAULT NULL,
                    `serial_number` varchar(150) DEFAULT NULL,
                    `location` varchar(150) DEFAULT NULL,
                    `status` varchar(50) DEFAULT NULL,
                    `remark` text DEFAULT NULL,
                    `date_testing` date DEFAULT NULL,
                    `tester` varchar(100) DEFAULT NULL,
                    `quantity` int(11) DEFAULT 1,
                    `stock_in_by` varchar(100) DEFAULT NULL,
                    `received_by` varchar(100) DEFAULT NULL,
                    `stock_in_date` datetime NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`),
                    KEY `idx_server_stockin_date` (`stock_in_date`),
                    KEY `idx_server_stockin_inventory` (`server_inventory_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        $assetDateSql = appSqlDateValue("ai.date_received");
        $serverDateSql = appSqlDateValue("si.date_testing");

        if(inventoryReportTableExists($mysqli, "asset_stockin_history")){
            $mysqli->query("
                INSERT INTO asset_stockin_history
                    (asset_inventory_id, part_number, serial_number, brand, description, quantity, location, remark, stock_in_by, received_by, date_received, stock_in_date)
                SELECT
                    ai.no,
                    ai.part_number,
                    ai.serial_number,
                    ai.brand,
                    ai.description,
                    COALESCE(ai.quantity, 1),
                    ai.location,
                    ai.remark,
                    ai.created_by,
                    ai.received_by,
                    CASE WHEN $assetDateSql IS NULL THEN NULL ELSE $assetDateSql END,
                    CASE
                        WHEN $assetDateSql IS NULL THEN NOW()
                        ELSE CAST($assetDateSql AS DATETIME)
                    END
                FROM asset_inventory ai
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM asset_stockin_history ash
                    WHERE ash.asset_inventory_id = ai.no
                )
            ");
        }

        if(inventoryReportTableExists($mysqli, "server_stockin_history")){
            $mysqli->query("
                INSERT INTO server_stockin_history
                    (server_inventory_id, server_name, brand, machine_type, serial_number, location, status, remark, date_testing, tester, quantity, stock_in_by, received_by, stock_in_date)
                SELECT
                    si.no,
                    si.server_name,
                    si.brand,
                    si.machine_type,
                    si.serial_number,
                    si.location,
                    si.status,
                    si.remark,
                    CASE WHEN $serverDateSql IS NULL THEN NULL ELSE $serverDateSql END,
                    si.tester,
                    1,
                    si.created_by,
                    si.received_by,
                    CASE
                        WHEN $serverDateSql IS NULL THEN NOW()
                        ELSE CAST($serverDateSql AS DATETIME)
                    END
                FROM server_inventory si
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM server_stockin_history ssh
                    WHERE ssh.server_inventory_id = si.no
                )
            ");
        }

        $done = true;
    }
}
?>
