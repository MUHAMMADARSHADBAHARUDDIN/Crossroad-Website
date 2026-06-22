<?php
if(!function_exists('contractSchemaTableExists')){
    function contractSchemaTableExists($mysqli, $tableName){
        $tableName = $mysqli->real_escape_string($tableName);
        $result = $mysqli->query("SHOW TABLES LIKE '$tableName'");
        return ($result && $result->num_rows > 0);
    }
}

if(!function_exists('contractSchemaColumnExists')){
    function contractSchemaColumnExists($mysqli, $tableName, $columnName){
        $tableName = str_replace("`", "", $tableName);
        $columnName = $mysqli->real_escape_string($columnName);
        $result = $mysqli->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
        return ($result && $result->num_rows > 0);
    }
}

if(!function_exists('contractSchemaIndexExists')){
    function contractSchemaIndexExists($mysqli, $tableName, $indexName){
        $tableName = str_replace("`", "", $tableName);
        $indexName = $mysqli->real_escape_string($indexName);
        $result = $mysqli->query("SHOW INDEX FROM `$tableName` WHERE Key_name = '$indexName'");
        return ($result && $result->num_rows > 0);
    }
}

if(!function_exists('contractProjectCodeNormalize')){
    function contractProjectCodeNormalize($value){
        $value = strtoupper(trim((string)($value ?? "")));
        $value = str_replace("\\", "/", $value);
        $value = preg_replace('/\s+/', '', $value);
        $value = preg_replace('/[^A-Z0-9\/_-]+/', '-', $value);
        $value = preg_replace('/\/+/', '/', $value);
        $value = trim($value, "/-_");

        return substr($value, 0, 50);
    }
}

if(!function_exists('contractProjectCodeMiddleNormalize')){
    function contractProjectCodeMiddleNormalize($value){
        $value = strtoupper(trim((string)($value ?? "")));
        $value = str_replace("\\", "/", $value);

        if(preg_match('/^PRO\/([^\/]*)\/\d+$/', $value, $matches)){
            $value = $matches[1];
        }

        $value = preg_replace('/\s+/', '', $value);
        $value = preg_replace('/[^A-Z0-9_-]+/', '-', $value);
        $value = trim($value, "-_");

        return substr($value, 0, 36);
    }
}

if(!function_exists('contractProjectCodePlaceholder')){
    function contractProjectCodePlaceholder(){
        return "PRO/ /000";
    }
}

if(!function_exists('contractProjectCodeIsPlaceholder')){
    function contractProjectCodeIsPlaceholder($value){
        $rawValue = trim((string)($value ?? ""));

        return $rawValue === "" || preg_match('/^PRO\/\s*\/0+$/i', $rawValue);
    }
}

if(!function_exists('contractProjectCodeDisplay')){
    function contractProjectCodeDisplay($value){
        if(contractProjectCodeIsPlaceholder($value)){
            return contractProjectCodePlaceholder();
        }

        $projectCode = contractProjectCodeNormalize($value);

        return $projectCode !== "" ? $projectCode : contractProjectCodePlaceholder();
    }
}

if(!function_exists('contractProjectCodeMiddleFromCode')){
    function contractProjectCodeMiddleFromCode($value){
        if(contractProjectCodeIsPlaceholder($value)){
            return "";
        }

        return contractProjectCodeMiddleNormalize($value);
    }
}

if(!function_exists('contractProjectCodeLikeEscape')){
    function contractProjectCodeLikeEscape($value){
        return str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $value);
    }
}

if(!function_exists('contractProjectCodeGenerateFromMiddle')){
    function contractProjectCodeGenerateFromMiddle($mysqli, $middle, $excludeNo = 0){
        $middle = contractProjectCodeMiddleNormalize($middle);

        if($middle === ""){
            return "";
        }

        $like = "PRO/" . contractProjectCodeLikeEscape($middle) . "/%";
        $maxNumber = 0;

        $stmt = $mysqli->prepare("
            SELECT project_code
            FROM project_inventory
            WHERE project_code LIKE ? ESCAPE '\\\\'
              AND no <> ?
        ");

        if($stmt){
            $excludeNo = (int)$excludeNo;
            $stmt->bind_param("si", $like, $excludeNo);
            $stmt->execute();
            $result = $stmt->get_result();

            while($row = $result->fetch_assoc()){
                $code = contractProjectCodeNormalize($row['project_code'] ?? "");

                if(preg_match('/^PRO\/' . preg_quote($middle, '/') . '\/(\d+)$/', $code, $matches)){
                    $maxNumber = max($maxNumber, (int)$matches[1]);
                }
            }
        }

        do{
            $maxNumber++;
            $projectCode = "PRO/" . $middle . "/" . str_pad((string)$maxNumber, 3, "0", STR_PAD_LEFT);
        }while(contractProjectCodeExists($mysqli, $projectCode, $excludeNo));

        return $projectCode;
    }
}

if(!function_exists('contractProjectCodeKnownPatterns')){
    function contractProjectCodeKnownPatterns(){
        return [
            "SUK" => ["SUK", "SETIAUSAHA KERAJAAN"],
            "UTM" => ["UTM", "UNIVERSITI TEKNOLOGI MALAYSIA"],
            "IWK" => ["IWK", "INDAH WATER", "INDAH WATER KONSORTIUM"],
            "PERKESO" => ["PERKESO", "PERTUBUHAN KESELAMATAN SOSIAL", "SOCSO"],
            "INTAN" => ["INTAN", "INSTITUT TADBIRAN AWAM NEGARA"],
            "KUSKOP" => ["KUSKOP", "KEMENTERIAN PEMBANGUNAN USAHAWAN DAN KOPERASI"],
            "KTMB" => ["KTMB", "KERETAPI TANAH MELAYU"],
            "SPR" => ["SPR", "SURUHANJAYA PILIHAN RAYA"],
            "UMT" => ["UMT", "UNIVERSITI MALAYSIA TERENGGANU"],
            "UPNM" => ["UPNM", "UNIVERSITI PERTAHANAN NASIONAL MALAYSIA"],
            "LHDN" => ["LHDN", "LEMBAGA HASIL DALAM NEGERI"],
            "KWSP" => ["KWSP", "KUMPULAN WANG SIMPANAN PEKERJA", "EPF"],
            "MCMC" => ["MCMC", "MALAYSIAN COMMUNICATIONS AND MULTIMEDIA COMMISSION", "SKMM"],
            "DOSM" => ["DOSM", "JABATAN PERANGKAAN"],
            "UPSI" => ["UPSI", "UNIVERSITI PENDIDIKAN SULTAN IDRIS"],
            "UM" => ["UNIVERSITI MALAYA"],
            "UKM" => ["UKM", "UNIVERSITI KEBANGSAAN MALAYSIA"],
            "USM" => ["USM", "UNIVERSITI SAINS MALAYSIA"],
            "UITM" => ["UITM", "UNIVERSITI TEKNOLOGI MARA"],
            "TNB" => ["TNB", "TENAGA NASIONAL"],
            "TM" => ["TELEKOM MALAYSIA"]
        ];
    }
}

if(!function_exists('contractProjectCodeFindKnownPrefix')){
    function contractProjectCodeFindKnownPrefix($text){
        $text = strtoupper((string)($text ?? ""));
        $text = preg_replace('/[^A-Z0-9]+/', ' ', $text);
        $text = trim(preg_replace('/\s+/', ' ', $text));

        if($text === ""){
            return "";
        }

        foreach(contractProjectCodeKnownPatterns() as $prefix => $aliases){
            foreach($aliases as $alias){
                $alias = strtoupper($alias);
                $alias = preg_replace('/[^A-Z0-9]+/', ' ', $alias);
                $alias = trim(preg_replace('/\s+/', ' ', $alias));

                if($alias !== "" && preg_match('/(^| )' . preg_quote($alias, '/') . '( |$)/', $text)){
                    return $prefix;
                }
            }
        }

        return "";
    }
}

if(!function_exists('contractProjectCodePrefix')){
    function contractProjectCodePrefix($projectName, $endUser = "", $projectOwner = "", $contractNo = ""){
        $sources = [$projectName, $endUser, $projectOwner, $contractNo];

        foreach($sources as $source){
            $knownPrefix = contractProjectCodeFindKnownPrefix($source);

            if($knownPrefix !== ""){
                return $knownPrefix;
            }
        }

        $skipTokens = [
            "AND", "THE", "FOR", "SDN", "BHD", "PT", "IT", "ICT", "ITD",
            "NAS", "SAN", "DR", "DC", "DATA", "CENTER", "CENTRE",
            "SUPPLY", "DELIVERY", "INSTALL", "TEST", "COMMISSION",
            "SERVICE", "SERVICES", "MAINTENANCE", "SUPPORT", "LICENSE",
            "RENEWAL", "PEMBAHARUAN", "PERKHIDMATAN", "PENYENGGARAAN",
            "SISTEM", "PROJEK", "PROJECT", "CONTRACT"
        ];

        foreach($sources as $source){
            preg_match_all('/\b[A-Z][A-Z0-9]{2,12}\b/', (string)$source, $matches);

            foreach($matches[0] as $token){
                $token = strtoupper($token);

                if(!in_array($token, $skipTokens, true) && !ctype_digit($token)){
                    return substr($token, 0, 12);
                }
            }
        }

        foreach($sources as $source){
            $words = preg_split('/[^A-Z0-9]+/', strtoupper((string)$source));
            $letters = "";

            foreach($words as $word){
                if($word === "" || in_array($word, $skipTokens, true) || ctype_digit($word)){
                    continue;
                }

                $letters .= substr($word, 0, 1);

                if(strlen($letters) >= 5){
                    break;
                }
            }

            if(strlen($letters) >= 2){
                return substr($letters, 0, 12);
            }

            foreach($words as $word){
                if($word !== "" && !in_array($word, $skipTokens, true) && !ctype_digit($word)){
                    return substr($word, 0, 12);
                }
            }
        }

        return "GEN";
    }
}

if(!function_exists('contractProjectCodeExists')){
    function contractProjectCodeExists($mysqli, $projectCode, $excludeNo = 0){
        $projectCode = contractProjectCodeNormalize($projectCode);

        if($projectCode === "" || !contractSchemaColumnExists($mysqli, "project_inventory", "project_code")){
            return false;
        }

        if((int)$excludeNo > 0){
            $stmt = $mysqli->prepare("
                SELECT no
                FROM project_inventory
                WHERE project_code = ?
                  AND no <> ?
                LIMIT 1
            ");

            if(!$stmt){
                return false;
            }

            $excludeNo = (int)$excludeNo;
            $stmt->bind_param("si", $projectCode, $excludeNo);
        } else {
            $stmt = $mysqli->prepare("
                SELECT no
                FROM project_inventory
                WHERE project_code = ?
                LIMIT 1
            ");

            if(!$stmt){
                return false;
            }

            $stmt->bind_param("s", $projectCode);
        }

        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }
}

if(!function_exists('contractProjectCodeGenerate')){
    function contractProjectCodeGenerate($mysqli, $projectName, $endUser = "", $projectOwner = "", $contractNo = "", $excludeNo = 0){
        $prefix = contractProjectCodePrefix($projectName, $endUser, $projectOwner, $contractNo);

        return contractProjectCodeGenerateFromMiddle($mysqli, $prefix, $excludeNo);
    }
}

if(!function_exists('contractSchemaBackfillProjectCodes')){
    function contractSchemaBackfillProjectCodes($mysqli){
        if(!contractSchemaColumnExists($mysqli, "project_inventory", "project_code")){
            return;
        }

        $result = $mysqli->query("
            SELECT no, project_code, project_name, end_user, project_owner, contract_no
            FROM project_inventory
            ORDER BY no ASC
        ");

        if(!$result){
            return;
        }

        while($row = $result->fetch_assoc()){
            $no = (int)($row['no'] ?? 0);
            $currentCode = contractProjectCodeNormalize($row['project_code'] ?? "");

            if($currentCode === "" || contractProjectCodeExists($mysqli, $currentCode, $no)){
                $stmt = $mysqli->prepare("
                    UPDATE project_inventory
                    SET project_code = NULL
                    WHERE no = ?
                      AND project_code IS NOT NULL
                ");

                if($stmt){
                    $stmt->bind_param("i", $no);
                    $stmt->execute();
                }

                continue;
            }

            $stmt = $mysqli->prepare("
                UPDATE project_inventory
                SET project_code = ?
                WHERE no = ?
                  AND (project_code IS NULL OR project_code = '' OR project_code <> ?)
            ");

            if($stmt){
                $stmt->bind_param("sis", $currentCode, $no, $currentCode);
                $stmt->execute();
            }
        }
    }
}

if(!function_exists('contractSchemaMigrationTableReady')){
    function contractSchemaMigrationTableReady($mysqli){
        $mysqli->query("
            CREATE TABLE IF NOT EXISTS `app_schema_migrations` (
                `migration_key` varchar(120) NOT NULL,
                `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`migration_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        return contractSchemaTableExists($mysqli, "app_schema_migrations");
    }
}

if(!function_exists('contractSchemaMigrationApplied')){
    function contractSchemaMigrationApplied($mysqli, $migrationKey){
        if(!contractSchemaMigrationTableReady($mysqli)){
            return true;
        }

        $stmt = $mysqli->prepare("
            SELECT migration_key
            FROM app_schema_migrations
            WHERE migration_key = ?
            LIMIT 1
        ");

        if(!$stmt){
            return true;
        }

        $stmt->bind_param("s", $migrationKey);
        $stmt->execute();

        return $stmt->get_result()->num_rows > 0;
    }
}

if(!function_exists('contractSchemaMarkMigrationApplied')){
    function contractSchemaMarkMigrationApplied($mysqli, $migrationKey){
        if(!contractSchemaMigrationTableReady($mysqli)){
            return;
        }

        $stmt = $mysqli->prepare("
            INSERT IGNORE INTO app_schema_migrations (migration_key)
            VALUES (?)
        ");

        if($stmt){
            $stmt->bind_param("s", $migrationKey);
            $stmt->execute();
        }
    }
}

if(!function_exists('contractSchemaResetExistingProjectCodes')){
    function contractSchemaResetExistingProjectCodes($mysqli){
        if(!contractSchemaColumnExists($mysqli, "project_inventory", "project_code")){
            return;
        }

        $migrationKey = "2026_06_22_reset_existing_project_codes_to_middle_placeholder";

        if(contractSchemaMigrationApplied($mysqli, $migrationKey)){
            return;
        }

        $mysqli->query("
            UPDATE project_inventory
            SET project_code = NULL
            WHERE project_code IS NOT NULL
              AND project_code <> ''
        ");

        contractSchemaMarkMigrationApplied($mysqli, $migrationKey);
    }
}

if(!function_exists('ensureContractProjectSchema')){
    function ensureContractProjectSchema($mysqli){
        static $done = false;

        if($done || !$mysqli || !contractSchemaTableExists($mysqli, "project_inventory")){
            return;
        }

        if(!contractSchemaColumnExists($mysqli, "project_inventory", "project_code")){
            $mysqli->query("
                ALTER TABLE `project_inventory`
                ADD COLUMN `project_code` varchar(50) DEFAULT NULL AFTER `no`
            ");
        }

        if(!contractSchemaColumnExists($mysqli, "project_inventory", "end_user")){
            $mysqli->query("
                ALTER TABLE `project_inventory`
                ADD COLUMN `end_user` varchar(255) DEFAULT NULL AFTER `account_manager`
            ");
        }

        contractSchemaResetExistingProjectCodes($mysqli);
        contractSchemaBackfillProjectCodes($mysqli);

        if(
            contractSchemaColumnExists($mysqli, "project_inventory", "project_code") &&
            !contractSchemaIndexExists($mysqli, "project_inventory", "idx_project_inventory_project_code")
        ){
            $mysqli->query("
                ALTER TABLE `project_inventory`
                ADD UNIQUE KEY `idx_project_inventory_project_code` (`project_code`)
            ");
        }

        $done = true;
    }
}
?>
