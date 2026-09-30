<?php

/**
 * Rollen, oproeptype, aanwezigheid per rol en locaties onder een verzamelpunt.
 * Draait zonder de server-auth.php; maakt die tijdelijk aan als hij ontbreekt.
 */

$root = dirname(__DIR__);
$authPath = $root . '/auth.php';
$createdAuth = false;
if (!is_file($authPath)) {
    file_put_contents(
        $authPath,
        "<?php\n\$adminUsers = ['admin@example.com'];\n"
        . "\$graphCredentials = ['tenantId' => '', 'clientId' => '', 'clientSecret' => ''];\n"
        . "\$mapDefaultCenter = ['lat' => 51.87, 'lng' => 4.60, 'zoom' => 16];\n"
    );
    $createdAuth = true;
}

$dbPath = sys_get_temp_dir() . '/soteria-rollen-' . getmypid() . '.sqlite';
putenv('SOTERIA_DB_PATH=' . $dbPath);

register_shutdown_function(static function () use ($authPath, $createdAuth, $dbPath): void {
    if ($createdAuth && is_file($authPath)) {
        unlink($authPath);
    }
    foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
});

require_once $root . '/lib.php';

$failures = 0;

function check(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        echo "ok  $message\n";
        return;
    }
    $failures++;
    fwrite(STDERR, "FAIL $message\n");
}

function seed_user(PDO $pdo, string $email, string $name, float $lat, float $lng): void
{
    soteria_upsert_user($pdo, $email, $name);
    $pdo->prepare(
        'UPDATE users SET last_lat = :lat, last_lng = :lng, last_seen = :seen WHERE email = :email'
    )->execute([
        ':lat' => $lat,
        ':lng' => $lng,
        ':seen' => time(),
        ':email' => $email,
    ]);
}

$pdo = soteria_pdo();

soteria_set_user_roles($pdo, 'ehbo@example.com', ['ehbo']);
$ehboRoles = soteria_roles_by_email($pdo)['ehbo@example.com'];
check(in_array('ehbo', $ehboRoles, true) && in_array('bhv', $ehboRoles, true), 'EHBO impliceert BHV');
check(!in_array('bhv', soteria_assigned_roles_by_email($pdo)['ehbo@example.com'], true), 'EHBO slaat BHV niet dubbel op');
check(soteria_user_matches_call($ehboRoles, 'bhv'), 'EHBO ontvangt BHV-oproep');
check(soteria_user_matches_call($ehboRoles, 'ehbo'), 'EHBO ontvangt EHBO-oproep');
check(!soteria_user_matches_call($ehboRoles, 'ontruimer'), 'EHBO ontvangt geen ontruimer-oproep');
check(!soteria_user_matches_call($ehboRoles, 'ploegleider'), 'EHBO ontvangt geen ploegleider-oproep');

soteria_set_user_roles($pdo, 'bhv@example.com', ['bhv']);
$bhvRoles = soteria_roles_by_email($pdo)['bhv@example.com'];
check(soteria_user_matches_call($bhvRoles, 'bhv'), 'BHV ontvangt BHV-oproep');
check(!soteria_user_matches_call($bhvRoles, 'ehbo'), 'BHV ontvangt geen EHBO-oproep');
check(!soteria_user_matches_call($bhvRoles, 'ontruimer'), 'BHV ontvangt geen ontruimer-oproep');

soteria_set_user_roles($pdo, 'ontr@example.com', ['ontruimer']);
$ontrRoles = soteria_roles_by_email($pdo)['ontr@example.com'];
check(soteria_user_matches_call($ontrRoles, 'bhv') && soteria_user_matches_call($ontrRoles, 'ontruimer'), 'ontruimer ontvangt BHV en ontruimer');
check(!soteria_user_matches_call($ontrRoles, 'ehbo'), 'ontruimer ontvangt geen EHBO-oproep');

soteria_set_user_roles($pdo, 'pl@example.com', ['ploegleider']);
$plRoles = soteria_roles_by_email($pdo)['pl@example.com'];
foreach (soteria_call_types() as $type) {
    check(soteria_user_matches_call($plRoles, $type), "ploegleider ontvangt $type");
}
check(!soteria_is_call_type('assembly') && !soteria_is_call_type('caller'), 'oude oproeptypes zijn gesloten');

$invalid = false;
try {
    soteria_set_user_roles($pdo, 'ehbo@example.com', ['baas']);
} catch (InvalidArgumentException) {
    $invalid = true;
}
check($invalid, 'onbekende rol wordt geweigerd');
check(soteria_assigned_roles_by_email($pdo)['ehbo@example.com'] === ['ehbo'], 'mislukte rolwijziging laat EHBO staan');

$lat = 51.8708;
$lng = 4.6025;
$pdo->prepare('INSERT INTO locations (name, lat, lng, created_at) VALUES (:name, :lat, :lng, :created_at)')
    ->execute([':name' => 'Hal', ':lat' => $lat, ':lng' => $lng, ':created_at' => time()]);
$locationId = (int) $pdo->lastInsertId();
$placeId = soteria_create_place($pdo, $locationId, 'Kantine');
$otherPlace = soteria_create_place($pdo, $locationId, 'Parkeerplaats');

$pdo->prepare('INSERT INTO locations (name, lat, lng, created_at) VALUES (:name, :lat, :lng, :created_at)')
    ->execute([':name' => 'Ver weg', ':lat' => 52.37, ':lng' => 4.89, ':created_at' => time()]);
$farLocationId = (int) $pdo->lastInsertId();
$farPlace = soteria_create_place($pdo, $farLocationId, 'Magazijn');

seed_user($pdo, 'caller@example.com', 'Oproeper', $lat, $lng);
seed_user($pdo, 'ehbo@example.com', 'Eva EHBO', $lat, $lng);
seed_user($pdo, 'bhv@example.com', 'Ben BHV', $lat, $lng);
seed_user($pdo, 'ontr@example.com', 'Otto Ontruimer', $lat, $lng);
seed_user($pdo, 'pl@example.com', 'Piet Ploegleider', $lat, $lng);
seed_user($pdo, 'none@example.com', 'Zonder rol', $lat, $lng);
seed_user($pdo, 'far@example.com', 'Ver BHV', 52.37, 4.89);
soteria_set_user_roles($pdo, 'far@example.com', ['bhv']);

$present = soteria_locations_with_presence($pdo);
$hal = null;
foreach ($present as $location) {
    if ((int) $location['id'] === $locationId) {
        $hal = $location;
    }
}
check(is_array($hal), 'verzamelpunt zit in aanwezigheid');
check(($hal['by_role']['bhv'] ?? 0) === 2, 'BHV-telling op Hal telt BHV en EHBO, niet iemand bij een ander verzamelpunt');
check(($hal['by_role']['ehbo'] ?? 0) === 1, 'EHBO-telling');
check(($hal['by_role']['ploegleider'] ?? 0) === 1, 'ploegleider-telling');
check(($hal['by_role']['ontruimer'] ?? 0) === 1, 'ontruimer-telling');
check(count($hal['places']) === 2, 'locaties hangen onder het verzamelpunt');
$eva = null;
foreach ($hal['people'] as $person) {
    if ($person['email'] === 'ehbo@example.com') {
        $eva = $person;
    }
}
check(is_array($eva) && in_array('bhv', $eva['roles'], true), 'aanwezige EHBO toont ook BHV');

$bhvCall = soteria_create_alert($pdo, 'caller@example.com', 'Oproeper', 'bhv', $lat, $lng);
check(!empty($bhvCall['ok']), 'BHV-oproep lukt');
$expected = ['bhv@example.com', 'ehbo@example.com', 'far@example.com', 'ontr@example.com', 'pl@example.com'];
$actual = soteria_select_alert_recipients($pdo, 'bhv', 'caller@example.com');
sort($actual);
check($actual === $expected, 'BHV-oproep bereikt BHV, EHBO, ontruimer en ploegleider, ook op een ander verzamelpunt');
check((int) ($bhvCall['recipient_count'] ?? -1) === 5, 'ontvanger-aantal klopt');
check(($bhvCall['dest_name'] ?? '') === 'Hal', 'bestemming is het verzamelpunt');

$alertId = (int) $bhvCall['alert_id'];
$updated = soteria_update_alert_sub_location($pdo, $alertId, 'caller@example.com', $placeId);
check(!empty($updated['ok']), 'oproeper zet de locatie');
check(($updated['alert']['sub_location_name'] ?? '') === 'Kantine', 'locatienaam is Kantine');
check(count($updated['alert']['places'] ?? []) === 2, 'status toont de locaties van het verzamelpunt');

$wrong = soteria_update_alert_sub_location($pdo, $alertId, 'caller@example.com', $farPlace);
check(empty($wrong['ok']), 'locatie van een ander verzamelpunt wordt geweigerd');
$stranger = soteria_update_alert_sub_location($pdo, $alertId, 'ehbo@example.com', $otherPlace);
check(empty($stranger['ok']), 'alleen de oproeper wijzigt de locatie');

$pending = soteria_pending_alert($pdo, 'ehbo@example.com');
check(is_array($pending) && str_contains((string) $pending['message'], 'Kantine'), 'oproeptekst noemt de gekozen locatie');
check(is_array($pending) && str_contains((string) $pending['message'], 'BHV'), 'oproeptekst noemt BHV');

$pdo->prepare('INSERT INTO alert_acks (alert_id, email, acked_at) VALUES (:id, :email, :acked)')
    ->execute([':id' => $alertId, ':email' => 'pl@example.com', ':acked' => time()]);
$acked = soteria_active_acknowledged_alerts($pdo, 'pl@example.com');
check(count($acked) === 1 && ($acked[0]['sub_location_name'] ?? '') === 'Kantine', 'bevestigde oproep toont de locatie');

$again = soteria_create_alert($pdo, 'caller@example.com', 'Oproeper', 'ehbo', $lat, $lng);
check(!empty($again['existing']), 'tweede oproep heropent de actieve oproep');

$pdo->prepare('UPDATE alerts SET active = 0, cancelled_at = :now WHERE id = :id')
    ->execute([':now' => time(), ':id' => $alertId]);

$ehboCall = soteria_create_alert($pdo, 'caller@example.com', 'Oproeper', 'ehbo', $lat, $lng);
$ehboRecipients = soteria_select_alert_recipients($pdo, 'ehbo', 'caller@example.com');
sort($ehboRecipients);
check($ehboRecipients === ['ehbo@example.com', 'pl@example.com'], 'EHBO-oproep bereikt EHBO en ploegleider');
check((int) ($ehboCall['recipient_count'] ?? -1) === 2, 'EHBO-ontvanger-aantal');

$pdo->prepare('UPDATE alerts SET active = 0, cancelled_at = :now WHERE caller_email = :email AND active = 1')
    ->execute([':now' => time(), ':email' => 'caller@example.com']);
$plCall = soteria_create_alert($pdo, 'caller@example.com', 'Oproeper', 'ploegleider', $lat, $lng);
$plRecipients = soteria_select_alert_recipients($pdo, 'ploegleider', 'caller@example.com');
check($plRecipients === ['pl@example.com'], 'ploegleider-oproep bereikt alleen ploegleiders');
check((int) ($plCall['recipient_count'] ?? -1) === 1, 'ploegleider-ontvanger-aantal');

$pdo->prepare('UPDATE alerts SET active = 0, cancelled_at = :now WHERE caller_email = :email AND active = 1')
    ->execute([':now' => time(), ':email' => 'caller@example.com']);
$ontrCall = soteria_create_alert($pdo, 'caller@example.com', 'Oproeper', 'ontruimer', $lat, $lng);
$ontrRecipients = soteria_select_alert_recipients($pdo, 'ontruimer', 'caller@example.com');
sort($ontrRecipients);
check($ontrRecipients === ['ontr@example.com', 'pl@example.com'], 'ontruimer-oproep bereikt ontruimer en ploegleider');
check((int) ($ontrCall['recipient_count'] ?? -1) === 2, 'ontruimer-ontvanger-aantal');

$old = soteria_create_alert($pdo, 'none@example.com', 'Zonder rol', 'assembly', $lat, $lng);
check(empty($old['ok']) && ($old['status'] ?? 0) === 400, 'oproep naar verzamelpunt/locatie is niet meer geldig');
$oldCaller = soteria_create_alert($pdo, 'none@example.com', 'Zonder rol', 'caller', $lat, $lng);
check(empty($oldCaller['ok']), 'oproep naar mijn locatie is niet meer geldig');

$token = soteria_issue_token($pdo, 'caller@example.com');
$pdo->prepare('UPDATE alerts SET active = 0, cancelled_at = :now WHERE caller_email = :email AND active = 1')
    ->execute([':now' => time(), ':email' => 'caller@example.com']);

$port = 8765;
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root],
    [1 => ['file', sys_get_temp_dir() . '/soteria-php-server.log', 'w'], 2 => ['file', sys_get_temp_dir() . '/soteria-php-server.err', 'w']],
    $pipes,
    $root,
    null
);
check(is_resource($server), 'PHP-server start');

$deadline = microtime(true) + 5;
$up = false;
while (microtime(true) < $deadline) {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/rollen.php');
    if ($probe !== false) {
        $up = true;
        break;
    }
    usleep(100000);
}
check($up, 'API antwoordt');
if (!is_resource($server) || !$up) {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    fwrite(STDERR, "PHP-server op poort $port is niet gestart\n");
    exit(1);
}

function http_json(string $url, string $token, array $payload): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer $token\r\n",
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'ignore_errors' => true,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    $decoded = json_decode((string) $body, true);
    return is_array($decoded) ? $decoded : [];
}

$base = 'http://127.0.0.1:' . $port;
$created = http_json($base . '/api.php', $token, [
    'action' => 'create_alert',
    'type' => 'ploegleider',
    'lat' => $lat,
    'lng' => $lng,
]);
check(!empty($created['ok']) && (int) ($created['recipient_count'] ?? 0) === 1, 'HTTP maakt een ploegleider-oproep');
$rejected = http_json($base . '/api.php', $token, ['action' => 'create_alert', 'type' => 'caller', 'lat' => $lat, 'lng' => $lng]);
check(empty($rejected['ok']), 'HTTP weigert het oude oproeptype');

$index = @file_get_contents($base . '/index.php');
check(is_string($index) && str_contains($index, 'Locatie toevoegen') && str_contains($index, 'Rollen toewijzen'), 'beheerpagina toont locaties en rollen');
$rolesPage = @file_get_contents($base . '/rollen.php');
check(is_string($rolesPage) && str_contains($rolesPage, 'EHBO telt automatisch ook als BHV'), 'rollenpagina legt EHBO uit');

$save = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query([
            'action' => 'save_roles',
            'email' => 'nieuw@example.com',
            'name' => 'Nieuw Persoon',
            'roles' => ['ontruimer', 'ploegleider'],
        ]),
        'ignore_errors' => true,
    ],
]);
$saved = file_get_contents($base . '/rollen.php', false, $save);
check(is_string($saved) && str_contains($saved, 'nieuw@example.com'), 'rollen toewijzen via de website');
$stored = soteria_assigned_roles_by_email($pdo)['nieuw@example.com'] ?? [];
check($stored === ['ploegleider', 'ontruimer'], 'website slaat ploegleider en ontruimer op');

soteria_rename_place($pdo, $placeId, 'Kantine nieuw');
$renamed = soteria_places($pdo, $locationId);
$found = false;
foreach ($renamed as $place) {
    if ((int) $place['id'] === $placeId && $place['name'] === 'Kantine nieuw') {
        $found = true;
    }
}
check($found, 'locatie hernoemen');
soteria_delete_location($pdo, $locationId);
check(soteria_places($pdo, $locationId) === [], 'verwijderen van verzamelpunt ruimt locaties op');

if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}

if ($failures > 0) {
    fwrite(STDERR, "$failures test(s) gefaald\n");
    exit(1);
}

echo "Alle tests geslaagd\n";
exit(0);
