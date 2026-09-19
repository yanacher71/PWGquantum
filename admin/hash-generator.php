<?php
declare(strict_types=1);
header('Cache-Control: no-store');
$hash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    if ($password !== '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);
    }
}
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>PWG Password Hash Generator</title>
<link rel="stylesheet" href="/styles.css">
<style>
.wrap{max-width:700px;margin:100px auto;padding:32px}
input,textarea{width:100%;box-sizing:border-box;padding:12px;margin:8px 0 18px;border:1px solid #ccd5df;border-radius:6px}
textarea{min-height:110px;font-family:monospace}
.note{margin:18px 0}
</style>
</head>
<body>
<main class="wrap">
<h1>PWG Password Hash Generator</h1>
<p class="note">Temporary setup utility. Enter the admin password you want to use. The password itself is not written to disk by this page.</p>
<form method="post" autocomplete="off">
<label>Admin password
<input type="password" name="password" required autocomplete="new-password">
</label>
<button class="btn btn-primary" type="submit">Generate Hash</button>
</form>
<?php if ($hash): ?>
<h2>Password hash</h2>
<textarea readonly onclick="this.select()"><?=h($hash)?></textarea>
<p>Add it to the private <code>pwg-db-config.php</code> as <code>admin_password_hash</code>.</p>
<?php endif; ?>
</main>
</body>
</html>
