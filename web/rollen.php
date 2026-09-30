<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/lib.php';

$admin = soteria_require_admin_session();
$pdo = soteria_pdo();
$adminName = trim((string) ($admin['name'] ?? $admin['email'] ?? 'beheerder'));
$error = '';
$savedEmail = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save_roles') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $roles = $_POST['roles'] ?? [];
        if (!is_array($roles)) {
            $roles = [];
        }
        try {
            if ($name !== '') {
                soteria_upsert_user($pdo, $email, $name);
            }
            soteria_set_user_roles($pdo, $email, $roles);
            $savedEmail = $email;
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
    } else {
        $error = 'Onbekende actie.';
    }
}

$assigned = soteria_assigned_roles_by_email($pdo);
$directory = soteria_directory_users($pdo);
$roleIds = soteria_role_ids();
$roleLabels = soteria_role_labels();
$effectiveCounts = array_fill_keys($roleIds, 0);
foreach (soteria_roles_by_email($pdo) as $roles) {
    foreach ($roles as $role) {
        if (isset($effectiveCounts[$role])) {
            $effectiveCounts[$role]++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Soteria — rollen</title>
    <style>
        :root { --bg: #f4f7fb; --panel: #fff; --text: #10233f; --muted: #5b6b82; --accent: #0b65c2; --ok: #067647; --danger: #b42318; --shadow: 0 16px 40px rgba(15, 35, 63, 0.08); }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; background: var(--bg); color: var(--text); }
        header { padding: 20px 24px; background: var(--panel); box-shadow: var(--shadow); display: flex; justify-content: space-between; align-items: center; gap: 16px; }
        h1 { margin: 0; font-size: 22px; }
        a.nav { color: var(--accent); font-weight: 700; text-decoration: none; }
        .muted { color: var(--muted); }
        main { padding: 20px 24px 40px; }
        .banner { background: #ecfdf3; color: var(--ok); padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; }
        .error { background: #fef3f2; color: var(--danger); padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; }
        .hint { max-width: 820px; line-height: 1.45; }
        .toolbar { display: flex; gap: 12px; align-items: center; margin: 16px 0; flex-wrap: wrap; }
        input[type="search"] { padding: 8px 10px; border: 1px solid #d8e0eb; border-radius: 8px; min-width: 240px; font-size: 15px; }
        table { width: 100%; border-collapse: collapse; background: var(--panel); box-shadow: var(--shadow); }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #d8e0eb; vertical-align: middle; }
        th { font-size: 14px; }
        td.center, th.center { text-align: center; }
        button { border: 0; background: var(--accent); color: #fff; padding: 8px 12px; border-radius: 8px; cursor: pointer; }
        .counts { font-size: 14px; }
        @media (max-width: 860px) {
            table { display: block; overflow-x: auto; }
            header { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>
    <header>
        <div>
            <h1>Soteria rollen</h1>
            <div class="muted">Ingelogd als <?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <a class="nav" href="index.php">Verzamelpunten</a>
    </header>
    <main>
        <?php if ($savedEmail !== ''): ?>
            <div class="banner">Rollen opgeslagen voor <?= htmlspecialchars($savedEmail, ENT_QUOTES, 'UTF-8') ?>.</div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <p class="hint">Wijs per persoon een of meer rollen toe. Een oproep gaat altijd naar het verzamelpunt en bereikt alleen aanwezige mensen met de gekozen rol. <strong>EHBO telt automatisch ook als BHV</strong> bij oproepen en aanwezigheid.</p>
        <p class="counts muted"><?= htmlspecialchars(soteria_role_summary($effectiveCounts), ENT_QUOTES, 'UTF-8') ?> (effectief, inclusief EHBO als BHV)</p>
        <div class="toolbar">
            <label for="filter">Zoeken</label>
            <input id="filter" type="search" placeholder="Naam of e-mail">
        </div>
        <table>
            <thead>
                <tr>
                    <th>Naam</th>
                    <th>E-mail</th>
                    <?php foreach ($roleIds as $roleId): ?>
                        <th class="center"><?= htmlspecialchars($roleLabels[$roleId], ENT_QUOTES, 'UTF-8') ?></th>
                    <?php endforeach; ?>
                    <th></th>
                </tr>
            </thead>
            <tbody id="people">
                <?php if ($directory === []): ?>
                    <tr>
                        <td colspan="<?= 3 + count($roleIds) ?>">Geen gebruikers gevonden. Controleer de Graph-koppeling in auth.php.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($directory as $index => $person): ?>
                    <?php
                    $email = (string) $person['email'];
                    $roles = $assigned[$email] ?? [];
                    $formId = 'roles-' . (int) $index;
                    ?>
                    <tr data-search="<?= htmlspecialchars(strtolower($person['name'] . ' ' . $email), ENT_QUOTES, 'UTF-8') ?>">
                        <td><?= htmlspecialchars((string) $person['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></td>
                        <?php foreach ($roleIds as $roleId): ?>
                            <td class="center">
                                <input form="<?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?>" type="checkbox" name="roles[]" value="<?= htmlspecialchars($roleId, ENT_QUOTES, 'UTF-8') ?>" <?= in_array($roleId, $roles, true) ? 'checked' : '' ?>>
                            </td>
                        <?php endforeach; ?>
                        <td><button type="submit" form="<?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?>">Opslaan</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php foreach ($directory as $index => $person): ?>
            <?php $formId = 'roles-' . (int) $index; ?>
            <form id="<?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?>" method="post">
                <input type="hidden" name="action" value="save_roles">
                <input type="hidden" name="email" value="<?= htmlspecialchars((string) $person['email'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="name" value="<?= htmlspecialchars((string) $person['name'], ENT_QUOTES, 'UTF-8') ?>">
            </form>
        <?php endforeach; ?>
    </main>
    <script>
        const filter = document.getElementById('filter');
        filter.addEventListener('input', () => {
            const query = filter.value.trim().toLowerCase();
            document.querySelectorAll('#people tr[data-search]').forEach((row) => {
                const haystack = row.getAttribute('data-search') || '';
                row.hidden = query !== '' && !haystack.includes(query);
            });
        });
    </script>
</body>
</html>
