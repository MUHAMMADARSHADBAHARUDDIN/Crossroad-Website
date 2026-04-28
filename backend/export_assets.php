<?php
session_start();

require_once "../includes/db_connect.php";
require_once "../includes/activity_log.php";
require_once "../includes/permissions.php";

/* ✅ SESSION CHECK */
if(!isset($_SESSION['username'])){
    die("No session");
}

/* ✅ EXPORT PERMISSION CHECK */
if(!hasPermission($mysqli, "inventory_export")){
    die("Access denied");
}

$format = $_GET['format'] ?? 'excel';

$username = $_SESSION['username'];
$role = $_SESSION['role'] ?? 'Unknown';

$ip = $_SERVER['REMOTE_ADDR'];
$time = date("Y-m-d H:i:s");

/* ✅ PREPARE DESCRIPTION (dynamic later) */

// GET DATA
$asset = $mysqli->query("SELECT * FROM asset_inventory");
$stock = $mysqli->query("SELECT * FROM stock_out_history");

// SIMPLE CSV (Excel OPENABLE)
if($format === "excel"){

    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=assets_report.xls");

    echo "
    <table border='1'>
        <tr style='background-color:#4CAF50; color:white;'>
            <th colspan='6'>ASSET INVENTORY</th>
        </tr>
        <tr style='background-color:#d9ead3;'>
            <th>Part Number</th>
            <th>Serial Number</th>
            <th>Brand</th>
            <th>Location</th>
            <th>Quantity</th>
        </tr>
    ";

    while($a = $asset->fetch_assoc()){
        echo "
        <tr>
            <td>{$a['part_number']}</td>
            <td>{$a['serial_number']}</td>
            <td>{$a['brand']}</td>
            <td>{$a['location']}</td>
            <td>{$a['quantity']}</td>
        </tr>
        ";
    }

    echo "</table><br><br>";

    // STOCK OUT TABLE
    echo "
    <table border='1'>
        <tr style='background-color:#c00000; color:white;'>
            <th colspan='5'>STOCK OUT HISTORY</th>
        </tr>
        <tr style='background-color:#f4cccc;'>
            <th>Part Number</th>
            <th>Serial</th>
            <th>Location</th>
            <th>Remark</th>
            <th>Date</th>
        </tr>
    ";

    while($s = $stock->fetch_assoc()){
        echo "
        <tr>
            <td>{$s['part_number']}</td>
            <td>{$s['serial_number']}</td>
            <td>{$s['location']}</td>
            <td>{$s['remark']}</td>
            <td>{$s['stock_out_date']}</td>
        </tr>
        ";
    }

    echo "</table>";

  $description = "User [$username] exported asset report (EXCEL).
  IP Address: $ip
  Time: $time";

  logActivity($mysqli, $username, $role, "EXPORT EXCEL", $description);

  exit();
}
// =======================
// PDF FIX (NO OVERLAP)
// =======================
if($format === "pdf"){

    require('../includes/fpdf/fpdf.php');

    class PDF extends FPDF {
        function Row($data, $widths){
            $nb = 0;
            for($i=0;$i<count($data);$i++){
                $nb = max($nb, $this->NbLines($widths[$i],$data[$i]));
            }
            $h = 6 * $nb;

            $this->CheckPageBreak($h);

            for($i=0;$i<count($data);$i++){
                $w = $widths[$i];
                $x = $this->GetX();
                $y = $this->GetY();

                $this->Rect($x,$y,$w,$h);
                $this->MultiCell($w,6,$data[$i],0);
                $this->SetXY($x+$w,$y);
            }
            $this->Ln($h);
        }

        function CheckPageBreak($h){
            if($this->GetY()+$h>$this->PageBreakTrigger)
                $this->AddPage();
        }

        function NbLines($w,$txt){
            $cw = &$this->CurrentFont['cw'];
            if($w==0)
                $w = $this->w-$this->rMargin-$this->x;
            $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
            $s = str_replace("\r",'',$txt);
            $nb = strlen($s);
            if($nb>0 and $s[$nb-1]=="\n")
                $nb--;
            $sep = -1;
            $i = 0;
            $j = 0;
            $l = 0;
            $nl = 1;
            while($i<$nb){
                $c = $s[$i];
                if($c=="\n"){
                    $i++;
                    $sep = -1;
                    $j = $i;
                    $l = 0;
                    $nl++;
                    continue;
                }
                if($c==' ')
                    $sep = $i;
                $l += $cw[$c];
                if($l>$wmax){
                    if($sep==-1){
                        if($i==$j)
                            $i++;
                    } else
                        $i = $sep+1;
                    $sep = -1;
                    $j = $i;
                    $l = 0;
                    $nl++;
                } else
                    $i++;
            }
            return $nl;
        }
    }

    $pdf = new PDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial','B',12);

    $pdf->Cell(0,10,'ASSET INVENTORY',0,1,'C');

    $pdf->SetFont('Arial','B',9);
    $widths = [25,35,25,25,40,15];

    $pdf->Row(['Part No','Serial','Brand','Location','Qty'], $widths);

    $pdf->SetFont('Arial','',8);

    while($a = $asset->fetch_assoc()){
        $pdf->Row([
            $a['part_number'],
            $a['serial_number'],
            $a['brand'],
            $a['location'],
            $a['quantity']
        ], $widths);
    }

    $pdf->Ln(10);

    $pdf->SetFont('Arial','B',12);
    $pdf->Cell(0,10,'STOCK OUT HISTORY',0,1,'C');

    $pdf->SetFont('Arial','B',9);
    $widths2 = [25,35,40,45,35];

    $pdf->Row(['Part No','Serial','Location','Remark','Date'], $widths2);

    $pdf->SetFont('Arial','',8);

    while($s = $stock->fetch_assoc()){
        $pdf->Row([
            $s['part_number'],
            $s['serial_number'],
            $s['location'],
            $s['remark'],
            $s['stock_out_date']
        ], $widths2);
    }

    $pdf->Output();

    $description = "User [$username] exported asset report (PDF).
    IP Address: $ip
    Time: $time";

    logActivity($mysqli, $username, $role, "EXPORT PDF", $description);

    exit();
}

// =======================
// PRINT FIX (NO OVERLAP)
// =======================

if($format === "print"){
?>
<html>
<head>
    <title>Print Report</title>
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
            text-align: left;
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

<h2>ASSET INVENTORY</h2>
<table>
<tr>
    <th>Part Number</th>
    <th>Serial</th>
    <th>Brand</th>
    <th>Location</th>
    <th>Qty</th>
</tr>

<?php while($a = $asset->fetch_assoc()){ ?>
<tr>
    <td><?= $a['part_number'] ?></td>
    <td><?= $a['serial_number'] ?></td>
    <td><?= $a['brand'] ?></td>
    <td><?= $a['location'] ?></td>
    <td><?= $a['quantity'] ?></td>
</tr>
<?php } ?>
</table>

<h2>STOCK OUT HISTORY</h2>
<table>
<tr>
    <th>Part Number</th>
    <th>Serial</th>
    <th>Location</th>
    <th>Remark</th>
    <th>Date</th>
</tr>

<?php while($s = $stock->fetch_assoc()){ ?>
<tr>
    <td><?= $s['part_number'] ?></td>
    <td><?= $s['serial_number'] ?></td>
    <td><?= $s['location'] ?></td>
    <td><?= $s['remark'] ?></td>
    <td><?= $s['stock_out_date'] ?></td>
</tr>
<?php } ?>

</table>

</body>
</html>
<?php
$description = "User [$username] printed asset report.
IP Address: $ip
Time: $time";

logActivity($mysqli, $username, $role, "PRINT REPORT", $description);

exit();
}
?>