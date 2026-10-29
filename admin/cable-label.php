<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/cable-access.php';
pwg_private_headers();
$configFile = dirname(__DIR__, 2) . '/pwg-db-config.php';
if (!is_file($configFile)) { http_response_code(500); exit('Configuration error.'); }
$config = require $configFile;
if (!pwg_is_admin($config)) { http_response_code(403); exit('Admin sign-in required.'); }
$serial = is_string($_GET['id'] ?? null) ? strtoupper(trim($_GET['id'])) : '';
if (!preg_match('/\A[A-Z0-9_-]{1,32}\z/D', $serial)) { http_response_code(400); exit('Invalid serial number.'); }
try {
    $pdo = new PDO('mysql:host='.$config['host'].';dbname='.$config['database'].';charset=utf8mb4',
        $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    $st = $pdo->prepare('SELECT serial_number, model_number, public_token FROM pwg_cables WHERE serial_number=? LIMIT 1');
    $st->execute([$serial]);
    $cable = $st->fetch();
} catch (Throwable $e) { http_response_code(500); exit('Database error.'); }
if (!$cable) { http_response_code(404); exit('Cable record not found.'); }
if (empty($cable['public_token'])) { http_response_code(409); exit('Run the token backfill before printing labels.'); }
$url = 'https://pwgquantum.com' . pwg_public_cable_path($cable['public_token']);
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($serial)?> QR Label</title>
<style>
body{font-family:Arial,sans-serif;background:#eee;color:#111}.toolbar{text-align:center;padding:16px}
.label{box-sizing:border-box;width:4in;min-height:2in;background:white;margin:20px auto;padding:.15in;display:flex;align-items:center;gap:.15in}
.meta{flex:1;overflow-wrap:anywhere}.serial{font:bold 20px monospace;margin:12px 0}.model{font:12px monospace}
#qr svg{display:block;width:1.55in;height:1.55in}.url{font:11px monospace;overflow-wrap:anywhere;max-width:600px;margin:auto}
@page{size:4in 2in;margin:0}@media print{body{background:white;margin:0}.toolbar,.url{display:none}.label{margin:0}}
</style></head><body>
<div class="toolbar"><button onclick="window.print()">Print Label</button> <a href="/admin/cables.php">Cable Admin</a></div>
<div class="label"><div class="meta"><strong>PWG QUANTUM</strong><div class="serial"><?=h($serial)?></div><div class="model"><?=h($cable['model_number'])?></div></div><div id="qr" data-url="<?=h($url)?>" aria-label="Cable report QR code"></div></div>
<p class="url"><a href="<?=h($url)?>" rel="noreferrer"><?=h($url)?></a></p>
<script src="/assets/vendor/qrcode.js"></script>
<script>
const element = document.getElementById('qr');
const code = qrcode(0, 'M');
code.addData(element.dataset.url);
code.make();
element.innerHTML = code.createSvgTag(4, 16);
</script></body></html>
