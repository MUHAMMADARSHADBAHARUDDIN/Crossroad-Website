<?php
if(!function_exists('visitTrackingTableExists')){
    function visitTrackingTableExists($mysqli, $tableName){
        $tableName = $mysqli->real_escape_string($tableName);
        $result = $mysqli->query("SHOW TABLES LIKE '$tableName'");
        return ($result && $result->num_rows > 0);
    }
}

if(!function_exists('visitTrackingColumnExists')){
    function visitTrackingColumnExists($mysqli, $tableName, $columnName){
        $tableName = str_replace("`", "", $tableName);
        $columnName = $mysqli->real_escape_string($columnName);
        $result = $mysqli->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
        return ($result && $result->num_rows > 0);
    }
}

if(!function_exists('ensureVisitTrackingSchema')){
    function ensureVisitTrackingSchema($mysqli){
        static $done = false;

        if($done || !$mysqli){
            return;
        }

        if(!visitTrackingTableExists($mysqli, "visit_logs")){
            $mysqli->query("
                CREATE TABLE `visit_logs` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `username` varchar(100) NOT NULL,
                    `email` varchar(255) DEFAULT NULL,
                    `role` varchar(100) DEFAULT NULL,
                    `account_type` varchar(50) DEFAULT NULL,
                    `ip_address` varchar(100) DEFAULT NULL,
                    `visit_source` varchar(50) NOT NULL DEFAULT 'login_page',
                    `visited_at` datetime NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`id`),
                    KEY `idx_visit_logs_source` (`visit_source`),
                    KEY `idx_visit_logs_visited_at` (`visited_at`),
                    KEY `idx_visit_logs_username_date` (`username`, `visited_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        } elseif(!visitTrackingColumnExists($mysqli, "visit_logs", "visit_source")){
            $mysqli->query("
                ALTER TABLE `visit_logs`
                ADD COLUMN `visit_source` varchar(50) NOT NULL DEFAULT 'login_success' AFTER `ip_address`
            ");

            $mysqli->query("ALTER TABLE `visit_logs` ADD KEY `idx_visit_logs_source` (`visit_source`)");
        }

        $mysqli->query("
            INSERT INTO visit_logs (username, email, role, account_type, ip_address, visit_source, visited_at)
            SELECT
                al.username,
                NULL,
                al.role,
                NULL,
                NULL,
                'login_success',
                al.log_time
            FROM activity_logs al
            WHERE al.action_type = 'LOGIN SUCCESS'
              AND NOT EXISTS (
                  SELECT 1
                  FROM visit_logs vl
                  WHERE vl.username = al.username
                    AND vl.visited_at = al.log_time
              )
        ");

        $done = true;
    }
}

if(!function_exists('recordUserVisit')){
    function recordUserVisit($mysqli, $username, $email, $role, $accountType){
        ensureVisitTrackingSchema($mysqli);

        $ip = $_SERVER['REMOTE_ADDR'] ?? "";

        $stmt = $mysqli->prepare("
            INSERT INTO visit_logs (username, email, role, account_type, ip_address, visit_source, visited_at)
            VALUES (?, ?, ?, ?, ?, 'login_success', NOW())
        ");

        if(!$stmt){
            return false;
        }

        $stmt->bind_param("sssss", $username, $email, $role, $accountType, $ip);
        return $stmt->execute();
    }
}

if(!function_exists('recordLoginPageVisit')){
    function recordLoginPageVisit($mysqli){
        ensureVisitTrackingSchema($mysqli);

        $ip = $_SERVER['REMOTE_ADDR'] ?? "";
        $username = "Login Page Visitor";
        $role = "Public";
        $accountType = "public";

        $stmt = $mysqli->prepare("
            INSERT INTO visit_logs (username, email, role, account_type, ip_address, visit_source, visited_at)
            VALUES (?, NULL, ?, ?, ?, 'login_page', NOW())
        ");

        if(!$stmt){
            return false;
        }

        $stmt->bind_param("ssss", $username, $role, $accountType, $ip);
        return $stmt->execute();
    }
}

if(!function_exists('getLoginPageVisitCount')){
    function getLoginPageVisitCount($mysqli){
        ensureVisitTrackingSchema($mysqli);

        $result = $mysqli->query("
            SELECT COUNT(*) AS total
            FROM visit_logs
            WHERE visit_source = 'login_page'
        ");

        if(!$result){
            return 0;
        }

        $row = $result->fetch_assoc();
        return (int)($row['total'] ?? 0);
    }
}

if(!function_exists('getTotalVisitCount')){
    function getTotalVisitCount($mysqli){
        ensureVisitTrackingSchema($mysqli);

        $result = $mysqli->query("SELECT COUNT(*) AS total FROM visit_logs");

        if(!$result){
            return 0;
        }

        $row = $result->fetch_assoc();
        return (int)($row['total'] ?? 0);
    }
}

if(!function_exists('getVisitSummaryForDate')){
    function getVisitSummaryForDate($mysqli, $date){
        ensureVisitTrackingSchema($mysqli);

        $start = date("Y-m-d 00:00:00", strtotime($date));
        $end = date("Y-m-d 00:00:00", strtotime($date . " +1 day"));

        $stmt = $mysqli->prepare("
            SELECT
                username,
                email,
                role,
                account_type,
                COUNT(*) AS visit_count,
                MIN(visited_at) AS first_visit,
                MAX(visited_at) AS last_visit
            FROM visit_logs
            WHERE visited_at >= ?
              AND visited_at < ?
            GROUP BY username, email, role, account_type
            ORDER BY visit_count DESC, username ASC
        ");

        if(!$stmt){
            return [];
        }

        $stmt->bind_param("ss", $start, $end);
        $stmt->execute();

        $result = $stmt->get_result();
        $rows = [];

        while($row = $result->fetch_assoc()){
            $rows[] = $row;
        }

        return $rows;
    }
}
?>
