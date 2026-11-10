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
        if ($status === 403 && ($query['alt'] ?? '') === 'media'
            && empty($config['google_drive_access_token']) && !empty($config['google_drive_api_key'])
            && str_starts_with($path, 'files/')) {
            return pwg_drive_public_download(rawurldecode(substr($path, 6)), $context);
        }
        $details = 'HTTP '.$status.($reason !== '' ? ', '.$reason : '');
        if ($networkError !== 0) $details .= ', network error '.$networkError;
        throw new RuntimeException($context.' failed ('.$details.'). Check folder sharing and server credentials, or retry if the service is temporarily unavailable.');
    }
    throw new RuntimeException($context.' failed.');
}

/** Public-file fallback carries neither an API key nor an OAuth token. */
function pwg_drive_public_download(string $fileId, string $context): string {
    if (!preg_match('/\\A[A-Za-z0-9_-]{1,128}\\z/D', $fileId)) throw new RuntimeException('Invalid Drive file ID.');
    $handle = curl_init('https://drive.google.com/uc?'.http_build_query(['export'=>'download', 'id'=>$fileId]));
    $body = ''; $tooLarge = false;
    curl_setopt_array($handle, [
        CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>30, CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_MAXREDIRS=>3, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > 4 * 1024 * 1024) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        }
    ]);
    $ok = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
    curl_close($handle);
    if ($tooLarge) throw new RuntimeException($context.': public download exceeds 4 MB.');
    if ($ok === false || $status !== 200 || stripos($contentType, 'text/html') !== false
        || preg_match('/^\\s*<(?:!doctype\\s+html|html)\\b/i', $body)) {
        throw new RuntimeException($context.' failed through both the Drive API (HTTP 403) and public download (HTTP '.$status.'). Confirm this file allows public downloads.');
    }
    return $body;
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

function pwg_drive_measurement_files(string $folder, array $names, array $config, ?array $entries = null): array {
    $files = [];
    foreach ($entries ?? pwg_drive_folder_entries($folder, $config) as $file) {
        if (!in_array($file['name'], $names, true)) continue;
        if (array_key_exists($file['name'], $files)) throw new RuntimeException('Duplicate filenames in Drive folder.');
        if (($file['mimeType'] ?? '') === 'application/vnd.google-apps.folder' || (int)($file['size'] ?? 0) > 4 * 1024 * 1024) throw new RuntimeException('Invalid measurement file or file exceeds 4 MB.');
        $files[$file['name']] = (int)($file['size'] ?? 0) === 0 ? '' : pwg_drive_request('files/'.rawurlencode($file['id']), ['alt'=>'media'], $config, 'Download '.$file['name'].' from folder '.$folder);
    }
    return $files;
}

/** Use the proven single-parent query, with at most four folders in flight. */
function pwg_drive_bulk_entries(array $folders, array $config): array {
    $result = [];
    foreach ($folders as $folder) $result[pwg_drive_folder_id($folder)] = [];
    if (!$result) return [];
    if (!function_exists('curl_multi_init')) {
        foreach (array_keys($result) as $folder) $result[$folder] = pwg_drive_folder_entries($folder, $config);
        return $result;
    }
    $headers = [];
    if (!empty($config['google_drive_access_token'])) $headers[] = 'Authorization: Bearer '.$config['google_drive_access_token'];
    elseif (empty($config['google_drive_api_key'])) throw new RuntimeException('Configure Drive credentials before importing.');
    foreach (array_chunk(array_keys($result), 4) as $batch) {
        $multi = curl_multi_init();
        $handles = []; $bodies = []; $responses = [];
        try {
            foreach ($batch as $folder) {
                $query = ['q'=>"'".$folder."' in parents and trashed = false",
                    'fields'=>'nextPageToken,files(id,name,size,mimeType)', 'pageSize'=>100];
                if (!$headers) $query['key'] = $config['google_drive_api_key'];
                $bodies[$folder] = '';
                $handle = curl_init('https://www.googleapis.com/drive/v3/files?'.http_build_query($query));
                curl_setopt_array($handle, [CURLOPT_HTTPHEADER=>$headers, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>30, CURLOPT_FOLLOWLOCATION=>false,
                    CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$bodies, $folder): int {
                        if (strlen($bodies[$folder]) + strlen($chunk) > 4 * 1024 * 1024) return 0;
                        $bodies[$folder] .= $chunk;
                        return strlen($chunk);
                    }]);
                $handles[$folder] = $handle;
                curl_multi_add_handle($multi, $handle);
            }
            do {
                $status = curl_multi_exec($multi, $running);
                if ($status !== CURLM_OK) throw new RuntimeException('Parallel Drive folder check failed. Retry the import.');
                if ($running > 0) {
                    if (curl_multi_select($multi, 1.0) === -1) usleep(10000);
                }
            } while ($running > 0);
            foreach ($handles as $folder=>$handle) {
                if (curl_errno($handle) === 0 && (int)curl_getinfo($handle, CURLINFO_HTTP_CODE) === 200) {
                    $response = json_decode($bodies[$folder], true);
                    if (is_array($response) && !isset($response['error']) && is_array($response['files'] ?? [])) $responses[$folder] = $response;
                }
            }
        } finally {
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }
        foreach ($batch as $folder) {
            $response = $responses[$folder] ?? null;
            if ($response === null || !empty($response['nextPageToken'])) {
                // Reuse the existing paginated, retried transport for failed or larger listings.
                $result[$folder] = pwg_drive_folder_entries($folder, $config);
            } else {
                $result[$folder] = $response['files'] ?? [];
            }
        }
    }
    return $result;
}
