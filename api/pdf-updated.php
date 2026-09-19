<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$configFile = dirname(__DIR__, 2) . '/pwg-db-config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'configuration']);
    exit;
}
$config = require $configFile;

$apiKey = (string)($config['api_key'] ?? '');
$provided = (string)($_SERVER['HTTP_X_PWG_API_KEY'] ?? '');
if ($apiKey === '' || $provided === '' || !hash_equals($apiKey, $provided)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . $config['host'] . ';dbname=' . $config['database'] . ';charset=utf8mb4',
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'database']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$body = json_decode((string)file_get_contents('php://input'), true);
$serial = strtoupper(trim((string)($body['serial_number'] ?? '')));
$pdfId = trim((string)($body['pdf_file_id'] ?? ''));
$sourceDocId = trim((string)($body['source_doc_id'] ?? ''));

if (!preg_match('/^[A-Z0-9_-]{1,32}$/', $serial) ||
    !preg_match('/^[A-Za-z0-9_-]{10,128}$/', $pdfId) ||
    ($sourceDocId !== '' && !preg_match('/^[A-Za-z0-9_-]{10,128}$/', $sourceDocId))) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_input']);
    exit;
}

$documentUrl = 'https://drive.google.com/file/d/' . rawurlencode($pdfId) . '/view?usp=sharing';

$stmt = $pdo->prepare(
    'UPDATE pwg_cables
     SET source_doc_id = COALESCE(NULLIF(?, \'\'), source_doc_id),
         pdf_file_id = ?, document_url = ?, pdf_updated_at = NOW()
     WHERE serial_number = ?'
);
$stmt->execute([$sourceDocId, $pdfId, $documentUrl, $serial]);

if ($stmt->rowCount() < 1) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'cable_not_found']);
    exit;
}

echo json_encode([
    'ok' => true,
    'serial_number' => $serial,
    'source_doc_id' => $sourceDocId,
    'pdf_file_id' => $pdfId,
    'document_url' => $documentUrl
], JSON_UNESCAPED_SLASHES);
