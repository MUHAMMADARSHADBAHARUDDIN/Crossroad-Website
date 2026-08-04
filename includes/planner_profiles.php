<?php
if(!function_exists('plannerOperationalRoles')){
    function plannerOperationalRoles(){
        return [
            "admin" => "Admin",
            "technical" => "Technical",
            "sales" => "Sales",
            "operation" => "Operation",
            "presales" => "Presales"
        ];
    }
}

if(!function_exists('plannerNormalizeOperationalRole')){
    function plannerNormalizeOperationalRole($role){
        $role = strtolower(trim((string)$role));
        return array_key_exists($role, plannerOperationalRoles()) ? $role : "";
    }
}

if(!function_exists('plannerOperationalRoleLabel')){
    function plannerOperationalRoleLabel($role){
        $role = plannerNormalizeOperationalRole($role);
        return $role !== "" ? plannerOperationalRoles()[$role] : "Not assigned";
    }
}

if(!function_exists('plannerAccountNickname')){
    function plannerAccountNickname($username){
        $username = trim((string)$username);

        if($username === ""){
            return "";
        }

        $lookup = strtoupper(preg_replace('/\s+/', ' ', $username));
        $specialNames = [
            "HAFIZUDIN" => "Pudin",
            "HAFIZUDD" => "Pudin",
            "EMRILL" => "Danniel",
            "EMMRIL" => "Danniel",
            "HAFIZI BIN ZAIRI" => "Fizi",
            "SYAHIRAN" => "Syafiq",
            "AZREEN" => "Azreen",
            "KHAIRUL" => "Ks"
        ];

        foreach($specialNames as $needle => $nickname){
            if(strpos($lookup, $needle) !== false){
                return $nickname;
            }
        }

        $skip = [
            "muhammad", "muhamad", "mohammad", "mohamad", "mohd", "ahmad",
            "nur", "wan", "siti", "syed", "sharifah", "tengku", "nik"
        ];

        foreach(preg_split('/\s+/', $username) as $part){
            $part = trim($part);

            if($part !== "" && !in_array(strtolower($part), $skip, true)){
                return ucfirst(strtolower($part));
            }
        }

        return ucfirst(strtolower($username));
    }
}

if(!function_exists('ensurePlannerProfileSchema')){
    function ensurePlannerProfileSchema($mysqli){
        static $done = false;

        if($done || !$mysqli){
            return;
        }

        $mysqli->query("
            CREATE TABLE IF NOT EXISTS `planner_user_profiles` (
                `username` varchar(150) NOT NULL,
                `account_type` varchar(30) NOT NULL,
                `planner_name` varchar(100) NOT NULL,
                `operational_role` varchar(30) NOT NULL DEFAULT '',
                `telegram_chat_id` varchar(100) NOT NULL DEFAULT '',
                `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`username`, `account_type`),
                KEY `idx_planner_profile_name` (`planner_name`),
                KEY `idx_planner_profile_role` (`operational_role`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $telegramColumn = $mysqli->query("SHOW COLUMNS FROM planner_user_profiles LIKE 'telegram_chat_id'");
        if(!$telegramColumn || $telegramColumn->num_rows === 0){
            $mysqli->query("ALTER TABLE planner_user_profiles ADD telegram_chat_id varchar(100) NOT NULL DEFAULT '' AFTER operational_role");
        }

        $mysqli->query("
            CREATE TABLE IF NOT EXISTS `planner_telegram_link_tokens` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `token_hash` char(64) NOT NULL,
                `username` varchar(150) NOT NULL,
                `account_type` varchar(30) NOT NULL,
                `expires_at` datetime NOT NULL,
                `used_at` datetime DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_planner_telegram_link_token` (`token_hash`),
                KEY `idx_planner_telegram_link_account` (`username`, `account_type`),
                KEY `idx_planner_telegram_link_expiry` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        foreach(["user", "administrator"] as $accountType){
            $result = $mysqli->query("SELECT username FROM `$accountType`");

            if(!$result){
                continue;
            }

            $stmt = $mysqli->prepare("
                INSERT INTO planner_user_profiles (username, account_type, planner_name)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE planner_name = VALUES(planner_name)
            ");

            if(!$stmt){
                continue;
            }

            while($row = $result->fetch_assoc()){
                $accountUsername = trim((string)($row['username'] ?? ""));
                $plannerName = plannerAccountNickname($accountUsername);
                $stmt->bind_param("sss", $accountUsername, $accountType, $plannerName);
                $stmt->execute();
            }
        }

        $done = true;
    }
}

if(!function_exists('plannerCreateTelegramLinkToken')){
    function plannerCreateTelegramLinkToken($mysqli, $username, $accountType){
        ensurePlannerProfileSchema($mysqli);
        $username = trim((string)$username);
        $accountType = trim((string)$accountType);

        if($username === "" || !in_array($accountType, ["user", "administrator"], true)){
            return "";
        }

        $mysqli->query("DELETE FROM planner_telegram_link_tokens WHERE expires_at < NOW() OR used_at IS NOT NULL");
        $invalidateStmt = $mysqli->prepare("
            UPDATE planner_telegram_link_tokens
            SET used_at = NOW()
            WHERE username = ? AND account_type = ? AND used_at IS NULL
        ");
        if($invalidateStmt){
            $invalidateStmt->bind_param("ss", $username, $accountType);
            $invalidateStmt->execute();
        }

        try {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        } catch(Throwable $error){
            return "";
        }

        $tokenHash = hash("sha256", $token);
        $stmt = $mysqli->prepare("
            INSERT INTO planner_telegram_link_tokens
                (token_hash, username, account_type, expires_at)
            VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))
        ");
        if(!$stmt){
            return "";
        }

        $stmt->bind_param("sss", $tokenHash, $username, $accountType);
        return $stmt->execute() ? $token : "";
    }
}

if(!function_exists('plannerConsumeTelegramLinkToken')){
    function plannerConsumeTelegramLinkToken($mysqli, $token, $chatId){
        ensurePlannerProfileSchema($mysqli);
        $token = trim((string)$token);
        $chatId = trim((string)$chatId);

        if(!preg_match('/^[A-Za-z0-9_-]{32,64}$/', $token) || !preg_match('/^-?\d{5,20}$/', $chatId)){
            return null;
        }

        $tokenHash = hash("sha256", $token);
        $mysqli->begin_transaction();

        try {
            $stmt = $mysqli->prepare("
                SELECT id, username, account_type
                FROM planner_telegram_link_tokens
                WHERE token_hash = ? AND used_at IS NULL AND expires_at >= NOW()
                LIMIT 1
                FOR UPDATE
            ");
            if(!$stmt){
                throw new RuntimeException("Unable to prepare Telegram link lookup.");
            }
            $stmt->bind_param("s", $tokenHash);
            $stmt->execute();
            $result = $stmt->get_result();
            $link = $result ? $result->fetch_assoc() : null;

            if(!$link){
                $mysqli->rollback();
                return null;
            }

            $clearStmt = $mysqli->prepare("
                UPDATE planner_user_profiles
                SET telegram_chat_id = ''
                WHERE telegram_chat_id = ?
            ");
            $clearStmt->bind_param("s", $chatId);
            $clearStmt->execute();

            $updateStmt = $mysqli->prepare("
                UPDATE planner_user_profiles
                SET telegram_chat_id = ?, updated_at = NOW()
                WHERE username = ? AND account_type = ?
                LIMIT 1
            ");
            $updateStmt->bind_param("sss", $chatId, $link['username'], $link['account_type']);
            $updateStmt->execute();

            if($updateStmt->affected_rows < 1){
                throw new RuntimeException("Crossroad user profile was not found.");
            }

            $linkId = (int)$link['id'];
            $usedStmt = $mysqli->prepare("UPDATE planner_telegram_link_tokens SET used_at = NOW() WHERE id = ?");
            $usedStmt->bind_param("i", $linkId);
            $usedStmt->execute();
            $mysqli->commit();

            return plannerGetUserProfile($mysqli, $link['username'], $link['account_type']);
        } catch(Throwable $error){
            $mysqli->rollback();
            return null;
        }
    }
}

if(!function_exists('plannerDisconnectTelegram')){
    function plannerDisconnectTelegram($mysqli, $username, $accountType){
        ensurePlannerProfileSchema($mysqli);
        $stmt = $mysqli->prepare("
            UPDATE planner_user_profiles
            SET telegram_chat_id = '', updated_at = NOW()
            WHERE username = ? AND account_type = ?
            LIMIT 1
        ");
        if(!$stmt){ return false; }
        $stmt->bind_param("ss", $username, $accountType);
        return $stmt->execute();
    }
}

if(!function_exists('plannerGetUserProfile')){
    function plannerGetUserProfile($mysqli, $username, $accountType = ""){
        ensurePlannerProfileSchema($mysqli);
        $username = trim((string)$username);
        $accountType = trim((string)$accountType);

        if($username === ""){
            return null;
        }

        if($accountType !== ""){
            $stmt = $mysqli->prepare("
                SELECT username, account_type, planner_name, operational_role, telegram_chat_id
                FROM planner_user_profiles
                WHERE username = ? AND account_type = ?
                LIMIT 1
            ");

            if($stmt){
                $stmt->bind_param("ss", $username, $accountType);
            }
        } else {
            $stmt = $mysqli->prepare("
                SELECT username, account_type, planner_name, operational_role, telegram_chat_id
                FROM planner_user_profiles
                WHERE username = ?
                ORDER BY account_type = 'administrator' DESC
                LIMIT 1
            ");

            if($stmt){
                $stmt->bind_param("s", $username);
            }
        }

        if(!$stmt || !$stmt->execute()){
            return null;
        }

        $result = $stmt->get_result();
        return $result ? ($result->fetch_assoc() ?: null) : null;
    }
}

if(!function_exists('plannerSaveUserProfile')){
    function plannerSaveUserProfile($mysqli, $username, $accountType, $role, $telegramChatId = ""){
        ensurePlannerProfileSchema($mysqli);
        $username = trim((string)$username);
        $accountType = trim((string)$accountType);
        $role = plannerNormalizeOperationalRole($role);
        $telegramChatId = trim((string)$telegramChatId);
        $plannerName = plannerAccountNickname($username);

        if($username === "" || !in_array($accountType, ["user", "administrator"], true) || $role === ""){
            return false;
        }

        $stmt = $mysqli->prepare("
            INSERT INTO planner_user_profiles
                (username, account_type, planner_name, operational_role, telegram_chat_id)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                planner_name = VALUES(planner_name),
                operational_role = VALUES(operational_role),
                telegram_chat_id = VALUES(telegram_chat_id)
        ");

        if(!$stmt){
            return false;
        }

        $stmt->bind_param("sssss", $username, $accountType, $plannerName, $role, $telegramChatId);
        return $stmt->execute();
    }
}

if(!function_exists('plannerDeleteUserProfiles')){
    function plannerDeleteUserProfiles($mysqli, $username, $accountType = ""){
        ensurePlannerProfileSchema($mysqli);

        if($accountType !== ""){
            $stmt = $mysqli->prepare("DELETE FROM planner_user_profiles WHERE username = ? AND account_type = ?");

            if($stmt){
                $stmt->bind_param("ss", $username, $accountType);
            }
        } else {
            $stmt = $mysqli->prepare("DELETE FROM planner_user_profiles WHERE username = ?");

            if($stmt){
                $stmt->bind_param("s", $username);
            }
        }

        return $stmt ? $stmt->execute() : false;
    }
}

if(!function_exists('plannerCurrentPicName')){
    function plannerCurrentPicName($mysqli){
        $username = $_SESSION['username'] ?? "";
        $accountType = function_exists('getCurrentAccountType') ? getCurrentAccountType($mysqli) : ($_SESSION['account_type'] ?? "");
        $profile = plannerGetUserProfile($mysqli, $username, $accountType);
        return trim((string)($profile['planner_name'] ?? plannerAccountNickname($username)));
    }
}

if(!function_exists('plannerCreatorOperationalRole')){
    function plannerCreatorOperationalRole($mysqli, $username, $accountType = ""){
        $profile = plannerGetUserProfile($mysqli, $username, $accountType);
        return plannerNormalizeOperationalRole($profile['operational_role'] ?? "");
    }
}

if(!function_exists('plannerGetEmailRecipientsByPlannerName')){
    function plannerGetEmailRecipientsByPlannerName($mysqli, $plannerName){
        ensurePlannerProfileSchema($mysqli);
        $plannerName = trim((string)$plannerName);

        if($plannerName === ""){
            return [];
        }

        $stmt = $mysqli->prepare("
            SELECT p.username, p.account_type, p.planner_name, a.email
            FROM planner_user_profiles p
            INNER JOIN administrator a
                ON p.account_type = 'administrator'
               AND a.username = p.username
            WHERE p.planner_name = ?

            UNION ALL

            SELECT p.username, p.account_type, p.planner_name, u.email
            FROM planner_user_profiles p
            INNER JOIN user u
                ON p.account_type = 'user'
               AND u.username = p.username
            WHERE p.planner_name = ?

            ORDER BY username ASC
        ");

        if(!$stmt){
            return [];
        }

        $stmt->bind_param("ss", $plannerName, $plannerName);

        if(!$stmt->execute()){
            return [];
        }

        $result = $stmt->get_result();
        $recipients = [];

        while($result && $row = $result->fetch_assoc()){
            $email = strtolower(trim((string)($row['email'] ?? "")));

            if(!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($recipients[$email])){
                continue;
            }

            $row['email'] = $email;
            $recipients[$email] = $row;
        }

        return array_values($recipients);
    }
}

if(!function_exists('plannerGetTelegramRecipientsByPlannerName')){
    function plannerGetTelegramRecipientsByPlannerName($mysqli, $plannerName){
        ensurePlannerProfileSchema($mysqli);
        $plannerName = trim((string)$plannerName);
        if($plannerName === ""){ return []; }

        $stmt = $mysqli->prepare("
            SELECT username, account_type, planner_name, telegram_chat_id
            FROM planner_user_profiles
            WHERE planner_name = ? AND telegram_chat_id <> ''
            ORDER BY username ASC
        ");
        if(!$stmt){ return []; }
        $stmt->bind_param("s", $plannerName);
        if(!$stmt->execute()){ return []; }

        $result = $stmt->get_result();
        $recipients = [];
        while($result && $row = $result->fetch_assoc()){
            $chatId = trim((string)($row['telegram_chat_id'] ?? ""));
            if($chatId === "" || isset($recipients[$chatId])){ continue; }
            $row['telegram_chat_id'] = $chatId;
            $recipients[$chatId] = $row;
        }
        return array_values($recipients);
    }
}
?>
