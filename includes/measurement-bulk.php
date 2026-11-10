<?php
declare(strict_types=1);

/** Match direct child folders only. Duplicate serials must never pick an arbitrary source. */
function pwg_bulk_plan(array $entries, array $serials): array {
    $known = array_fill_keys($serials, true);
    $folders = []; $counts = [];
    foreach ($entries as $entry) {
        if (($entry['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') continue;
        if (strtolower($entry['name']) === 'calibration') continue;
        $serial = strtoupper(trim($entry['name']));
        $folders[] = ['serial'=>$serial, 'folder'=>$entry['id']];
        $counts[$serial] = ($counts[$serial] ?? 0) + 1;
    }
    usort($folders, static fn($a, $b) => strcmp($a['serial'], $b['serial']));
    foreach ($folders as &$folder) {
        $folder['status'] = 'queued';
        $folder['message'] = '';
        if (!preg_match('/\A[A-Z0-9_-]{1,32}\z/D', $folder['serial']) || !isset($known[$folder['serial']])) {
            $folder['status'] = 'skipped';
            $folder['message'] = 'No matching cable record.';
        } elseif ($counts[$folder['serial']] > 1) {
            $folder['status'] = 'failed';
            $folder['message'] = 'Duplicate cable folders. Rename or remove the duplicate before importing.';
        }
    }
    unset($folder);
    return $folders;
}

function pwg_bulk_record(array $files, array $calibration, array $metadata): ?array {
    $hasData = false;
    foreach (['tdr.txt','s11.s1p','s22.s1p'] as $name) {
        if (trim($files[$name] ?? '') !== '') $hasData = true;
    }
    if (!$hasData) return null;
    $conditions = trim(preg_replace('/^\xEF\xBB\xBF/', '', $files['measurement_conditions.txt'] ?? ''));
    if ($conditions === '' || strlen($conditions) > 2000) throw new RuntimeException('measurement_conditions.txt is missing, empty or exceeds 2000 bytes.');
    $metadata['conditions'] = $conditions;
    return pwg_measurement_build($files, $calibration, $metadata);
}

function pwg_bulk_unchanged(array $existing, array $record): bool {
    unset($existing['imported_at'], $record['imported_at']);
    // Normalize JSON's integer/float representation used by persisted plot samples.
    return json_encode($existing, JSON_THROW_ON_ERROR) === json_encode($record, JSON_THROW_ON_ERROR);
}

function pwg_bulk_has_measurements(array $entries): bool {
    foreach ($entries as $file) {
        if (in_array($file['name'], ['tdr.txt','s11.s1p','s22.s1p'], true) && (int)($file['size'] ?? 0) > 0) return true;
    }
    return false;
}
