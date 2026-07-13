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
            "AZREEN" => "Azreen"
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
                `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`username`, `account_type`),
                KEY `idx_planner_profile_name` (`planner_name`),
                KEY `idx_planner_profile_role` (`operational_role`)
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
                SELECT username, account_type, planner_name, operational_role
                FROM planner_user_profiles
                WHERE username = ? AND account_type = ?
                LIMIT 1
            ");
            $stmt?->bind_param("ss", $username, $accountType);
        } else {
            $stmt = $mysqli->prepare("
                SELECT username, account_type, planner_name, operational_role
                FROM planner_user_profiles
                WHERE username = ?
                ORDER BY account_type = 'administrator' DESC
                LIMIT 1
            ");
            $stmt?->bind_param("s", $username);
        }

        if(!$stmt || !$stmt->execute()){
            return null;
        }

        $result = $stmt->get_result();
        return $result ? ($result->fetch_assoc() ?: null) : null;
    }
}

if(!function_exists('plannerSaveUserProfile')){
    function plannerSaveUserProfile($mysqli, $username, $accountType, $role){
        ensurePlannerProfileSchema($mysqli);
        $username = trim((string)$username);
        $accountType = trim((string)$accountType);
        $role = plannerNormalizeOperationalRole($role);
        $plannerName = plannerAccountNickname($username);

        if($username === "" || !in_array($accountType, ["user", "administrator"], true) || $role === ""){
            return false;
        }

        $stmt = $mysqli->prepare("
            INSERT INTO planner_user_profiles
                (username, account_type, planner_name, operational_role)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                planner_name = VALUES(planner_name),
                operational_role = VALUES(operational_role)
        ");

        if(!$stmt){
            return false;
        }

        $stmt->bind_param("ssss", $username, $accountType, $plannerName, $role);
        return $stmt->execute();
    }
}

if(!function_exists('plannerDeleteUserProfiles')){
    function plannerDeleteUserProfiles($mysqli, $username, $accountType = ""){
        ensurePlannerProfileSchema($mysqli);

        if($accountType !== ""){
            $stmt = $mysqli->prepare("DELETE FROM planner_user_profiles WHERE username = ? AND account_type = ?");
            $stmt?->bind_param("ss", $username, $accountType);
        } else {
            $stmt = $mysqli->prepare("DELETE FROM planner_user_profiles WHERE username = ?");
            $stmt?->bind_param("s", $username);
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
?>
