<?php
declare(strict_types=1);

function pwg_private_headers(): void {
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}

function pwg_is_admin(array $config): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    $authorized = ($_SESSION['pwg_admin'] ?? false) === true
        && !empty($config['admin_password_hash']);
    session_write_close();
    return $authorized;
}

/** A supplied token is authoritative: invalid tokens never fall back to a serial. */
function pwg_cable_selector(array $query, bool $isAdmin): ?array {
    if (array_key_exists('token', $query)) {
        $token = $query['token'];
        if (!is_string($token) || !preg_match('/\A[a-f0-9]{32}\z/D', $token)) { return null; }
        return ['public_token', $token];
    }
    if (!$isAdmin || !isset($query['id']) || !is_string($query['id'])) { return null; }
    $serial = strtoupper(trim($query['id']));
    if (!preg_match('/\A[A-Z0-9_-]{1,32}\z/D', $serial)) { return null; }
    return ['serial_number', $serial];
}

function pwg_access_selector(array $query, array $config): ?array {
    return pwg_cable_selector($query, array_key_exists('token', $query) ? false : pwg_is_admin($config));
}

function pwg_public_cable_path(string $token): string {
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $token)) {
        throw new InvalidArgumentException('Cable has no valid public token.');
    }
    return '/c/' . $token;
}

function pwg_public_report_url(string $token): string {
    return 'https://pwgquantum.com' . pwg_public_cable_path($token) . '/report';
}
