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

$format = $_GET['format'] ?? 'excel';

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? 'Unknown';

$ip = $_SERVER['REMOTE_ADDR'];
$time = date("Y-m-d H:i:s");

$server = $mysqli->query("SELECT * FROM server_inventory");
$stock = $mysqli->query("SELECT * FROM server_stockout_history");

/* =======================
   EXCEL
======================= */
if($format === "excel"){

    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=servers_report.xls");

    echo "
    <table border='1'>
        <tr style='background-color:#4CAF50; color:white;'>
            <th colspan='6'>SERVER INVENTORY</th>
        </tr>
        <tr style='background-color:#d9ead3;'>
            <th>Server Name</th>
            <th>Brand</th>
            <th>Machine Type</th>
            <th>Serial Number</th>
            <th>Status</th>
            <th>Tester</th>
        </tr>
    ";

    while($s = $server->fetch_assoc()){
        echo "
        <tr>
            <td>{$s['server_name']}</td>
            <td>{$s['brand']}</td>
            <td>{$s['machine_type']}</td>
            <td>{$s['serial_number']}</td>
            <td>{$s['status']}</td>
            <td>{$s['tester']}</td>
        </tr>
        ";
    }

    echo "</table><br><br>";

    echo "
    <table border='1'>
        <tr style='background-color:#c00000; color:white;'>
            <th colspan='6'>SERVER STOCK OUT HISTORY</th>
        </tr>
        <tr style='background-color:#f4cccc;'>
            <th>Server Name</th>
            <th>Machine Type</th>
            <th>Serial</th>
            <th>Status</th>
            <th>Remark</th>
            <th>Date</th>
        </tr>
    ";

    while($s = $stock->fetch_assoc()){
        echo "
        <tr>
            <td>{$s['server_name']}</td>
            <td>{$s['machine_type']}</td>
            <td>{$s['serial_number']}</td>
            <td>{$s['status']}</td>
            <td>{$s['remark']}</td>
            <td>{$s['stock_out_date']}</td>
        </tr>
        ";
    }

    echo "</table>";

    $description = "User [$username] exported server report (EXCEL).
IP Address: $ip
Time: $time";

    logActivity($mysqli, $username, $role, "EXPORT SERVER EXCEL", $description);
    exit();
}

/* =======================
   PDF
======================= */
if($format === "pdf"){

    require('../includes/fpdf/fpdf.php');

    class PDF extends FPDF {
        function Row($data, $widths){
            $nb = 0;
            for($i=0;$i<count($data);$i++){
                $nb = max($nb, $this->NbLines($widths[$i], $data[$i]));
            }

            $h = 6 * $nb;
            $this->CheckPageBreak($h);

            for($i=0;$i<count($data);$i++){
                $w = $widths[$i];
                $x = $this->GetX();
                $y = $this->GetY();

                $this->Rect($x, $y, $w, $h);
                $this->MultiCell($w, 6, $data[$i], 0);
                $this->SetXY($x + $w, $y);
            }

            $this->Ln($h);
        }

        function CheckPageBreak($h){
            if($this->GetY() + $h > $this->PageBreakTrigger){
                $this->AddPage();
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

                $l += $cw[$c];

                if($l > $wmax){
                    if($sep == -1){
                        if($i == $j){
                            $i++;
                        }
                    } else {
                        $i = $sep + 1;
                    }

                    $sep = -1;
                    $j = $i;
                    $l = 0;
                    $nl++;
                } else {
                    $i++;
                }
            }

            return $nl;
        }
    }

    $pdf = new PDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial','B',12);

    $pdf->Cell(0,10,'SERVER INVENTORY',0,1,'C');

    $pdf->SetFont('Arial','B',9);
    $widths = [35, 30, 35, 40, 25, 25];

    $pdf->Row(['Name', 'Brand', 'Type', 'Serial', 'Status', 'Tester'], $widths);

    $pdf->SetFont('Arial','',8);

    while($s = $server->fetch_assoc()){
        $pdf->Row([
            $s['server_name'],
            $s['brand'],
            $s['machine_type'],
            $s['serial_number'],
            $s['status'],
            $s['tester']
        ], $widths);
    }

    $pdf->Ln(10);

    $pdf->SetFont('Arial','B',12);
    $pdf->Cell(0,10,'SERVER STOCK OUT HISTORY',0,1,'C');

    $pdf->SetFont('Arial','B',9);
    $widths2 = [35, 35, 40, 25, 30, 25];

    $pdf->Row(['Name', 'Type', 'Serial', 'Status', 'Remark', 'Date'], $widths2);

    $pdf->SetFont('Arial','',8);

    while($s = $stock->fetch_assoc()){
        $pdf->Row([
            $s['server_name'],
            $s['machine_type'],
            $s['serial_number'],
            $s['status'],
            $s['remark'],
            $s['stock_out_date']
        ], $widths2);
    }

    $pdf->Output();

    $description = "User [$username] exported server report (PDF).
IP Address: $ip
Time: $time";

    logActivity($mysqli, $username, $role, "EXPORT SERVER PDF", $description);
    exit();
}

/* =======================
   PRINT
======================= */
if($format === "print"){
?>
<html>
<head>
    <title>Server Report</title>
    <style>
        body { font-family: Arial; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        th, td {
            border: 1px solid black;
            padding: 8px;
        }
        h2 {
            text-align: center;
        }
        @media print {
            @page { size: A4; margin: 20mm; }
        }
    </style>
</head>
<body onload="window.print()">

<h2>SERVER INVENTORY</h2>

<table>
<tr>
    <th>Name</th>
    <th>Brand</th>
    <th>Type</th>
    <th>Serial</th>
    <th>Status</th>
    <th>Tester</th>
</tr>

<?php while($s = $server->fetch_assoc()){ ?>
<tr>
    <td><?= $s['server_name'] ?></td>
    <td><?= $s['brand'] ?></td>
    <td><?= $s['machine_type'] ?></td>
    <td><?= $s['serial_number'] ?></td>
    <td><?= $s['status'] ?></td>
    <td><?= $s['tester'] ?></td>
</tr>
<?php } ?>
</table>

<h2>SERVER STOCK OUT HISTORY</h2>

<table>
<tr>
    <th>Name</th>
    <th>Type</th>
    <th>Serial</th>
    <th>Status</th>
    <th>Remark</th>
    <th>Date</th>
</tr>

<?php while($s = $stock->fetch_assoc()){ ?>
<tr>
    <td><?= $s['server_name'] ?></td>
    <td><?= $s['machine_type'] ?></td>
    <td><?= $s['serial_number'] ?></td>
    <td><?= $s['status'] ?></td>
    <td><?= $s['remark'] ?></td>
    <td><?= $s['stock_out_date'] ?></td>
</tr>
<?php } ?>
</table>

</body>
</html>
<?php

$description = "User [$username] printed server report.
IP Address: $ip
Time: $time";

logActivity($mysqli, $username, $role, "PRINT SERVER REPORT", $description);
exit();
}
?>