<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/measurements.php';
require_once dirname(__DIR__).'/includes/measurement-bulk.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function folder(string $name, string $id): array { return ['name'=>$name, 'id'=>$id, 'mimeType'=>'application/vnd.google-apps.folder']; }
$plan = pwg_bulk_plan([
    folder('PWG000013','a'), folder('pwg000013','b'), folder('PWG000011','c'),
    folder('PWG999999','d'), folder('calibration','e'), folder('../outside','f'),
    ['name'=>'PWG000011','id'=>'g','mimeType'=>'text/plain']
], ['PWG000011','PWG000013']);
check(count($plan) === 5, 'Only non-calibration folders are reported');
$byId = array_column($plan, null, 'folder');
check($byId['a']['status'] === 'failed' && $byId['b']['status'] === 'failed', 'All duplicate sources rejected');
check($byId['c']['status'] === 'queued', 'Known cable matched');
check($byId['d']['status'] === 'skipped' && $byId['f']['status'] === 'skipped', 'Unknown and malformed serials skipped');
check(pwg_bulk_record(['tdr.txt'=>'','s11.s1p'=>'','s22.s1p'=>''], [], []) === null, 'Zero-byte placeholders skipped before requiring conditions');
$files = ['s11.s1p'=>"# Hz S DB R 50\n0 -30 0\n1e9 -40 90\n", 'measurement_conditions.txt'=>"\xEF\xBB\xBFConditions\r\n"];
$record = pwg_bulk_record($files, [], ['drive_folder_id'=>'folder']);
check($record['metadata']['conditions'] === 'Conditions', 'BOM and whitespace removed');
check(isset($record['plots']['s11']) && !isset($record['plots']['tdr']), 'S-parameter-only import works without calibration');
$older = $record; $older['imported_at'] = 'earlier';
check(pwg_bulk_unchanged($older, $record), 'Retry does not republish identical data');
$persisted = json_decode(json_encode($older), true);
check(pwg_bulk_unchanged($persisted, $record), 'JSON round trip preserves unchanged detection');
$changed = $record; $changed['metadata']['conditions'] = 'Updated';
check(!pwg_bulk_unchanged($record, $changed), 'Changed conditions trigger update');
foreach (['', str_repeat('x',2001)] as $bad) {
    $files['measurement_conditions.txt'] = $bad;
    $rejected = false;
    try { pwg_bulk_record($files, [], []); } catch (RuntimeException $e) { $rejected = true; }
    check($rejected, 'Reject missing or oversized conditions');
}
echo "Bulk measurement matching and record tests passed.\n";
