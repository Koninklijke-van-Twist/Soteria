<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/lib.php';

$email = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
$sessionName = trim((string) ($_SESSION['user']['name'] ?? ''));
if ($email === '') {
    http_response_code(401);
    echo 'Inloggen is mislukt.';
    exit;
}

$pdo = soteria_pdo();
$displayName = soteria_upsert_user($pdo, $email, $sessionName !== '' ? $sessionName : null);
$token = soteria_issue_token($pdo, $email);
$redirect = 'nl.kvt.soteria://auth?token=' . rawurlencode($token);
$intentUrl = 'intent://auth?token=' . rawurlencode($token) . '#Intent;scheme=nl.kvt.soteria;package=nl.kvt.soteria;end';

header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Soteria koppelen</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; background: #10233f; color: #fff; display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; }
        .card { background: #fff; color: #10233f; padding: 28px; border-radius: 16px; max-width: 420px; text-align: center; }
        a.button { display: inline-block; margin-top: 16px; background: #b42318; color: #fff; text-decoration: none; padding: 14px 20px; border-radius: 10px; font-weight: 700; }
    </style>
    <meta http-equiv="refresh" content="0;url=<?= htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
    <div class="card">
        <h1>Soteria</h1>
        <p>Ingelogd als <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>.</p>
        <p>Tik op de knop als de app niet vanzelf opent.</p>
        <a class="button" href="<?= htmlspecialchars($intentUrl, ENT_QUOTES, 'UTF-8') ?>">Open Soteria</a>
    </div>
    <script>
        window.location.replace(<?= json_encode($redirect, JSON_UNESCAPED_SLASHES) ?>);
    </script>
</body>
</html>
