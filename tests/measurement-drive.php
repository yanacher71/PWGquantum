<?php
declare(strict_types=1);
// Run with php -n so this test can simulate cURL without a network or credentials.
if (extension_loaded('curl')) throw new RuntimeException('Run this test with php -n.');
foreach (['CURLOPT_HTTPHEADER','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_FOLLOWLOCATION','CURLOPT_WRITEFUNCTION','CURLINFO_HTTP_CODE','CURLINFO_CONTENT_TYPE','CURLOPT_MAXREDIRS','CURLOPT_PROTOCOLS','CURLOPT_REDIR_PROTOCOLS','CURLPROTO_HTTPS'] as $index=>$name) define($name, $index+1);
$responses = []; $requests = [];
if (!extension_loaded('curl')) {
function curl_init(string $url): object { return (object)['url'=>$url]; }
function curl_setopt_array(object $handle, array $options): void { $handle->options = $options; }
function curl_exec(object $handle): bool {
    global $responses, $requests;
    $requests[] = $handle->url;
    $GLOBALS['requestOptions'][] = $handle->options;
    $handle->response = array_shift($responses);
    if (!$handle->response) throw new RuntimeException('Unexpected request.');
    $body = $handle->response['body'] ?? '';
    $written = ($handle->options[CURLOPT_WRITEFUNCTION])($handle, $body);
    return $written === strlen($body) && empty($handle->response['network']);
}
function curl_getinfo(object $handle, int $option): int|string { return $option === CURLINFO_CONTENT_TYPE ? ($handle->response['type'] ?? 'text/plain') : $handle->response['status']; }
function curl_errno(object $handle): int { return $handle->response['network'] ?? 0; }
function curl_close(object $handle): void {}
}
if (!extension_loaded('curl')) {
    define('CURLM_OK', 0);
    function curl_multi_init(): object { return (object)['handles'=>[]]; }
    function curl_multi_add_handle(object $multi, object $handle): void {
        $multi->handles[] = $handle;
        $GLOBALS['maxParallel'] = max($GLOBALS['maxParallel'] ?? 0, count($multi->handles));
    }
    function curl_multi_exec(object $multi, &$running): int {
        foreach ($multi->handles as $handle) curl_exec($handle);
        $running = 0;
        return CURLM_OK;
    }
    function curl_multi_select(object $multi, float $timeout): int { return 1; }
    function curl_multi_remove_handle(object $multi, object $handle): void {}
    function curl_multi_close(object $multi): void {}
}
require_once dirname(__DIR__).'/includes/measurement-drive.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$config = ['google_drive_api_key'=>'private-test-key'];
$page = [];
for ($i=0; $i<100; $i++) $page[] = ['id'=>'id'.$i, 'name'=>'PWG'.$i];
$responses = [
    ['status'=>200, 'body'=>json_encode(['files'=>$page, 'nextPageToken'=>'second-page'])],
    ['status'=>503, 'body'=>'temporary error'],
    ['status'=>200, 'body'=>json_encode(['files'=>[['id'=>'last', 'name'=>'calibration']]])]
];
$files = pwg_drive_folder_entries('parent-folder-123', $config);
check(count($files) === 101, 'More than 100 children listed');
check(count($requests) === 3 && str_contains($requests[1], 'pageToken=second-page') && $requests[1] === $requests[2], 'Retry retains pagination token');
$responses = [['status'=>403, 'body'=>json_encode(['error'=>['errors'=>[['reason'=>'userRateLimitExceeded']]]])], ['status'=>200, 'body'=>'ok']];
check(pwg_drive_request('files', [], $config) === 'ok', 'Rate limit retried');
$responses = [['status'=>0, 'network'=>28], ['status'=>200, 'body'=>'ok']];
check(pwg_drive_request('files', [], $config) === 'ok', 'Timeout retried');
$responses = [['status'=>403, 'body'=>json_encode(['error'=>['errors'=>[['reason'=>'forbidden']], 'message'=>'private-test-key']])]];
try { pwg_drive_request('files', [], $config, 'List parent folder'); throw new LogicException('Expected access failure.'); }
catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'List parent folder') && str_contains($e->getMessage(), 'HTTP 403, forbidden'), 'Context and provider reason reported');
    check(!str_contains($e->getMessage(), 'private-test-key'), 'Credentials never included in errors');
    check($responses === [], 'Access denial not retried');
}
$responses = array_fill(0, 3, ['status'=>503, 'body'=>'temporary error']);
try { pwg_drive_request('files', [], $config); throw new LogicException('Expected exhausted retries.'); }
catch (RuntimeException $e) { check($responses === [] && str_contains($e->getMessage(), 'HTTP 503'), 'Retries bounded at three attempts'); }
$responses = [['status'=>403], ['status'=>200, 'body'=>'public waveform']];
check(pwg_drive_request('files/test-file', ['alt'=>'media'], $config) === 'public waveform', 'Public key download falls back after 403');
check(str_starts_with(end($requests), 'https://drive.google.com/uc?'), 'Fallback uses fixed public endpoint');
check(!str_contains(end($requests), 'private-test-key') && !isset($GLOBALS['requestOptions'][count($requests)-1][CURLOPT_HTTPHEADER]), 'No credentials sent to public download');
$responses = [['status'=>403]];
try { pwg_drive_request('files/test-file', ['alt'=>'media'], ['google_drive_access_token'=>'private-token']); throw new LogicException('Expected OAuth failure.'); }
catch (RuntimeException $e) { check($responses === [] && str_contains($e->getMessage(), 'HTTP 403'), 'OAuth failure never falls back'); }
$responses = [['status'=>403], ['status'=>200, 'type'=>'text/html', 'body'=>'<html>Sign in</html>']];
try { pwg_drive_request('files/test-file', ['alt'=>'media'], $config); throw new LogicException('Expected HTML rejection.'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'both the Drive API'), 'HTML error pages rejected'); }
$folders = [];
for ($i=0; $i<100; $i++) $folders[] = 'cable-folder-'.str_pad((string)$i,3,'0',STR_PAD_LEFT);
$before = count($requests);
$responses = array_fill(0, 100, ['status'=>200, 'body'=>json_encode(['files'=>[]])]);
$metadata = pwg_drive_bulk_entries($folders, $config);
check(count($metadata) === 100 && count($requests)-$before === 100, 'One compatible query per folder');
check($GLOBALS['maxParallel'] === 4, 'Concurrency bounded at four');
foreach (array_slice($requests,$before) as $url) {
    parse_str(parse_url($url,PHP_URL_QUERY),$query);
    check(!str_contains($query['q'],' or ') && substr_count($query['q'],'in parents') === 1, 'Never use a multi-parent query');
}
$responses = [
    ['status'=>200,'body'=>json_encode(['files'=>[], 'nextPageToken'=>'more'])],
    ['status'=>200,'body'=>json_encode(['files'=>[]])],
    ['status'=>200,'body'=>json_encode(['files'=>[], 'nextPageToken'=>'more'])],
    ['status'=>200,'body'=>json_encode(['files'=>[['id'=>'data-file','name'=>'tdr.txt','size'=>'123']]])]
];
$metadata = pwg_drive_bulk_entries(array_slice($folders,0,2), $config);
check(count($metadata[$folders[0]]) === 1 && $metadata[$folders[1]] === [], 'Large listings reuse pagination and stay mapped to correct folder');
$before = count($requests);
$responses = [['status'=>200,'body'=>'waveform bytes']];
$download = pwg_drive_measurement_files($folders[0], ['tdr.txt'], $config, $metadata[$folders[0]]);
check($download['tdr.txt'] === 'waveform bytes' && count($requests)-$before === 1, 'Reuse metadata without another folder listing');
$responses = [['status'=>503], ['status'=>200,'body'=>json_encode(['files'=>[]])]];
check(pwg_drive_bulk_entries([$folders[0]],$config)[$folders[0]] === [], 'Failed concurrent request recovers with normal transport');
$responses = [['status'=>200,'body'=>'{}']];
check(pwg_drive_bulk_entries([$folders[0]],$config)[$folders[0]] === [], 'Omitted empty files array is accepted');
echo "Drive pagination, retry and diagnostic tests passed.\n";
