<?php
declare(strict_types=1);
require_once __DIR__.'/includes/cable-access.php';
require_once __DIR__.'/includes/measurements.php';
pwg_private_headers();
$configFile = dirname(__DIR__).'/pwg-db-config.php';
if (!is_file($configFile)) { http_response_code(500); exit('Configuration error.'); }
$config = require $configFile;
$selector = pwg_access_selector($_GET, $config);
if ($selector === null) { http_response_code(404); exit('Measurement not found.'); }
try {
    $pdo = new PDO('mysql:host='.$config['host'].';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    [$column, $value] = $selector;
    $stmt = $pdo->prepare('SELECT serial_number FROM pwg_cables WHERE '.$column.'=? LIMIT 1');
    $stmt->execute([$value]);
    $serial = $stmt->fetchColumn();
} catch (Throwable $e) { http_response_code(500); exit('Database error.'); }
$name = $_GET['file'] ?? null;
if (!is_string($serial) || !is_string($name) || !in_array($name, ['tdr.txt','s11.s1p','s22.s1p'], true)) { http_response_code(404); exit('Measurement not found.'); }
$record = pwg_measurement_read($serial);
if (empty($record['raw'][$name])) { http_response_code(404); exit('Measurement not found.'); }
header('Content-Type: text/plain; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$serial.'-'.$name.'"');
header('X-Content-Type-Options: nosniff');
echo $record['raw'][$name];
