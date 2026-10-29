<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/cable-access.php';
pwg_private_headers();
$configFile = dirname(__DIR__) . '/pwg-db-config.php';
if (!is_file($configFile)) { http_response_code(500); exit('Configuration error.'); }
$config = require $configFile;
$selector = pwg_access_selector($_GET, $config);
if ($selector === null) { http_response_code(404); exit('Report not found.'); }
try {
    $pdo = new PDO('mysql:host='.$config['host'].';dbname='.$config['database'].';charset=utf8mb4',
        $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
    [$column, $value] = $selector;
    $stmt = $pdo->prepare('SELECT serial_number FROM pwg_cables WHERE '.$column.'=? LIMIT 1');
    $stmt->execute([$value]);
    $serial = $stmt->fetchColumn();
} catch (Throwable $e) { http_response_code(500); exit('Database error.'); }
if (!is_string($serial) || !preg_match('/\A[A-Z0-9_-]{1,32}\z/D', $serial)) {
    http_response_code(404); exit('Report not found.');
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
header('Cache-Control: private, no-store');
if ($size !== false) {
    header('Content-Length: ' . (string)$size);
}
readfile($file);
exit;
