<?php
declare(strict_types=1);
session_start();

$configFile = dirname(__DIR__, 2) . '/pwg-db-config.php';
if (!is_file($configFile)) { http_response_code(500); exit('Configuration error.'); }
$config = require $configFile;

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function redirectSelf(string $q=''): never { header('Location: /admin/cables.php' . $q); exit; }

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
<title>PWG Cable Admin</title><link rel="stylesheet" href="/styles.css">
<style>.admin{max-width:520px;margin:120px auto;padding:32px}.admin input{width:100%;padding:12px;margin:10px 0 18px;border:1px solid #ccd5df;border-radius:6px}</style></head>
<body><main class="admin"><h1>PWG Cable Admin</h1><p>Authorized access only.</p>
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
    $serial=strtoupper(trim((string)($_POST['serial_number']??'')));
    $model=trim((string)($_POST['model_number']??''));
    if(!preg_match('/^[A-Z0-9_-]{1,32}$/',$serial) || $model==='') $error='Serial number and model number are required.';
    else {
        $fields=[
          'cable_family'=>trim((string)($_POST['cable_family']??'')),
          'length_mm'=>trim((string)($_POST['length_mm']??'')),
          'outer_diameter_mm'=>trim((string)($_POST['outer_diameter_mm']??'')),
          'connector_a'=>trim((string)($_POST['connector_a']??'')),
          'connector_b'=>trim((string)($_POST['connector_b']??'')),
          'manufacture_date'=>trim((string)($_POST['manufacture_date']??'')),
          'lot_number'=>trim((string)($_POST['lot_number']??'')),
          'status'=>(string)($_POST['status']??'prototype'),
          'source_doc_id'=>trim((string)($_POST['source_doc_id']??'')),
          'notes'=>trim((string)($_POST['notes']??''))
        ];
        foreach($fields as $k=>$v) if($v==='') $fields[$k]=null;
        if($fields['length_mm']!==null && (!ctype_digit((string)$fields['length_mm']) || (int)$fields['length_mm']>65535)) $error='Length must be 0–65535 mm.';
        elseif($fields['outer_diameter_mm']!==null && !is_numeric((string)$fields['outer_diameter_mm'])) $error='Outer diameter must be numeric.';
        elseif($fields['source_doc_id']!==null && !preg_match('/^[A-Za-z0-9_-]{10,128}$/',(string)$fields['source_doc_id'])) $error='Enter the Google Doc file ID, not the full URL.';
        else {
            $sql="INSERT INTO pwg_cables
            (serial_number,model_number,cable_family,length_mm,outer_diameter_mm,connector_a,connector_b,manufacture_date,lot_number,status,source_doc_id,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE model_number=VALUES(model_number),cable_family=VALUES(cable_family),length_mm=VALUES(length_mm),
            outer_diameter_mm=VALUES(outer_diameter_mm),connector_a=VALUES(connector_a),connector_b=VALUES(connector_b),
            manufacture_date=VALUES(manufacture_date),lot_number=VALUES(lot_number),status=VALUES(status),
            source_doc_id=VALUES(source_doc_id),notes=VALUES(notes)";
            try {
                $stmt=$pdo->prepare($sql);
                $stmt->execute([$serial,$model,$fields['cable_family'],$fields['length_mm'],$fields['outer_diameter_mm'],$fields['connector_a'],$fields['connector_b'],$fields['manufacture_date'],$fields['lot_number'],$fields['status'],$fields['source_doc_id'],$fields['notes']]);
                $message='Cable record saved.';
            } catch(Throwable $e){ $error='Could not save cable record.'; }
        }
    }
}

$edit=null;
if(isset($_GET['edit'])){
    $s=strtoupper(trim((string)$_GET['edit']));
    if(preg_match('/^[A-Z0-9_-]{1,32}$/',$s)){
        $st=$pdo->prepare('SELECT * FROM pwg_cables WHERE serial_number=? LIMIT 1'); $st->execute([$s]); $edit=$st->fetch()?:null;
    }
}
$rows=$pdo->query('SELECT serial_number,model_number,status,source_doc_id,pdf_file_id,internal_source_doc_id,internal_pdf_file_id,updated_at FROM pwg_cables ORDER BY id DESC LIMIT 100')->fetchAll();
$statuses=['prototype','testing','passed','failed','shipped'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>PWG Cable Admin</title><link rel="stylesheet" href="/styles.css">
<style>
.admin-wrap{max-width:1200px;margin:90px auto;padding:28px}.admin-top{display:flex;justify-content:space-between;align-items:center;gap:20px}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.form-grid .wide{grid-column:1/-1}
.form-grid input,.form-grid select,.form-grid textarea{width:100%;padding:10px;border:1px solid #ccd5df;border-radius:6px;background:#fff}
.form-grid textarea{min-height:100px}.panel{border:1px solid #dbe2ea;border-radius:8px;padding:24px;margin:24px 0;background:#fff}
.admin-table{width:100%;border-collapse:collapse}.admin-table th,.admin-table td{padding:10px;border-bottom:1px solid #e2e7ed;text-align:left}
.notice{padding:12px;border:1px solid #ccd5df;border-radius:6px;margin:15px 0}@media(max-width:700px){.form-grid{grid-template-columns:1fr}}
</style></head><body><main class="admin-wrap">
<div class="admin-top"><div><h1>PWG Cable Admin</h1><p>Create and edit cable records.</p></div>
<form method="post"><button class="btn btn-secondary" name="logout" value="1">Sign out</button></form></div>
<?php if($message):?><div class="notice"><?=h($message)?></div><?php endif;?><?php if($error):?><div class="notice"><?=h($error)?></div><?php endif;?>
<section class="panel"><h2><?= $edit?'Edit '.h($edit['serial_number']):'New Cable' ?></h2>
<form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=h($csrf)?>">
<label>Serial Number<input name="serial_number" maxlength="32" required value="<?=h($edit['serial_number']??'')?>"></label>
<label>Model Number<input name="model_number" maxlength="64" required value="<?=h($edit['model_number']??'')?>"></label>
<label>Cable Family<input name="cable_family" maxlength="16" value="<?=h($edit['cable_family']??'')?>"></label>
<label>Length (mm)<input name="length_mm" inputmode="numeric" value="<?=h($edit['length_mm']??'')?>"></label>
<label>Outer Diameter (mm)<input name="outer_diameter_mm" inputmode="decimal" value="<?=h($edit['outer_diameter_mm']??'')?>"></label>
<label>Connector A<input name="connector_a" maxlength="32" value="<?=h($edit['connector_a']??'')?>"></label>
<label>Connector B<input name="connector_b" maxlength="32" value="<?=h($edit['connector_b']??'')?>"></label>
<label>Manufacture Date<input type="date" name="manufacture_date" value="<?=h($edit['manufacture_date']??'')?>"></label>
<label>Lot Number<input name="lot_number" maxlength="32" value="<?=h($edit['lot_number']??'')?>"></label>
<label>Status<select name="status"><?php foreach($statuses as $s):?><option value="<?=h($s)?>" <?=($edit['status']??'prototype')===$s?'selected':''?>><?=h($s)?></option><?php endforeach;?></select></label>
<label class="wide">Google Doc File ID<input name="source_doc_id" maxlength="128" value="<?=h($edit['source_doc_id']??'')?>" placeholder="Example: 1mfzzpCxOT_ac0zINUmep27BHNFsUXpVV"></label>
<label class="wide">Notes<textarea name="notes"><?=h($edit['notes']??'')?></textarea></label>
<div class="wide"><button class="btn btn-primary" type="submit" name="save" value="1">Save Cable</button> <?php if($edit):?><a class="btn btn-secondary" href="/admin/cables.php">New Cable</a><?php endif;?></div>
</form></section>
<section class="panel"><h2>Recent Cables</h2><div style="overflow:auto"><table class="admin-table"><thead><tr><th>Serial</th><th>Model</th><th>Status</th><th>Documents</th><th>Actions</th></tr></thead><tbody>
<?php foreach($rows as $r):?>
<tr>
<td><?=h($r['serial_number'])?></td>
<td><?=h($r['model_number'])?></td>
<td><?=h($r['status'])?></td>
<td>
<strong>Customer:</strong>
<?php if($r['source_doc_id']):?>
<a href="https://docs.google.com/document/d/<?=rawurlencode($r['source_doc_id'])?>/edit" target="_blank" rel="noopener">Doc</a>
<?php else:?>pending<?php endif;?>
<?php if($r['pdf_file_id']):?> &nbsp;|&nbsp; <a href="/c/<?=rawurlencode($r['serial_number'])?>/report" target="_blank">PDF</a><?php endif;?>
<br>
<strong>Internal:</strong>
<?php if($r['internal_source_doc_id']):?>
<a href="https://docs.google.com/document/d/<?=rawurlencode($r['internal_source_doc_id'])?>/edit" target="_blank" rel="noopener">Doc</a>
<?php else:?>pending<?php endif;?>
<?php if($r['internal_pdf_file_id']):?>
&nbsp;|&nbsp; <a href="https://drive.google.com/file/d/<?=rawurlencode($r['internal_pdf_file_id'])?>/view" target="_blank" rel="noopener">PDF</a>
<?php endif;?>
</td>
<td>
<a href="/admin/cables.php?edit=<?=rawurlencode($r['serial_number'])?>">Edit</a>
&nbsp;|&nbsp; <a href="/c/<?=rawurlencode($r['serial_number'])?>" target="_blank">Customer Page</a>
</td>
</tr>
<?php endforeach;?>
</tbody></table></div></section></main></body></html>
