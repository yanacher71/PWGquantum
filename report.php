<?php
declare(strict_types=1);

$serial = strtoupper(trim((string)($_GET['id'] ?? '')));
if (!preg_match('/^[A-Z0-9_-]{1,32}$/', $serial)) {
    http_response_code(404);
    exit('Report not found.');
}

$file = dirname(__DIR__) . '/pwg-reports/' . $serial . '.pdf';
if (!is_file($file) || !is_readable($file)) {
    http_response_code(404);
    exit('Report not found.');
}

$size = filesize($file);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $serial . '.pdf"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
if ($size !== false) {
    header('Content-Length: ' . (string)$size);
}
readfile($file);
exit;
