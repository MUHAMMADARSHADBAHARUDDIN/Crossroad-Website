<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/receiving_schema.php";
require_once "../includes/workflow_report_pdf.php";
if(!isset($_SESSION['username']) || !hasPermission($mysqli, "part_request_view")){ die("Access denied"); }
ensurePartRequestSchema($mysqli);
$range = workflowReportRange($_GET['range'] ?? 'today', $_GET['from'] ?? '', $_GET['to'] ?? '');
$stmt = $mysqli->prepare("SELECT pr.*, (SELECT COUNT(*) FROM part_request_items pri WHERE pri.part_request_id=pr.id) AS item_count FROM part_requests pr WHERE request_date BETWEEN ? AND ? ORDER BY request_date, id");
$stmt->bind_param("ss", $range['start'], $range['end']);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
$sent = 0;
$failed = 0;
while($row = $result->fetch_assoc()){
    $rows[] = $row;
    if($row['email_status'] === 'Sent'){ $sent++; } elseif($row['email_status'] === 'Failed'){ $failed++; }
}
$pdf = workflowReportStart(
    "Part Request Report",
    "This report summarizes part requests created during the selected reporting period. It includes the request purpose, number of different items, delivery status, and requestor for review and follow-up.",
    $range,
    "Total part requests: " . count($rows) . "\nEmails sent: " . $sent . "\nEmails failed: " . $failed
);
$pdf->AddPage();
$pdf->SectionTitle("Part Request Table");
$widths = [28, 26, 102, 22, 45, 46];
$pdf->TableHeader(["Request ID", "Date", "Purpose", "Items", "Email Status", "Requested By"], $widths);
foreach($rows as $row){
    $pdf->Row([
        $row['request_id'],
        date('d M Y', strtotime($row['request_date'])),
        $row['purpose'],
        (string)max(1, (int)$row['item_count']),
        "To: fazdlan@crossroad.my\nCC: support@crossroad.my\n" . $row['email_status'],
        $row['requested_by']
    ], $widths);
}
while(ob_get_level() > 0){ ob_end_clean(); }
header("Content-Type: application/pdf");
header('Content-Disposition: inline; filename="part_request_report.pdf"');
$pdf->Output('I', 'part_request_report.pdf');
exit;
?>
