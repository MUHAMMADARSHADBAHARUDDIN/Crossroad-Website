<?php
require __DIR__ . '/../includes/db_connect.php';
require __DIR__ . '/../includes/contract_schema.php';

if (($argv[1] ?? '') === '--inspect') {
    foreach ([
        'all' => 'SELECT COUNT(*) c FROM project_inventory',
        'distinct_codes' => 'SELECT COUNT(DISTINCT project_code) c FROM project_inventory',
        'master_codes' => "SELECT COUNT(*) c FROM project_inventory WHERE project_code LIKE 'CRS%' OR project_code LIKE 'CR-%'",
        'generated_codes' => "SELECT COUNT(*) c FROM project_inventory WHERE project_code LIKE 'PRO/%'"
    ] as $label => $query) {
        $result = $mysqli->query($query);
        echo $label . '=' . $result->fetch_assoc()['c'] . PHP_EOL;
    }
    exit;
}

// The application schema helper has already added the optional columns safely.
$sql = file_get_contents(__DIR__ . '/2026_07_14_contract_expansion_import.sql');
$sql = preg_replace('/ALTER TABLE `project_inventory`.*?;/s', '', $sql, 1);
if (!$mysqli->multi_query($sql)) {
    fwrite(STDERR, $mysqli->error . PHP_EOL);
    exit(1);
}
do {
    if ($result = $mysqli->store_result()) $result->free();
    if (!$mysqli->more_results()) break;
} while ($mysqli->next_result());
if ($mysqli->errno) {
    fwrite(STDERR, $mysqli->error . PHP_EOL);
    exit(1);
}
ensureContractProjectSchema($mysqli);
echo "Contract import completed." . PHP_EOL;
