<?php
declare(strict_types=1);

$configFile = dirname(__DIR__) . '/pwg-db-config.php';
if (!is_file($configFile)) { http_response_code(500); exit('Configuration error.'); }
$config = require $configFile;

$batchNumber = strtoupper(trim((string)($_GET['batch'] ?? '')));
if ($batchNumber === '' || !preg_match('/^[A-Z0-9_-]{1,32}$/', $batchNumber)) {
    http_response_code(400);
    exit('Invalid batch number.');
}

try {
    $pdo = new PDO(
        'mysql:host=' . $config['host'] . ';dbname=' . $config['database'] . ';charset=utf8mb4',
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]
    );

    $stmt=$pdo->prepare('SELECT * FROM pwg_batches WHERE batch_number=? LIMIT 1');
    $stmt->execute([$batchNumber]);
    $batch=$stmt->fetch();

    if (!$batch) {
        http_response_code(404);
        exit('Batch record not found.');
    }

    $cablesStmt=$pdo->prepare(
        'SELECT serial_number,model_number,length_mm,manufacture_date,status
         FROM pwg_cables
         WHERE batch_id=?
         ORDER BY manufacture_date DESC,id DESC'
    );
    $cablesStmt->execute([$batch['id']]);
    $cables=$cablesStmt->fetchAll();

    $summaryStmt=$pdo->prepare(
        'SELECT COUNT(*) AS cable_count,
                COALESCE(SUM(length_mm),0) AS finished_length_mm
         FROM pwg_cables
         WHERE batch_id=?'
    );
    $summaryStmt->execute([$batch['id']]);
    $summary=$summaryStmt->fetch();
} catch(Throwable $e) {
    http_response_code(500);
    exit('Database connection error.');
}

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function dash($v): string { return ($v===null || $v==='') ? '&mdash;' : h($v); }

$unaccounted=null;
if ($batch['initial_length_mm']!==null && $batch['remaining_length_mm']!==null) {
    $unaccounted=(int)$batch['initial_length_mm']-(int)$batch['remaining_length_mm']-(int)$summary['finished_length_mm'];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title><?=h($batch['batch_number'])?> | PWG Batch</title>
<link rel="stylesheet" href="/styles.css">
<style>
.batch-page{padding:calc(var(--header-height) + 64px) 0 80px;min-height:70vh}
.batch-card{background:var(--color-surface);border:1px solid var(--color-border);border-radius:var(--radius-sm);padding:32px;margin-bottom:24px}
.batch-kicker{font-family:var(--font-mono);color:var(--color-accent);font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px}
.summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 24px}
.summary-box{border:1px solid var(--color-border);border-radius:8px;padding:16px}.summary-box strong{display:block;font-size:1.25rem}
@media(max-width:800px){.summary-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:480px){.summary-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="site-header" id="site-header"><div class="header-inner">
<a href="/" class="logo"><span class="logo-mark"><img src="/logo.gif" alt="PWG Quantum" width="32" height="32"></span>
<span class="logo-text"><span class="logo-title">PWG Quantum</span><span class="logo-sub">Phononic Waveguides</span></span></a>
<div class="header-actions"><a href="/admin/batches.php" class="btn btn-secondary btn-small">Batch Admin</a></div>
</div></header>

<main class="batch-page"><div class="section-inner">
<div class="section-head"><div class="batch-kicker">Internal Batch Record</div><h1><?=h($batch['batch_number'])?></h1><p><?=dash($batch['description'])?></p></div>

<div class="summary-grid">
<div class="summary-box"><span>Initial</span><strong><?= $batch['initial_length_mm']!==null ? h($batch['initial_length_mm']).' mm' : '—' ?></strong></div>
<div class="summary-box"><span>Remaining</span><strong><?= $batch['remaining_length_mm']!==null ? h($batch['remaining_length_mm']).' mm' : '—' ?></strong></div>
<div class="summary-box"><span>Finished Cables</span><strong><?=h($summary['cable_count'])?></strong></div>
<div class="summary-box"><span>Process / Scrap</span><strong><?= $unaccounted!==null ? h($unaccounted).' mm' : '—' ?></strong></div>
</div>

<section class="batch-card">
<div class="table-scroll"><table class="data-table spec-table"><tbody>
<tr><th>Batch Number</th><td class="mono"><?=h($batch['batch_number'])?></td></tr>
<tr><th>Cable Family</th><td><?=dash($batch['cable_family'])?></td></tr>
<tr><th>Manufacture Date</th><td><?=dash($batch['manufacture_date'])?></td></tr>
<tr><th>Status</th><td><?=dash($batch['status'])?></td></tr>
<tr><th>Inner Conductor</th><td><?=dash($batch['inner_conductor'])?></td></tr>
<tr><th>Dielectric</th><td><?=dash($batch['dielectric'])?></td></tr>
<tr><th>Dielectric OD</th><td><?= $batch['dielectric_od_mm']!==null ? h($batch['dielectric_od_mm']).' mm' : '—' ?></td></tr>
<tr><th>Outer Conductor</th><td><?=dash($batch['outer_conductor'])?></td></tr>
<tr><th>Braid Pitch</th><td><?= $batch['braid_pitch_mm']!==null ? h($batch['braid_pitch_mm']).' mm' : '—' ?></td></tr>
<tr><th>Process Revision</th><td><?=dash($batch['process_revision'])?></td></tr>
<tr><th>Storage Location</th><td><?=dash($batch['storage_location'])?></td></tr>
</tbody></table></div>
<?php if(!empty($batch['notes'])):?><div style="margin-top:24px"><h3>Notes</h3><p><?=nl2br(h($batch['notes']))?></p></div><?php endif;?>
<?php if(!empty($batch['document_url'])):?><div style="margin-top:24px"><a class="btn btn-primary" href="<?=h($batch['document_url'])?>" target="_blank" rel="noopener">Batch Document</a></div><?php endif;?>
</section>

<section class="batch-card"><h2>Cables from this Batch</h2>
<div class="table-scroll"><table class="data-table"><thead><tr><th>Serial</th><th>Model</th><th>Length</th><th>Date</th><th>Status</th></tr></thead><tbody>
<?php if(!$cables):?><tr><td colspan="5">No cable records are linked to this batch yet.</td></tr><?php else: foreach($cables as $c):?>
<tr><td><a href="/c/<?=rawurlencode($c['serial_number'])?>" target="_blank"><?=h($c['serial_number'])?></a></td><td><?=h($c['model_number'])?></td>
<td><?= $c['length_mm']!==null ? h($c['length_mm']).' mm' : '—' ?></td><td><?=dash($c['manufacture_date'])?></td><td><?=dash($c['status'])?></td></tr>
<?php endforeach; endif;?>
</tbody></table></div>
<div style="margin-top:24px"><a class="btn btn-primary" href="/admin/cables.php?batch_id=<?=h($batch['id'])?>">Create Cable from Batch</a></div>
</section>
</div></main>
</body></html>
