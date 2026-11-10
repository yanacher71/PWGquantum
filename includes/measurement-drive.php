<?php
declare(strict_types=1);

function pwg_drive_request(string $path, array $query, array $config, string $context = 'Drive API request'): string {
    if (!function_exists('curl_init')) throw new RuntimeException('Drive import requires PHP cURL.');
    $headers = [];
    if (!empty($config['google_drive_access_token'])) $headers[] = 'Authorization: Bearer '.$config['google_drive_access_token'];
    elseif (!empty($config['google_drive_api_key'])) $query['key'] = $config['google_drive_api_key'];
    else throw new RuntimeException('Configure google_drive_api_key for shared public folders or google_drive_access_token for private Drive access, or upload files below.');

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $handle = curl_init('https://www.googleapis.com/drive/v3/'.$path.'?'.http_build_query($query));
        $body = ''; $tooLarge = false;
        curl_setopt_array($handle, [CURLOPT_HTTPHEADER=>$headers, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>30, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > 4 * 1024 * 1024) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            }]);
        $ok = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $networkError = curl_errno($handle);
        curl_close($handle);
        if ($tooLarge) throw new RuntimeException($context.': response exceeds 4 MB.');
        if ($ok !== false && $status === 200) return $body;

        $error = json_decode($body, true);
        $reason = $error['error']['errors'][0]['reason'] ?? $error['error']['status'] ?? '';
        // Report only bounded error codes. Provider messages and request URLs can contain keys.
        if (!is_string($reason) || !preg_match('/\\A[A-Za-z][A-Za-z0-9_]{0,79}\\z/D', $reason)) $reason = '';
        $retry = in_array($status, [429,500,502,503,504], true)
            || ($status === 403 && in_array($reason, ['rateLimitExceeded','userRateLimitExceeded'], true))
            || ($ok === false && in_array($networkError, [6,7,18,28,35,52,56], true));
        if ($retry && $attempt < 2) {
            usleep(250000 * (2 ** $attempt));
            continue;
        }
        $details = 'HTTP '.$status.($reason !== '' ? ', '.$reason : '');
        if ($networkError !== 0) $details .= ', network error '.$networkError;
        throw new RuntimeException($context.' failed ('.$details.'). Check folder sharing and server credentials, or retry if the service is temporarily unavailable.');
    }
    throw new RuntimeException($context.' failed.');
}

function pwg_drive_folder_id(string $value): string {
    if (preg_match('~^https://drive\.google\.com/drive/folders/([A-Za-z0-9_-]+)(?:[/?].*)?$~', $value, $match)) $value = $match[1];
    if (!preg_match('/\A[A-Za-z0-9_-]{10,128}\z/D', $value)) throw new RuntimeException('Enter a valid Drive folder ID or folder URL.');
    return $value;
}

function pwg_drive_folder_entries(string $folder, array $config): array {
    $folder = pwg_drive_folder_id($folder);
    $files = []; $page = null; $pageNumber = 1;
    do {
        $query = ['q'=>"'".$folder."' in parents and trashed = false", 'fields'=>'nextPageToken,files(id,name,size,mimeType)', 'pageSize'=>100];
        if ($page) $query['pageToken'] = $page;
        $response = json_decode(pwg_drive_request('files', $query, $config, 'List folder '.$folder.', page '.$pageNumber), true, 512, JSON_THROW_ON_ERROR);
        foreach ($response['files'] ?? [] as $file) $files[] = $file;
        $page = $response['nextPageToken'] ?? null;
        $pageNumber++;
    } while ($page);
    return $files;
}

function pwg_drive_measurement_files(string $folder, array $names, array $config): array {
    $files = [];
    foreach (pwg_drive_folder_entries($folder, $config) as $file) {
        if (!in_array($file['name'], $names, true)) continue;
        if (array_key_exists($file['name'], $files)) throw new RuntimeException('Duplicate filenames in Drive folder.');
        if (($file['mimeType'] ?? '') === 'application/vnd.google-apps.folder' || (int)($file['size'] ?? 0) > 4 * 1024 * 1024) throw new RuntimeException('Invalid measurement file or file exceeds 4 MB.');
        $files[$file['name']] = (int)($file['size'] ?? 0) === 0 ? '' : pwg_drive_request('files/'.rawurlencode($file['id']), ['alt'=>'media'], $config, 'Download '.$file['name'].' from folder '.$folder);
    }
    return $files;
}
