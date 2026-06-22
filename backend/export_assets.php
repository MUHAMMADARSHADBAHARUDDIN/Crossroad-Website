<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

if(!isset($_SESSION['username'])){
    die("No session");
}

if(!hasPermission($mysqli, "inventory_export")){
    die("Access denied");
}

$format = strtolower(trim($_GET['format'] ?? 'excel'));
$movement = strtolower(trim($_GET['movement'] ?? 'all'));

if(!in_array($format, ["excel", "pdf", "print"], true)){
    $format = "excel";
}

if(!in_array($movement, ["stock_in", "stock_out", "all"], true)){
    $movement = "all";
}

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? 'Unknown';
$ip = $_SERVER['REMOTE_ADDR'] ?? "CLI";
$time = date("Y-m-d H:i:s");

function assetExportEscape($value){
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function assetExportFetchRows($mysqli, $sql){
    $result = $mysqli->query($sql);
    $rows = [];

    if($result){
        while($row = $result->fetch_assoc()){
            $rows[] = $row;
        }
    }

    return $rows;
}

function assetExportSections($mysqli, $movement){
    $sections = [];

    if($movement === "stock_in" || $movement === "all"){
        $sections[] = [
            "title" => "ASSET STOCK IN",
            "file_label" => "asset_stock_in",
            "header_color" => "#4CAF50",
            "sub_color" => "#d9ead3",
            "columns" => [
                "stock_in_date" => "Date",
                "stock_in_by" => "Stock In By",
                "part_number" => "Part Number",
                "serial_number" => "Serial Number",
                "brand" => "Brand",
                "quantity" => "Qty",
                "location" => "Location"
            ],
            "pdf_widths" => [32, 34, 42, 45, 32, 16, 45],
            "rows" => assetExportFetchRows($mysqli, "
                SELECT stock_in_date, stock_in_by, part_number, serial_number, brand, quantity, location
                FROM asset_stockin_history
                ORDER BY stock_in_date ASC, id ASC
            ")
        ];
    }

    if($movement === "stock_out" || $movement === "all"){
        $sections[] = [
            "title" => "ASSET STOCK OUT",
            "file_label" => "asset_stock_out",
            "header_color" => "#c00000",
            "sub_color" => "#f4cccc",
            "columns" => [
                "stock_out_date" => "Date",
                "stock_out_by" => "Stock Out By",
                "part_number" => "Part Number",
                "serial_number" => "Serial Number",
                "quantity" => "Qty",
                "location" => "Location",
                "remark" => "Remark"
            ],
            "pdf_widths" => [34, 34, 44, 45, 20, 38, 50],
            "rows" => assetExportFetchRows($mysqli, "
                SELECT stock_out_date, stock_out_by, part_number, serial_number, COALESCE(quantity, 1) AS quantity, location, remark
                FROM stock_out_history
                ORDER BY stock_out_date ASC, id ASC
            ")
        ];
    }

    return $sections;
}

function assetExportFileLabel($sections, $movement){
    if($movement === "stock_in"){
        return "asset_stock_in";
    }

    if($movement === "stock_out"){
        return "asset_stock_out";
    }

    return "asset_stock_movement";
}

$sections = assetExportSections($mysqli, $movement);
$fileLabel = assetExportFileLabel($sections, $movement);
$movementLabel = $movement === "stock_in" ? "Stock In" : ($movement === "stock_out" ? "Stock Out" : "Stock Movement");

if($format === "excel"){
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename={$fileLabel}.xls");

    foreach($sections as $section){
        echo "<table border='1'>";
        echo "<tr style='background-color:" . assetExportEscape($section['header_color']) . "; color:white;'>";
        echo "<th colspan='" . count($section['columns']) . "'>" . assetExportEscape($section['title']) . "</th>";
        echo "</tr><tr style='background-color:" . assetExportEscape($section['sub_color']) . ";'>";

        foreach($section['columns'] as $label){
            echo "<th>" . assetExportEscape($label) . "</th>";
        }

        echo "</tr>";

        foreach($section['rows'] as $row){
            echo "<tr>";

            foreach(array_keys($section['columns']) as $field){
                echo "<td>" . assetExportEscape($row[$field] ?? "") . "</td>";
            }

            echo "</tr>";
        }

        echo "</table><br><br>";
    }

    $description = "User [$username] exported asset $movementLabel (EXCEL).
IP Address: $ip
Time: $time";

    logActivity($mysqli, $username, $role, "EXPORT ASSET EXCEL", $description);
    exit();
}

if($format === "pdf"){
    require('../includes/fpdf/fpdf.php');

    class AssetExportPDF extends FPDF {
        function Row($data, $widths){
            $nb = 0;

            for($i = 0; $i < count($data); $i++){
                $nb = max($nb, $this->NbLines($widths[$i], (string)$data[$i]));
            }

            $h = 6 * $nb;
            $this->CheckPageBreak($h);

            for($i = 0; $i < count($data); $i++){
                $w = $widths[$i];
                $x = $this->GetX();
                $y = $this->GetY();

                $this->Rect($x, $y, $w, $h);
                $this->MultiCell($w, 6, (string)$data[$i], 0);
                $this->SetXY($x + $w, $y);
            }

            $this->Ln($h);
        }

        function CheckPageBreak($h){
            if($this->GetY() + $h > $this->PageBreakTrigger){
                $this->AddPage('L');
            }
        }

        function NbLines($w, $txt){
            $cw = &$this->CurrentFont['cw'];

            if($w == 0){
                $w = $this->w - $this->rMargin - $this->x;
            }

            $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
            $s = str_replace("\r", '', $txt);
            $nb = strlen($s);

            if($nb > 0 && $s[$nb - 1] == "\n"){
                $nb--;
            }

            $sep = -1;
            $i = 0;
            $j = 0;
            $l = 0;
            $nl = 1;

            while($i < $nb){
                $c = $s[$i];

                if($c == "\n"){
                    $i++;
                    $sep = -1;
                    $j = $i;
                    $l = 0;
                    $nl++;
                    continue;
                }

                if($c == ' '){
                    $sep = $i;
                }

                $l += $cw[$c] ?? 0;

                if($l > $wmax){
                    if($sep == -1){
                        if($i == $j){
                            $i++;
                        }
                    }else{
                        $i = $sep + 1;
                    }

                    $sep = -1;
                    $j = $i;
                    $l = 0;
                    $nl++;
                }else{
                    $i++;
                }
            }

            return $nl;
        }
    }

    $pdf = new AssetExportPDF('L');
    $pdf->AddPage('L');

    foreach($sections as $index => $section){
        if($index > 0){
            $pdf->AddPage('L');
        }

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, $section['title'], 0, 1, 'C');
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Row(array_values($section['columns']), $section['pdf_widths']);
        $pdf->SetFont('Arial', '', 8);

        foreach($section['rows'] as $row){
            $line = [];

            foreach(array_keys($section['columns']) as $field){
                $line[] = $row[$field] ?? "";
            }

            $pdf->Row($line, $section['pdf_widths']);
        }
    }

    $description = "User [$username] exported asset $movementLabel (PDF).
IP Address: $ip
Time: $time";

    logActivity($mysqli, $username, $role, "EXPORT ASSET PDF", $description);
    $pdf->Output('I', $fileLabel . ".pdf");
    exit();
}

if($format === "print"){
?>
<!DOCTYPE html>
<html>
<head>
    <title>Print <?= assetExportEscape($movementLabel) ?> Asset Report</title>
    <link rel="icon" type="image/png" href="../image/logo.png">
    <link rel="shortcut icon" type="image/png" href="../image/logo.png">
    <link rel="apple-touch-icon" href="../image/logo.png">
    <style>
        body { font-family: Arial, sans-serif; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        th, td { border: 1px solid black; padding: 8px; text-align: left; }
        h2 { text-align: center; }
        @media print { @page { size: A4 landscape; margin: 12mm; } }
    </style>
</head>
<body onload="window.print()">

<?php foreach($sections as $section): ?>
    <h2><?= assetExportEscape($section['title']) ?></h2>
    <table>
        <tr>
            <?php foreach($section['columns'] as $label): ?>
                <th><?= assetExportEscape($label) ?></th>
            <?php endforeach; ?>
        </tr>
        <?php foreach($section['rows'] as $row): ?>
            <tr>
                <?php foreach(array_keys($section['columns']) as $field): ?>
                    <td><?= assetExportEscape($row[$field] ?? "") ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endforeach; ?>

</body>
</html>
<?php
    $description = "User [$username] printed asset $movementLabel.
IP Address: $ip
Time: $time";

    logActivity($mysqli, $username, $role, "PRINT ASSET REPORT", $description);
    exit();
}
?>
