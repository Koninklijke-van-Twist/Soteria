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
soteria_expire_stale_alerts($pdo);

if ($action === 'me') {
    $roles = soteria_roles_by_email($pdo)[$email] ?? [];
    soteria_json([
        'ok' => true,
        'email' => $email,
        'name' => $displayName,
        'roles' => $roles,
        'role_labels' => soteria_role_label_list($roles),
        'token_ttl_seconds' => SOTERIA_TOKEN_TTL_SECONDS,
    ]);
}

if ($action === 'heartbeat') {
    $deliveredAlertId = (int) ($body['delivered_alert_id'] ?? 0);
    if ($deliveredAlertId > 0) {
        soteria_mark_alert_delivered($pdo, $deliveredAlertId, $email);
    }

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
    $inactive = soteria_inactive_alert_for_recipient($pdo, $email, $currentAlertId);

    soteria_json([
        'ok' => true,
        'locations' => soteria_locations_with_presence($pdo),
        'pending_alert' => soteria_pending_alert($pdo, $email),
        'cancelled_alert' => $inactive['cancelled'],
        'expired_alert' => $inactive['expired'],
        'active_acknowledged_alerts' => soteria_active_acknowledged_alerts($pdo, $email),
    ]);
}

if ($action === 'locations') {
    soteria_json([
        'ok' => true,
        'locations' => soteria_locations_with_presence($pdo),
        'active_acknowledged_alerts' => soteria_active_acknowledged_alerts($pdo, $email),
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

    soteria_mark_alert_delivered($pdo, $alertId, $email);
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

if ($action === 'update_alert_location') {
    $alertId = (int) ($body['alert_id'] ?? 0);
    $placeId = (int) ($body['sub_location_id'] ?? $body['place_id'] ?? 0);
    $result = soteria_update_alert_sub_location($pdo, $alertId, $email, $placeId);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    soteria_json($result, $status);
}

if ($action === 'create_alert') {
    $type = trim((string) ($body['type'] ?? ''));
    $lat = (float) ($body['lat'] ?? 0);
    $lng = (float) ($body['lng'] ?? 0);
    if ($lat === 0.0 && $lng === 0.0) {
        $lat = (float) ($user['last_lat'] ?? 0);
        $lng = (float) ($user['last_lng'] ?? 0);
    }
    $result = soteria_create_alert($pdo, $email, $displayName, $type, $lat, $lng);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    soteria_json($result, $status);
}

soteria_json(['ok' => false, 'error' => 'Onbekende actie.'], 400);
