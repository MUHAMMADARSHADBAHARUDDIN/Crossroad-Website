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

if(!function_exists('inventoryReportColumnType')){
    function inventoryReportColumnType($mysqli, $tableName, $columnName){
        $tableName = str_replace("`", "", $tableName);
        $columnName = $mysqli->real_escape_string($columnName);
        $result = $mysqli->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");

        if(!$result || $result->num_rows === 0){
            return "";
        }

        $row = $result->fetch_assoc();
        return strtolower((string)($row['Type'] ?? ""));
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
        inventoryReportEnsureColumn($mysqli, "server_stockout_history", "stockout_components", "longtext DEFAULT NULL AFTER `remark`");
        inventoryReportEnsureColumn($mysqli, "server_stockout_history", "quantity", "int(11) DEFAULT 1 AFTER `tester`");

        if(!inventoryReportTableExists($mysqli, "server_components")){
            $mysqli->query("
                CREATE TABLE `server_components` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `server_inventory_id` int(11) NOT NULL,
                    `component_type` varchar(100) NOT NULL,
                    `part_number` varchar(100) NOT NULL,
                    `serial_number` varchar(150) NOT NULL,
                    `created_by` varchar(100) DEFAULT NULL,
                    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_server_component_serial` (`serial_number`),
                    KEY `idx_server_component_server` (`server_inventory_id`),
                    KEY `idx_server_component_type` (`component_type`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        if(!inventoryReportTableExists($mysqli, "laptop_inventory")){
            $mysqli->query("
                CREATE TABLE `laptop_inventory` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `delivery_date` date DEFAULT NULL,
                    `owner` varchar(150) NOT NULL,
                    `serial_number` varchar(150) NOT NULL,
                    `brand` varchar(100) DEFAULT NULL,
                    `model` varchar(150) DEFAULT NULL,
                    `remark` text DEFAULT NULL,
                    `office365_license` varchar(100) DEFAULT NULL,
                    `antivirus_license` varchar(100) DEFAULT NULL,
                    `license_type` varchar(255) DEFAULT NULL,
                    `license_ownership` varchar(150) DEFAULT NULL,
                    `license_family` text NULL,
                    `license_family_details` longtext DEFAULT NULL,
                    `license_expired_date` date DEFAULT NULL,
                    `document_file_name` varchar(255) DEFAULT NULL,
                    `document_original_name` varchar(255) DEFAULT NULL,
                    `document_uploaded_by` varchar(100) DEFAULT NULL,
                    `document_uploaded_at` datetime DEFAULT NULL,
                    `created_by` varchar(100) DEFAULT NULL,
                    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                    `updated_at` datetime DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_laptop_serial_number` (`serial_number`),
                    KEY `idx_laptop_owner` (`owner`),
                    KEY `idx_laptop_license_ownership` (`license_ownership`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        if(inventoryReportTableExists($mysqli, "laptop_inventory")){
            inventoryReportEnsureColumn($mysqli, "laptop_inventory", "remark", "text DEFAULT NULL AFTER `model`");
            inventoryReportEnsureColumn($mysqli, "laptop_inventory", "license_family", "text NULL AFTER `license_ownership`");
            inventoryReportEnsureColumn($mysqli, "laptop_inventory", "license_family_details", "longtext DEFAULT NULL AFTER `license_family`");
            inventoryReportEnsureColumn($mysqli, "laptop_inventory", "document_file_name", "varchar(255) DEFAULT NULL AFTER `license_expired_date`");
            inventoryReportEnsureColumn($mysqli, "laptop_inventory", "document_original_name", "varchar(255) DEFAULT NULL AFTER `document_file_name`");
            inventoryReportEnsureColumn($mysqli, "laptop_inventory", "document_uploaded_by", "varchar(100) DEFAULT NULL AFTER `document_original_name`");
            inventoryReportEnsureColumn($mysqli, "laptop_inventory", "document_uploaded_at", "datetime DEFAULT NULL AFTER `document_uploaded_by`");

            $licenseFamilyType = inventoryReportColumnType($mysqli, "laptop_inventory", "license_family");

            if($licenseFamilyType !== "" && strpos($licenseFamilyType, "text") === false){
                $mysqli->query("ALTER TABLE `laptop_inventory` MODIFY `license_family` text NULL");
            }
        }

        if(!inventoryReportTableExists($mysqli, "office_licenses")){
            $mysqli->query("
                CREATE TABLE `office_licenses` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `owner` varchar(150) NOT NULL,
                    `family` varchar(150) DEFAULT NULL,
                    `license_name` varchar(150) NOT NULL,
                    `expired_date` date DEFAULT NULL,
                    `created_by` varchar(100) DEFAULT NULL,
                    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                    `updated_at` datetime DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_office_license_owner` (`owner`),
                    KEY `idx_office_license_family` (`family`),
                    KEY `idx_office_license_expired` (`expired_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }

        if(inventoryReportTableExists($mysqli, "office_licenses")){
            inventoryReportEnsureColumn($mysqli, "office_licenses", "owner", "varchar(150) DEFAULT NULL AFTER `id`");
            inventoryReportEnsureColumn($mysqli, "office_licenses", "family", "varchar(150) DEFAULT NULL AFTER `owner`");
            inventoryReportEnsureColumn($mysqli, "office_licenses", "license_name", "varchar(150) DEFAULT NULL AFTER `family`");
            inventoryReportEnsureColumn($mysqli, "office_licenses", "expired_date", "date DEFAULT NULL AFTER `license_name`");
            inventoryReportEnsureColumn($mysqli, "office_licenses", "created_by", "varchar(100) DEFAULT NULL AFTER `expired_date`");
            inventoryReportEnsureColumn($mysqli, "office_licenses", "created_at", "datetime NOT NULL DEFAULT current_timestamp() AFTER `created_by`");
            inventoryReportEnsureColumn($mysqli, "office_licenses", "updated_at", "datetime DEFAULT NULL AFTER `created_at`");
        }

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
