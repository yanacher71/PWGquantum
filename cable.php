<?php
declare(strict_types=1);

$configFile = dirname(__DIR__) . '/pwg-db-config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configuration error.');
}
$config = require $configFile;

$serial = strtoupper(trim($_GET['id'] ?? ''));
if ($serial === '' || !preg_match('/^[A-Z0-9_-]{1,32}$/', $serial)) {
    http_response_code(400);
    exit('Invalid cable serial number.');
}

try {
    $pdo = new PDO(
        'mysql:host=' . $config['host'] . ';dbname=' . $config['database'] . ';charset=utf8mb4',
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    $stmt = $pdo->prepare(
        'SELECT serial_number, model_number, cable_family, length_mm,
                outer_diameter_mm, connector_a, connector_b, manufacture_date,
                lot_number, status, document_url, notes
         FROM pwg_cables WHERE serial_number = ? LIMIT 1'
    );
    $stmt->execute([$serial]);
    $cable = $stmt->fetch();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Database connection error.');
}

if (!$cable) {
    http_response_code(404);
    exit('Cable record not found.');
}

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
function valueOrDash($value): string {
    return ($value === null || $value === '') ? '&mdash;' : h($value);
}

$documentUrl = null;
if (!empty($cable['document_url'])) {
    $candidate = filter_var($cable['document_url'], FILTER_VALIDATE_URL);
    if ($candidate && strtolower((string)parse_url($candidate, PHP_URL_SCHEME)) === 'https') {
        $documentUrl = $candidate;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($cable['serial_number']) ?> | PWG Quantum</title>
  <meta name="robots" content="noindex">
  <link rel="stylesheet" href="styles.css">
  <style>
    .cable-page { padding: calc(var(--header-height) + 64px) 0 80px; min-height: 70vh; }
    .cable-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-sm); padding: 32px; }
    .cable-kicker { font-family: var(--font-mono); color: var(--color-accent); font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 8px; }
    .cable-model { color: var(--color-ink-secondary); margin: 8px 0 28px; }
    .cable-actions { margin-top: 28px; display: flex; gap: 12px; flex-wrap: wrap; }
    .cable-notes { margin-top: 28px; padding-top: 24px; border-top: 1px solid var(--color-border); }
  </style>
</head>
<body>
<header class="site-header" id="site-header">
  <div class="header-inner">
    <a href="index.html" class="logo" aria-label="PWG Quantum home">
      <span class="logo-mark"><img src="logo.gif" alt="PWG Quantum" width="32" height="32"></span>
      <span class="logo-text"><span class="logo-title">PWG Quantum</span><span class="logo-sub">Phononic Waveguides</span></span>
    </a>
    <div class="header-actions"><a href="index.html" class="btn btn-secondary btn-small">Main Site</a></div>
  </div>
</header>
<main class="cable-page">
  <div class="section-inner">
    <div class="section-head">
      <div class="cable-kicker">Cable Record</div>
      <h1><?= h($cable['serial_number']) ?></h1>
      <p class="cable-model"><?= h($cable['model_number']) ?></p>
    </div>
    <div class="cable-card">
      <div class="table-scroll">
        <table class="data-table spec-table"><tbody>
          <tr><th>Serial Number</th><td class="mono"><?= h($cable['serial_number']) ?></td></tr>
          <tr><th>Model Number</th><td class="mono"><?= h($cable['model_number']) ?></td></tr>
          <tr><th>Cable Family</th><td><?= valueOrDash($cable['cable_family']) ?></td></tr>
          <tr><th>Length</th><td><?= $cable['length_mm'] !== null ? h($cable['length_mm']) . ' mm' : '&mdash;' ?></td></tr>
          <tr><th>Outer Diameter</th><td><?= $cable['outer_diameter_mm'] !== null ? h($cable['outer_diameter_mm']) . ' mm' : '&mdash;' ?></td></tr>
          <tr><th>Connector A</th><td><?= valueOrDash($cable['connector_a']) ?></td></tr>
          <tr><th>Connector B</th><td><?= valueOrDash($cable['connector_b']) ?></td></tr>
          <tr><th>Manufacture Date</th><td><?= valueOrDash($cable['manufacture_date']) ?></td></tr>
          <tr><th>Lot Number</th><td><?= valueOrDash($cable['lot_number']) ?></td></tr>
          <tr><th>Status</th><td><?= valueOrDash($cable['status']) ?></td></tr>
        </tbody></table>
      </div>
      <?php if (!empty($cable['notes'])): ?>
        <div class="cable-notes"><h3>Notes</h3><p><?= nl2br(h($cable['notes'])) ?></p></div>
      <?php endif; ?>
      <div class="cable-actions">
        <?php if ($documentUrl): ?><a class="btn btn-primary" href="<?= h($documentUrl) ?>" target="_blank" rel="noopener noreferrer">View Test Report</a><?php endif; ?>
        <a class="btn btn-secondary" href="index.html">PWG Quantum</a>
      </div>
    </div>
  </div>
</main>
<footer class="site-footer">
  <div class="section-inner footer-inner">
    <div class="footer-brand"><img src="logo.gif" alt="" width="28" height="28" class="footer-logo"><div class="footer-text"><strong>PWG Quantum</strong><span>Phononic Waveguides</span></div></div>
    <div class="footer-copy">&copy; <?= date('Y') ?> Phononic Waveguides. Cable records and qualification documentation.</div>
  </div>
</footer>
</body>
</html>