<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc !== 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Usage: php backfill-cable-tokens.php /absolute/path/to/pwg-db-config.php\n"); exit(1);
}
$config = require $argv[1];
try {
    $pdo = new PDO('mysql:host='.$config['host'].';dbname='.$config['database'].';charset=utf8mb4',
        $config['username'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $count = 0; $last = 0;
    $update = $pdo->prepare('UPDATE pwg_cables SET public_token=? WHERE id=? AND public_token IS NULL');
    do {
        $page = $pdo->prepare('SELECT id FROM pwg_cables WHERE public_token IS NULL AND id>? ORDER BY id LIMIT 500');
        $page->execute([$last]);
        $ids = $page->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $last = (int)$id;
            for ($attempt = 0; ; $attempt++) {
                try {
                    $update->execute([bin2hex(random_bytes(16)), $id]);
                    $count += $update->rowCount();
                    break;
                } catch (PDOException $e) {
                    if (($e->errorInfo[1] ?? 0) !== 1062 || $attempt >= 4) { throw $e; }
                }
            }
        }
    } while ($ids);
    echo "Created tokens for $count cable records. Existing tokens were preserved.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Backfill failed; check migration and database configuration. Safe to rerun.\n"); exit(1);
}
