<?php

require_once __DIR__ . '/auth.php';

const SOTERIA_PRESENCE_METERS = 1000;
const SOTERIA_PRESENCE_TTL_SECONDS = 180;
const SOTERIA_TOKEN_TTL_SECONDS = 2592000; // 30 dagen
// Open oproepen verlopen na 20 minuten. Een telefoon die uren later online komt
// krijgt de oproep niet meer; een toestel dat nog rinkelt stopt via de heartbeat.
const SOTERIA_ALERT_TTL_SECONDS = 1200;
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

function soteria_db_path(): string
{
    $override = getenv('SOTERIA_DB_PATH');
    if (is_string($override) && $override !== '') {
        return $override;
    }

    return SOTERIA_DB_PATH;
}

function soteria_pdo(): PDO
{
    $path = soteria_db_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Data-directory kon niet worden aangemaakt.');
    }

    if ($path === SOTERIA_DB_PATH) {
        soteria_migrate_legacy_database();
    }

    $pdo = new PDO('sqlite:' . $path);
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
    soteria_add_column_if_missing($pdo, 'alerts', 'expired_at', 'INTEGER');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS alert_recipients (
            alert_id INTEGER NOT NULL,
            email TEXT NOT NULL,
            PRIMARY KEY (alert_id, email)
        )'
    );
    soteria_add_column_if_missing($pdo, 'alert_recipients', 'delivered_at', 'INTEGER');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS alert_acks (
            alert_id INTEGER NOT NULL,
            email TEXT NOT NULL,
            acked_at INTEGER NOT NULL,
            PRIMARY KEY (alert_id, email)
        )'
    );
    soteria_add_column_if_missing($pdo, 'alerts', 'sub_location_id', 'INTEGER');
    soteria_add_column_if_missing($pdo, 'alerts', 'sub_location_name', 'TEXT');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_roles (
            email TEXT NOT NULL,
            role TEXT NOT NULL,
            PRIMARY KEY (email, role)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS gathering_places (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            location_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_gathering_places_location ON gathering_places(location_id)'
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

/**
 * Vaste rollen. EHBO geldt ook als BHV bij oproepen en aanwezigheid.
 *
 * @return list<string>
 */
function soteria_role_ids(): array
{
    return ['ploegleider', 'bhv', 'ehbo', 'ontruimer'];
}

/**
 * @return array<string, string>
 */
function soteria_role_labels(): array
{
    return [
        'ploegleider' => 'Ploegleider',
        'bhv' => 'BHV',
        'ehbo' => 'EHBO',
        'ontruimer' => 'Ontruimer',
    ];
}

/**
 * Oproepen gaan altijd naar het verzamelpunt. Het type bepaalt alleen wie gebeld wordt.
 *
 * @return list<string>
 */
function soteria_call_types(): array
{
    return ['bhv', 'ehbo', 'ploegleider', 'ontruimer'];
}

function soteria_is_call_type(string $type): bool
{
    return in_array($type, soteria_call_types(), true);
}

/**
 * @return list<string>
 */
function soteria_roles_for_call_type(string $type): array
{
    switch ($type) {
        case 'bhv':
            return ['bhv', 'ehbo', 'ontruimer', 'ploegleider'];
        case 'ehbo':
            return ['ehbo', 'ploegleider'];
        case 'ploegleider':
            return ['ploegleider'];
        case 'ontruimer':
            return ['ontruimer', 'ploegleider'];
        default:
            return [];
    }
}

function soteria_normalize_role(string $role): string
{
    $role = strtolower(trim($role));
    return in_array($role, soteria_role_ids(), true) ? $role : '';
}

/**
 * @param list<string> $roles
 * @return list<string>
 */
function soteria_effective_roles(array $roles): array
{
    $normalized = [];
    foreach ($roles as $role) {
        $role = soteria_normalize_role((string) $role);
        if ($role !== '') {
            $normalized[$role] = true;
        }
    }
    if (isset($normalized['ehbo'])) {
        $normalized['bhv'] = true;
    }

    $ordered = [];
    foreach (soteria_role_ids() as $role) {
        if (isset($normalized[$role])) {
            $ordered[] = $role;
        }
    }

    return $ordered;
}

/**
 * @param list<string> $effectiveRoles
 */
function soteria_user_matches_call(array $effectiveRoles, string $type): bool
{
    $wanted = soteria_roles_for_call_type($type);
    foreach ($effectiveRoles as $role) {
        if (in_array($role, $wanted, true)) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<string> $roles
 * @return list<string>
 */
function soteria_role_label_list(array $roles): array
{
    $labels = soteria_role_labels();
    $result = [];
    foreach ($roles as $role) {
        if (isset($labels[$role])) {
            $result[] = $labels[$role];
        }
    }

    return $result;
}

/**
 * @param array<string, int> $counts
 */
function soteria_role_summary(array $counts): string
{
    $labels = soteria_role_labels();
    $parts = [];
    foreach (soteria_role_ids() as $role) {
        $parts[] = (int) ($counts[$role] ?? 0) . ' ' . $labels[$role];
    }

    return implode(' · ', $parts);
}

/**
 * Opgeslagen rollen, zonder de impliciete BHV-rol van EHBO.
 *
 * @return array<string, list<string>>
 */
function soteria_assigned_roles_by_email(PDO $pdo): array
{
    $rows = $pdo->query('SELECT email, role FROM user_roles')->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    if (!is_array($rows)) {
        return $map;
    }
    foreach ($rows as $row) {
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        $role = soteria_normalize_role((string) ($row['role'] ?? ''));
        if ($email === '' || $role === '') {
            continue;
        }
        $map[$email][$role] = true;
    }
    foreach ($map as $email => $roleSet) {
        $ordered = [];
        foreach (soteria_role_ids() as $role) {
            if (isset($roleSet[$role])) {
                $ordered[] = $role;
            }
        }
        $map[$email] = $ordered;
    }

    return $map;
}

/**
 * Effectieve rollen per e-mail. EHBO levert ook BHV op.
 *
 * @return array<string, list<string>>
 */
function soteria_roles_by_email(PDO $pdo): array
{
    $assigned = soteria_assigned_roles_by_email($pdo);
    $effective = [];
    foreach ($assigned as $email => $roles) {
        $effective[$email] = soteria_effective_roles($roles);
    }

    return $effective;
}

/**
 * @param list<string> $roles
 */
function soteria_set_user_roles(PDO $pdo, string $email, array $roles): void
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Ongeldig e-mailadres.');
    }

    $clean = [];
    foreach ($roles as $role) {
        $raw = trim((string) $role);
        if ($raw === '') {
            continue;
        }
        $normalized = soteria_normalize_role($raw);
        if ($normalized === '') {
            throw new InvalidArgumentException('Onbekende rol.');
        }
        $clean[$normalized] = true;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM user_roles WHERE email = :email')->execute([':email' => $email]);
        $insert = $pdo->prepare('INSERT INTO user_roles (email, role) VALUES (:email, :role)');
        foreach (array_keys($clean) as $role) {
            $insert->execute([':email' => $email, ':role' => $role]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * @return list<array{email:string,name:string,title:string}>
 */
function soteria_directory_users(PDO $pdo): array
{
    $byEmail = [];
    foreach (soteria_graph_users() as $user) {
        if (!is_array($user)) {
            continue;
        }
        $email = strtolower(trim((string) ($user['Email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $name = trim((string) ($user['Naam'] ?? ''));
        $byEmail[$email] = [
            'email' => $email,
            'name' => $name !== '' ? $name : $email,
            'title' => trim((string) ($user['Titel'] ?? '')),
        ];
    }

    $known = $pdo->query('SELECT email, display_name FROM users')->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($known)) {
        foreach ($known as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '' || isset($byEmail[$email])) {
                continue;
            }
            $name = trim((string) ($row['display_name'] ?? ''));
            $byEmail[$email] = [
                'email' => $email,
                'name' => $name !== '' ? $name : $email,
                'title' => '',
            ];
        }
    }

    foreach (array_keys(soteria_assigned_roles_by_email($pdo)) as $email) {
        if (!isset($byEmail[$email])) {
            $byEmail[$email] = [
                'email' => $email,
                'name' => soteria_display_name_for_email($email),
                'title' => '',
            ];
        }
    }

    $users = array_values($byEmail);
    usort($users, static function (array $a, array $b): int {
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    return $users;
}

function soteria_locations(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, name, lat, lng FROM locations ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/**
 * @return list<array{id:int,location_id:int,name:string}>
 */
function soteria_places(PDO $pdo, ?int $locationId = null): array
{
    if ($locationId === null) {
        $rows = $pdo->query(
            'SELECT id, location_id, name FROM gathering_places ORDER BY name COLLATE NOCASE'
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $statement = $pdo->prepare(
            'SELECT id, location_id, name FROM gathering_places WHERE location_id = :location_id ORDER BY name COLLATE NOCASE'
        );
        $statement->execute([':location_id' => $locationId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!is_array($rows)) {
        return [];
    }

    $places = [];
    foreach ($rows as $row) {
        $places[] = [
            'id' => (int) ($row['id'] ?? 0),
            'location_id' => (int) ($row['location_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
        ];
    }

    return $places;
}

function soteria_create_place(PDO $pdo, int $locationId, string $name): int
{
    $name = trim($name);
    if ($locationId <= 0 || $name === '') {
        throw new InvalidArgumentException('Naam en verzamelpunt zijn verplicht.');
    }
    $exists = $pdo->prepare('SELECT 1 FROM locations WHERE id = :id');
    $exists->execute([':id' => $locationId]);
    if ($exists->fetchColumn() === false) {
        throw new InvalidArgumentException('Verzamelpunt niet gevonden.');
    }
    $statement = $pdo->prepare(
        'INSERT INTO gathering_places (location_id, name, created_at) VALUES (:location_id, :name, :created_at)'
    );
    $statement->execute([
        ':location_id' => $locationId,
        ':name' => $name,
        ':created_at' => time(),
    ]);

    return (int) $pdo->lastInsertId();
}

function soteria_rename_place(PDO $pdo, int $placeId, string $name): void
{
    $name = trim($name);
    if ($placeId <= 0 || $name === '') {
        throw new InvalidArgumentException('Ongeldige locatie.');
    }
    $statement = $pdo->prepare('UPDATE gathering_places SET name = :name WHERE id = :id');
    $statement->execute([':name' => $name, ':id' => $placeId]);
    if ($statement->rowCount() < 1) {
        throw new InvalidArgumentException('Locatie niet gevonden.');
    }
    $pdo->prepare('UPDATE alerts SET sub_location_name = :name WHERE sub_location_id = :id AND active = 1')
        ->execute([':name' => $name, ':id' => $placeId]);
}

function soteria_delete_place(PDO $pdo, int $placeId): void
{
    if ($placeId <= 0) {
        return;
    }
    $pdo->prepare('DELETE FROM gathering_places WHERE id = :id')->execute([':id' => $placeId]);
}

function soteria_delete_location(PDO $pdo, int $locationId): void
{
    if ($locationId <= 0) {
        return;
    }
    $pdo->prepare('DELETE FROM gathering_places WHERE location_id = :id')->execute([':id' => $locationId]);
    $pdo->prepare('DELETE FROM locations WHERE id = :id')->execute([':id' => $locationId]);
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
    $rolesByEmail = soteria_roles_by_email($pdo);
    $people = [];
    foreach (soteria_present_users($pdo) as $user) {
        $email = strtolower(trim((string) ($user['email'] ?? '')));
        $name = trim((string) ($user['display_name'] ?? ''));
        $roles = $rolesByEmail[$email] ?? [];
        $people[] = [
            'email' => $email,
            'name' => $name !== '' ? $name : $email,
            'roles' => $roles,
            'role_labels' => soteria_role_label_list($roles),
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

/**
 * @param list<array{roles?:list<string>}> $people
 * @return array<string, int>
 */
function soteria_role_counts(array $people): array
{
    $counts = [];
    foreach (soteria_role_ids() as $role) {
        $counts[$role] = 0;
    }
    foreach ($people as $person) {
        foreach ($person['roles'] ?? [] as $role) {
            if (isset($counts[$role])) {
                $counts[$role]++;
            }
        }
    }

    return $counts;
}

function soteria_locations_with_presence(PDO $pdo): array
{
    $locations = soteria_locations($pdo);
    $present = soteria_present_users($pdo);
    $rolesByEmail = soteria_roles_by_email($pdo);
    $places = soteria_places($pdo);
    $placesByLocation = [];
    foreach ($places as $place) {
        $placesByLocation[$place['location_id']][] = [
            'id' => $place['id'],
            'name' => $place['name'],
        ];
    }
    $result = [];

    foreach ($locations as $location) {
        $locationId = (int) ($location['id'] ?? 0);
        $people = [];
        foreach ($present as $user) {
            if (!soteria_user_is_present_at($user, $location)) {
                continue;
            }
            $email = strtolower(trim((string) ($user['email'] ?? '')));
            $roles = $rolesByEmail[$email] ?? [];
            $people[] = [
                'email' => $email,
                'name' => (string) ($user['display_name'] ?? ''),
                'roles' => $roles,
                'role_labels' => soteria_role_label_list($roles),
            ];
        }

        usort($people, static function (array $a, array $b): int {
            return strcasecmp((string) $a['name'], (string) $b['name']);
        });

        $byRole = soteria_role_counts($people);
        $result[] = [
            'id' => $locationId,
            'name' => (string) ($location['name'] ?? ''),
            'lat' => (float) ($location['lat'] ?? 0),
            'lng' => (float) ($location['lng'] ?? 0),
            'present_count' => count($people),
            'by_role' => $byRole,
            'role_summary' => soteria_role_summary($byRole),
            'people' => $people,
            'places' => $placesByLocation[$locationId] ?? [],
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

/**
 * Zet verlopen open oproepen inactief. Geannuleerde oproepen blijven ongemoeid.
 */
function soteria_expire_stale_alerts(PDO $pdo): void
{
    $now = time();
    $statement = $pdo->prepare(
        'UPDATE alerts
         SET active = 0, expired_at = :now
         WHERE active = 1
           AND cancelled_at IS NULL
           AND created_at <= :cutoff'
    );
    $statement->execute([
        ':now' => $now,
        ':cutoff' => $now - SOTERIA_ALERT_TTL_SECONDS,
    ]);
}

/**
 * De heartbeat heeft pending_alert op dit toestel afgeleverd.
 * Dit is iets anders dan de gebruiker die op Bevestigen tikt.
 */
function soteria_mark_alert_delivered(PDO $pdo, int $alertId, string $email): void
{
    if ($alertId <= 0) {
        return;
    }
    $statement = $pdo->prepare(
        'UPDATE alert_recipients
         SET delivered_at = :now
         WHERE alert_id = :alert_id AND email = :email AND delivered_at IS NULL'
    );
    $statement->execute([
        ':now' => time(),
        ':alert_id' => $alertId,
        ':email' => strtolower(trim($email)),
    ]);
}

/**
 * @return array{cancelled: ?array, expired: ?array}
 */
function soteria_inactive_alert_for_recipient(PDO $pdo, string $email, int $alertId): array
{
    $empty = ['cancelled' => null, 'expired' => null];
    if ($alertId <= 0) {
        return $empty;
    }
    $statement = $pdo->prepare(
        'SELECT a.id, a.caller_name, a.cancelled_at, a.expired_at
         FROM alerts a
         INNER JOIN alert_recipients r ON r.alert_id = a.id AND r.email = :email
         WHERE a.id = :id AND a.active = 0
         LIMIT 1'
    );
    $statement->execute([
        ':email' => strtolower(trim($email)),
        ':id' => $alertId,
    ]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return $empty;
    }
    $payload = [
        'id' => (int) $row['id'],
        'caller_name' => (string) $row['caller_name'],
    ];
    if ($row['expired_at'] !== null) {
        return ['cancelled' => null, 'expired' => $payload];
    }
    if ($row['cancelled_at'] !== null) {
        return ['cancelled' => $payload, 'expired' => null];
    }
    return $empty;
}

function soteria_call_audience_label(string $type): string
{
    switch ($type) {
        case 'bhv':
            return 'BHV';
        case 'ehbo':
            return 'EHBO';
        case 'ploegleider':
            return 'ploegleiders';
        case 'ontruimer':
            return 'ontruimers';
        default:
            return 'hulp';
    }
}

function soteria_resolve_sub_location_name(PDO $pdo, array $row): string
{
    $placeId = (int) ($row['sub_location_id'] ?? 0);
    if ($placeId > 0) {
        $statement = $pdo->prepare('SELECT name FROM gathering_places WHERE id = :id');
        $statement->execute([':id' => $placeId]);
        $name = $statement->fetchColumn();
        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }
    }

    return trim((string) ($row['sub_location_name'] ?? ''));
}

function soteria_alert_destination_label(array $row): string
{
    $dest = trim((string) ($row['dest_name'] ?? ''));
    if ($dest === '') {
        $dest = 'het verzamelpunt';
    }
    $sub = trim((string) ($row['sub_location_name'] ?? ''));
    if ($sub !== '') {
        return $dest . ' (' . $sub . ')';
    }

    return $dest;
}

function soteria_alert_message(array $row): string
{
    $type = (string) ($row['type'] ?? '');
    $name = (string) ($row['caller_name'] ?? '');
    $place = soteria_alert_destination_label($row);
    if ($type === 'caller') {
        return $name . ' roept je op naar zijn/haar huidige locatie';
    }
    if ($type === 'assembly') {
        return $name . ' roept je op naar verzamelpunt ' . $place;
    }

    return $name . ' roept ' . soteria_call_audience_label($type) . ' op naar verzamelpunt ' . $place;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function soteria_alert_payload(PDO $pdo, array $row, bool $withMessage = false): array
{
    $subName = soteria_resolve_sub_location_name($pdo, $row);
    $row['sub_location_name'] = $subName;
    $payload = [
        'id' => (int) $row['id'],
        'caller_name' => (string) $row['caller_name'],
        'type' => (string) $row['type'],
        'dest_name' => trim((string) ($row['dest_name'] ?? '')),
        'dest_lat' => (float) $row['dest_lat'],
        'dest_lng' => (float) $row['dest_lng'],
        'location_id' => isset($row['location_id']) && $row['location_id'] !== null ? (int) $row['location_id'] : null,
        'sub_location_id' => isset($row['sub_location_id']) && $row['sub_location_id'] !== null && (int) $row['sub_location_id'] > 0
            ? (int) $row['sub_location_id']
            : null,
        'sub_location_name' => $subName !== '' ? $subName : null,
        'created_at' => (int) $row['created_at'],
    ];
    if ($withMessage) {
        $payload['message'] = soteria_alert_message($row);
    }

    return $payload;
}

function soteria_pending_alert(PDO $pdo, string $email): ?array
{
    $statement = $pdo->prepare(
        'SELECT a.id, a.caller_email, a.caller_name, a.type, a.dest_name, a.dest_lat, a.dest_lng,
                a.location_id, a.sub_location_id, a.sub_location_name, a.created_at
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

    return soteria_alert_payload($pdo, $row, true);
}

function soteria_active_outgoing_alert(PDO $pdo, string $email): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, caller_email, caller_name, type, dest_name, dest_lat, dest_lng,
                location_id, sub_location_id, sub_location_name, created_at, active
         FROM alerts
         WHERE caller_email = :email AND active = 1
         ORDER BY created_at DESC
         LIMIT 1'
    );
    $statement->execute([':email' => strtolower(trim($email))]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function soteria_active_acknowledged_alerts(PDO $pdo, string $email): array
{
    $statement = $pdo->prepare(
        'SELECT a.id, a.caller_name, a.type, a.dest_name, a.dest_lat, a.dest_lng,
                a.location_id, a.sub_location_id, a.sub_location_name, a.created_at
         FROM alerts a
         INNER JOIN alert_recipients r ON r.alert_id = a.id AND r.email = :email
         INNER JOIN alert_acks k ON k.alert_id = a.id AND k.email = :email
         WHERE a.active = 1
         ORDER BY a.created_at DESC'
    );
    $statement->execute([':email' => strtolower(trim($email))]);
    $alerts = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (is_array($row)) {
            $alerts[] = soteria_alert_payload($pdo, $row);
        }
    }
    return $alerts;
}

/**
 * Aanwezige gebruikers op een verzamelpunt, gefilterd op de rollen van het oproeptype.
 *
 * @return list<string>
 */
function soteria_select_alert_recipients(PDO $pdo, string $type, string $callerEmail): array
{
    $callerEmail = strtolower(trim($callerEmail));
    $rolesByEmail = soteria_roles_by_email($pdo);
    $locations = soteria_locations($pdo);
    $recipients = [];
    foreach (soteria_present_users($pdo) as $candidate) {
        $email = strtolower(trim((string) ($candidate['email'] ?? '')));
        if ($email === '' || $email === $callerEmail) {
            continue;
        }
        $roles = $rolesByEmail[$email] ?? [];
        if (!soteria_user_matches_call($roles, $type)) {
            continue;
        }
        foreach ($locations as $location) {
            if (soteria_user_is_present_at($candidate, $location)) {
                $recipients[$email] = true;
                break;
            }
        }
    }

    return array_keys($recipients);
}

/**
 * @return array{ok:bool,status:int,error?:string,alert_id?:int,existing?:bool,recipient_count?:int,dest_name?:string}
 */
function soteria_create_alert(PDO $pdo, string $email, string $displayName, string $type, float $lat, float $lng): array
{
    $email = strtolower(trim($email));
    $type = trim($type);
    if (!soteria_is_call_type($type)) {
        return ['ok' => false, 'status' => 400, 'error' => 'Kies een oproeptype: BHV, EHBO, ploegleiders of ontruimers.'];
    }

    $existing = soteria_active_outgoing_alert($pdo, $email);
    if ($existing !== null) {
        return [
            'ok' => true,
            'status' => 200,
            'alert_id' => (int) $existing['id'],
            'existing' => true,
            'recipient_count' => 0,
            'dest_name' => (string) ($existing['dest_name'] ?? ''),
        ];
    }

    if ($lat === 0.0 && $lng === 0.0) {
        $position = $pdo->prepare('SELECT last_lat, last_lng FROM users WHERE email = :email');
        $position->execute([':email' => $email]);
        $row = $position->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $lat = (float) ($row['last_lat'] ?? 0);
            $lng = (float) ($row['last_lng'] ?? 0);
        }
    }
    if ($lat === 0.0 && $lng === 0.0) {
        return ['ok' => false, 'status' => 400, 'error' => 'Je locatie is nodig voor een oproep.'];
    }

    $locations = soteria_locations($pdo);
    if ($locations === []) {
        return ['ok' => false, 'status' => 400, 'error' => 'Er zijn nog geen verzamelpunten ingesteld.'];
    }

    $nearest = soteria_nearest_location($pdo, $lat, $lng);
    if ($nearest === null) {
        return ['ok' => false, 'status' => 400, 'error' => 'Geen verzamelpunt gevonden.'];
    }

    $recipients = soteria_select_alert_recipients($pdo, $type, $email);
    $destName = (string) $nearest['name'];
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO alerts (caller_email, caller_name, type, dest_name, dest_lat, dest_lng, location_id, created_at, active)
             VALUES (:caller_email, :caller_name, :type, :dest_name, :dest_lat, :dest_lng, :location_id, :created_at, 1)'
        );
        $insert->execute([
            ':caller_email' => $email,
            ':caller_name' => $displayName,
            ':type' => $type,
            ':dest_name' => $destName,
            ':dest_lat' => (float) $nearest['lat'],
            ':dest_lng' => (float) $nearest['lng'],
            ':location_id' => (int) $nearest['id'],
            ':created_at' => time(),
        ]);
        $alertId = (int) $pdo->lastInsertId();
        $recipientInsert = $pdo->prepare(
            'INSERT OR IGNORE INTO alert_recipients (alert_id, email) VALUES (:alert_id, :email)'
        );
        foreach ($recipients as $recipientEmail) {
            $recipientInsert->execute([
                ':alert_id' => $alertId,
                ':email' => $recipientEmail,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return [
        'ok' => true,
        'status' => 200,
        'alert_id' => $alertId,
        'existing' => false,
        'recipient_count' => count($recipients),
        'dest_name' => $destName,
    ];
}

/**
 * @return array{ok:bool,status:int,error?:string,alert?:array}
 */
function soteria_update_alert_sub_location(PDO $pdo, int $alertId, string $callerEmail, int $placeId): array
{
    $callerEmail = strtolower(trim($callerEmail));
    if ($alertId <= 0 || $placeId <= 0) {
        return ['ok' => false, 'status' => 400, 'error' => 'Kies een locatie.'];
    }

    $alert = $pdo->prepare(
        'SELECT id, location_id, active FROM alerts WHERE id = :id AND caller_email = :email LIMIT 1'
    );
    $alert->execute([':id' => $alertId, ':email' => $callerEmail]);
    $row = $alert->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || (int) ($row['active'] ?? 0) !== 1) {
        return ['ok' => false, 'status' => 404, 'error' => 'Actieve oproep niet gevonden.'];
    }

    $locationId = (int) ($row['location_id'] ?? 0);
    if ($locationId <= 0) {
        return ['ok' => false, 'status' => 400, 'error' => 'Deze oproep heeft geen verzamelpunt.'];
    }

    $place = $pdo->prepare(
        'SELECT id, name FROM gathering_places WHERE id = :id AND location_id = :location_id LIMIT 1'
    );
    $place->execute([':id' => $placeId, ':location_id' => $locationId]);
    $placeRow = $place->fetch(PDO::FETCH_ASSOC);
    if (!is_array($placeRow)) {
        return ['ok' => false, 'status' => 400, 'error' => 'Die locatie hoort niet bij dit verzamelpunt.'];
    }

    $update = $pdo->prepare(
        'UPDATE alerts
         SET sub_location_id = :place_id, sub_location_name = :place_name
         WHERE id = :id AND caller_email = :email AND active = 1'
    );
    $update->execute([
        ':place_id' => $placeId,
        ':place_name' => (string) $placeRow['name'],
        ':id' => $alertId,
        ':email' => $callerEmail,
    ]);

    $status = soteria_alert_status($pdo, $alertId, $callerEmail);

    return ['ok' => true, 'status' => 200, 'alert' => $status ?? []];
}

function soteria_alert_status(PDO $pdo, int $alertId, string $callerEmail): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, caller_email, caller_name, type, dest_name, dest_lat, dest_lng,
                location_id, sub_location_id, sub_location_name,
                created_at, active, cancelled_at, expired_at
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

    $rolesByEmail = soteria_roles_by_email($pdo);
    $recipients = $pdo->prepare(
        'SELECT r.email, r.delivered_at, u.display_name, u.last_lat, u.last_lng, u.last_seen, k.acked_at
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
        $lastSeen = isset($recipient['last_seen']) ? (int) $recipient['last_seen'] : null;
        $distance = null;
        if ($lat !== null && $lng !== null &&
            $lastSeen !== null && $lastSeen >= time() - SOTERIA_PRESENCE_TTL_SECONDS &&
            ($callerLat !== 0.0 || $callerLng !== 0.0)
        ) {
            $distance = (int) round(soteria_haversine_meters($callerLat, $callerLng, $lat, $lng));
        }
        $email = strtolower(trim((string) $recipient['email']));
        $roles = $rolesByEmail[$email] ?? [];
        $people[] = [
            'email' => $email,
            'name' => trim((string) ($recipient['display_name'] ?? '')) ?: $email,
            'roles' => $roles,
            'role_labels' => soteria_role_label_list($roles),
            'delivered' => $recipient['delivered_at'] !== null,
            'delivered_at' => $recipient['delivered_at'] !== null ? (int) $recipient['delivered_at'] : null,
            'responded' => $recipient['acked_at'] !== null,
            'responded_at' => $recipient['acked_at'] !== null ? (int) $recipient['acked_at'] : null,
            'distance_meters' => $distance,
            'last_seen' => $lastSeen,
        ];
    }

    $payload = soteria_alert_payload($pdo, $alert);
    $locationId = (int) ($payload['location_id'] ?? 0);
    $places = [];
    if ($locationId > 0) {
        foreach (soteria_places($pdo, $locationId) as $place) {
            $places[] = [
                'id' => $place['id'],
                'name' => $place['name'],
            ];
        }
    }
    $payload['active'] = (bool) $alert['active'];
    $payload['cancelled_at'] = $alert['cancelled_at'] !== null ? (int) $alert['cancelled_at'] : null;
    $payload['expired_at'] = $alert['expired_at'] !== null ? (int) $alert['expired_at'] : null;
    $payload['places'] = $places;
    $payload['recipients'] = $people;

    return $payload;
}

function soteria_require_admin_session(): array
{
    $email = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    $isAdmin = !empty($_SESSION['user']['admin']) || soteria_is_admin_email($email);
    if (!$isAdmin) {
        http_response_code(403);
        echo 'Alleen beheerders hebben toegang.';
        exit;
    }

    return $_SESSION['user'];
}
