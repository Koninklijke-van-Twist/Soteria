<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/lib.php';

$admin = soteria_require_admin_session();
$pdo = soteria_pdo();
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

    if ($action === 'people') {
        echo json_encode(
            ['ok' => true, 'people' => soteria_present_people($pdo)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        soteria_delete_location($pdo, $id);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'create_place') {
        $locationId = (int) ($_POST['location_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        try {
            $id = soteria_create_place($pdo, $locationId, $name);
        } catch (InvalidArgumentException $exception) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    if ($action === 'update_place') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        try {
            soteria_rename_place($pdo, $id, $name);
        } catch (InvalidArgumentException $exception) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete_place') {
        $id = (int) ($_POST['id'] ?? 0);
        soteria_delete_place($pdo, $id);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Onbekende actie.']);
    exit;
}

$locations = soteria_locations_with_presence($pdo);
$people = soteria_present_people($pdo);
$adminName = trim((string) ($admin['name'] ?? $admin['email'] ?? 'beheerder'));
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Soteria — verzamelpunten</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <style>
        :root { --bg: #f4f7fb; --panel: #fff; --text: #10233f; --muted: #5b6b82; --accent: #0b65c2; --danger: #b42318; --shadow: 0 16px 40px rgba(15, 35, 63, 0.08); }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; background: var(--bg); color: var(--text); }
        header { padding: 20px 24px; background: var(--panel); box-shadow: var(--shadow); display: flex; justify-content: space-between; align-items: center; }
        h1 { margin: 0; font-size: 22px; }
        .muted { color: var(--muted); }
        .layout { display: grid; grid-template-columns: 400px 1fr; min-height: calc(100vh - 72px); }
        a.nav { color: var(--accent); font-weight: 700; text-decoration: none; }
        aside { padding: 20px; border-right: 1px solid #d8e0eb; background: var(--panel); }
        #map { min-height: 480px; }
        .leaflet-container { height: 100%; min-height: 480px; }
        .location { padding: 12px 0; border-bottom: 1px solid #d8e0eb; }
        .location strong { display: block; }
        .places { margin-top: 8px; padding-left: 8px; border-left: 3px solid #d8e0eb; }
        .place { display: flex; justify-content: space-between; gap: 8px; align-items: center; margin-top: 6px; }
        button.small { padding: 4px 8px; font-size: 13px; }
        button, .btn { border: 0; background: var(--accent); color: #fff; padding: 8px 12px; border-radius: 8px; cursor: pointer; }
        button.danger { background: var(--danger); }
        .hint { font-size: 14px; line-height: 1.45; }
        .responder-pin { width: 22px; height: 22px; border-radius: 50%; background: var(--accent); border: 2px solid #fff; box-shadow: 0 2px 6px rgba(15, 35, 63, 0.35); color: #fff; font: 700 16px/18px Arial, Helvetica, sans-serif; text-align: center; }
        .legend { display: flex; align-items: center; gap: 8px; margin-top: 16px; font-size: 14px; }
        .legend .responder-pin { flex: 0 0 auto; }
        @media (max-width: 860px) { .layout { grid-template-columns: 1fr; } #map { min-height: 360px; } }
    </style>
</head>
<body>
    <header>
        <div>
            <h1>Soteria verzamelpunten</h1>
            <div class="muted">Ingelogd als <?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div>
            <a class="nav" href="rollen.php">Rollen toewijzen</a>
            <div class="muted">Aanwezig = binnen 1 km</div>
        </div>
    </header>
    <div class="layout">
        <aside>
            <p class="hint">Klik op de kaart om een verzamelpunt te plaatsen. Sleep een pin om te verplaatsen. Locaties onder een verzamelpunt kies je tijdens een actieve oproep.</p>
            <div class="legend">
                <span class="responder-pin">+</span>
                <span class="muted" id="peopleCount"></span>
            </div>
            <div id="list">
                <?php foreach ($locations as $location): ?>
                    <div class="location" data-id="<?= (int) $location['id'] ?>">
                        <strong><?= htmlspecialchars((string) $location['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <span class="muted"><?= (int) $location['present_count'] ?> aanwezig</span>
                        <div class="muted"><?= htmlspecialchars((string) ($location['role_summary'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="places">
                            <div class="muted">Locaties</div>
                            <?php foreach ($location['places'] as $place): ?>
                                <div class="place">
                                    <span><?= htmlspecialchars((string) $place['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span>
                                        <button type="button" class="small" data-rename-place="<?= (int) $place['id'] ?>" data-place-name="<?= htmlspecialchars((string) $place['name'], ENT_QUOTES, 'UTF-8') ?>">Hernoem</button>
                                        <button type="button" class="small danger" data-delete-place="<?= (int) $place['id'] ?>">Verwijder</button>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                            <div style="margin-top:8px;">
                                <button type="button" class="small" data-add-place="<?= (int) $location['id'] ?>">Locatie toevoegen</button>
                            </div>
                        </div>
                        <div style="margin-top:8px;">
                            <button class="danger" data-delete="<?= (int) $location['id'] ?>">Verzamelpunt verwijderen</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </aside>
        <div id="map"></div>
    </div>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        <?php // JSON_HEX_TAG houdt een naam met bijvoorbeeld </script> binnen de string. ?>
        const locations = <?= json_encode($locations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
        const center = <?= json_encode($center, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
        const initialPeople = <?= json_encode($people, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            })[char]);
        }

        async function post(data) {
            const body = new URLSearchParams(data);
            const response = await fetch(window.location.href, { method: 'POST', body, credentials: 'same-origin' });
            return response.json();
        }

        function promptName(current, label) {
            const name = window.prompt(label || 'Naam van het verzamelpunt', current || '');
            return name ? name.trim() : '';
        }

        const map = L.map('map').setView([Number(center.lat), Number(center.lng)], Number(center.zoom || 16));
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(map);

        const bounds = [];

        function addMarker(location) {
            const marker = L.marker([Number(location.lat), Number(location.lng)], { draggable: true, title: location.name })
                .addTo(map)
                .bindTooltip(escapeHtml(location.name));
            bounds.push([Number(location.lat), Number(location.lng)]);
            marker.on('click', async (event) => {
                L.DomEvent.stopPropagation(event);
                const name = promptName(location.name);
                if (!name) return;
                await post({ action: 'update', id: String(location.id), name, lat: String(location.lat), lng: String(location.lng) });
                window.location.reload();
            });
            marker.on('dragend', async () => {
                const pos = marker.getLatLng();
                await post({
                    action: 'update',
                    id: String(location.id),
                    name: location.name,
                    lat: String(pos.lat),
                    lng: String(pos.lng)
                });
            });
        }

        locations.forEach(addMarker);
        if (bounds.length > 1) {
            map.fitBounds(bounds, { padding: [32, 32] });
        } else if (bounds.length === 1) {
            map.setView(bounds[0], Number(center.zoom || 16));
        }

        const responderIcon = L.divIcon({
            className: '',
            html: '<div class="responder-pin">+</div>',
            iconSize: [22, 22],
            iconAnchor: [11, 11],
            tooltipAnchor: [0, -12]
        });
        const responderLayer = L.layerGroup().addTo(map);
        const peopleCount = document.getElementById('peopleCount');

        function renderPeople(people) {
            responderLayer.clearLayers();
            people.forEach((person) => {
                if (!Number.isFinite(person.lat) || !Number.isFinite(person.lng)) return;
                const roles = Array.isArray(person.role_labels) ? person.role_labels.filter(Boolean).join(', ') : '';
                const label = roles ? person.name + ' (' + roles + ')' : person.name;
                L.marker([person.lat, person.lng], { icon: responderIcon, zIndexOffset: 500 })
                    .bindTooltip(escapeHtml(label), { direction: 'top' })
                    .addTo(responderLayer);
            });
            peopleCount.textContent = people.length === 1
                ? '1 persoon online — hover voor naam en rol'
                : people.length + ' personen online — hover voor naam en rol';
        }

        renderPeople(initialPeople);
        // Aanwezigheid vervalt na 3 minuten zonder heartbeat, dus de kaart ververst zichzelf.
        setInterval(async () => {
            try {
                const result = await post({ action: 'people' });
                if (result.ok) renderPeople(result.people);
            } catch (error) {
                /* volgende ronde opnieuw */
            }
        }, 30000);

        map.on('click', async (event) => {
            const name = promptName('');
            if (!name) return;
            const result = await post({
                action: 'create',
                name,
                lat: String(event.latlng.lat),
                lng: String(event.latlng.lng)
            });
            if (result.ok) {
                window.location.reload();
            }
        });

        document.querySelectorAll('[data-delete]').forEach((button) => {
            button.addEventListener('click', async () => {
                if (!confirm('Dit verzamelpunt en de locaties eronder verwijderen?')) return;
                await post({ action: 'delete', id: button.getAttribute('data-delete') });
                window.location.reload();
            });
        });

        document.querySelectorAll('[data-add-place]').forEach((button) => {
            button.addEventListener('click', async () => {
                const name = promptName('', 'Naam van de locatie binnen dit verzamelpunt');
                if (!name) return;
                const result = await post({
                    action: 'create_place',
                    location_id: button.getAttribute('data-add-place'),
                    name
                });
                if (result.ok) window.location.reload();
                else window.alert(result.error || 'Locatie toevoegen mislukt.');
            });
        });

        document.querySelectorAll('[data-rename-place]').forEach((button) => {
            button.addEventListener('click', async () => {
                const name = promptName(button.getAttribute('data-place-name') || '', 'Nieuwe naam van de locatie');
                if (!name) return;
                const result = await post({
                    action: 'update_place',
                    id: button.getAttribute('data-rename-place'),
                    name
                });
                if (result.ok) window.location.reload();
                else window.alert(result.error || 'Hernoemen mislukt.');
            });
        });

        document.querySelectorAll('[data-delete-place]').forEach((button) => {
            button.addEventListener('click', async () => {
                if (!confirm('Deze locatie verwijderen?')) return;
                await post({ action: 'delete_place', id: button.getAttribute('data-delete-place') });
                window.location.reload();
            });
        });
    </script>
</body>
</html>
