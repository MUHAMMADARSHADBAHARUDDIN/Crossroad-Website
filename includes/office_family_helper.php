<?php
if(!function_exists('officeInventoryFetchFamilyOptions')){
    function officeInventoryFetchFamilyOptions($mysqli){
        $options = [];
        $seen = [];

        foreach(["user", "administrator"] as $tableName){
            if(function_exists('inventoryReportTableExists') && !inventoryReportTableExists($mysqli, $tableName)){
                continue;
            }

            $result = $mysqli->query("
                SELECT username
                FROM `$tableName`
                WHERE TRIM(username) <> ''
                ORDER BY username ASC
            ");

            if(!$result){
                continue;
            }

            while($row = $result->fetch_assoc()){
                $name = trim((string)($row['username'] ?? ""));

                if($name === ""){
                    continue;
                }

                $key = strtolower($name);

                if(isset($seen[$key])){
                    continue;
                }

                $seen[$key] = true;
                $options[] = $name;
            }
        }

        natcasesort($options);
        return array_values($options);
    }
}

if(!function_exists('officeInventorySplitFamilyValues')){
    function officeInventorySplitFamilyValues($values){
        if(is_array($values)){
            $parts = $values;
        }
        else{
            $parts = preg_split('/\s*,\s*|\r\n|\r|\n/', (string)($values ?? ""));
        }

        $clean = [];

        foreach($parts as $value){
            $value = trim((string)$value);

            if($value !== ""){
                $clean[] = $value;
            }
        }

        return $clean;
    }
}

if(!function_exists('officeInventoryNormalizeFamilyValues')){
    function officeInventoryNormalizeFamilyValues($values){
        $clean = [];
        $seen = [];

        foreach(officeInventorySplitFamilyValues($values) as $value){
            $key = strtolower($value);

            if(isset($seen[$key])){
                continue;
            }

            $seen[$key] = true;
            $clean[] = $value;
        }

        return implode(", ", $clean);
    }
}

if(!function_exists('officeInventoryFamilyRows')){
    function officeInventoryFamilyRows($submittedValues = null, $existingValue = ""){
        $values = $submittedValues !== null
            ? officeInventorySplitFamilyValues($submittedValues)
            : officeInventorySplitFamilyValues($existingValue);

        return !empty($values) ? $values : [""];
    }
}

if(!function_exists('officeInventoryOfficeLicenseOptions')){
    function officeInventoryOfficeLicenseOptions(){
        return [
            "Microsoft Office 365" => "Microsoft Office 365"
        ];
    }
}

if(!function_exists('officeInventoryAntivirusLicenseOptions')){
    function officeInventoryAntivirusLicenseOptions(){
        return [
            "McAfee" => "McAfee",
            "TrendMicro" => "TrendMicro"
        ];
    }
}

if(!function_exists('officeInventoryNormalizeDateValue')){
    function officeInventoryNormalizeDateValue($value){
        if(function_exists('appNormalizeDateInput')){
            return appNormalizeDateInput($value);
        }

        return trim((string)($value ?? ""));
    }
}

if(!function_exists('officeInventoryFamilyDetailRowsFromPost')){
    function officeInventoryFamilyDetailRowsFromPost($post, $skipEmptyRows = true){
        $families = $post['license_family'] ?? [];
        $officeLicenses = $post['family_office365_license'] ?? [];
        $officeExpiredDates = $post['family_office365_expired_date'] ?? [];
        $antivirusLicenses = $post['family_antivirus_license'] ?? [];
        $antivirusExpiredDates = $post['family_antivirus_expired_date'] ?? [];

        if(!is_array($families)){
            $families = [$families];
        }

        $count = max(
            count($families),
            is_array($officeLicenses) ? count($officeLicenses) : 0,
            is_array($officeExpiredDates) ? count($officeExpiredDates) : 0,
            is_array($antivirusLicenses) ? count($antivirusLicenses) : 0,
            is_array($antivirusExpiredDates) ? count($antivirusExpiredDates) : 0,
            1
        );

        $rows = [];

        for($index = 0; $index < $count; $index++){
            $row = [
                "family" => trim((string)($families[$index] ?? "")),
                "office365_license" => trim((string)($officeLicenses[$index] ?? "")),
                "office365_expired_date" => officeInventoryNormalizeDateValue($officeExpiredDates[$index] ?? ""),
                "antivirus_license" => trim((string)($antivirusLicenses[$index] ?? "")),
                "antivirus_expired_date" => officeInventoryNormalizeDateValue($antivirusExpiredDates[$index] ?? "")
            ];

            if(
                $skipEmptyRows &&
                $row['family'] === "" &&
                $row['office365_license'] === "" &&
                $row['office365_expired_date'] === "" &&
                $row['antivirus_license'] === "" &&
                $row['antivirus_expired_date'] === ""
            ){
                continue;
            }

            $rows[] = $row;
        }

        return !empty($rows) ? $rows : [[
            "family" => "",
            "office365_license" => "",
            "office365_expired_date" => "",
            "antivirus_license" => "",
            "antivirus_expired_date" => ""
        ]];
    }
}

if(!function_exists('officeInventoryDecodeFamilyDetails')){
    function officeInventoryDecodeFamilyDetails($value){
        $value = trim((string)($value ?? ""));

        if($value === ""){
            return [];
        }

        $decoded = json_decode($value, true);

        if(!is_array($decoded)){
            return [];
        }

        $rows = [];

        foreach($decoded as $item){
            if(!is_array($item)){
                continue;
            }

            $rows[] = [
                "family" => trim((string)($item['family'] ?? "")),
                "office365_license" => trim((string)($item['office365_license'] ?? "")),
                "office365_expired_date" => officeInventoryNormalizeDateValue($item['office365_expired_date'] ?? ""),
                "antivirus_license" => trim((string)($item['antivirus_license'] ?? "")),
                "antivirus_expired_date" => officeInventoryNormalizeDateValue($item['antivirus_expired_date'] ?? "")
            ];
        }

        return $rows;
    }
}

if(!function_exists('officeInventoryFamilyDetailRowsForRecord')){
    function officeInventoryFamilyDetailRowsForRecord($row){
        $rows = officeInventoryDecodeFamilyDetails($row['license_family_details'] ?? "");

        if(!empty($rows)){
            return $rows;
        }

        $families = officeInventorySplitFamilyValues($row['license_family'] ?? "");
        $fallbackRows = [];

        foreach($families as $family){
            $fallbackRows[] = [
                "family" => $family,
                "office365_license" => trim((string)($row['office365_license'] ?? "")),
                "office365_expired_date" => officeInventoryNormalizeDateValue($row['license_expired_date'] ?? ""),
                "antivirus_license" => trim((string)($row['antivirus_license'] ?? "")),
                "antivirus_expired_date" => officeInventoryNormalizeDateValue($row['license_expired_date'] ?? "")
            ];
        }

        if(empty($fallbackRows)){
            $fallbackRows[] = [
                "family" => "",
                "office365_license" => trim((string)($row['office365_license'] ?? "")),
                "office365_expired_date" => officeInventoryNormalizeDateValue($row['license_expired_date'] ?? ""),
                "antivirus_license" => trim((string)($row['antivirus_license'] ?? "")),
                "antivirus_expired_date" => officeInventoryNormalizeDateValue($row['license_expired_date'] ?? "")
            ];
        }

        return $fallbackRows;
    }
}

if(!function_exists('officeInventoryFamilyDetailRowsForForm')){
    function officeInventoryFamilyDetailRowsForForm($post = null, $row = []){
        if(is_array($post) && array_key_exists('license_family', $post)){
            return officeInventoryFamilyDetailRowsFromPost($post, false);
        }

        $rows = officeInventoryFamilyDetailRowsForRecord($row);
        return !empty($rows) ? $rows : officeInventoryFamilyDetailRowsFromPost([], false);
    }
}

if(!function_exists('officeInventoryFamilyDetailsJson')){
    function officeInventoryFamilyDetailsJson($rows){
        $cleanRows = [];

        foreach($rows as $row){
            $family = trim((string)($row['family'] ?? ""));
            $officeLicense = trim((string)($row['office365_license'] ?? ""));
            $officeExpired = officeInventoryNormalizeDateValue($row['office365_expired_date'] ?? "");
            $antivirusLicense = trim((string)($row['antivirus_license'] ?? ""));
            $antivirusExpired = officeInventoryNormalizeDateValue($row['antivirus_expired_date'] ?? "");

            if(
                $family === "" &&
                $officeLicense === "" &&
                $officeExpired === "" &&
                $antivirusLicense === "" &&
                $antivirusExpired === ""
            ){
                continue;
            }

            $cleanRows[] = [
                "family" => $family,
                "office365_license" => $officeLicense,
                "office365_expired_date" => $officeExpired,
                "antivirus_license" => $antivirusLicense,
                "antivirus_expired_date" => $antivirusExpired
            ];
        }

        if(empty($cleanRows)){
            return null;
        }

        return json_encode($cleanRows, JSON_UNESCAPED_UNICODE);
    }
}

if(!function_exists('officeInventoryUniqueText')){
    function officeInventoryUniqueText($values){
        $clean = [];
        $seen = [];

        foreach($values as $value){
            $value = trim((string)$value);

            if($value === ""){
                continue;
            }

            $key = strtolower($value);

            if(isset($seen[$key])){
                continue;
            }

            $seen[$key] = true;
            $clean[] = $value;
        }

        return implode(", ", $clean);
    }
}

if(!function_exists('officeInventoryFamilyDetailSummary')){
    function officeInventoryFamilyDetailSummary($rows){
        $families = [];
        $officeLicenses = [];
        $antivirusLicenses = [];
        $licenseTypes = [];
        $expiredDates = [];

        foreach($rows as $row){
            $families[] = $row['family'] ?? "";
            $officeLicenses[] = $row['office365_license'] ?? "";
            $antivirusLicenses[] = $row['antivirus_license'] ?? "";

            if(trim((string)($row['office365_license'] ?? "")) !== ""){
                $licenseTypes[] = $row['office365_license'];
            }

            if(trim((string)($row['antivirus_license'] ?? "")) !== ""){
                $licenseTypes[] = $row['antivirus_license'];
            }

            $expiredDates[] = $row['office365_expired_date'] ?? "";
            $expiredDates[] = $row['antivirus_expired_date'] ?? "";
        }

        $firstExpiredDate = "";

        foreach($expiredDates as $date){
            $date = officeInventoryNormalizeDateValue($date);

            if($date !== ""){
                $firstExpiredDate = $date;
                break;
            }
        }

        return [
            "license_family" => officeInventoryUniqueText($families),
            "office365_license" => officeInventoryUniqueText($officeLicenses),
            "antivirus_license" => officeInventoryUniqueText($antivirusLicenses),
            "license_type" => officeInventoryUniqueText($licenseTypes),
            "license_expired_date" => $firstExpiredDate
        ];
    }
}
?>
