<?php
require_once __DIR__ . "/inventory_report_schema.php";

if(!function_exists('ensureReceivingSchema')){
    function ensureReceivingSchema($mysqli){
        static $done = false;

        if($done || !$mysqli){
            return;
        }

        ensureInventoryReportSchema($mysqli);

        $mysqli->query("
            CREATE TABLE IF NOT EXISTS `receiving_records` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `received_date` date NOT NULL,
                `received_by` varchar(150) NOT NULL,
                `item_type` varchar(50) NOT NULL,
                `item_name` varchar(180) NOT NULL,
                `part_number` varchar(120) DEFAULT NULL,
                `serial_number` varchar(150) DEFAULT NULL,
                `brand` varchar(100) DEFAULT NULL,
                `description` text DEFAULT NULL,
                `quantity` int(11) NOT NULL DEFAULT 1,
                `rack_location` varchar(150) NOT NULL,
                `purpose_route` varchar(30) NOT NULL,
                `purpose_detail` text DEFAULT NULL,
                `client_name` varchar(180) DEFAULT NULL,
                `project_name` varchar(180) DEFAULT NULL,
                `remark` text DEFAULT NULL,
                `routed_table` varchar(80) DEFAULT NULL,
                `routed_id` int(11) DEFAULT NULL,
                `created_by` varchar(150) NOT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_receiving_date` (`received_date`),
                KEY `idx_receiving_receiver` (`received_by`),
                KEY `idx_receiving_route` (`purpose_route`),
                KEY `idx_receiving_serial` (`serial_number`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");


        foreach([
            "attachment_file_name" => "varchar(255) DEFAULT NULL",
            "attachment_original_name" => "varchar(255) DEFAULT NULL",
            "attachment_mime" => "varchar(120) DEFAULT NULL",
            "attachment_size" => "bigint(20) DEFAULT NULL"
        ] as $column => $definition){
            $check = $mysqli->query("SHOW COLUMNS FROM `receiving_records` LIKE '" . $mysqli->real_escape_string($column) . "'");
            if(!$check || $check->num_rows === 0){ $mysqli->query("ALTER TABLE `receiving_records` ADD `$column` $definition"); }
        }

        $done = true;
    }
}

if(!function_exists('ensurePartRequestSchema')){
    function ensurePartRequestSchema($mysqli){
        $mysqli->query("CREATE TABLE IF NOT EXISTS `part_requests` (`id` int NOT NULL AUTO_INCREMENT, `request_id` varchar(20) DEFAULT NULL, `request_date` date NOT NULL, `purpose` text NOT NULL, `ticket_number` varchar(100) NOT NULL, `part_number` varchar(120) NOT NULL, `description` text NOT NULL, `recipient_email` varchar(190) NOT NULL, `requested_by` varchar(150) NOT NULL, `email_status` varchar(30) NOT NULL DEFAULT 'Pending', `email_response` text DEFAULT NULL, `created_at` datetime NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (`id`), UNIQUE KEY `uniq_part_request_id` (`request_id`), KEY `idx_part_request_date` (`request_date`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $mysqli->query("CREATE TABLE IF NOT EXISTS `part_request_items` (
            `id` int NOT NULL AUTO_INCREMENT,
            `part_request_id` int NOT NULL,
            `item_number` int NOT NULL,
            `ticket_number` varchar(100) NOT NULL,
            `part_number` varchar(120) NOT NULL,
            `description` text NOT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_part_request_item_number` (`part_request_id`, `item_number`),
            KEY `idx_part_request_items_request` (`part_request_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
?>
