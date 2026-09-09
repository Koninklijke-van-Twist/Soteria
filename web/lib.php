<?php

require_once __DIR__ . '/auth.php';

const SOTERIA_PRESENCE_METERS = 1000;
const SOTERIA_PRESENCE_TTL_SECONDS = 180;
const SOTERIA_TOKEN_TTL_SECONDS = 2592000; // 30 dagen
// Apache blokkeert ^\.ht standaard, ook zonder werkende .htaccess in data/.
const SOTERIA_DB_PATH = __DIR__ . '/data/.ht-soteria.sqlite';
const SOTERIA_LEGACY_DB_PATH = __DIR__ . '/data/soteria.sqlite';

function soteria_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function soteria_request_json(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function soteria_pdo(): PDO
{
    $dir = dirname(SOTERIA_DB_PATH);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Data-directory kon niet worden aangemaakt.');
    }

    soteria_migrate_legacy_database();

    $pdo = new PDO('sqlite:' . SOTERIA_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            email TEXT PRIMARY KEY,
            display_name TEXT NOT NULL,
            last_lat REAL,
            last_lng REAL,
            last_seen INTEGER,
            updated_at INTEGER NOT NULL
        )'
    );
    soteria_migrate_tokens_to_hashed($pdo);
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tokens (
            token_hash TEXT PRIMARY KEY,
            email TEXT NOT NULL,
            expires_at INTEGER NOT NULL,
            created_at INTEGER NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS locations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            lat REAL NOT NULL,
            lng REAL NOT NULL,
            created_at INTEGER NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            caller_email TEXT NOT NULL,
            caller_name TEXT NOT NULL,
            type TEXT NOT NULL,
            dest_name TEXT,
            dest_lat REAL NOT NULL,
            dest_lng REAL NOT NULL,
            location_id INTEGER,
            created_at INTEGER NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            cancelled_at INTEGER
        )'
    );
    soteria_add_column_if_missing($pdo, 'alerts', 'cancelled_at', 'INTEGER');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS alert_recipients (
            alert_id INTEGER NOT NULL,
            email TEXT NOT NULL,
            PRIMARY KEY (alert_id, email)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS alert_acks (
            alert_id INTEGER NOT NULL,
            email TEXT NOT NULL,
            acked_at INTEGER NOT NULL,
            PRIMARY KEY (alert_id, email)
        )'
    );

    return $pdo;
}

function soteria_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    $columns = $pdo->query("SELECT name FROM pragma_table_info('$table')")->fetchAll(PDO::FETCH_COLUMN);
    if (is_array($columns) && !in_array($column, $columns, true)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}

/**
 * Verplaatst een database die nog op de oude, publiek benaderbare naam staat.
 */
function soteria_migrate_legacy_database(): void
{
    if (is_file(SOTERIA_DB_PATH) || !is_file(SOTERIA_LEGACY_DB_PATH)) {
        return;
    }

    if (!@rename(SOTERIA_LEGACY_DB_PATH, SOTERIA_DB_PATH)) {
        return;
    }

    foreach (['-wal', '-shm'] as $suffix) {
        $legacySidecar = SOTERIA_LEGACY_DB_PATH . $suffix;
        if (is_file($legacySidecar)) {
            @unlink($legacySidecar);
        }
    }
}

/**
 * Tokens worden alleen als hash bewaard; oudere tabellen met platte tokens vervallen.
 */
function soteria_migrate_tokens_to_hashed(PDO $pdo): void
{
    $columns = $pdo->query("SELECT name FROM pragma_table_info('tokens')")->fetchAll(PDO::FETCH_COLUMN);
    if (!is_array($columns) || $columns === []) {
        return;
    }

    if (!in_array('token_hash', $columns, true)) {
        $pdo->exec('DROP TABLE tokens');
    }
}

function soteria_hash_token(string $token): string
{
    return hash('sha256', $token);
}

function soteria_haversine_meters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earth = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $earth * asin(min(1.0, sqrt($a)));
}

function soteria_graph_users(): array
{
    try {
        $users = include __DIR__ . '/getusers_fetch.php';
        return is_array($users) ? $users : [];
    } catch (Throwable) {
        return [];
    }
}

function soteria_display_name_for_email(string $email): string
{
    $email = strtolower(trim($email));
    foreach (soteria_graph_users() as $user) {
        if (!is_array($user)) {
            continue;
        }
        $candidate = strtolower(trim((string) ($user['Email'] ?? '')));
        if ($candidate === $email) {
            $name = trim((string) ($user['Naam'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
    }

    $local = explode('@', $email)[0] ?? $email;
    return $local !== '' ? $local : $email;
}

function soteria_upsert_user(PDO $pdo, string $email, ?string $displayName = null): string
{
    $email = strtolower(trim($email));
    $name = trim((string) $displayName);
    if ($name === '') {
        $name = soteria_display_name_for_email($email);
    }

    $statement = $pdo->prepare(
        'INSERT INTO users (email, display_name, updated_at)
         VALUES (:email, :display_name, :updated_at)
         ON CONFLICT(email) DO UPDATE SET
            display_name = excluded.display_name,
            updated_at = excluded.updated_at'
    );
    $statement->execute([
        ':email' => $email,
        ':display_name' => $name,
        ':updated_at' => time(),
    ]);

    return $name;
}

function soteria_issue_token(PDO $pdo, string $email): string
{
    $email = strtolower(trim($email));
    $token = bin2hex(random_bytes(32));
    $now = time();
    $statement = $pdo->prepare(
        'INSERT INTO tokens (token_hash, email, expires_at, created_at)
         VALUES (:token_hash, :email, :expires_at, :created_at)'
    );
    $statement->execute([
        ':token_hash' => soteria_hash_token($token),
        ':email' => $email,
        ':expires_at' => $now + SOTERIA_TOKEN_TTL_SECONDS,
        ':created_at' => $now,
    ]);

    return $token;
}

/**
 * Apache geeft de Authorization-header niet altijd door, dus ook body/query accepteren.
 */
function soteria_bearer_token(array $body = []): string
{
    $headers = [
        (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
        (string) ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''),
    ];

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $headers[] = (string) $value;
            }
        }
    }

    foreach ($headers as $header) {
        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            return trim($matches[1]);
        }
    }

    $bodyToken = trim((string) ($body['token'] ?? ''));
    if ($bodyToken !== '') {
        return $bodyToken;
    }

    return trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
}

function soteria_require_api_user(PDO $pdo, array $body = []): array
{
    $token = soteria_bearer_token($body);
    if ($token === '') {
        soteria_json(['ok' => false, 'error' => 'Niet ingelogd.'], 401);
    }

    $tokenHash = soteria_hash_token($token);
    $statement = $pdo->prepare(
        'SELECT t.email, t.expires_at, u.display_name, u.last_lat, u.last_lng, u.last_seen
         FROM tokens t
         LEFT JOIN users u ON u.email = t.email
         WHERE t.token_hash = :token_hash
         LIMIT 1'
    );
    $statement->execute([':token_hash' => $tokenHash]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        soteria_json(['ok' => false, 'error' => 'Sessie ongeldig.'], 401);
    }

    if ((int) ($row['expires_at'] ?? 0) < time()) {
        soteria_json(['ok' => false, 'error' => 'Sessie verlopen.'], 401);
    }

    $touch = $pdo->prepare('UPDATE tokens SET expires_at = :expires_at WHERE token_hash = :token_hash');
    $touch->execute([
        ':expires_at' => time() + SOTERIA_TOKEN_TTL_SECONDS,
        ':token_hash' => $tokenHash,
    ]);

    return $row;
}

function soteria_locations(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, name, lat, lng FROM locations ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function soteria_present_users(PDO $pdo): array
{
    $since = time() - SOTERIA_PRESENCE_TTL_SECONDS;
    $users = $pdo->prepare(
        'SELECT email, display_name, last_lat, last_lng, last_seen
         FROM users
         WHERE last_seen >= :since AND last_lat IS NOT NULL AND last_lng IS NOT NULL'
    );
    $users->execute([':since' => $since]);
    $rows = $users->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/**
 * Actieve BHV'ers met een bekende positie, klaar om als JSON naar de kaart te gaan.
 */
function soteria_present_people(PDO $pdo): array
{
    $people = [];
    foreach (soteria_present_users($pdo) as $user) {
        $email = strtolower(trim((string) ($user['email'] ?? '')));
        $name = trim((string) ($user['display_name'] ?? ''));
        $people[] = [
            'email' => $email,
            'name' => $name !== '' ? $name : $email,
            'lat' => (float) ($user['last_lat'] ?? 0),
            'lng' => (float) ($user['last_lng'] ?? 0),
            'last_seen' => (int) ($user['last_seen'] ?? 0),
        ];
    }

    usort($people, static function (array $a, array $b): int {
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    return $people;
}

function soteria_user_is_present_at(array $user, array $location): bool
{
    $lat = (float) ($user['last_lat'] ?? 0);
    $lng = (float) ($user['last_lng'] ?? 0);
    $locLat = (float) ($location['lat'] ?? 0);
    $lngLoc = (float) ($location['lng'] ?? 0);
    return soteria_haversine_meters($lat, $lng, $locLat, $lngLoc) <= SOTERIA_PRESENCE_METERS;
}

function soteria_locations_with_presence(PDO $pdo): array
{
    $locations = soteria_locations($pdo);
    $present = soteria_present_users($pdo);
    $result = [];

    foreach ($locations as $location) {
        $people = [];
        foreach ($present as $user) {
            if (!soteria_user_is_present_at($user, $location)) {
                continue;
            }
            $people[] = [
                'email' => (string) ($user['email'] ?? ''),
                'name' => (string) ($user['display_name'] ?? ''),
            ];
        }

        usort($people, static function (array $a, array $b): int {
            return strcasecmp((string) $a['name'], (string) $b['name']);
        });

        $result[] = [
            'id' => (int) ($location['id'] ?? 0),
            'name' => (string) ($location['name'] ?? ''),
            'lat' => (float) ($location['lat'] ?? 0),
            'lng' => (float) ($location['lng'] ?? 0),
            'present_count' => count($people),
            'people' => $people,
        ];
    }

    return $result;
}

function soteria_nearest_location(PDO $pdo, float $lat, float $lng): ?array
{
    $nearest = null;
    $nearestDistance = null;
    foreach (soteria_locations($pdo) as $location) {
        $distance = soteria_haversine_meters(
            $lat,
            $lng,
            (float) $location['lat'],
            (float) $location['lng']
        );
        if ($nearestDistance === null || $distance < $nearestDistance) {
            $nearest = $location;
            $nearestDistance = $distance;
        }
    }

    return $nearest;
}

function soteria_pending_alert(PDO $pdo, string $email): ?array
{
    $statement = $pdo->prepare(
        'SELECT a.id, a.caller_email, a.caller_name, a.type, a.dest_name, a.dest_lat, a.dest_lng, a.created_at
         FROM alerts a
         INNER JOIN alert_recipients r ON r.alert_id = a.id AND r.email = :email
         LEFT JOIN alert_acks k ON k.alert_id = a.id AND k.email = :email
         WHERE a.active = 1 AND k.email IS NULL
         ORDER BY a.created_at DESC
         LIMIT 1'
    );
    $statement->execute([':email' => $email]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    $isAssembly = (string) ($row['type'] ?? '') === 'assembly';
    $destination = $isAssembly ? 'het verzamelpunt' : 'de locatie van de oproeper';
    $destLabel = trim((string) ($row['dest_name'] ?? $destination));
    $messageDestination = $isAssembly
        ? 'verzamelpunt ' . $destLabel
        : 'zijn/haar huidige locatie';

    return [
        'id' => (int) $row['id'],
        'caller_name' => (string) $row['caller_name'],
        'type' => (string) $row['type'],
        'dest_name' => $destLabel,
        'dest_lat' => (float) $row['dest_lat'],
        'dest_lng' => (float) $row['dest_lng'],
        'created_at' => (int) $row['created_at'],
        'message' => (string) $row['caller_name'] . ' roept je op naar ' . $messageDestination,
    ];
}

function soteria_active_outgoing_alert(PDO $pdo, string $email): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, caller_name, type, dest_name, dest_lat, dest_lng, created_at
         FROM alerts
         WHERE caller_email = :email AND active = 1
         ORDER BY created_at DESC
         LIMIT 1'
    );
    $statement->execute([':email' => strtolower(trim($email))]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function soteria_alert_status(PDO $pdo, int $alertId, string $callerEmail): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, caller_email, caller_name, type, dest_name, dest_lat, dest_lng,
                created_at, active, cancelled_at
         FROM alerts
         WHERE id = :id AND caller_email = :caller_email
         LIMIT 1'
    );
    $statement->execute([
        ':id' => $alertId,
        ':caller_email' => strtolower(trim($callerEmail)),
    ]);
    $alert = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($alert)) {
        return null;
    }

    $caller = $pdo->prepare('SELECT last_lat, last_lng FROM users WHERE email = :email LIMIT 1');
    $caller->execute([':email' => strtolower(trim($callerEmail))]);
    $callerPosition = $caller->fetch(PDO::FETCH_ASSOC);
    $callerLat = (float) ($callerPosition['last_lat'] ?? $alert['dest_lat'] ?? 0);
    $callerLng = (float) ($callerPosition['last_lng'] ?? $alert['dest_lng'] ?? 0);

    $recipients = $pdo->prepare(
        'SELECT r.email, u.display_name, u.last_lat, u.last_lng, u.last_seen, k.acked_at
         FROM alert_recipients r
         LEFT JOIN users u ON u.email = r.email
         LEFT JOIN alert_acks k ON k.alert_id = r.alert_id AND k.email = r.email
         WHERE r.alert_id = :alert_id
         ORDER BY COALESCE(u.display_name, r.email) COLLATE NOCASE'
    );
    $recipients->execute([':alert_id' => $alertId]);
    $people = [];
    foreach ($recipients->fetchAll(PDO::FETCH_ASSOC) as $recipient) {
        $lat = isset($recipient['last_lat']) ? (float) $recipient['last_lat'] : null;
        $lng = isset($recipient['last_lng']) ? (float) $recipient['last_lng'] : null;
        $distance = null;
        if ($lat !== null && $lng !== null && ($callerLat !== 0.0 || $callerLng !== 0.0)) {
            $distance = (int) round(soteria_haversine_meters($callerLat, $callerLng, $lat, $lng));
        }
        $people[] = [
            'email' => (string) $recipient['email'],
            'name' => trim((string) ($recipient['display_name'] ?? '')) ?: (string) $recipient['email'],
            'responded' => $recipient['acked_at'] !== null,
            'responded_at' => $recipient['acked_at'] !== null ? (int) $recipient['acked_at'] : null,
            'distance_meters' => $distance,
            'last_seen' => isset($recipient['last_seen']) ? (int) $recipient['last_seen'] : null,
        ];
    }

    return [
        'id' => (int) $alert['id'],
        'caller_name' => (string) $alert['caller_name'],
        'type' => (string) $alert['type'],
        'dest_name' => (string) $alert['dest_name'],
        'dest_lat' => (float) $alert['dest_lat'],
        'dest_lng' => (float) $alert['dest_lng'],
        'created_at' => (int) $alert['created_at'],
        'active' => (bool) $alert['active'],
        'cancelled_at' => $alert['cancelled_at'] !== null ? (int) $alert['cancelled_at'] : null,
        'recipients' => $people,
    ];
}

function soteria_require_admin_session(): array
{
    $email = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    $isAdmin = !empty($_SESSION['user']['admin']) || soteria_is_admin_email($email);
    if (!$isAdmin) {
        http_response_code(403);
        echo 'Alleen beheerders kunnen verzamelpunten instellen.';
        exit;
    }

    return $_SESSION['user'];
}
