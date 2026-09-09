<?php

require_once __DIR__ . '/lib.php';

header('Cache-Control: no-store');

$pdo = soteria_pdo();
$body = soteria_request_json();
$action = trim((string) ($body['action'] ?? $_GET['action'] ?? $_POST['action'] ?? ''));

if ($action === '') {
    soteria_json(['ok' => false, 'error' => 'Geen actie opgegeven.'], 400);
}

$user = soteria_require_api_user($pdo, $body);
$email = strtolower(trim((string) ($user['email'] ?? '')));
$displayName = (string) ($user['display_name'] ?? soteria_display_name_for_email($email));

if ($action === 'me') {
    soteria_json([
        'ok' => true,
        'email' => $email,
        'name' => $displayName,
        'token_ttl_seconds' => SOTERIA_TOKEN_TTL_SECONDS,
    ]);
}

if ($action === 'heartbeat') {
    $lat = (float) ($body['lat'] ?? $_POST['lat'] ?? 0);
    $lng = (float) ($body['lng'] ?? $_POST['lng'] ?? 0);
    soteria_upsert_user($pdo, $email, $displayName);
    if ($lat !== 0.0 || $lng !== 0.0) {
        $update = $pdo->prepare(
            'UPDATE users SET last_lat = :lat, last_lng = :lng, last_seen = :seen, updated_at = :seen WHERE email = :email'
        );
        $update->execute([
            ':lat' => $lat,
            ':lng' => $lng,
            ':seen' => time(),
            ':email' => $email,
        ]);
    }

    $currentAlertId = (int) ($body['current_alert_id'] ?? 0);
    $cancelledAlert = null;
    if ($currentAlertId > 0) {
        $cancelled = $pdo->prepare(
            'SELECT a.id, a.caller_name
             FROM alerts a
             INNER JOIN alert_recipients r ON r.alert_id = a.id AND r.email = :email
             WHERE a.id = :id AND a.active = 0 AND a.cancelled_at IS NOT NULL
             LIMIT 1'
        );
        $cancelled->execute([':email' => $email, ':id' => $currentAlertId]);
        $row = $cancelled->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $cancelledAlert = [
                'id' => (int) $row['id'],
                'caller_name' => (string) $row['caller_name'],
            ];
        }
    }

    soteria_json([
        'ok' => true,
        'locations' => soteria_locations_with_presence($pdo),
        'pending_alert' => soteria_pending_alert($pdo, $email),
        'cancelled_alert' => $cancelledAlert,
    ]);
}

if ($action === 'locations') {
    soteria_json([
        'ok' => true,
        'locations' => soteria_locations_with_presence($pdo),
    ]);
}

if ($action === 'pending_alert') {
    soteria_json([
        'ok' => true,
        'alert' => soteria_pending_alert($pdo, $email),
    ]);
}

if ($action === 'ack_alert') {
    $alertId = (int) ($body['alert_id'] ?? $_POST['alert_id'] ?? 0);
    if ($alertId <= 0) {
        soteria_json(['ok' => false, 'error' => 'Ongeldige oproep.'], 400);
    }

    $recipient = $pdo->prepare(
        'SELECT 1
         FROM alert_recipients r
         INNER JOIN alerts a ON a.id = r.alert_id
         WHERE r.alert_id = :alert_id AND r.email = :email AND a.active = 1
         LIMIT 1'
    );
    $recipient->execute([':alert_id' => $alertId, ':email' => $email]);
    if ($recipient->fetchColumn() === false) {
        soteria_json(['ok' => false, 'error' => 'Actieve oproep niet gevonden.'], 404);
    }

    $ack = $pdo->prepare(
        'INSERT INTO alert_acks (alert_id, email, acked_at)
         VALUES (:alert_id, :email, :acked_at)
         ON CONFLICT(alert_id, email) DO UPDATE SET acked_at = excluded.acked_at'
    );
    $ack->execute([
        ':alert_id' => $alertId,
        ':email' => $email,
        ':acked_at' => time(),
    ]);

    soteria_json(['ok' => true]);
}

if ($action === 'active_outgoing_alert') {
    $alert = soteria_active_outgoing_alert($pdo, $email);
    soteria_json([
        'ok' => true,
        'alert' => $alert === null ? null : soteria_alert_status($pdo, (int) $alert['id'], $email),
    ]);
}

if ($action === 'alert_status') {
    $alertId = (int) ($body['alert_id'] ?? 0);
    $alert = $alertId > 0 ? soteria_alert_status($pdo, $alertId, $email) : null;
    if ($alert === null) {
        soteria_json(['ok' => false, 'error' => 'Oproep niet gevonden.'], 404);
    }
    soteria_json(['ok' => true, 'alert' => $alert]);
}

if ($action === 'cancel_alert') {
    $alertId = (int) ($body['alert_id'] ?? 0);
    if ($alertId <= 0) {
        soteria_json(['ok' => false, 'error' => 'Ongeldige oproep.'], 400);
    }
    $cancel = $pdo->prepare(
        'UPDATE alerts
         SET active = 0, cancelled_at = :cancelled_at
         WHERE id = :id AND caller_email = :email AND active = 1'
    );
    $cancel->execute([
        ':cancelled_at' => time(),
        ':id' => $alertId,
        ':email' => $email,
    ]);
    if ($cancel->rowCount() < 1) {
        soteria_json(['ok' => false, 'error' => 'Actieve oproep niet gevonden.'], 404);
    }
    soteria_json(['ok' => true]);
}

if ($action === 'create_alert') {
    $type = trim((string) ($body['type'] ?? ''));
    $lat = (float) ($body['lat'] ?? 0);
    $lng = (float) ($body['lng'] ?? 0);

    if (!in_array($type, ['assembly', 'caller'], true)) {
        soteria_json(['ok' => false, 'error' => 'Kies een oproeptype.'], 400);
    }
    if ($lat === 0.0 && $lng === 0.0) {
        $lat = (float) ($user['last_lat'] ?? 0);
        $lng = (float) ($user['last_lng'] ?? 0);
    }

    $existing = soteria_active_outgoing_alert($pdo, $email);
    if ($existing !== null) {
        soteria_json([
            'ok' => true,
            'alert_id' => (int) $existing['id'],
            'existing' => true,
        ]);
    }
    if ($lat === 0.0 && $lng === 0.0) {
        soteria_json(['ok' => false, 'error' => 'Je locatie is nodig voor een oproep.'], 400);
    }

    $locations = soteria_locations($pdo);
    if ($locations === []) {
        soteria_json(['ok' => false, 'error' => 'Er zijn nog geen verzamelpunten ingesteld.'], 400);
    }

    $destName = '';
    $destLat = $lat;
    $destLng = $lng;
    $locationId = null;

    if ($type === 'assembly') {
        $nearest = soteria_nearest_location($pdo, $lat, $lng);
        if ($nearest === null) {
            soteria_json(['ok' => false, 'error' => 'Geen verzamelpunt gevonden.'], 400);
        }
        $destName = (string) $nearest['name'];
        $destLat = (float) $nearest['lat'];
        $destLng = (float) $nearest['lng'];
        $locationId = (int) $nearest['id'];
    } else {
        $destName = 'locatie van ' . $displayName;
    }

    $present = soteria_present_users($pdo);
    $recipients = [];
    foreach ($present as $candidate) {
        $candidateEmail = strtolower(trim((string) ($candidate['email'] ?? '')));
        if ($candidateEmail === '' || $candidateEmail === $email) {
            continue;
        }
        foreach ($locations as $location) {
            if (soteria_user_is_present_at($candidate, $location)) {
                $recipients[$candidateEmail] = true;
                break;
            }
        }
    }

    $pdo->beginTransaction();
    $insert = $pdo->prepare(
        'INSERT INTO alerts (caller_email, caller_name, type, dest_name, dest_lat, dest_lng, location_id, created_at, active)
         VALUES (:caller_email, :caller_name, :type, :dest_name, :dest_lat, :dest_lng, :location_id, :created_at, 1)'
    );
    $insert->execute([
        ':caller_email' => $email,
        ':caller_name' => $displayName,
        ':type' => $type,
        ':dest_name' => $destName,
        ':dest_lat' => $destLat,
        ':dest_lng' => $destLng,
        ':location_id' => $locationId,
        ':created_at' => time(),
    ]);
    $alertId = (int) $pdo->lastInsertId();

    $recipientInsert = $pdo->prepare(
        'INSERT OR IGNORE INTO alert_recipients (alert_id, email) VALUES (:alert_id, :email)'
    );
    foreach (array_keys($recipients) as $recipientEmail) {
        $recipientInsert->execute([
            ':alert_id' => $alertId,
            ':email' => $recipientEmail,
        ]);
    }
    $pdo->commit();

    soteria_json([
        'ok' => true,
        'alert_id' => $alertId,
        'recipient_count' => count($recipients),
        'dest_name' => $destName,
    ]);
}

soteria_json(['ok' => false, 'error' => 'Onbekende actie.'], 400);
