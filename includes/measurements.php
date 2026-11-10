<?php
declare(strict_types=1);

/** Strict parsers for the 86100C ASCII waveform and one-port Touchstone exports. */
function pwg_tdr_parse(string $text): array {
    if (!preg_match('/^X Units:\s+second\s*$/m', $text) || !preg_match('/^Y Units:\s+Volt\s*$/m', $text)) {
        throw new RuntimeException('TDR must contain seconds and volts.');
    }
    $parts = explode('XY Data:', $text, 2);
    if (count($parts) !== 2) throw new RuntimeException('Missing TDR samples.');
    preg_match('/^Date:\s+([^\r\n]+)/m', $text, $date);
    preg_match('/^Time:\s+([^\r\n]+)/m', $text, $time);
    $points = [];
    foreach (preg_split('/\R/', trim($parts[1])) as $line) {
        $values = array_map('trim', explode(',', $line));
        if (count($values) !== 2 || !is_numeric($values[0]) || !is_numeric($values[1])) throw new RuntimeException('Invalid TDR sample.');
        [$x, $y] = array_map('floatval', $values);
        if (!is_finite($x) || !is_finite($y) || ($points && $x <= $points[count($points)-1][0])) throw new RuntimeException('Invalid TDR time axis.');
        $points[] = [$x, $y];
        if (count($points) > 100000) throw new RuntimeException('Too many TDR samples.');
    }
    if (count($points) < 2) throw new RuntimeException('Too few TDR samples.');
    preg_match('/^Points:\s+(\d+)/m', $text, $count);
    if (!$count || (int)$count[1] !== count($points)) throw new RuntimeException('TDR sample count does not match its header.');
    return ['points'=>$points, 'measured_at'=>trim(($date[1] ?? '').' '.($time[1] ?? ''))];
}

function pwg_s1p_parse(string $text): array {
    $points = []; $scale = null; $format = '';
    foreach (preg_split('/\R/', $text) as $line) {
        $line = trim(explode('!', $line, 2)[0]);
        if ($line === '') continue;
        if ($line[0] === '#') {
            if (!preg_match('/^#\s*(HZ|KHZ|MHZ|GHZ)\s+S\s+(DB|MA|RI)\s+R\s+50(?:\.0*)?\s*$/i', $line, $header)) throw new RuntimeException('Expected a 50 ohm one-port Touchstone file.');
            $scale = ['HZ'=>1, 'KHZ'=>1e3, 'MHZ'=>1e6, 'GHZ'=>1e9][strtoupper($header[1])];
            $format = strtoupper($header[2]);
            continue;
        }
        $values = preg_split('/\s+/', $line);
        if ($scale === null || count($values) !== 3 || count(array_filter($values, 'is_numeric')) !== 3) throw new RuntimeException('Invalid Touchstone sample.');
        [$frequency, $a, $b] = array_map('floatval', $values);
        $frequency *= $scale / 1e9;
        if (!is_finite($frequency) || !is_finite($a) || !is_finite($b) || $frequency < 0 || ($points && $frequency <= $points[count($points)-1][0])) throw new RuntimeException('Invalid Touchstone values.');
        if ($format === 'MA' && $a < 0) throw new RuntimeException('Invalid reflection magnitude.');
        $magnitude = $format === 'RI' ? hypot($a, $b) : $a;
        $db = $format === 'DB' ? $a : ($magnitude > 0 ? 20 * log10($magnitude) : null);
        $points[] = [$frequency, $db];
        if (count($points) > 100000) throw new RuntimeException('Too many frequency samples.');
    }
    if (count($points) < 2) throw new RuntimeException('Too few frequency samples.');
    return $points;
}

function pwg_measurement_root(): string { return dirname(__DIR__, 2).'/pwg-measurements'; }

function pwg_measurement_read(string $serial): ?array {
    if (!preg_match('/\A[A-Z0-9_-]{1,32}\z/D', $serial)) return null;
    $path = pwg_measurement_root().'/'.$serial.'.json';
    if (!is_file($path)) return null;
    $record = json_decode((string)file_get_contents($path), true);
    return is_array($record) ? $record : null;
}

function pwg_measurement_build(array $raw, array $calibration, array $metadata): array {
    $record = ['imported_at'=>gmdate('c'), 'metadata'=>$metadata, 'raw'=>[], 'plots'=>[]];
    foreach (['s11.s1p', 's22.s1p', 'tdr.txt'] as $name) {
        if (trim($raw[$name] ?? '') === '') continue;
        $record['raw'][$name] = $raw[$name];
        if ($name !== 'tdr.txt') $record['plots'][substr($name, 0, 3)] = pwg_s1p_parse($raw[$name]);
    }
    if (isset($record['raw']['tdr.txt'])) {
        $means = [];
        foreach (['50ohms.txt', 'open.txt', 'short.txt'] as $name) {
            $reference = pwg_tdr_parse($calibration[$name] ?? '');
            $values = [];
            foreach ($reference['points'] as [$time, $voltage]) {
                if ($time >= 0.6e-9 && $time <= 1.8e-9) $values[] = $voltage;
            }
            if (count($values) < 20) throw new RuntimeException('Calibration must cover 0.6–1.8 ns with at least 20 samples.');
            $means[$name] = array_sum($values) / count($values);
        }
        $span = $means['open.txt'] - $means['50ohms.txt'];
        if ($span < 0.01 || abs($means['50ohms.txt'] - ($means['open.txt'] + $means['short.txt']) / 2) > $span * 0.02) throw new RuntimeException('Open, short and load references are inconsistent.');
        $trace = pwg_tdr_parse($raw['tdr.txt']);
        $record['measured_at'] = $trace['measured_at'];
        $record['calibration'] = ['method'=>'constant open/load normalization', 'window_ns'=>[0.6,1.8], 'voltages'=>$means, 'raw'=>$calibration];
        $plot = [];
        foreach ($trace['points'] as [$time, $voltage]) {
            $rho = ($voltage - $means['50ohms.txt']) / $span;
            $ohms = abs(1 - $rho) < 1e-8 ? null : 50 * (1 + $rho) / (1 - $rho);
            $plot[] = [$time * 1e9, $ohms];
        }
        $record['plots']['tdr'] = $plot;
    }
    if (!$record['plots']) throw new RuntimeException('No nonempty measurements to import.');
    return $record;
}

function pwg_measurement_save(string $serial, array $record): void {
    if (!preg_match('/\A[A-Z0-9_-]{1,32}\z/D', $serial)) throw new RuntimeException('Invalid cable serial.');
    $directory = pwg_measurement_root();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Could not create measurement storage.');
    $temp = tempnam($directory, 'import-');
    if ($temp === false) throw new RuntimeException('Could not create measurement file.');
    try {
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || !rename($temp, $directory.'/'.$serial.'.json')) throw new RuntimeException('Could not save measurements.');
    } finally { if (is_file($temp)) unlink($temp); }
}
