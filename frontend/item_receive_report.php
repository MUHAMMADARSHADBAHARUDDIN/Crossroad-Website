<?php
session_start();
require_once "../includes/db_connect.php";
require_once "../includes/permissions.php";
require_once "../includes/receiving_schema.php";
require_once "../includes/workflow_report_pdf.php";
if(!isset($_SESSION['username']) || !hasPermission($mysqli, "receiving_view")){ die("Access denied"); }
ensureReceivingSchema($mysqli);
$range = workflowReportRange($_GET['range'] ?? 'today', $_GET['from'] ?? '', $_GET['to'] ?? '');
$stmt = $mysqli->prepare("SELECT * FROM receiving_records WHERE received_date BETWEEN ? AND ? ORDER BY received_date, id");
$stmt->bind_param("ss", $range['start'], $range['end']);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
$totalQuantity = 0;
while($row = $result->fetch_assoc()){ $rows[] = $row; $totalQuantity += max(1, (int)$row['quantity']); }
$pdf = workflowReportStart(
    "Item Receive Report",
    "This report summarizes items received during the selected reporting period. It records who received each item, its identification details, quantity, rack location, and remarks before manual inventory processing.",
    $range,
    "Total receiving records: " . count($rows) . "\nTotal item quantity: " . $totalQuantity
);
$pdf->AddPage();
$pdf->SectionTitle("Item Receive Table");
$widths = [21, 26, 37, 25, 46, 10, 23, 28, 51];
$pdf->TableHeader(["Date", "Received By", "Item", "Part No.", "Serial / Asset ID", "Qty", "Rack", "Picture", "Remark"], $widths);
foreach($rows as $row){
    $attachmentPath = "";
    $attachmentLabel = "-";
    $storedAttachment = basename((string)($row['attachment_file_name'] ?? ''));
    $originalAttachment = trim((string)($row['attachment_original_name'] ?? ''));
    $attachmentMime = strtolower(trim((string)($row['attachment_mime'] ?? '')));
    if($storedAttachment !== ""){
        $candidatePath = __DIR__ . "/../uploads/item_receive/" . $storedAttachment;
        $extension = strtolower(pathinfo($storedAttachment, PATHINFO_EXTENSION));
        if(is_file($candidatePath) && in_array($extension, ['png', 'jpg', 'jpeg'], true) && in_array($attachmentMime, ['image/png', 'image/jpeg'], true)){
            $attachmentPath = $candidatePath;
        } else {
            $attachmentLabel = $originalAttachment !== "" ? $originalAttachment : "Attached file";
        }
    }
    $pdf->RowWithImage([
        date('d M Y', strtotime($row['received_date'])),
        $row['received_by'],
        $row['item_name'] . ($row['item_type'] ? "\n" . $row['item_type'] : ""),
        $row['part_number'] ?: '-',
        $row['serial_number'] ?: '-',
        (string)max(1, (int)$row['quantity']),
        $row['rack_location'],
        "",
        $row['remark'] ?: '-'
    ], $widths, 7, $attachmentPath, $attachmentLabel);
}
while(ob_get_level() > 0){ ob_end_clean(); }
header("Content-Type: application/pdf");
header('Content-Disposition: inline; filename="item_receive_report.pdf"');
$pdf->Output('I', 'item_receive_report.pdf');
exit;
?>
