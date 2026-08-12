<?php
require_once __DIR__ . "/fpdf/fpdf.php";

function workflowPdfText($value){
    $value = str_replace(["\r\n", "\r"], "\n", (string)($value ?? ""));
    $converted = @iconv("UTF-8", "windows-1252//TRANSLIT", $value);
    return $converted !== false ? $converted : preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $value);
}

function workflowReportRange($range, $from = "", $to = ""){
    $today = date("Y-m-d");
    $start = $today;
    $end = $today;
    $label = "Today";

    if($range === "7days"){
        $start = date("Y-m-d", strtotime("-6 days"));
        $label = "7 Day Report";
    } elseif($range === "30days"){
        $start = date("Y-m-d", strtotime("-29 days"));
        $label = "30 Day Report";
    } elseif($range === "monthly"){
        $start = date("Y-m-01");
        $label = "Monthly Report";
    } elseif($range === "yearly"){
        $start = date("Y-01-01");
        $label = "Yearly Report";
    } elseif($range === "custom"){
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : $today;
        $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : $today;
        if($start > $end){ $swap = $start; $start = $end; $end = $swap; }
        $label = "Custom Report";
    }

    return ["start" => $start, "end" => $end, "label" => $label];
}

class WorkflowReportPDF extends FPDF {
    function Footer(){
        $this->SetY(-11);
        $this->SetFont('Arial', '', 7.5);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 6, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'C');
        $this->SetTextColor(0, 0, 0);
    }

    function NbLines($width, $text){
        $cw = &$this->CurrentFont['cw'];
        $maxWidth = ($width - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $text = str_replace("\r", '', workflowPdfText($text));
        $length = strlen($text);
        if($length && $text[$length - 1] === "\n"){ $length--; }
        $separator = -1; $i = 0; $lineStart = 0; $lineWidth = 0; $lines = 1;
        while($i < $length){
            $character = $text[$i];
            if($character === "\n"){
                $i++; $separator = -1; $lineStart = $i; $lineWidth = 0; $lines++; continue;
            }
            if($character === ' '){ $separator = $i; }
            $lineWidth += $cw[$character] ?? 0;
            if($lineWidth > $maxWidth){
                if($separator === -1){ if($i === $lineStart){ $i++; } }
                else { $i = $separator + 1; }
                $separator = -1; $lineStart = $i; $lineWidth = 0; $lines++;
            } else { $i++; }
        }
        return $lines;
    }

    function CheckPageBreak($height){
        if($this->GetY() + $height > $this->PageBreakTrigger){ $this->AddPage($this->CurOrientation); }
    }

    function Row($data, $widths, $lineHeight = 4.8, $fill = false){
        $lines = 1;
        foreach($data as $index => $value){ $lines = max($lines, $this->NbLines($widths[$index], (string)$value)); }
        $height = max(7, $lineHeight * $lines);
        $this->CheckPageBreak($height);
        foreach($data as $index => $value){
            $width = $widths[$index]; $x = $this->GetX(); $y = $this->GetY();
            $this->Rect($x, $y, $width, $height, $fill ? 'DF' : 'D');
            $this->MultiCell($width, $lineHeight, workflowPdfText($value), 0, 'L', false);
            $this->SetXY($x + $width, $y);
        }
        $this->Ln($height);
    }

    function RowWithImage($data, $widths, $imageIndex, $imagePath = "", $imageLabel = "-", $lineHeight = 4.6){
        $lines = 1;
        foreach($data as $index => $value){
            if($index === $imageIndex){ continue; }
            $lines = max($lines, $this->NbLines($widths[$index], (string)$value));
        }
        $hasImage = $imagePath !== "" && is_file($imagePath);
        $height = max($hasImage ? 23 : 8, $lineHeight * $lines);
        $this->CheckPageBreak($height);

        foreach($data as $index => $value){
            $width = $widths[$index];
            $x = $this->GetX();
            $y = $this->GetY();
            $this->Rect($x, $y, $width, $height);
            if($index === $imageIndex){
                if($hasImage){
                    $maxWidth = max(4, $width - 4);
                    $maxHeight = max(4, $height - 4);
                    $imageSize = @getimagesize($imagePath);
                    if($imageSize && $imageSize[0] > 0 && $imageSize[1] > 0){
                        $scale = min($maxWidth / $imageSize[0], $maxHeight / $imageSize[1]);
                        $drawWidth = $imageSize[0] * $scale;
                        $drawHeight = $imageSize[1] * $scale;
                        $this->Image($imagePath, $x + ($width - $drawWidth) / 2, $y + ($height - $drawHeight) / 2, $drawWidth, $drawHeight);
                    } else {
                        $this->SetXY($x, $y + 1);
                        $this->MultiCell($width, $lineHeight, workflowPdfText($imageLabel), 0, 'C');
                    }
                } else {
                    $this->SetXY($x, $y + 1);
                    $this->MultiCell($width, $lineHeight, workflowPdfText($imageLabel), 0, 'C');
                }
            } else {
                $this->MultiCell($width, $lineHeight, workflowPdfText($value), 0, 'L', false);
            }
            $this->SetXY($x + $width, $y);
        }
        $this->Ln($height);
    }

    function SectionTitle($title){
        $this->Ln(2);
        $this->SetFillColor(33, 37, 41);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 7, workflowPdfText($title), 0, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);
        $this->Ln(1.5);
    }

    function Paragraph($text){
        $this->SetFont('Arial', '', 8.5);
        $this->MultiCell(0, 5.5, workflowPdfText($text), 0, 'J');
        $this->Ln(1);
    }

    function TableHeader($headers, $widths){
        $this->SetFillColor(255, 193, 7);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'B', 7.2);
        $this->Row($headers, $widths, 4.8, true);
        $this->SetFont('Arial', '', 7.2);
    }
}

function workflowReportStart($title, $introduction, $range, $summary){
    $pdf = new WorkflowReportPDF('L', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 17);
    $pdf->Cell(0, 9, workflowPdfText($title), 0, 1, 'C');
    $pdf->Ln(6);
    $logo = realpath(__DIR__ . "/../image/logo.png");
    if($logo && is_file($logo)){
        $width = 34;
        $pdf->Image($logo, ($pdf->GetPageWidth() - $width) / 2, $pdf->GetY(), $width);
        $pdf->Ln(33);
    } else { $pdf->Ln(10); }
    $pdf->SectionTitle("Introduction");
    $pdf->Paragraph($introduction);
    $pdf->SectionTitle("Report Date Range");
    $pdf->Paragraph("Report type: " . $range['label'] . "\nDate range: " . date('d F Y', strtotime($range['start'])) . " to " . date('d F Y', strtotime($range['end'])) . ". Generated on " . date('d F Y, h:i A') . ".");
    $pdf->SectionTitle("Report Summary");
    $pdf->Paragraph($summary);
    return $pdf;
}
?>
