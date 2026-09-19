<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$configFile = dirname(__DIR__, 2) . '/pwg-db-config.php';
if (!is_file($configFile)) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'configuration']); exit; }
$config = require $configFile;

$apiKey=(string)($config['api_key']??'');
$provided=(string)($_SERVER['HTTP_X_PWG_API_KEY']??'');
if($apiKey===''||$provided===''||!hash_equals($apiKey,$provided)){
  http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit;
}
if($_SERVER['REQUEST_METHOD']!=='POST'){
  http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method']); exit;
}

$serial=strtoupper(trim((string)($_SERVER['HTTP_X_PWG_SERIAL']??'')));
if(!preg_match('/^[A-Z0-9_-]{1,32}$/',$serial)){
  http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid_serial']); exit;
}

$body=file_get_contents('php://input');
if($body===false || strlen($body)<5 || substr($body,0,5)!=='%PDF-'){
  http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid_pdf']); exit;
}
if(strlen($body)>25*1024*1024){
  http_response_code(413); echo json_encode(['ok'=>false,'error'=>'too_large']); exit;
}

$dir=dirname(__DIR__).'/reports';
if(!is_dir($dir) && !mkdir($dir,0755,true)){
  http_response_code(500); echo json_encode(['ok'=>false,'error'=>'storage']); exit;
}
$target=$dir.'/'.$serial.'.pdf';
$tmp=$target.'.tmp.'.bin2hex(random_bytes(6));
if(file_put_contents($tmp,$body,LOCK_EX)===false || !rename($tmp,$target)){
  @unlink($tmp); http_response_code(500); echo json_encode(['ok'=>false,'error'=>'write']); exit;
}

echo json_encode(['ok'=>true,'serial_number'=>$serial,'report_url'=>'https://pwgquantum.com/reports/'.rawurlencode($serial).'.pdf'],JSON_UNESCAPED_SLASHES);
