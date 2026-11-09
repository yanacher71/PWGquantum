<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/measurements.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function waveform(float $voltage): string {
    $text = "Points: 25\nX Units: second\nY Units: Volt\nXY Data:\n";
    for ($i=0; $i<25; $i++) $text .= sprintf("%.12e, %.12e\n", (0.6 + $i * 0.05) * 1e-9, $voltage);
    return $text;
}
$calibration = ['50ohms.txt'=>waveform(0.2), 'open.txt'=>waveform(0.4), 'short.txt'=>waveform(0)];
$raw = ['tdr.txt'=>waveform(0.2), 's11.s1p'=>"# Hz S DB R 50\n0 -30 0\n1e9 -40 90\n", 's22.s1p'=>''];
$record = pwg_measurement_build($raw, $calibration, []);
check(abs($record['plots']['tdr'][0][1] - 50) < 1e-8, 'Matched load must convert to 50 ohms');
check($record['plots']['s11'][1] === [1.0, -40.0], 'Frequency normalized to GHz');
check(!isset($record['raw']['s22.s1p']), 'Empty placeholder is unavailable');
check($record['calibration']['raw'] === $calibration, 'Original references retained');
$raw['tdr.txt'] = waveform(0.3);
check(abs(pwg_measurement_build($raw, $calibration, [])['plots']['tdr'][0][1] - 150) < 1e-8, '150 ohm conversion');
$raw['tdr.txt'] = waveform(0.4);
check(pwg_measurement_build($raw, $calibration, [])['plots']['tdr'][0][1] === null, 'Open circuit has no finite impedance');
check(abs(pwg_s1p_parse("# GHz S RI R 50\n0 0.1 0\n1 0 0.01\n")[1][1] + 40) < 1e-8, 'Complex reflection conversion');
foreach (["# Hz S DB R 50\n1 -30 0\n1 -20 0\n", "# Hz S DB R 75\n0 -30 0\n1 -20 0\n", "<html>error</html>"] as $invalid) {
    $rejected = false;
    try { pwg_s1p_parse($invalid); } catch (RuntimeException $e) { $rejected = true; }
    check($rejected, 'Reject invalid downloaded data');
}
$calibration['short.txt'] = waveform(0.1);
$rejected = false;
try { pwg_measurement_build($raw, $calibration, []); } catch (RuntimeException $e) { $rejected = true; }
check($rejected, 'Reject inconsistent calibration');
check(pwg_measurement_read('../secret') === null, 'Reject storage traversal');
echo "Measurement parser and calibration tests passed.\n";
