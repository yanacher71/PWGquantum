<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/cable-access.php';
function check(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
$token = str_repeat('ab', 16);
check(pwg_cable_selector(['id'=>'PWG000001'], false) === null, 'Anonymous serial denied');
check(pwg_cable_selector(['id'=>'PWG000001'], true) === ['serial_number','PWG000001'], 'Admin serial retained');
check(pwg_cable_selector(['token'=>$token], false) === ['public_token',$token], 'Token accepted');
check(pwg_cable_selector(['token'=>$token,'id'=>'PWG000002'], false) === ['public_token',$token], 'Token cannot select another serial');
foreach (['', 'abc', str_repeat('g',32), strtoupper($token), $token."\n", ['nested'], null] as $bad) {
    check(pwg_cable_selector(['token'=>$bad], false) === null, 'Malformed token denied');
    check(pwg_cable_selector(['token'=>$bad,'id'=>'PWG000001'], true) === null, 'No fallback from bad token');
}
check(pwg_cable_selector(['id'=>['nested']], true) === null, 'Malformed admin selector denied');
check(pwg_public_report_url($token) === 'https://pwgquantum.com/c/'.$token.'/report', 'Report URL has only token');
check(pwg_cable_selector([], true) === null, 'Missing selector denied');
echo "Cable access policy tests passed.\n";
