<?php
require_once __DIR__ . "/date_helpers.php";
require_once __DIR__ . "/contract_schema.php";
require_once __DIR__ . "/contract_task_schema.php";

if(!function_exists('projectDashboardEscape')){
    function projectDashboardEscape($value){
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if(!function_exists('projectDashboardTableExists')){
    function projectDashboardTableExists($mysqli, $tableName){
        $tableName = $mysqli->real_escape_string($tableName);
        $result = $mysqli->query("SHOW TABLES LIKE '$tableName'");
        return ($result && $result->num_rows > 0);
    }
}

if(!function_exists('projectDashboardColumnExists')){
    function projectDashboardColumnExists($mysqli, $tableName, $columnName){
        $tableName = str_replace("`", "", $tableName);
        $columnName = $mysqli->real_escape_string($columnName);
        $result = $mysqli->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
        return ($result && $result->num_rows > 0);
    }
}

if(!function_exists('projectDashboardStatusCase')){
    function projectDashboardStatusCase($alias = "pi"){
        $contractEndSql = appSqlDateValue($alias . ".contract_end");

        return "
            CASE
                WHEN $contractEndSql IS NOT NULL
                     AND $contractEndSql < CURDATE()
                THEN 'Closed'
                WHEN $contractEndSql IS NOT NULL
                     AND $contractEndSql BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                THEN 'Expiring Soon'
                ELSE 'Active'
            END
        ";
    }
}

if(!function_exists('projectDashboardMoney')){
    function projectDashboardMoney($value){
        return "RM " . number_format((float)$value, 2);
    }
}

if(!function_exists('projectDashboardDate')){
    function projectDashboardDate($value){
        $value = trim((string)($value ?? ""));

        if($value === "" || $value === "0000-00-00"){
            return "-";
        }

        $timestamp = strtotime($value);
        return $timestamp ? date("d M Y", $timestamp) : $value;
    }
}

if(!function_exists('projectDashboardFetchSuggestions')){
    function projectDashboardFetchSuggestions($mysqli){
        ensureContractProjectSchema($mysqli);

        $projectCodes = [];
        $owners = [];

        $codeResult = $mysqli->query("
            SELECT DISTINCT project_code
            FROM project_inventory
            WHERE project_code IS NOT NULL
            ORDER BY project_code ASC
        ");

        if($codeResult){
            while($row = $codeResult->fetch_assoc()){
                $display = contractProjectCodeDisplay($row['project_code'] ?? "");

                if($display !== "" && !in_array($display, $projectCodes, true)){
                    $projectCodes[] = $display;
                }
            }
        }

        $placeholderResult = $mysqli->query("
            SELECT COUNT(*) AS total
            FROM project_inventory
            WHERE project_code IS NULL
               OR TRIM(project_code) = ''
               OR project_code REGEXP '^PRO/[[:space:]]*/0+$'
        ");

        if($placeholderResult){
            $placeholderCount = (int)($placeholderResult->fetch_assoc()['total'] ?? 0);
            $placeholder = contractProjectCodePlaceholder();

            if($placeholderCount > 0 && !in_array($placeholder, $projectCodes, true)){
                array_unshift($projectCodes, $placeholder);
            }
        }

        $ownerResult = $mysqli->query("
            SELECT DISTINCT project_owner
            FROM project_inventory
            WHERE project_owner IS NOT NULL
              AND TRIM(project_owner) <> ''
            ORDER BY project_owner ASC
        ");

        if($ownerResult){
            while($row = $ownerResult->fetch_assoc()){
                $owner = trim((string)($row['project_owner'] ?? ""));

                if($owner !== ""){
                    $owners[] = $owner;
                }
            }
        }

        return [
            "project_codes" => $projectCodes,
            "owners" => $owners
        ];
    }
}

if(!function_exists('projectDashboardNormalizeFilterType')){
    function projectDashboardNormalizeFilterType($filterType){
        return $filterType === "owner" ? "owner" : "project_code";
    }
}

if(!function_exists('projectDashboardFetchContracts')){
    function projectDashboardFetchContracts($mysqli, $filterType, $filterValue){
        ensureContractProjectSchema($mysqli);
        ensureContractTaskCompletionSchema($mysqli);

        $filterType = projectDashboardNormalizeFilterType($filterType);
        $filterValue = trim((string)$filterValue);

        if($filterValue === ""){
            return [];
        }

        $statusCase = projectDashboardStatusCase("pi");
        $contractStartSql = appSqlDateValue("pi.contract_start");

        $claimJoinSql = "";
        $claimSelectSql = "0 AS claim_total";
        $taskJoinSql = "";
        $taskTotalSelect = "0 AS task_total";
        $taskDoneSelect = "0 AS task_done";

        $hasTasks = projectDashboardTableExists($mysqli, "contract_tasks")
            && projectDashboardColumnExists($mysqli, "contract_tasks", "contract_id");

        if($hasTasks){
            if(projectDashboardColumnExists($mysqli, "contract_tasks", "claim_amount")){
                $claimJoinSql = "
                    LEFT JOIN (
                        SELECT contract_id, SUM(COALESCE(claim_amount, 0)) AS claim_total
                        FROM contract_tasks
                        GROUP BY contract_id
                    ) claim_summary ON claim_summary.contract_id = pi.no
                ";
                $claimSelectSql = "COALESCE(claim_summary.claim_total, 0) AS claim_total";
            }

            if(projectDashboardColumnExists($mysqli, "contract_tasks", "is_completed")){
                $doneSql = "SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END)";
            } elseif(projectDashboardColumnExists($mysqli, "contract_tasks", "completed")){
                $doneSql = "SUM(CASE WHEN completed = 1 THEN 1 ELSE 0 END)";
            } elseif(projectDashboardColumnExists($mysqli, "contract_tasks", "status")){
                $doneSql = "SUM(CASE WHEN LOWER(status) IN ('completed','complete','done') THEN 1 ELSE 0 END)";
            } else {
                $doneSql = "SUM(0)";
            }

            $taskJoinSql = "
                LEFT JOIN (
                    SELECT contract_id, COUNT(*) AS task_total, $doneSql AS task_done
                    FROM contract_tasks
                    GROUP BY contract_id
                ) task_summary ON task_summary.contract_id = pi.no
            ";
            $taskTotalSelect = "COALESCE(task_summary.task_total, 0) AS task_total";
            $taskDoneSelect = "COALESCE(task_summary.task_done, 0) AS task_done";
        }

        $whereColumn = $filterType === "owner" ? "pi.project_owner" : "pi.project_code";
        $whereSql = "LOWER(TRIM($whereColumn)) = LOWER(TRIM(?))";
        $needsFilterParam = true;

        if($filterType === "project_code" && contractProjectCodeIsPlaceholder($filterValue)){
            $whereSql = "(pi.project_code IS NULL OR TRIM(pi.project_code) = '' OR pi.project_code REGEXP '^PRO/[[:space:]]*/0+$')";
            $needsFilterParam = false;
        }

        $stmt = $mysqli->prepare("
            SELECT
                pi.no,
                pi.project_code,
                pi.year_awarded,
                pi.project_name,
                pi.project_owner,
                pi.contract_no,
                pi.contract_start,
                pi.contract_end,
                pi.amount,
                $statusCase AS auto_status,
                $claimSelectSql,
                $taskTotalSelect,
                $taskDoneSelect
            FROM project_inventory pi
            $claimJoinSql
            $taskJoinSql
            WHERE $whereSql
            ORDER BY
                CASE WHEN $contractStartSql IS NULL THEN 1 ELSE 0 END ASC,
                $contractStartSql ASC,
                pi.no ASC
        ");

        if(!$stmt){
            return [];
        }

        if($needsFilterParam){
            $stmt->bind_param("s", $filterValue);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];

        while($row = $result->fetch_assoc()){
            $amount = (float)($row['amount'] ?? 0);
            $claimTotal = (float)($row['claim_total'] ?? 0);
            $taskTotal = (int)($row['task_total'] ?? 0);
            $taskDone = (int)($row['task_done'] ?? 0);

            $row['display_project_code'] = contractProjectCodeDisplay($row['project_code'] ?? "");
            $row['amount_numeric'] = $amount;
            $row['claim_total_numeric'] = $claimTotal;
            $row['leftover_numeric'] = max(0, $amount - $claimTotal);
            $row['task_total_numeric'] = $taskTotal;
            $row['task_done_numeric'] = $taskDone;
            $row['progress_percent'] = $taskTotal > 0 ? round(($taskDone / $taskTotal) * 100) : 0;

            $rows[] = $row;
        }

        return $rows;
    }
}

if(!function_exists('projectDashboardBuildData')){
    function projectDashboardBuildData($contracts){
        $summary = [
            "total_contracts" => count($contracts),
            "active" => 0,
            "expiring" => 0,
            "closed" => 0,
            "amount" => 0,
            "claimed" => 0,
            "leftover" => 0,
            "progress" => 0
        ];

        $statusCounts = [
            "Active" => 0,
            "Expiring Soon" => 0,
            "Closed" => 0
        ];
        $amountByProject = [];
        $claimByProject = [];
        $amountByYear = [];
        $claimByYear = [];
        $timeline = [];

        foreach($contracts as $row){
            $status = $row['auto_status'] ?? "Active";
            $amount = (float)($row['amount_numeric'] ?? 0);
            $claimed = (float)($row['claim_total_numeric'] ?? 0);
            $year = trim((string)($row['year_awarded'] ?? "")) !== "" ? (string)$row['year_awarded'] : "Unknown";
            $projectName = trim((string)($row['project_name'] ?? "")) !== "" ? $row['project_name'] : "Project " . ($row['no'] ?? "");

            if(isset($statusCounts[$status])){
                $statusCounts[$status]++;
            }

            if($status === "Active"){
                $summary["active"]++;
            } elseif($status === "Expiring Soon"){
                $summary["expiring"]++;
            } elseif($status === "Closed"){
                $summary["closed"]++;
            }

            $summary["amount"] += $amount;
            $summary["claimed"] += $claimed;

            if(!isset($amountByProject[$projectName])){
                $amountByProject[$projectName] = 0;
                $claimByProject[$projectName] = 0;
            }

            if(!isset($amountByYear[$year])){
                $amountByYear[$year] = 0;
                $claimByYear[$year] = 0;
            }

            $amountByProject[$projectName] += $amount;
            $claimByProject[$projectName] += $claimed;
            $amountByYear[$year] += $amount;
            $claimByYear[$year] += $claimed;

            $timeline[] = [
                "project" => $projectName,
                "project_code" => $row['display_project_code'] ?? contractProjectCodePlaceholder(),
                "contract_no" => $row['contract_no'] ?? "",
                "start" => $row['contract_start'] ?? "",
                "end" => $row['contract_end'] ?? "",
                "status" => $status,
                "amount" => $amount,
                "claimed" => $claimed,
                "leftover" => (float)($row['leftover_numeric'] ?? 0),
                "progress" => (int)($row['progress_percent'] ?? 0)
            ];
        }

        $summary["leftover"] = max(0, $summary["amount"] - $summary["claimed"]);
        $summary["progress"] = $summary["amount"] > 0 ? round(($summary["claimed"] / $summary["amount"]) * 100) : 0;

        ksort($amountByYear);
        ksort($claimByYear);

        return [
            "summary" => $summary,
            "status_counts" => $statusCounts,
            "amount_by_project" => $amountByProject,
            "claim_by_project" => $claimByProject,
            "amount_by_year" => $amountByYear,
            "claim_by_year" => $claimByYear,
            "timeline" => $timeline
        ];
    }
}
?>
