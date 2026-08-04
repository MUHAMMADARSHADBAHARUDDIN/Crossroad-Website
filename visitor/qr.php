<?php
require_once __DIR__ . '/../includes/security.php';
sendSecurityHeaders();
$scheme = securityIsHttps() ? 'https' : 'http';
$host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
$basePath = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/visitor/qr.php'))), '/');
$formUrl = $scheme . '://' . $host . $basePath . '/visitor/';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Visitor Check-in QR Code</title><style>
body{font-family:Arial,sans-serif;text-align:center;color:#102a43;margin:0;padding:32px 16px}.logo{width:100px;max-height:76px;object-fit:contain}h1{font-size:36px;margin:20px 0 10px}p{color:#526b7f;font-size:19px;margin:0}#qrcode{display:flex;justify-content:center;margin:28px auto}#qrcode img,#qrcode canvas{width:min(420px,85vw)!important;height:min(420px,85vw)!important;max-height:none;image-rendering:pixelated}.print{border:0;border-radius:8px;background:#1677c8;color:white;padding:12px 22px;font-weight:bold;font-size:16px;cursor:pointer}@media print{html,body{width:210mm;height:297mm}body{box-sizing:border-box;padding:18mm 15mm;-webkit-print-color-adjust:exact;print-color-adjust:exact}.logo{width:120px;max-height:90px}h1{font-size:42px}p{font-size:22px}#qrcode{margin:32px auto}#qrcode img,#qrcode canvas{width:125mm!important;height:125mm!important}.print{display:none}}@page{size:A4 portrait;margin:0}
</style></head><body><img class="logo" src="../image/logo.png" alt="Crossroad Solutions"><h1>Visitor Check-in</h1><p>Scan to register your visit</p><div id="qrcode"></div><button class="print" onclick="window.print()">Print QR code</button><script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script><script>new QRCode(document.getElementById('qrcode'),{text:<?= json_encode($formUrl) ?>,width:500,height:500,correctLevel:QRCode.CorrectLevel.H});</script></body></html>
