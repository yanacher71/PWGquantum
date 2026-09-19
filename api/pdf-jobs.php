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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$stmt = $pdo->query(
    "SELECT serial_number, model_number, cable_family, length_mm, outer_diameter_mm,
            connector_a, connector_b, manufacture_date, lot_number, status,
            source_doc_id, pdf_file_id, pdf_updated_at
     FROM pwg_cables
     ORDER BY serial_number"
);
echo json_encode(['ok' => true, 'cables' => $stmt->fetchAll()], JSON_UNESCAPED_SLASHES);
