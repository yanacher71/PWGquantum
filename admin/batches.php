<?php
declare(strict_types=1);
session_start();

$configFile = dirname(__DIR__, 2) . '/pwg-db-config.php';
if (!is_file($configFile)) { http_response_code(500); exit('Configuration error.'); }
$config = require $configFile;

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function redirectSelf(string $q=''): never { header('Location: /admin/batches.php' . $q); exit; }

$adminHash = (string)($config['admin_password_hash'] ?? '');
if ($adminHash === '') { http_response_code(503); exit('Admin access is not configured.'); }

if (isset($_POST['logout'])) { $_SESSION = []; session_destroy(); redirectSelf(); }

if (empty($_SESSION['pwg_admin'])) {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if (password_verify((string)$_POST['password'], $adminHash)) {
            session_regenerate_id(true);
            $_SESSION['pwg_admin'] = true;
            redirectSelf();
        }
        $error = 'Incorrect password.';
    }
    ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>PWG Batch Admin</title><link rel="stylesheet" href="/styles.css">
<style>.admin{max-width:520px;margin:120px auto;padding:32px}.admin input{width:100%;padding:12px;margin:10px 0 18px;border:1px solid #ccd5df;border-radius:6px}</style></head>
<body><main class="admin"><h1>PWG Batch Admin</h1><p>Authorized access only.</p>
<?php if($error): ?><p><?=h($error)?></p><?php endif; ?>
<form method="post"><label>Password</label><input type="password" name="password" required autofocus>
<button class="btn btn-primary" type="submit">Sign in</button></form></main></body></html><?php exit;
}

try {
    $pdo = new PDO('mysql:host='.$config['host'].';dbname='.$config['database'].';charset=utf8mb4',
        $config['username'],$config['password'],[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false
        ]);
} catch(Throwable $e){ http_response_code(500); exit('Database error.'); }

if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['csrf'];
$message=''; $error='';

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save'])) {
    if (!hash_equals($csrf,(string)($_POST['csrf']??''))) { http_response_code(403); exit('Invalid request.'); }

    $batchNumber=strtoupper(trim((string)($_POST['batch_number']??'')));
    if(!preg_match('/^[A-Z0-9_-]{1,32}$/',$batchNumber)) {
        $error='Batch number is required and may contain only letters, numbers, underscore, and hyphen.';
    } else {
        $fields=[
            'cable_family'=>trim((string)($_POST['cable_family']??'')),
            'description'=>trim((string)($_POST['description']??'')),
            'manufacture_date'=>trim((string)($_POST['manufacture_date']??'')),
            'initial_length_mm'=>trim((string)($_POST['initial_length_mm']??'')),
            'remaining_length_mm'=>trim((string)($_POST['remaining_length_mm']??'')),
            'inner_conductor'=>trim((string)($_POST['inner_conductor']??'')),
            'dielectric'=>trim((string)($_POST['dielectric']??'')),
            'dielectric_od_mm'=>trim((string)($_POST['dielectric_od_mm']??'')),
            'outer_conductor'=>trim((string)($_POST['outer_conductor']??'')),
            'braid_pitch_mm'=>trim((string)($_POST['braid_pitch_mm']??'')),
            'process_revision'=>trim((string)($_POST['process_revision']??'')),
            'status'=>(string)($_POST['status']??'prototype'),
            'storage_location'=>trim((string)($_POST['storage_location']??'')),
            'document_url'=>trim((string)($_POST['document_url']??'')),
            'notes'=>trim((string)($_POST['notes']??''))
        ];
        foreach($fields as $k=>$v) if($v==='') $fields[$k]=null;

        foreach (['initial_length_mm','remaining_length_mm'] as $k) {
            if($fields[$k]!==null && !ctype_digit((string)$fields[$k])) {
                $error='Lengths must be whole millimeters.';
                break;
            }
        }
        if($error==='' && $fields['dielectric_od_mm']!==null && !is_numeric((string)$fields['dielectric_od_mm'])) $error='Dielectric OD must be numeric.';
        if($error==='' && $fields['braid_pitch_mm']!==null && !is_numeric((string)$fields['braid_pitch_mm'])) $error='Braid pitch must be numeric.';
        if($error==='' && $fields['document_url']!==null) {
            $u=filter_var((string)$fields['document_url'], FILTER_VALIDATE_URL);
            if(!$u || strtolower((string)parse_url((string)$u, PHP_URL_SCHEME))!=='https') $error='Document URL must be a valid HTTPS URL.';
        }

        if($error==='') {
            $sql="INSERT INTO pwg_batches
            (batch_number,cable_family,description,manufacture_date,initial_length_mm,remaining_length_mm,inner_conductor,dielectric,dielectric_od_mm,outer_conductor,braid_pitch_mm,process_revision,status,storage_location,document_url,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              cable_family=VALUES(cable_family),description=VALUES(description),manufacture_date=VALUES(manufacture_date),
              initial_length_mm=VALUES(initial_length_mm),remaining_length_mm=VALUES(remaining_length_mm),
              inner_conductor=VALUES(inner_conductor),dielectric=VALUES(dielectric),dielectric_od_mm=VALUES(dielectric_od_mm),
              outer_conductor=VALUES(outer_conductor),braid_pitch_mm=VALUES(braid_pitch_mm),process_revision=VALUES(process_revision),
              status=VALUES(status),storage_location=VALUES(storage_location),document_url=VALUES(document_url),notes=VALUES(notes)";
            try {
                $stmt=$pdo->prepare($sql);
                $stmt->execute([$batchNumber,$fields['cable_family'],$fields['description'],$fields['manufacture_date'],$fields['initial_length_mm'],$fields['remaining_length_mm'],$fields['inner_conductor'],$fields['dielectric'],$fields['dielectric_od_mm'],$fields['outer_conductor'],$fields['braid_pitch_mm'],$fields['process_revision'],$fields['status'],$fields['storage_location'],$fields['document_url'],$fields['notes']]);
                $message='Batch record saved.';
            } catch(Throwable $e){ $error='Could not save batch record.'; }
        }
    }
}

$edit=null;
if(isset($_GET['edit'])){
    $b=strtoupper(trim((string)$_GET['edit']));
    if(preg_match('/^[A-Z0-9_-]{1,32}$/',$b)){
        $st=$pdo->prepare('SELECT * FROM pwg_batches WHERE batch_number=? LIMIT 1');
        $st->execute([$b]);
        $edit=$st->fetch()?:null;
    }
}

$rows=$pdo->query(
    'SELECT b.*,
            COUNT(c.id) AS cable_count,
            COALESCE(SUM(c.length_mm),0) AS finished_length_mm
     FROM pwg_batches b
     LEFT JOIN pwg_cables c ON c.batch_id=b.id
     GROUP BY b.id
     ORDER BY b.id DESC
     LIMIT 100'
)->fetchAll();

$statuses=['prototype','active','consumed','rejected','archived'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>PWG Batch Admin</title><link rel="stylesheet" href="/styles.css">
<style>
.admin-wrap{max-width:1250px;margin:90px auto;padding:28px}.admin-top{display:flex;justify-content:space-between;align-items:center;gap:20px}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.form-grid .wide{grid-column:1/-1}
.form-grid input,.form-grid select,.form-grid textarea{width:100%;padding:10px;border:1px solid #ccd5df;border-radius:6px;background:#fff}
.form-grid textarea{min-height:100px}.panel{border:1px solid #dbe2ea;border-radius:8px;padding:24px;margin:24px 0;background:#fff}
.admin-table{width:100%;border-collapse:collapse}.admin-table th,.admin-table td{padding:10px;border-bottom:1px solid #e2e7ed;text-align:left;vertical-align:top}
.notice{padding:12px;border:1px solid #ccd5df;border-radius:6px;margin:15px 0}.nav-actions{display:flex;gap:10px;flex-wrap:wrap}
@media(max-width:700px){.form-grid{grid-template-columns:1fr}}
</style></head><body><main class="admin-wrap">
<div class="admin-top"><div><h1>PWG Batch Admin</h1><p>Track prototype and production cable rolls.</p></div>
<div class="nav-actions"><a class="btn btn-secondary" href="/admin/cables.php">Cable Admin</a>
<form method="post"><button class="btn btn-secondary" name="logout" value="1">Sign out</button></form></div></div>
<?php if($message):?><div class="notice"><?=h($message)?></div><?php endif;?><?php if($error):?><div class="notice"><?=h($error)?></div><?php endif;?>

<section class="panel"><h2><?= $edit?'Edit '.h($edit['batch_number']):'New Batch' ?></h2>
<form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=h($csrf)?>">
<label>Batch Number<input name="batch_number" maxlength="32" required value="<?=h($edit['batch_number']??'')?>" placeholder="B-C1-260925-001"></label>
<label>Cable Family<input name="cable_family" maxlength="16" value="<?=h($edit['cable_family']??'')?>"></label>
<label class="wide">Description<input name="description" maxlength="255" value="<?=h($edit['description']??'')?>"></label>
<label>Manufacture Date<input type="date" name="manufacture_date" value="<?=h($edit['manufacture_date']??'')?>"></label>
<label>Status<select name="status"><?php foreach($statuses as $s):?><option value="<?=h($s)?>" <?=($edit['status']??'prototype')===$s?'selected':''?>><?=h($s)?></option><?php endforeach;?></select></label>
<label>Initial Length (mm)<input name="initial_length_mm" inputmode="numeric" value="<?=h($edit['initial_length_mm']??'')?>"></label>
<label>Remaining Length (mm)<input name="remaining_length_mm" inputmode="numeric" value="<?=h($edit['remaining_length_mm']??'')?>"></label>
<label>Inner Conductor<input name="inner_conductor" maxlength="128" value="<?=h($edit['inner_conductor']??'')?>"></label>
<label>Dielectric<input name="dielectric" maxlength="128" value="<?=h($edit['dielectric']??'')?>"></label>
<label>Dielectric OD (mm)<input name="dielectric_od_mm" inputmode="decimal" value="<?=h($edit['dielectric_od_mm']??'')?>"></label>
<label>Braid Pitch (mm)<input name="braid_pitch_mm" inputmode="decimal" value="<?=h($edit['braid_pitch_mm']??'')?>"></label>
<label class="wide">Outer Conductor<input name="outer_conductor" maxlength="255" value="<?=h($edit['outer_conductor']??'')?>"></label>
<label>Process Revision<input name="process_revision" maxlength="32" value="<?=h($edit['process_revision']??'')?>"></label>
<label>Storage Location<input name="storage_location" maxlength="128" value="<?=h($edit['storage_location']??'')?>"></label>
<label class="wide">Document URL<input name="document_url" maxlength="1024" value="<?=h($edit['document_url']??'')?>" placeholder="https://..."></label>
<label class="wide">Notes<textarea name="notes"><?=h($edit['notes']??'')?></textarea></label>
<div class="wide"><button class="btn btn-primary" type="submit" name="save" value="1">Save Batch</button>
<?php if($edit):?><a class="btn btn-secondary" href="/admin/batches.php">New Batch</a><?php endif;?></div>
</form></section>

<section class="panel"><h2>Recent Batches</h2><div style="overflow:auto"><table class="admin-table"><thead>
<tr><th>Batch</th><th>Family</th><th>Status</th><th>Initial</th><th>Remaining</th><th>Cables</th><th>Finished Length</th><th>Location</th><th>Actions</th></tr></thead><tbody>
<?php foreach($rows as $r):?>
<tr>
<td><strong><?=h($r['batch_number'])?></strong><br><small><?=h($r['description'])?></small></td>
<td><?=h($r['cable_family'])?></td>
<td><?=h($r['status'])?></td>
<td><?= $r['initial_length_mm']!==null ? h($r['initial_length_mm']).' mm' : '—' ?></td>
<td><?= $r['remaining_length_mm']!==null ? h($r['remaining_length_mm']).' mm' : '—' ?></td>
<td><?=h($r['cable_count'])?></td>
<td><?=h($r['finished_length_mm'])?> mm</td>
<td><?=h($r['storage_location'])?></td>
<td><a href="/admin/batches.php?edit=<?=rawurlencode($r['batch_number'])?>">Edit</a>
&nbsp;|&nbsp; <a href="/batch.php?batch=<?=rawurlencode($r['batch_number'])?>" target="_blank">Batch Page</a>
&nbsp;|&nbsp; <a href="/admin/cables.php?batch_id=<?=h($r['id'])?>">Create Cable</a></td>
</tr>
<?php endforeach;?>
</tbody></table></div></section>
</main></body></html>
