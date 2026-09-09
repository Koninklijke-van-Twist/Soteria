<?php

if (empty($graphCredentials['tenantId']) || empty($graphCredentials['clientId']) || empty($graphCredentials['clientSecret'])) {
    throw new RuntimeException('Graph-credentials ontbreken in auth.php.');
}

if (!function_exists('applyMicrosoftGraphCurlSslOptions')) {
    function applyMicrosoftGraphCurlSslOptions($curlHandle): void
    {
        $caInfo = ini_get('curl.cainfo');
        if ($caInfo === false || trim((string) $caInfo) === '' || !is_file((string) $caInfo)) {
            curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, 0);
        }
    }
}

$tenantId = $graphCredentials['tenantId'];
$clientId = $graphCredentials['clientId'];
$clientSecret = $graphCredentials['clientSecret'];

$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0750, true);
}

$today = date('Y-m-d');
foreach (glob($cacheDir . '/users_cache_*.json') ?: [] as $filePath) {
    $fileName = basename($filePath);
    if (preg_match('/^users_cache_(\d{4}-\d{2}-\d{2})\.json$/', $fileName, $matches)) {
        if ($matches[1] < $today) {
            unlink($filePath);
        }
    }
}

$cacheFile = $cacheDir . '/users_cache_' . $today . '.json';
if (is_file($cacheFile)) {
    $data = json_decode((string) file_get_contents($cacheFile), true);
    if (is_array($data)) {
        return $data;
    }
}

$tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";
$tokenPostFields = http_build_query([
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'scope' => 'https://graph.microsoft.com/.default',
    'grant_type' => 'client_credentials',
]);

$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $tokenPostFields,
    CURLOPT_RETURNTRANSFER => true,
]);
applyMicrosoftGraphCurlSslOptions($ch);

$tokenResponse = curl_exec($ch);
if ($tokenResponse === false) {
    throw new RuntimeException('Fout bij ophalen token: ' . curl_error($ch));
}

$tokenData = json_decode((string) $tokenResponse, true);
curl_close($ch);

if (!isset($tokenData['access_token'])) {
    throw new RuntimeException('Geen access_token in token response.');
}

$accessToken = $tokenData['access_token'];
$graphUrl = 'https://graph.microsoft.com/v1.0/users?$select=id,accountEnabled,displayName,mail,userPrincipalName,jobTitle,userType,businessPhones&$filter=accountEnabled%20eq%20true';
$allUsers = [];

do {
    $ch = curl_init($graphUrl);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
    ]);
    applyMicrosoftGraphCurlSslOptions($ch);

    $usersResponse = curl_exec($ch);
    if ($usersResponse === false) {
        throw new RuntimeException('Fout bij ophalen users: ' . curl_error($ch));
    }

    $usersData = json_decode((string) $usersResponse, true);
    curl_close($ch);

    if (!isset($usersData['value']) || !is_array($usersData['value'])) {
        throw new RuntimeException('Onverwachte users response.');
    }

    $allUsers = array_merge($allUsers, $usersData['value']);
    $graphUrl = $usersData['@odata.nextLink'] ?? null;
} while ($graphUrl !== null);

$result = [];
foreach ($allUsers as $user) {
    if (trim((string) ($user['jobTitle'] ?? '')) === '') {
        continue;
    }

    $businessPhones = $user['businessPhones'] ?? [];
    $telefoonnummer = null;
    if (is_array($businessPhones) && $businessPhones !== []) {
        $telefoonnummer = $businessPhones[0];
    }

    $resolvedEmail = strtolower(trim((string) (($user['mail'] ?? '') !== '' ? $user['mail'] : ($user['userPrincipalName'] ?? ''))));
    if ($resolvedEmail === '' || !filter_var($resolvedEmail, FILTER_VALIDATE_EMAIL)) {
        continue;
    }

    $result[] = [
        'Id' => $user['id'] ?? null,
        'Naam' => $user['displayName'] ?? null,
        'Email' => $resolvedEmail,
        'Telefoonnummer' => $telefoonnummer,
        'Titel' => $user['jobTitle'] ?? null,
    ];
}

file_put_contents(
    $cacheFile,
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    LOCK_EX
);

return $result;
