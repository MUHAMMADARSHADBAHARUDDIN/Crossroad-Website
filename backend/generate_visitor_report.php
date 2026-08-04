<?php
ob_start();
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/permissions.php';
require_once '../includes/visitor_schema.php';
require_once '../includes/fpdf/fpdf.php';

if(!isset($_SESSION['username'])){ http_response_code(401); die('No session.'); }
if(!hasPermission($mysqli, 'visitor_report')){ http_response_code(403); die('Access denied.'); }
ensureVisitorSchema($mysqli);

function visitorReportDate($value){
    $value = trim((string)$value);
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)){ return null; }
    $parts = explode('-', $value);
    return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]) ? $value : null;
}
function visitorPdfText($value){
    $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT', (string)$value);
    return $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', '', (string)$value);
}
$period = strtolower(trim((string)($_GET['period'] ?? 'today')));
$today = date('Y-m-d');
switch($period){
    case '7day': $start = date('Y-m-d', strtotime('-6 days')); $end = $today; $label = 'Last 7 Days'; break;
    case '30day': $start = date('Y-m-d', strtotime('-29 days')); $end = $today; $label = 'Last 30 Days'; break;
    case 'monthly': $start = date('Y-m-01'); $end = date('Y-m-t'); $label = date('F Y'); break;
    case 'yearly': $start = date('Y-01-01'); $end = date('Y-12-31'); $label = date('Y'); break;
    case 'custom':
        $start = visitorReportDate($_GET['start_date'] ?? '');
        $end = visitorReportDate($_GET['end_date'] ?? '');
        if($start === null || $end === null || $start > $end){ die('Please choose a valid custom date range.'); }
        $label = date('d M Y', strtotime($start)) . ' - ' . date('d M Y', strtotime($end));
        break;
    case 'today': default: $start = $today; $end = $today; $label = 'Today'; break;
}

$startTime = $start . ' 00:00:00';
$endTime = $end . ' 23:59:59';
$stmt = $mysqli->prepare('SELECT name, phone, unit_number, company, person_to_meet, purpose, visit_time FROM visitors WHERE visit_time BETWEEN ? AND ? ORDER BY visit_time ASC, id ASC');
$stmt->bind_param('ss', $startTime, $endTime);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while($row = $result->fetch_assoc()){ $rows[] = $row; }

class VisitorReportPdf extends FPDF {
    public $periodLabel = '';
    public $totalRows = 0;
    function Header(){
        $logo = __DIR__ . '/../image/logo.png';
        if(file_exists($logo)){ $this->Image($logo, 10, 8, 16); }
        $this->SetFont('Arial', 'B', 16); $this->Cell(0, 7, 'Crossroad Solutions Sdn Bhd', 0, 1, 'C');
        $this->SetFont('Arial', 'B', 12); $this->Cell(0, 7, 'Visitor Report', 0, 1, 'C');
        $this->SetFont('Arial', '', 9); $this->Cell(0, 6, visitorPdfText($this->periodLabel . ' | ' . $this->totalRows . ' visitor(s)'), 0, 1, 'C');
        $this->Ln(3); $this->tableHeader();
    }
    function tableHeader(){
        $headers = [['No.',10],['Name',36],['Phone',27],['Unit',13],['Company',39],['Person to Meet',39],['Purpose',61],['Time',52]];
        $this->SetFillColor(26, 42, 67); $this->SetTextColor(255); $this->SetFont('Arial','B',8);
        foreach($headers as $item){ $this->Cell($item[1], 8, $item[0], 1, 0, 'C', true); }
        $this->Ln(); $this->SetTextColor(0);
    }
    function Footer(){ $this->SetY(-12); $this->SetFont('Arial','I',8); $this->SetTextColor(100); $this->Cell(0,5,'Generated ' . date('d M Y, h:i A') . ' | Page ' . $this->PageNo(),0,0,'C'); }
}

$pdf = new VisitorReportPdf('L', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10); $pdf->SetAutoPageBreak(true, 16); $pdf->periodLabel = $label; $pdf->totalRows = count($rows); $pdf->AddPage();
$widths = [10,36,27,13,39,39,61,52];
if(empty($rows)){
    $pdf->SetFont('Arial','I',10); $pdf->Cell(array_sum($widths), 14, 'No visitor records found for this period.', 1, 1, 'C');
} else {
    $pdf->SetFont('Arial','',7.5);
    foreach($rows as $index => $row){
        if($pdf->GetY() > 190){ $pdf->AddPage(); $pdf->SetFont('Arial','',7.5); }
        $values = [$index + 1, $row['name'], $row['phone'], $row['unit_number'], $row['company'], $row['person_to_meet'], $row['purpose'], date('d M Y h:i A', strtotime($row['visit_time']))];
        foreach($values as $i => $value){
            $text = preg_replace('/\s+/', ' ', trim(visitorPdfText($value)));
            $original = $text;
            while(strlen($text) > 2 && $pdf->GetStringWidth($text) > $widths[$i] - 3){ $text = substr($text, 0, -1); }
            if($text !== $original){ $text = rtrim($text) . '...'; }
            $pdf->Cell($widths[$i], 8, $text, 1, 0, $i === 0 || $i === 3 ? 'C' : 'L');
        }
        $pdf->Ln();
    }
}
ob_end_clean();
$filename = 'visitor-report-' . $start . '-to-' . $end . '.pdf';
$pdf->Output('I', $filename);
exit;
