<?php
declare(strict_types=1);

function pwg_drive_request(string $path, array $query, array $config): string {
    if (!function_exists('curl_init')) throw new RuntimeException('Drive import requires PHP cURL.');
    $headers = [];
    if (!empty($config['google_drive_access_token'])) $headers[] = 'Authorization: Bearer '.$config['google_drive_access_token'];
    elseif (!empty($config['google_drive_api_key'])) $query['key'] = $config['google_drive_api_key'];
    else throw new RuntimeException('Configure google_drive_api_key for shared public folders or google_drive_access_token for private Drive access, or upload files below.');
    $handle = curl_init('https://www.googleapis.com/drive/v3/'.$path.'?'.http_build_query($query));
    $body = '';
    curl_setopt_array($handle, [CURLOPT_HTTPHEADER=>$headers, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>30, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 4 * 1024 * 1024) return 0;
            $body .= $chunk;
            return strlen($chunk);
        }]);
    $ok = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    if ($ok === false || $status !== 200) throw new RuntimeException('Drive request failed. Check folder sharing, server credentials and file size.');
    return $body;
}

function pwg_drive_folder_id(string $value): string {
    if (preg_match('~^https://drive\.google\.com/drive/folders/([A-Za-z0-9_-]+)(?:[/?].*)?$~', $value, $match)) $value = $match[1];
    if (!preg_match('/\A[A-Za-z0-9_-]{10,128}\z/D', $value)) throw new RuntimeException('Enter a valid Drive folder ID or folder URL.');
    return $value;
}

function pwg_drive_measurement_files(string $folder, array $names, array $config): array {
    $folder = pwg_drive_folder_id($folder);
    $files = []; $page = null;
    do {
        $query = ['q'=>"'".$folder."' in parents and trashed = false", 'fields'=>'nextPageToken,files(id,name,size,mimeType)', 'pageSize'=>100];
        if ($page) $query['pageToken'] = $page;
        $response = json_decode(pwg_drive_request('files', $query, $config), true, 512, JSON_THROW_ON_ERROR);
        foreach ($response['files'] ?? [] as $file) {
            if (!in_array($file['name'], $names, true)) continue;
            if (array_key_exists($file['name'], $files)) throw new RuntimeException('Duplicate filenames in Drive folder.');
            if (($file['mimeType'] ?? '') === 'application/vnd.google-apps.folder' || (int)($file['size'] ?? 0) > 4 * 1024 * 1024) throw new RuntimeException('Invalid measurement file or file exceeds 4 MB.');
            $files[$file['name']] = (int)($file['size'] ?? 0) === 0 ? '' : pwg_drive_request('files/'.rawurlencode($file['id']), ['alt'=>'media'], $config);
        }
        $page = $response['nextPageToken'] ?? null;
    } while ($page);
    return $files;
}
