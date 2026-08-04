<?php
if(!function_exists('ensureBulletinSchema')){
    function ensureBulletinSchema($mysqli){
        $created = $mysqli->query("CREATE TABLE IF NOT EXISTS standby_bulletins (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            standby_name VARCHAR(150) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            created_by VARCHAR(150) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_standby_dates (start_date, end_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        if(!$created){ return false; }

        return (bool)$mysqli->query("CREATE TABLE IF NOT EXISTS bulletin_messages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            message VARCHAR(500) NOT NULL,
            created_by VARCHAR(150) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_bulletin_message_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if(!function_exists('bulletinValidDate')){
    function bulletinValidDate($value){
        $value = trim((string)$value);
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)){ return false; }
        $parts = explode('-', $value);
        return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
    }
}
?>
