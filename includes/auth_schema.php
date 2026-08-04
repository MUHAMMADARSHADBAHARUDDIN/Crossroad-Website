<?php
if(!function_exists('authSchemaTableExists')){
    function authSchemaTableExists($mysqli, $tableName){
        $tableName = $mysqli->real_escape_string($tableName);
        $result = $mysqli->query("SHOW TABLES LIKE '$tableName'");
        return ($result && $result->num_rows > 0);
    }
}

if(!function_exists('authSchemaColumnExists')){
    function authSchemaColumnExists($mysqli, $tableName, $columnName){
        $tableName = str_replace("`", "", $tableName);
        $columnName = $mysqli->real_escape_string($columnName);
        $result = $mysqli->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
        return ($result && $result->num_rows > 0);
    }
}

if(!function_exists('ensureFirstLoginPasswordSchema')){
    function ensureFirstLoginPasswordSchema($mysqli){
        static $done = false;

        if($done || !$mysqli){
            return;
        }

        foreach(["system_admin", "administrator", "user"] as $table){
            if(!authSchemaTableExists($mysqli, $table)){
                continue;
            }

            if(!authSchemaColumnExists($mysqli, $table, "must_change_password")){
                $mysqli->query("
                    ALTER TABLE `$table`
                    ADD COLUMN `must_change_password` tinyint(1) NOT NULL DEFAULT 1
                ");
            }
        }

        $done = true;
    }
}

if(!function_exists('authAccountTable')){
    function authAccountTable($accountType){
        $map = [
            "system_admin" => "system_admin",
            "administrator" => "administrator",
            "user" => "user"
        ];

        return $map[$accountType] ?? "";
    }
}

if(!function_exists('authFetchAccountBySession')){
    function authFetchAccountBySession($mysqli){
        ensureFirstLoginPasswordSchema($mysqli);

        $accountType = $_SESSION['account_type'] ?? "";
        $username = $_SESSION['username'] ?? "";
        $email = $_SESSION['email'] ?? "";
        $table = authAccountTable($accountType);

        if($table === "" || $username === ""){
            return null;
        }

        $emailCondition = $email !== "" ? " OR email = ?" : "";
        $sql = "
            SELECT username, email, password, " . ($table === "user" ? "role" : "'' AS role") . ", must_change_password
            FROM `$table`
            WHERE username = ?$emailCondition
            LIMIT 1
        ";

        $stmt = $mysqli->prepare($sql);
        if(!$stmt){
            return null;
        }

        if($email !== ""){
            $stmt->bind_param("ss", $username, $email);
        } else {
            $stmt->bind_param("s", $username);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        return $result ? $result->fetch_assoc() : null;
    }
}

if(!function_exists('authCurrentAccountMustChangePassword')){
    function authCurrentAccountMustChangePassword($mysqli){
        $account = authFetchAccountBySession($mysqli);

        if(!$account){
            return false;
        }

        return (int)($account['must_change_password'] ?? 0) === 1;
    }
}

if(!function_exists('authUpdateCurrentPassword')){
    function authUpdateCurrentPassword($mysqli, $passwordHash){
        ensureFirstLoginPasswordSchema($mysqli);

        $accountType = $_SESSION['account_type'] ?? "";
        $username = $_SESSION['username'] ?? "";
        $table = authAccountTable($accountType);

        if($table === "" || $username === ""){
            return false;
        }

        $stmt = $mysqli->prepare("
            UPDATE `$table`
            SET password = ?, must_change_password = 0
            WHERE username = ?
            LIMIT 1
        ");

        if(!$stmt){
            return false;
        }

        $stmt->bind_param("ss", $passwordHash, $username);
        return $stmt->execute();
    }
}
?>
