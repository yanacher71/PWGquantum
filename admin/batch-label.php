<?php
declare(strict_types=1);
session_start();

$configFile = dirname(__DIR__, 2) . '/pwg-db-config.php';
if (!is_file($configFile)) { http_response_code(500); exit('Configuration error.'); }
$config = require $configFile;

if (empty($_SESSION['pwg_admin'])) { http_response_code(403); exit('Admin sign-in required.'); }

$batchNumber = strtoupper(trim((string)($_GET['batch'] ?? '')));
if ($batchNumber === '' || !preg_match('/^[A-Z0-9_-]{1,32}$/', $batchNumber)) {
    http_response_code(400); exit('Invalid batch number.');
}

try {
    $pdo = new PDO(
        'mysql:host='.$config['host'].';dbname='.$config['database'].';charset=utf8mb4',
        $config['username'],$config['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $st=$pdo->prepare('SELECT batch_number,cable_family,manufacture_date,remaining_length_mm,dielectric_od_mm,outer_conductor FROM pwg_batches WHERE batch_number=? LIMIT 1');
    $st->execute([$batchNumber]);
    $batch=$st->fetch();
} catch(Throwable $e) { http_response_code(500); exit('Database error.'); }

if(!$batch){ http_response_code(404); exit('Batch record not found.'); }

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$batchUrl = $scheme.'://'.$host.'/batch.php?batch='.rawurlencode($batch['batch_number']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($batch['batch_number'])?> Label | PWG</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eee;font-family:Arial,Helvetica,sans-serif;color:#111}
.toolbar{padding:16px;text-align:center}.label{width:4in;min-height:2in;margin:20px auto;background:#fff;border:1px solid #bbb;padding:.14in;display:grid;grid-template-columns:1fr 1.32in;gap:.12in;align-items:center}
.brand{font-size:13px;font-weight:700;letter-spacing:.04em}.sub{font-size:9px;margin-bottom:8px}.batch{font-size:20px;font-weight:700;font-family:monospace;margin:4px 0 8px}
.meta{font-size:10px;line-height:1.45}.qr-wrap{text-align:center}.qr-wrap img{width:1.18in;height:1.18in;image-rendering:pixelated}.qr-caption{font:8px monospace;margin-top:4px;overflow-wrap:anywhere}
button,a{font-size:14px;padding:9px 14px;margin:0 4px}
@page{size:4in 2in;margin:0}@media print{body{background:#fff}.toolbar{display:none}.label{margin:0;border:0;width:4in;height:2in;min-height:2in;page-break-after:avoid}}
</style></head><body>
<div class="toolbar"><button onclick="window.print()">Print Label</button><a href="/admin/batches.php">Back to Batch Admin</a></div>
<div class="label">
<div>
<div class="brand">PWG QUANTUM</div><div class="sub">Phononic Waveguides · Cable Batch</div>
<div class="batch"><?=h($batch['batch_number'])?></div>
<div class="meta">
<?php if($batch['cable_family']):?><strong>Family:</strong> <?=h($batch['cable_family'])?><br><?php endif;?>
<?php if($batch['dielectric_od_mm']!==null):?><strong>Dielectric OD:</strong> <?=h($batch['dielectric_od_mm'])?> mm<br><?php endif;?>
<?php if($batch['remaining_length_mm']!==null):?><strong>Remaining:</strong> <?=h($batch['remaining_length_mm'])?> mm<br><?php endif;?>
<?php if($batch['manufacture_date']):?><strong>Date:</strong> <?=h($batch['manufacture_date'])?><br><?php endif;?>
<?php if($batch['outer_conductor']):?><strong>Outer:</strong> <?=h($batch['outer_conductor'])?><?php endif;?>
</div></div>
<div class="qr-wrap">
<img id="qr" alt="QR code for batch record">
<div class="qr-caption"><?=h($batch['batch_number'])?></div>
</div></div>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.4/build/qrcode.min.js"></script>
<script>
(function(){
  var url = <?=json_encode($batchUrl, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  var img = document.getElementById('qr');
  if (window.QRCode && QRCode.toDataURL) {
    QRCode.toDataURL(url,{width:500,margin:4,errorCorrectionLevel:'M'},function(err,data){
      if(!err) img.src=data;
    });
  }
})();
</script>
</body></html>
