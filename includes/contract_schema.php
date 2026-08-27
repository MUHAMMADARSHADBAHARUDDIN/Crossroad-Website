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

        return (
            $rawValue === "" ||
            preg_match('/^PRO\/\s*\/0+$/i', $rawValue) ||
            preg_match('/^PRO\/0+$/i', $rawValue)
        );
    }
}

if(!function_exists('contractProjectCodeHasExpectedFormat')){
    function contractProjectCodeHasExpectedFormat($value){
        if(contractProjectCodeIsPlaceholder($value)){
            return false;
        }

        $projectCode = contractProjectCodeNormalize($value);

        return preg_match('/^PRO\/[A-Z0-9_-]+\/\d+$/', $projectCode) === 1;
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
            "SUKN9" => [
                "SUK NEGERI SEMBILAN",
                "SETIAUSAHA KERAJAAN NEGERI SEMBILAN",
                "PEJABAT SETIAUSAHA KERAJAAN NEGERI SEMBILAN"
            ],
            "AGMSN" => [
                "AG-MESINIAGA",
                "AG MESINIAGA",
                "AG-MESINIAGA SDN BHD",
                "AG MESINIAGA SDN BHD"
            ],
            "MEDSEL" => [
                "MEDIA SELANGOR",
                "MEDIA SELANGOR SDN BHD"
            ],
            "MEDPRI" => [
                "MEDIA PRIMA",
                "MEDIA PRIMA BERHAD"
            ],
            "GSPAPER" => [
                "GS PAPERBOARD",
                "GS PAPERBOARD & PACKAGING",
                "GS PAPERBOARD AND PACKAGING",
                "GS PAPERBOARD & PACKAGING SDN BHD",
                "GS PAPERBOARD AND PACKAGING SDN BHD"
            ],
            "POCDATA" => [
                "POCKET DATA",
                "POCKET DATA M SDN BHD",
                "POCKET DATA (M) SDN BHD"
            ],
            "PACOIL" => [
                "PACIFIC OILS",
                "PACIFIC OILS & FATS",
                "PACIFIC OILS AND FATS",
                "PACIFIC OILS & FATS INDUSTRIES",
                "PACIFIC OILS AND FATS INDUSTRIES",
                "PACIFIC OILS & FATS INDUSTRIES SDN BHD",
                "PACIFIC OILS AND FATS INDUSTRIES SDN BHD"
            ],
            "COOPB" => [
                "CO-OPBANK PERTAMA",
                "CO OPBANK PERTAMA",
                "CO-OPBANK PERTAMA MALAYSIA",
                "CO OPBANK PERTAMA MALAYSIA",
                "KOPERASI CO-OPBANK PERTAMA MALAYSIA BERHAD"
            ],
            "BMMB" => [
                "BANK MUAMALAT",
                "BANK MUAMALAT MALAYSIA",
                "BANK MUAMALAT MALAYSIA BERHAD"
            ],
            "BKRM" => [
                "BANK RAKYAT",
                "BANK KERJASAMA RAKYAT",
                "BANK KERJASAMA RAKYAT MALAYSIA",
                "BANK KERJASAMA RAKYAT MALAYSIA BERHAD"
            ],
            "PPKB" => [
                "PERBADANAN PEMBANGUNAN KAMPONG BAHARU",
                "PERBADANAN PEMBANGUNAN KAMPONG BHARU"
            ],
            "AHZAKI" => [
                "AHMAD ZAKI SDN BHD",
                "AHMAD ZAKI",
                "AHMAD ZAKI RESOURCES",
                "AHMAD ZAKI RESOURCES BERHAD"
            ],
            "NIPPON" => [
                "NIPPON PAINT MALAYSIA SDN BHD",
                "NIPPON PAINT M SDN BHD",
                "NIPPON PAINT (M) SDN BHD",
                "NIPPON PAINT",
                "NIPPON"
            ],
            "HERNAL" => [
                "HERITAGE INTERNATIONAL",
                "HERITAGE INTERNATIONAL SDN BHD"
            ],
            "FIRSOL" => [
                "FIRST SOLUTION",
                "FIRST SOLUTIONS",
                "FIRST SOLUTION SDN BHD",
                "FIRST SOLUTIONS SDN BHD"
            ],
            "FORSOL" => [
                "FOTIA SOLUTIONS",
                "FOTIA SOLUTIONS SDN BHD"
            ],
            "RFMS" => [
                "RAFULIN FMS",
                "RAFULIN FMS SDN BHD"
            ],
            "PRODUA" => [
                "PERODUA",
                "PERUSAHAAN OTOMOBIL KEDUA",
                "PERUSAHAAN OTOMOBIL KEDUA SDN BHD"
            ],
            "020PLA" => [
                "O2O PLANTATION",
                "020 PLANTATION",
                "O2O PLANTATION SDN BHD"
            ],
            "KPDK" => [
                "KEMENTERIAN PERLADANGAN DAN KOMODITI",
                "MINISTRY OF PLANTATION AND COMMODITIES"
            ],
            "JASWKL" => [
                "JAS WP KL",
                "JABATAN ALAM SEKITAR WILAYAH PERSEKUTUAN KUALA LUMPUR"
            ],
            "JPSM" => [
                "JABATAN PERHUTANAN",
                "JABATAN PERHUTANAN SEMENANJUNG MALAYSIA"
            ],
            "JDN" => [
                "JABATAN DIGITAL NEGARA"
            ],
            "MAHB" => [
                "MALAYSIA AIRPORT",
                "MALAYSIA AIRPORTS",
                "MALAYSIA AIRPORTS HOLDINGS",
                "MALAYSIA AIRPORTS HOLDINGS BERHAD"
            ],
            "MSB" => [
                "MESINIAGA",
                "MESINIAGA BERHAD"
            ],
            "EXAMMED" => [
                "EXAMEDIA",
                "EXAMEDIA SOLUTIONS",
                "EXAMEDIA SOLUTIONS SDN BHD"
            ],
            "SUK" => [
                "SUK",
                "SETIAUSAHA KERAJAAN",
                "PEJABAT SETIAUSAHA KERAJAAN NEGERI"
            ],
            "UTM" => [
                "UTM",
                "UNIVERSITI TEKNOLOGI MALAYSIA"
            ],
            "IWK" => [
                "IWK",
                "INDAH WATER",
                "INDAH WATER KONSORTIUM",
                "INDAH WATER KONSORTIUM SDN BHD"
            ],
            "PERKESO" => [
                "PERKESO",
                "PERTUBUHAN KESELAMATAN SOSIAL",
                "SOCSO",
                "SOCIAL SECURITY ORGANISATION"
            ],
            "INTAN" => [
                "INTAN",
                "INSTITUT TADBIRAN AWAM NEGARA",
                "NATIONAL INSTITUTE OF PUBLIC ADMINISTRATION"
            ],
            "KUSKOP" => [
                "KUSKOP",
                "KEMENTERIAN PEMBANGUNAN USAHAWAN DAN KOPERASI",
                "MINISTRY OF ENTREPRENEUR AND COOPERATIVES DEVELOPMENT"
            ],
            "KTMB" => [
                "KTMB",
                "KERETAPI TANAH MELAYU",
                "KERETAPI TANAH MELAYU BERHAD"
            ],
            "SPR" => [
                "SPR",
                "SURUHANJAYA PILIHAN RAYA",
                "SURUHANJAYA PILIHAN RAYA MALAYSIA",
                "ELECTION COMMISSION OF MALAYSIA"
            ],
            "UMT" => [
                "UMT",
                "UNIVERSITI MALAYSIA TERENGGANU"
            ],
            "UPNM" => [
                "UPNM",
                "UNIVERSITI PERTAHANAN NASIONAL MALAYSIA",
                "NATIONAL DEFENCE UNIVERSITY OF MALAYSIA"
            ],
            "LHDN" => [
                "LHDN",
                "LEMBAGA HASIL DALAM NEGERI",
                "LEMBAGA HASIL DALAM NEGERI MALAYSIA",
                "INLAND REVENUE BOARD OF MALAYSIA"
            ],
            "KWSP" => [
                "KWSP",
                "KUMPULAN WANG SIMPANAN PEKERJA",
                "EPF",
                "EMPLOYEES PROVIDENT FUND"
            ],
            "MCMC" => [
                "MCMC",
                "MALAYSIAN COMMUNICATIONS AND MULTIMEDIA COMMISSION",
                "SKMM",
                "SURUHANJAYA KOMUNIKASI DAN MULTIMEDIA MALAYSIA"
            ],
            "DOSM" => [
                "DOSM",
                "DEPARTMENT OF STATISTICS MALAYSIA",
                "JABATAN PERANGKAAN MALAYSIA"
            ],
            "STATS" => [
                "JABATAN PERANGKAAN",
                "JABATAN PERANGKAAN MALAYSIA",
                "DEPARTMENT OF STATISTICS MALAYSIA"
            ],
            "UPSI" => [
                "UPSI",
                "UNIVERSITI PENDIDIKAN SULTAN IDRIS"
            ],
            "UNIMAP" => [
                "UNIMAP",
                "UNIVERSITI MALAYSIA PERLIS"
            ],
            "UM" => [
                "UM",
                "UNIVERSITI MALAYA"
            ],
            "UKM" => [
                "UKM",
                "UNIVERSITI KEBANGSAAN MALAYSIA"
            ],
            "USM" => [
                "USM",
                "UNIVERSITI SAINS MALAYSIA"
            ],
            "UITM" => [
                "UITM",
                "UNIVERSITI TEKNOLOGI MARA"
            ],
            "UTHM" => [
                "UTHM",
                "UNIVERSITI TUN HUSSEIN ONN MALAYSIA"
            ],
            "UPM" => [
                "UPM",
                "UNIVERSITI PUTRA MALAYSIA"
            ],
            "TNB" => [
                "TNB",
                "TENAGA NASIONAL",
                "TENAGA NASIONAL BERHAD",
                "TENAGA NATIONAL BERHAD"
            ],
            "TM" => [
                "TM",
                "TELEKOM MALAYSIA",
                "TELEKOM MALAYSIA BERHAD"
            ],
            "JPM" => [
                "JPM",
                "JABATAN PERDANA MENTERI",
                "PRIME MINISTER'S DEPARTMENT"
            ],
            "MEDAC" => [
                "MEDAC",
                "MINISTRY OF ENTREPRENEUR DEVELOPMENT AND COOPERATIVES",
                "KEMENTERIAN PEMBANGUNAN USAHAWAN DAN KOPERASI"
            ],
            "SPT" => [
                "SPT",
                "SPT SERVICES",
                "SPT SERVICES SDN BHD"
            ],
            "MPKJ" => [
                "MPKJ",
                "MAJLIS PERBANDARAN KAJANG"
            ],
            "SSM" => [
                "SSM",
                "SURUHANJAYA SYARIKAT MALAYSIA",
                "COMPANIES COMMISSION OF MALAYSIA"
            ],
            "JPWPKL" => [
                "JPWPKL",
                "JABATAN PENDIDIKAN WILAYAH PERSEKUTUAN KUALA LUMPUR"
            ],
            "DIGI" => [
                "DIGI",
                "DIGI TELECOMMUNICATIONS",
                "DIGI TELECOMMUNICATIONS SDN BHD",
                "CELCOMDIGI",
                "CELCOMDIGI BERHAD"
            ],
            "MERC" => [
                "MERCEDES-BENZ",
                "MERCEDES",
                "MERCEDES-BENZ MALAYSIA",
                "MERCEDES-BENZ MALAYSIA SDN BHD"
            ],
            "SME" => [
                "SME",
                "SME CORP",
                "SME CORPORATION MALAYSIA",
                "SME CORPORATION MALAYSIA BERHAD"
            ],
            "KKM" => [
                "KKM",
                "KEMENTERIAN KESIHATAN MALAYSIA",
                "MINISTRY OF HEALTH MALAYSIA"
            ],
            "KARS" => [
                "KARS",
                "KARS TECHNOLOGIES",
                "KARS TECHNOLOGIES SDN BHD"
            ],
            "JKR" => [
                "JKR",
                "JABATAN KERJA RAYA",
                "JABATAN KERJA RAYA MALAYSIA",
                "PUBLIC WORKS DEPARTMENT MALAYSIA"
            ],
            "JKM" => [
                "JKM",
                "JABATAN KEBAJIKAN MASYARAKAT",
                "DEPARTMENT OF SOCIAL WELFARE MALAYSIA"
            ],
            "HIGHP" => [
                "HIGHPOINT",
                "HIGHPOINT SERVICE NETWORK",
                "HIGHPOINT SERVICE NETWORK SDN BHD"
            ],
            "MOSTI" => [
                "MOSTI",
                "KEMENTERIAN SAINS TEKNOLOGI DAN INOVASI",
                "KEMENTERIAN SAINS, TEKNOLOGI DAN INOVASI",
                "MINISTRY OF SCIENCE TECHNOLOGY AND INNOVATION",
                "MINISTRY OF SCIENCE, TECHNOLOGY AND INNOVATION"
            ],
            "HTAR" => [
                "HTAR",
                "HOSPITAL TENGKU AMPUAN RAHIMAH"
            ],
            "UGC" => [
                "UNIGREEN",
                "UNIGREEN CHEMICALS",
                "UNIGREEN CHEMICALS SDN BHD"
            ],
            "PNB" => [
                "PNB",
                "PERMODALAN NASIONAL",
                "PERMODALAN NASIONAL BERHAD"
            ],
            "AMKOR" => [
                "AMKOR",
                "AMKOR TECHNOLOGY",
                "AMKOR TECHNOLOGY MALAYSIA",
                "AMKOR TECHNOLOGY MALAYSIA SDN BHD"
            ],
            "KGIS" => [
                "KGIS",
                "KG INVICTA SERVICES"
            ],
            "JPN" => [
                "JPN",
                "JABATAN PENDAFTARAN NEGARA",
                "JABATAN PENDAFTARAN NEGARA MALAYSIA",
                "NATIONAL REGISTRATION DEPARTMENT"
            ],
            "NAHRIM" => [
                "NAHRIM",
                "INSTITUT PENYELIDIKAN AIR KEBANGSAAN MALAYSIA",
                "NATIONAL WATER RESEARCH INSTITUTE OF MALAYSIA"
            ],
            "ASTRO" => [
                "ASTRO",
                "ASTRO MALAYSIA",
                "ASTRO MALAYSIA HOLDINGS",
                "ASTRO MALAYSIA HOLDINGS BERHAD"
            ],
            "MAMPU" => [
                "MAMPU",
                "MALAYSIAN ADMINISTRATIVE MODERNISATION AND MANAGEMENT PLANNING UNIT",
                "UNIT PEMODENAN TADBIRAN DAN PERANCANGAN PENGURUSAN MALAYSIA"
            ],
            "JPA" => [
                "JPA",
                "JABATAN PERKHIDMATAN AWAM",
                "JABATAN PERKHIDMATAN AWAM MALAYSIA",
                "PUBLIC SERVICE DEPARTMENT MALAYSIA"
            ],
            "SPA" => [
                "SPA",
                "SURUHANJAYA PERKHIDMATAN AWAM",
                "SURUHANJAYA PERKHIDMATAN AWAM MALAYSIA",
                "PUBLIC SERVICES COMMISSION OF MALAYSIA"
            ],
            "KDN" => [
                "KDN",
                "KEMENTERIAN DALAM NEGERI",
                "MINISTRY OF HOME AFFAIRS"
            ],
            "MELL" => [
                "MILLENIUM",
                "MILLENNIUM",
                "MILLENNIUM TECHNOLOGY",
                "MILLENNIUM TECHNOLOGY SERVICES"
            ],
            "PDRM" => [
                "PDRM",
                "POLIS DIRAJA MALAYSIA",
                "ROYAL MALAYSIA POLICE"
            ],
            "MKN" => [
                "MKN",
                "MAJLIS KESELAMATAN NEGARA",
                "NATIONAL SECURITY COUNCIL"
            ],
            "MASJID" => [
                "MASJID"
            ],
            "RENTO" => [
                "RENTOKIL",
                "RENTOKIL INITIAL",
                "RENTOKIL INITIAL MALAYSIA",
                "RENTOKIL INITIAL M SDN BHD",
                "RENTOKIL INITIAL (M) SDN BHD"
            ],
            "TRAITX" => [
                "TRAITX",
                "TRAITX SOLUTIONS",
                "TRAITX SOLUTIONS SDN BHD"
            ],
            "LPPKN" => [
                "LPPKN",
                "LEMBAGA PENDUDUK DAN PEMBANGUNAN KELUARGA NEGARA",
                "NATIONAL POPULATION AND FAMILY DEVELOPMENT BOARD"
            ],
            "MOE" => [
                "MOE",
                "MINISTRY OF EDUCATION",
                "MINISTRY OF EDUCATION MALAYSIA",
                "KEMENTERIAN PENDIDIKAN MALAYSIA"
            ],
            "AGD" => [
                "AGD",
                "ACCOUNTANT GENERAL'S DEPARTMENT",
                "ACCOUNTANT GENERAL'S DEPARTMENT OF MALAYSIA",
                "JABATAN AKAUNTAN NEGARA",
                "JABATAN AKAUNTAN NEGARA MALAYSIA"
            ],
            "PRGSV" => [
                "PROGRESSIVE",
                "PROGRESSIVE IMPACT",
                "PROGRESSIVE IMPACT CORPORATION",
                "PROGRESSIVE IMPACT CORPORATION BERHAD"
            ],
            "PROTON" => [
                "PROTON",
                "PROTON HOLDINGS",
                "PROTON HOLDINGS BERHAD",
                "PERUSAHAAN OTOMOBIL NASIONAL"
            ],
            "SIPMA" => [
                "SIPMA",
                "SUKAN INSTITUSI PENDIDIKAN MALAYSIA"
            ],
            "SSUK" => [
                "SELANGOR SUK",
                "SUK SELANGOR",
                "SETIAUSAHA KERAJAAN NEGERI SELANGOR",
                "PEJABAT SETIAUSAHA KERAJAAN NEGERI SELANGOR"
            ],
            "VINX" => [
                "VINX",
                "VINX MALAYSIA",
                "VINX MALAYSIA SDN BHD"
            ],
            "TW" => [
                "TRADEWINDS",
                "TRADE WINDS",
                "TRADEWINDS PLANTATION",
                "TRADEWINDS PLANTATION BERHAD"
            ],
            "ILKBS" => [
                "ILKBS",
                "INSTITUSI LATIHAN KEMAHIRAN BELIA DAN SUKAN"
            ]
        ];
    }
}

if(!function_exists('contractProjectCodeCanonicalEndUsers')){
    function contractProjectCodeCanonicalEndUsers(){
        return [
            "SUKN9" => "Pejabat Setiausaha Kerajaan Negeri Sembilan",
            "AGMSN" => "AG-Mesiniaga Sdn Bhd",
            "MEDSEL" => "Media Selangor Sdn Bhd",
            "MEDPRI" => "Media Prima Berhad",
            "GSPAPER" => "GS Paperboard & Packaging Sdn Bhd",
            "POCDATA" => "Pocket Data (M) Sdn Bhd",
            "PACOIL" => "Pacific Oils & Fats Industries Sdn Bhd",
            "COOPB" => "Koperasi Co-opbank Pertama Malaysia Berhad",
            "BMMB" => "Bank Muamalat Malaysia Berhad",
            "BKRM" => "Bank Kerjasama Rakyat Malaysia Berhad",
            "PPKB" => "Perbadanan Pembangunan Kampong Bharu",
            "AHZAKI" => "Ahmad Zaki Sdn Bhd",
            "NIPPON" => "Nippon Paint (M) Sdn Bhd",
            "HERNAL" => "Heritage International Sdn Bhd",
            "FIRSOL" => "First Solution Sdn Bhd",
            "FORSOL" => "Fotia Solutions Sdn Bhd",
            "RFMS" => "Rafulin FMS Sdn Bhd",
            "PRODUA" => "Perusahaan Otomobil Kedua Sdn Bhd",
            "020PLA" => "O2O Plantation Sdn Bhd",
            "KPDK" => "Kementerian Perladangan dan Komoditi",
            "JASWKL" => "Jabatan Alam Sekitar Wilayah Persekutuan Kuala Lumpur",
            "JPSM" => "Jabatan Perhutanan Semenanjung Malaysia",
            "JDN" => "Jabatan Digital Negara",
            "MAHB" => "Malaysia Airports Holdings Berhad",
            "MSB" => "Mesiniaga Berhad",
            "EXAMMED" => "Examedia Solutions Sdn Bhd",
            "SUK" => "Pejabat Setiausaha Kerajaan Negeri",
            "UTM" => "Universiti Teknologi Malaysia",
            "IWK" => "Indah Water Konsortium Sdn Bhd",
            "PERKESO" => "Pertubuhan Keselamatan Sosial",
            "INTAN" => "Institut Tadbiran Awam Negara",
            "KUSKOP" => "Kementerian Pembangunan Usahawan dan Koperasi",
            "KTMB" => "Keretapi Tanah Melayu Berhad",
            "SPR" => "Suruhanjaya Pilihan Raya Malaysia",
            "UMT" => "Universiti Malaysia Terengganu",
            "UPNM" => "Universiti Pertahanan Nasional Malaysia",
            "LHDN" => "Lembaga Hasil Dalam Negeri Malaysia",
            "KWSP" => "Kumpulan Wang Simpanan Pekerja",
            "MCMC" => "Malaysian Communications and Multimedia Commission",
            "DOSM" => "Department of Statistics Malaysia",
            "STATS" => "Jabatan Perangkaan Malaysia",
            "UPSI" => "Universiti Pendidikan Sultan Idris",
            "UNIMAP" => "Universiti Malaysia Perlis",
            "UM" => "Universiti Malaya",
            "UKM" => "Universiti Kebangsaan Malaysia",
            "USM" => "Universiti Sains Malaysia",
            "UITM" => "Universiti Teknologi MARA",
            "UTHM" => "Universiti Tun Hussein Onn Malaysia",
            "UPM" => "Universiti Putra Malaysia",
            "TNB" => "Tenaga Nasional Berhad",
            "TM" => "Telekom Malaysia Berhad",
            "JPM" => "Jabatan Perdana Menteri",
            "MEDAC" => "Ministry of Entrepreneur Development and Cooperatives",
            "SPT" => "SPT Services Sdn Bhd",
            "MPKJ" => "Majlis Perbandaran Kajang",
            "SSM" => "Suruhanjaya Syarikat Malaysia",
            "JPWPKL" => "Jabatan Pendidikan Wilayah Persekutuan Kuala Lumpur",
            "DIGI" => "Digi Telecommunications Sdn Bhd",
            "MERC" => "Mercedes-Benz Malaysia Sdn Bhd",
            "SME" => "SME Corporation Malaysia",
            "KKM" => "Kementerian Kesihatan Malaysia",
            "KARS" => "KARS Technologies Sdn Bhd",
            "JKR" => "Jabatan Kerja Raya Malaysia",
            "JKM" => "Jabatan Kebajikan Masyarakat",
            "HIGHP" => "Highpoint Service Network Sdn Bhd",
            "MOSTI" => "Kementerian Sains, Teknologi dan Inovasi",
            "HTAR" => "Hospital Tengku Ampuan Rahimah",
            "UGC" => "Unigreen Chemicals Sdn Bhd",
            "PNB" => "Permodalan Nasional Berhad",
            "AMKOR" => "Amkor Technology Malaysia Sdn Bhd",
            "KGIS" => "KG Invicta Services",
            "JPN" => "Jabatan Pendaftaran Negara Malaysia",
            "NAHRIM" => "Institut Penyelidikan Air Kebangsaan Malaysia",
            "ASTRO" => "Astro Malaysia Holdings Berhad",
            "MAMPU" => "Malaysian Administrative Modernisation and Management Planning Unit",
            "JPA" => "Jabatan Perkhidmatan Awam Malaysia",
            "SPA" => "Suruhanjaya Perkhidmatan Awam Malaysia",
            "KDN" => "Kementerian Dalam Negeri",
            "MELL" => "Millennium Technology Services",
            "PDRM" => "Polis Diraja Malaysia",
            "MKN" => "Majlis Keselamatan Negara",
            "MASJID" => "Masjid",
            "RENTO" => "Rentokil Initial (M) Sdn Bhd",
            "TRAITX" => "TraitX Solutions Sdn Bhd",
            "LPPKN" => "Lembaga Penduduk dan Pembangunan Keluarga Negara",
            "MOE" => "Ministry of Education Malaysia",
            "AGD" => "Accountant General's Department of Malaysia",
            "PRGSV" => "Progressive Impact Corporation Berhad",
            "PROTON" => "Proton Holdings Berhad",
            "SIPMA" => "Sukan Institusi Pendidikan Malaysia",
            "SSUK" => "Pejabat Setiausaha Kerajaan Negeri Selangor",
            "VINX" => "VINX Malaysia Sdn Bhd",
            "TW" => "Tradewinds Plantation Berhad",
            "ILKBS" => "Institusi Latihan Kemahiran Belia dan Sukan"
        ];
    }
}

if(!function_exists('contractProjectCodeCanonicalEndUser')){
    function contractProjectCodeCanonicalEndUser($value){
        $prefix = contractProjectCodeFindKnownPrefix($value);
        $canonicalEndUsers = contractProjectCodeCanonicalEndUsers();

        if($prefix !== "" && isset($canonicalEndUsers[$prefix])){
            return $canonicalEndUsers[$prefix];
        }

        return trim((string)($value ?? ""));
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

        foreach(contractProjectCodeCanonicalEndUsers() as $prefix => $canonicalEndUser){
            $canonicalEndUser = strtoupper((string)$canonicalEndUser);
            $canonicalEndUser = preg_replace('/[^A-Z0-9]+/', ' ', $canonicalEndUser);
            $canonicalEndUser = trim(preg_replace('/\s+/', ' ', $canonicalEndUser));

            if($canonicalEndUser !== "" && $text === $canonicalEndUser){
                return $prefix;
            }
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
            $rawProjectCode = $row['project_code'] ?? "";
            $currentCode = contractProjectCodeNormalize($rawProjectCode);
            $hasValidCurrentCode = (
                $currentCode !== "" &&
                contractProjectCodeHasExpectedFormat($rawProjectCode) &&
                !contractProjectCodeExists($mysqli, $currentCode, $no)
            );

            if(!$hasValidCurrentCode){
                $hasAvailableSource = false;

                foreach(["project_name", "end_user", "project_owner", "contract_no"] as $sourceColumn){
                    if(trim((string)($row[$sourceColumn] ?? "")) !== ""){
                        $hasAvailableSource = true;
                        break;
                    }
                }

                if(!$hasAvailableSource){
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

                $generatedMiddle = contractProjectCodePrefix(
                    $row['project_name'] ?? "",
                    $row['end_user'] ?? "",
                    $row['project_owner'] ?? "",
                    $row['contract_no'] ?? ""
                );
                $currentCode = contractProjectCodeGenerateFromMiddle($mysqli, $generatedMiddle, $no);
            }

            if($currentCode === ""){
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

        $contractColumns = [
            "notification_email" => "varchar(255) DEFAULT NULL AFTER `contract_end`",
            "payment_term" => "varchar(100) DEFAULT NULL AFTER `amount`",
            "no_of_pm" => "decimal(8,2) DEFAULT NULL AFTER `payment_term`",
            "project_remark" => "text DEFAULT NULL AFTER `no_of_pm`",
            "pm_y1_q1" => "varchar(50) DEFAULT NULL AFTER `no_of_pm`",
            "pm_y1_q2" => "varchar(50) DEFAULT NULL AFTER `pm_y1_q1`",
            "pm_y1_q3" => "varchar(50) DEFAULT NULL AFTER `pm_y1_q2`",
            "pm_y1_q4" => "varchar(50) DEFAULT NULL AFTER `pm_y1_q3`",
            "pm_y2_q1" => "varchar(50) DEFAULT NULL AFTER `pm_y1_q4`",
            "pm_y2_q2" => "varchar(50) DEFAULT NULL AFTER `pm_y2_q1`",
            "pm_y2_q3" => "varchar(50) DEFAULT NULL AFTER `pm_y2_q2`",
            "pm_y2_q4" => "varchar(50) DEFAULT NULL AFTER `pm_y2_q3`",
            "pm_y3_q1" => "varchar(50) DEFAULT NULL AFTER `pm_y2_q4`",
            "pm_y3_q2" => "varchar(50) DEFAULT NULL AFTER `pm_y3_q1`",
            "pm_y3_q3" => "varchar(50) DEFAULT NULL AFTER `pm_y3_q2`",
            "pm_y3_q4" => "varchar(50) DEFAULT NULL AFTER `pm_y3_q3`",
            "pm_y4_q1" => "varchar(50) DEFAULT NULL AFTER `pm_y3_q4`",
            "pm_y4_q2" => "varchar(50) DEFAULT NULL AFTER `pm_y4_q1`",
            "pm_y4_q3" => "varchar(50) DEFAULT NULL AFTER `pm_y4_q2`",
            "pm_y4_q4" => "varchar(50) DEFAULT NULL AFTER `pm_y4_q3`"
        ];

        foreach($contractColumns as $columnName => $definition){
            if(!contractSchemaColumnExists($mysqli, "project_inventory", $columnName)){
                $mysqli->query("ALTER TABLE `project_inventory` ADD COLUMN `$columnName` $definition");
            }
        }

        contractSchemaResetExistingProjectCodes($mysqli);

        $needsProjectCodeBackfill = false;
        $backfillCheck = $mysqli->query("
            SELECT 1
            FROM project_inventory
            WHERE project_code IS NULL
               OR project_code = ''
               OR project_code NOT LIKE 'PRO/%'
            LIMIT 1
        ");

        if($backfillCheck && $backfillCheck->num_rows > 0){
            $needsProjectCodeBackfill = true;
        }

        if($needsProjectCodeBackfill){
            contractSchemaBackfillProjectCodes($mysqli);
        }

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
