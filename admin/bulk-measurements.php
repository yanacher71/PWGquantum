<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/cable-access.php';
require_once dirname(__DIR__).'/includes/measurements.php';
require_once dirname(__DIR__).'/includes/measurement-drive.php';
require_once dirname(__DIR__).'/includes/measurement-bulk.php';
pwg_private_headers();
$configFile = dirname(__DIR__, 2).'/pwg-db-config.php';
if (!is_file($configFile)) { http_response_code(500); exit('Configuration error.'); }
$config = require $configFile;
if (!pwg_is_admin($config)) { header('Location: /admin/cables.php', true, 303); exit; }
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];
session_write_close();

function bulk_json(array $value, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) bulk_json(['error'=>'Invalid request. Reload the page and sign in again.'], 403);
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'scan') {
            $parent = pwg_drive_folder_id(trim((string)($_POST['parent'] ?? '')));
            $entries = pwg_drive_folder_entries($parent, $config);
            $calFolder = trim((string)($_POST['calibration'] ?? ''));
            if ($calFolder === '') {
                $matches = array_values(array_filter($entries, static fn($entry) => ($entry['mimeType'] ?? '') === 'application/vnd.google-apps.folder' && strtolower($entry['name']) === 'calibration'));
                if (count($matches) > 1) throw new RuntimeException('Multiple calibration folders found. Enter the intended calibration folder URL.');
                $calFolder = $matches[0]['id'] ?? '';
            }
            $calibration = [];
            if ($calFolder !== '') {
                $calFolder = pwg_drive_folder_id($calFolder);
                $calibration = pwg_drive_measurement_files($calFolder, ['50ohms.txt','open.txt','short.txt'], $config);
            }
            $pdo = new PDO('mysql:host='.$config['host'].';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $serials = $pdo->query('SELECT serial_number FROM pwg_cables')->fetchAll(PDO::FETCH_COLUMN);
            $rows = pwg_bulk_plan($entries, $serials);
            $jobId = bin2hex(random_bytes(16));
            session_start();
            $_SESSION['measurement_bulk'] = ['id'=>$jobId, 'expires'=>time()+3600, 'parent'=>$parent, 'calibration_folder'=>$calFolder, 'calibration'=>$calibration, 'rows'=>$rows];
            session_write_close();
            bulk_json(['job'=>$jobId, 'rows'=>$rows]);
        }
        if ($action !== 'import') bulk_json(['error'=>'Unknown action.'], 400);
        session_start();
        $job = $_SESSION['measurement_bulk'] ?? null;
        $index = filter_var($_POST['index'] ?? null, FILTER_VALIDATE_INT);
        if (!$job || !is_string($_POST['job'] ?? null) || !hash_equals($job['id'], $_POST['job']) || $job['expires'] < time()) {
            session_write_close();
            bulk_json(['error'=>'Import session expired or replaced. Start a new scan.'], 409);
        }
        if ($index === false || $index < 0 || !isset($job['rows'][$index])) {
            session_write_close();
            bulk_json(['error'=>'Invalid cable selection.'], 400);
        }
        $row = $job['rows'][$index];
        if ($row['status'] !== 'queued') {
            session_write_close();
            bulk_json(['row'=>$row]);
        }
        // The session lock serializes retries and concurrent requests for this admin session.
        try {
            $pdo = new PDO('mysql:host='.$config['host'].';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $stmt = $pdo->prepare('SELECT serial_number FROM pwg_cables WHERE serial_number=? LIMIT 1');
            $stmt->execute([$row['serial']]);
            if (!$stmt->fetchColumn()) throw new RuntimeException('Cable record no longer exists.');
            $files = pwg_drive_measurement_files($row['folder'], ['tdr.txt','s11.s1p','s22.s1p','measurement_conditions.txt'], $config);
            $record = pwg_bulk_record($files, $job['calibration'], ['drive_folder_id'=>$row['folder'], 'calibration_folder_id'=>$job['calibration_folder']]);
            $existing = pwg_measurement_read($row['serial']);
            if ($record === null) {
                $row['status'] = 'skipped';
                $row['message'] = 'No nonempty measurements.';
            } elseif ($existing && pwg_bulk_unchanged($existing, $record)) {
                $row['status'] = 'skipped';
                $row['message'] = 'Measurements and conditions are unchanged.';
            } else {
                pwg_measurement_save($row['serial'], $record);
                $row['status'] = 'imported';
                $row['message'] = 'Published '.strtoupper(implode(', ', array_keys($record['plots']))).'.';
            }
        } catch (Throwable $e) {
            $row['status'] = 'failed';
            $row['message'] = $e instanceof PDOException ? 'Database error.' : $e->getMessage();
        }
        $_SESSION['measurement_bulk']['rows'][$index] = $row;
        $_SESSION['measurement_bulk']['expires'] = time()+3600;
        session_write_close();
        bulk_json(['row'=>$row]);
    } catch (Throwable $e) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        bulk_json(['error'=>$e instanceof PDOException ? 'Database error.' : $e->getMessage()], 400);
    }
}
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bulk measurement import</title><link rel="stylesheet" href="/styles.css">
<style>main{max-width:1050px;margin:100px auto;padding:24px}label{display:block;margin:18px 0}input{display:block;width:100%;padding:10px}table{width:100%;border-collapse:collapse;margin-top:20px}td,th{text-align:left;padding:10px;border-bottom:1px solid #ddd;overflow-wrap:anywhere}.actions{display:flex;gap:12px;flex-wrap:wrap}button:disabled{opacity:.5}#summary{margin:20px 0}.actions [hidden]{display:none!important}</style></head><body><main>
<a href="/admin/cables.php">Cable Admin</a><h1>Bulk measurement import</h1>
<p>Choose the parent folder containing the cable folders named by serial number. Each successful import publishes the available measurements and replaces that cable’s previous set and conditions. Empty folders and unchanged measurements are skipped. Failed imports retain existing published measurements.</p>
<form id="bulk-form"><input type="hidden" name="csrf" value="<?=h($csrf)?>">
<label>Parent Drive folder URL or ID<input name="parent" required placeholder="CABLETESTS folder"></label>
<label>Calibration Drive folder URL or ID (optional)<input name="calibration" placeholder="Automatically uses the calibration folder inside the parent"></label>
<p>Each measured cable folder needs measurement_conditions.txt. TDR also requires 50ohms.txt, open.txt and short.txt in the shared calibration folder.</p>
<div class="actions"><button class="btn btn-primary" id="start" type="submit">Import all measurements from Drive</button><button class="btn btn-secondary" id="pause" type="button" disabled>Pause after current cable</button><button class="btn btn-secondary" id="resume" type="button" hidden>Resume import</button></div></form>
<p id="summary" role="status" aria-live="polite"></p>
<table><thead><tr><th>Cable folder</th><th>Result</th><th>Details</th></tr></thead><tbody id="results"></tbody></table>
<noscript>Enable JavaScript to run the bulk importer.</noscript>
</main><script src="/assets/bulk-measurements.js?v=<?=h((string)filemtime(dirname(__DIR__).'/assets/bulk-measurements.js'))?>" defer></script></body></html>
