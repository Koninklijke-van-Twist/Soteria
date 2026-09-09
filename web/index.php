<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/lib.php';

$admin = soteria_require_admin_session();
$pdo = soteria_pdo();
$mapsApiKey = trim((string) ($mapsApiKey ?? ''));
$center = is_array($mapDefaultCenter ?? null) ? $mapDefaultCenter : ['lat' => 51.8708, 'lng' => 4.6025, 'zoom' => 16];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $lat = (float) ($_POST['lat'] ?? 0);
        $lng = (float) ($_POST['lng'] ?? 0);
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Naam is verplicht.']);
            exit;
        }
        $statement = $pdo->prepare(
            'INSERT INTO locations (name, lat, lng, created_at) VALUES (:name, :lat, :lng, :created_at)'
        );
        $statement->execute([
            ':name' => $name,
            ':lat' => $lat,
            ':lng' => $lng,
            ':created_at' => time(),
        ]);
        echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $lat = (float) ($_POST['lat'] ?? 0);
        $lng = (float) ($_POST['lng'] ?? 0);
        if ($id <= 0 || $name === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Ongeldige locatie.']);
            exit;
        }
        $statement = $pdo->prepare('UPDATE locations SET name = :name, lat = :lat, lng = :lng WHERE id = :id');
        $statement->execute([
            ':name' => $name,
            ':lat' => $lat,
            ':lng' => $lng,
            ':id' => $id,
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $statement = $pdo->prepare('DELETE FROM locations WHERE id = :id');
        $statement->execute([':id' => $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Onbekende actie.']);
    exit;
}

$locations = soteria_locations_with_presence($pdo);
$adminName = trim((string) ($admin['name'] ?? $admin['email'] ?? 'beheerder'));
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Soteria — verzamelpunten</title>
    <style>
        :root { --bg: #f4f7fb; --panel: #fff; --text: #10233f; --muted: #5b6b82; --accent: #0b65c2; --danger: #b42318; --shadow: 0 16px 40px rgba(15, 35, 63, 0.08); }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; background: var(--bg); color: var(--text); }
        header { padding: 20px 24px; background: var(--panel); box-shadow: var(--shadow); display: flex; justify-content: space-between; align-items: center; }
        h1 { margin: 0; font-size: 22px; }
        .muted { color: var(--muted); }
        .layout { display: grid; grid-template-columns: 340px 1fr; min-height: calc(100vh - 72px); }
        aside { padding: 20px; border-right: 1px solid #d8e0eb; background: var(--panel); }
        #map { min-height: 480px; }
        .location { padding: 12px 0; border-bottom: 1px solid #d8e0eb; }
        .location strong { display: block; }
        button, .btn { border: 0; background: var(--accent); color: #fff; padding: 8px 12px; border-radius: 8px; cursor: pointer; }
        button.danger { background: var(--danger); }
        .hint { font-size: 14px; line-height: 1.45; }
        @media (max-width: 860px) { .layout { grid-template-columns: 1fr; } #map { min-height: 360px; } }
    </style>
</head>
<body>
    <header>
        <div>
            <h1>Soteria verzamelpunten</h1>
            <div class="muted">Ingelogd als <?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <span class="muted">Aanwezig = binnen 1 km</span>
    </header>
    <div class="layout">
        <aside>
            <p class="hint">Klik op de kaart om een verzamelpunt te plaatsen. Sleep een pin om te verplaatsen.</p>
            <div id="list">
                <?php foreach ($locations as $location): ?>
                    <div class="location" data-id="<?= (int) $location['id'] ?>">
                        <strong><?= htmlspecialchars((string) $location['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <span class="muted"><?= (int) $location['present_count'] ?> BHV aanwezig</span>
                        <div style="margin-top:8px;">
                            <button class="danger" data-delete="<?= (int) $location['id'] ?>">Verwijder</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </aside>
        <div id="map"></div>
    </div>
    <script>
        const locations = <?= json_encode($locations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const center = <?= json_encode($center, JSON_UNESCAPED_UNICODE) ?>;
        const hasMapsKey = <?= $mapsApiKey !== '' ? 'true' : 'false' ?>;

        async function post(data) {
            const body = new URLSearchParams(data);
            const response = await fetch(window.location.href, { method: 'POST', body, credentials: 'same-origin' });
            return response.json();
        }

        function promptName(current) {
            const name = window.prompt('Naam van het verzamelpunt', current || '');
            return name ? name.trim() : '';
        }

        async function initMap() {
            const map = new google.maps.Map(document.getElementById('map'), {
                center: { lat: Number(center.lat), lng: Number(center.lng) },
                zoom: Number(center.zoom || 16),
                mapTypeControl: true
            });

            const markers = [];

            function addMarker(location) {
                const marker = new google.maps.Marker({
                    position: { lat: Number(location.lat), lng: Number(location.lng) },
                    map,
                    draggable: true,
                    title: location.name
                });
                marker.addListener('click', async () => {
                    const name = promptName(location.name);
                    if (!name) return;
                    await post({ action: 'update', id: String(location.id), name, lat: String(location.lat), lng: String(location.lng) });
                    location.name = name;
                    marker.setTitle(name);
                    window.location.reload();
                });
                marker.addListener('dragend', async () => {
                    const pos = marker.getPosition();
                    await post({
                        action: 'update',
                        id: String(location.id),
                        name: location.name,
                        lat: String(pos.lat()),
                        lng: String(pos.lng())
                    });
                });
                markers.push(marker);
            }

            locations.forEach(addMarker);

            map.addListener('click', async (event) => {
                const name = promptName('');
                if (!name) return;
                const result = await post({
                    action: 'create',
                    name,
                    lat: String(event.latLng.lat()),
                    lng: String(event.latLng.lng())
                });
                if (result.ok) {
                    window.location.reload();
                }
            });
        }

        document.querySelectorAll('[data-delete]').forEach((button) => {
            button.addEventListener('click', async () => {
                if (!confirm('Dit verzamelpunt verwijderen?')) return;
                await post({ action: 'delete', id: button.getAttribute('data-delete') });
                window.location.reload();
            });
        });

        if (hasMapsKey) {
            window.initMap = initMap;
        } else {
            document.getElementById('map').innerHTML = '<p style="padding:24px">Zet <code>$mapsApiKey</code> in <code>auth.php</code> om Google Maps te gebruiken. Je kunt daarna op de kaart klikken om verzamelpunten te plaatsen.</p>';
        }
    </script>
    <?php if ($mapsApiKey !== ''): ?>
        <script src="https://maps.googleapis.com/maps/api/js?key=<?= htmlspecialchars($mapsApiKey, ENT_QUOTES, 'UTF-8') ?>&callback=initMap" async defer></script>
    <?php endif; ?>
</body>
</html>
