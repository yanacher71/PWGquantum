<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/cable-access.php';
require_once dirname(__DIR__).'/includes/measurements.php';
require_once dirname(__DIR__).'/includes/measurement-drive.php';
pwg_private_headers();
$configFile = dirname(__DIR__, 2).'/pwg-db-config.php';
if (!is_file($configFile)) { http_response_code(500); exit('Configuration error.'); }
$config = require $configFile;
if (!pwg_is_admin($config)) { header('Location: /admin/cables.php', true, 303); exit; }
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];
session_write_close();
$serial = is_string($_GET['id'] ?? null) ? strtoupper(trim($_GET['id'])) : '';
if (!preg_match('/\A[A-Z0-9_-]{1,32}\z/D', $serial)) { http_response_code(400); exit('Invalid serial.'); }
try {
    $pdo = new PDO('mysql:host='.$config['host'].';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $stmt = $pdo->prepare('SELECT serial_number FROM pwg_cables WHERE serial_number=? LIMIT 1');
    $stmt->execute([$serial]);
    if (!$stmt->fetchColumn()) { http_response_code(404); exit('Cable not found.'); }
} catch (Throwable $e) { http_response_code(500); exit('Database error.'); }
$error = ''; $message = '';
$record = pwg_measurement_read($serial);
$folder = (string)($record['metadata']['drive_folder_id'] ?? '');
$calFolder = (string)($record['metadata']['calibration_folder_id'] ?? '');
$conditions = (string)($record['metadata']['conditions'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) { http_response_code(403); exit('Invalid request.'); }
    try {
        $folder = trim((string)($_POST['folder'] ?? ''));
        $calFolder = trim((string)($_POST['calibration_folder'] ?? ''));
        $conditions = trim((string)($_POST['conditions'] ?? ''));
        if ($conditions === '' || strlen($conditions) > 2000) throw new RuntimeException('Describe the measurement conditions in 1–2000 characters.');
        $raw = []; $calibration = [];
        if (($_POST['method'] ?? '') === 'drive') {
            $folder = pwg_drive_folder_id($folder);
            $raw = pwg_drive_measurement_files($folder, ['tdr.txt','s11.s1p','s22.s1p'], $config);
            if (trim($raw['tdr.txt'] ?? '') !== '') {
                $calFolder = pwg_drive_folder_id($calFolder);
                $calibration = pwg_drive_measurement_files($calFolder, ['50ohms.txt','open.txt','short.txt'], $config);
            }
        } else {
            foreach (['tdr'=>'tdr.txt','s11'=>'s11.s1p','s22'=>'s22.s1p','load'=>'50ohms.txt','open'=>'open.txt','short'=>'short.txt'] as $field=>$name) {
                $file = $_FILES[$field] ?? null;
                if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) continue;
                if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 4 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Upload failed or file exceeds 4 MB.');
                $text = file_get_contents($file['tmp_name']);
                if ($text === false) throw new RuntimeException('Could not read uploaded file.');
                if (in_array($field, ['load','open','short'], true)) $calibration[$name] = $text;
                else $raw[$name] = $text;
            }
            $folder = $folder === '' ? '' : pwg_drive_folder_id($folder);
            $calFolder = $calFolder === '' ? '' : pwg_drive_folder_id($calFolder);
        }
        $record = pwg_measurement_build($raw, $calibration, ['drive_folder_id'=>$folder, 'calibration_folder_id'=>$calFolder, 'conditions'=>$conditions]);
        pwg_measurement_save($serial, $record);
        $message = 'Measurements imported and published on this cable’s QR page.';
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Measurements <?=h($serial)?></title><link rel="stylesheet" href="/styles.css">
<style>main{max-width:900px;margin:100px auto;padding:24px}label{display:block;margin:16px 0}input,textarea{display:block;width:100%;padding:10px}fieldset{padding:20px;margin:20px 0}.notice{padding:16px;border:1px solid #ccc}</style></head><body><main>
<a href="/admin/cables.php">Cable Admin</a><h1>Measurements · <?=h($serial)?></h1>
<?php if($message):?><p class="notice" role="status"><?=h($message)?></p><?php endif;?><?php if($error):?><p class="notice" role="alert"><?=h($error)?></p><?php endif;?>
<?php if($record):?><p>Last import <?=h($record['imported_at'])?>. Available plots <?=h(implode(', ', array_keys($record['plots'])))?>.</p><?php endif;?>
<p>Importing replaces the complete published measurement set. Empty files mean not measured. Check the selected files belong to <?=h($serial)?>.</p>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h($csrf)?>">
<label>Measurement conditions<textarea name="conditions" maxlength="2000" required placeholder="Measurement date, cable end assignments, reference plane, cable-only window or connectors included, and calibration applicability"><?=h($conditions)?></textarea></label>
<label>Cable Drive folder ID or URL<input name="folder" value="<?=h($folder)?>"></label>
<label>Calibration Drive folder ID or URL<input name="calibration_folder" value="<?=h($calFolder)?>"></label>
<fieldset><legend>Import from Drive</legend><p>Uses server credentials from pwg-db-config.php. Expected filenames are tdr.txt, s11.s1p and s22.s1p. TDR requires 50ohms.txt, open.txt and short.txt in the calibration folder.</p><button class="btn btn-primary" name="method" value="drive">Import measurements from Drive</button></fieldset>
<fieldset><legend>Upload measurement files</legend>
<?php foreach(['tdr'=>'TDR · tdr.txt','s11'=>'S11 · s11.s1p','s22'=>'S22 · s22.s1p','load'=>'50 Ω reference · 50ohms.txt','open'=>'Open reference · open.txt','short'=>'Short reference · short.txt'] as $field=>$label):?>
<label><?=h($label)?><input type="file" name="<?=h($field)?>"></label><?php endforeach;?>
<p>Maximum 4 MB per file. A TDR import requires all three reference files, acquired with the same setup. The constant calibration averages 0.6–1.8 ns.</p>
<button class="btn btn-primary" name="method" value="upload">Upload and publish measurements</button></fieldset></form>
</main></body></html>
