<?php
declare(strict_types=1);

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

date_default_timezone_set('Europe/Berlin');

define('RC_RINGCX_WALLBOARD_REFRESH_SECONDS', 15);

$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

$CONFIG_FILE = '/etc/suitecrm/rc_ringcx_wallboard.php';
$CACHE_DIR = '/var/lib/rc_ringcx_wallboard';
$TOKEN_CACHE_FILE = $CACHE_DIR . '/token_cache.json';

if (!is_dir($CACHE_DIR)) {
    @mkdir($CACHE_DIR, 0750, true);
}

$config = is_readable($CONFIG_FILE) ? include $CONFIG_FILE : false;
if ($config === false || !isset($config['RingCX'])) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['error' => 'Config Fehler']); sugar_cleanup(true); }
    echo '<div style="color:red; padding:20px;">Config konnte nicht geladen werden (/etc/suitecrm/rc_ringcx_wallboard.php).</div>';
    sugar_cleanup(true);
}

// --- Portierte Funktionen (identisch zum RingCX Wallboard in WordPress) ---

function rc_ringcx_format_duration($seconds): string {
    if ($seconds === null || $seconds === '') return '00:00:00';
    $seconds = max(0, (int)$seconds);
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}

function rc_ringcx_http_request(string $url, string $method = 'GET', array $headers = [], $data = null): array {
    $ch = curl_init();
    $headerLines = [];
    foreach ($headers as $key => $value) { $headerLines[] = $key . ': ' . $value; }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headerLines, CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ]);
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
    }
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    if ($body === false) { curl_close($ch); throw new RuntimeException('cURL-Fehler: ' . $error); }
    curl_close($ch);
    return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true)];
}

function rc_ringcx_load_token_cache(string $file): array {
    if (!file_exists($file)) return [];
    $json = file_get_contents($file);
    if ($json === false || trim($json) === '') return [];
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function rc_ringcx_save_token_cache(string $file, array $data): void {
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($tmp, $file);
}

function rc_ringcx_build_basic_auth(string $clientId, string $clientSecret): string {
    return 'Basic ' . base64_encode($clientId . ':' . $clientSecret);
}

function rc_ringcx_login_with_jwt(array $ringcx): array {
    $clientId = $ringcx['CLIENT_ID']; $clientSecret = $ringcx['CLIENT_SECRET']; $jwtAssertion = $ringcx['JWT_ASSERTION'];
    $authUrl = $ringcx['AUTH_URL'] ?? 'https://platform.ringcentral.com/restapi/oauth/token'; $baseUrl = rtrim($ringcx['BASE_URL'], '/');
    $rcHeaders = ['Authorization' => rc_ringcx_build_basic_auth($clientId, $clientSecret), 'Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'];
    $rcData = ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwtAssertion];
    $rcResponse = rc_ringcx_http_request($authUrl, 'POST', $rcHeaders, $rcData);
    if ($rcResponse['status'] < 200 || $rcResponse['status'] >= 300 || empty($rcResponse['json']['access_token'])) { throw new RuntimeException('RingEX Token Fehler: ' . $rcResponse['body']); }
    $exJson = $rcResponse['json']; $rcAccessToken = $exJson['access_token']; $rcTokenType = $exJson['token_type'] ?? 'Bearer';
    $cxHeaders = ['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'];
    $cxData = ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwtAssertion, 'rcAccessToken' => $rcAccessToken, 'rcTokenType' => $rcTokenType];
    $cxResponse = rc_ringcx_http_request($baseUrl . '/api/auth/login/rc/accesstoken', 'POST', $cxHeaders, $cxData);
    if ($cxResponse['status'] < 200 || $cxResponse['status'] >= 300 || empty($cxResponse['json']['accessToken'])) { throw new RuntimeException('RingCX Login Fehler: ' . $cxResponse['body']); }
    return [
        'ringex_access_token' => $exJson['access_token'], 'ringex_token_type' => $exJson['token_type'] ?? 'Bearer', 'ringex_expires_at' => time() + (int)($exJson['expires_in'] ?? 3600) - 60,
        'ringex_refresh_token' => $exJson['refresh_token'] ?? null, 'ringex_refresh_expires_at' => isset($exJson['refresh_token_expires_in']) ? time() + (int)$exJson['refresh_token_expires_in'] - 60 : 0,
        'ringcx_access_token' => $cxResponse['json']['accessToken'], 'ringcx_token_type' => $cxResponse['json']['tokenType'] ?? 'Bearer', 'ringcx_expires_at' => time() + (int)($exJson['expires_in'] ?? 3600) - 60,
    ];
}

function rc_ringcx_refresh_ringex_token(array $ringcx, array $cache): array {
    if (empty($cache['ringex_refresh_token'])) throw new RuntimeException('Kein Refresh-Token.');
    if (!empty($cache['ringex_refresh_expires_at']) && time() >= (int)$cache['ringex_refresh_expires_at']) throw new RuntimeException('Refresh-Token abgelaufen.');
    $clientId = $ringcx['CLIENT_ID']; $clientSecret = $ringcx['CLIENT_SECRET']; $authUrl = $ringcx['AUTH_URL'] ?? 'https://platform.ringcentral.com/restapi/oauth/token';
    $headers = ['Authorization' => rc_ringcx_build_basic_auth($clientId, $clientSecret), 'Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'];
    $data = ['grant_type' => 'refresh_token', 'refresh_token' => $cache['ringex_refresh_token']];
    $response = rc_ringcx_http_request($authUrl, 'POST', $headers, $data);
    if ($response['status'] < 200 || $response['status'] >= 300 || empty($response['json']['access_token'])) throw new RuntimeException('Refresh fehlgeschlagen.');
    $json = $response['json'];
    return [
        'ringex_access_token' => $json['access_token'], 'ringex_token_type' => $json['token_type'] ?? 'Bearer', 'ringex_expires_at' => time() + (int)($json['expires_in'] ?? 3600) - 60,
        'ringex_refresh_token' => $json['refresh_token'] ?? $cache['ringex_refresh_token'], 'ringex_refresh_expires_at' => isset($json['refresh_token_expires_in']) ? time() + (int)$json['refresh_token_expires_in'] - 60 : ($cache['ringex_refresh_expires_at'] ?? 0),
    ];
}

function rc_ringcx_login_ringcx_with_ringex(array $ringcx, string $ringexAccessToken, string $ringexTokenType): array {
    $baseUrl = rtrim($ringcx['BASE_URL'], '/'); $jwtAssertion = $ringcx['JWT_ASSERTION'];
    $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'];
    $data = ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwtAssertion, 'rcAccessToken' => $ringexAccessToken, 'rcTokenType' => $ringexTokenType];
    $response = rc_ringcx_http_request($baseUrl . '/api/auth/login/rc/accesstoken', 'POST', $headers, $data);
    if ($response['status'] < 200 || $response['status'] >= 300 || empty($response['json']['accessToken'])) throw new RuntimeException('RingCX Login failed.');
    return ['ringcx_access_token' => $response['json']['accessToken'], 'ringcx_token_type' => $response['json']['tokenType'] ?? 'Bearer'];
}

function rc_ringcx_get_valid_token(array $ringcx, string $cacheFile): array {
    $cache = rc_ringcx_load_token_cache($cacheFile);
    if (!empty($cache['ringcx_access_token']) && !empty($cache['ringcx_token_type']) && !empty($cache['ringcx_expires_at']) && time() < (int)$cache['ringcx_expires_at']) {
        return [$cache['ringcx_access_token'], $cache['ringcx_token_type']];
    }

    // Lock analog zum RingEX-Wallboard-Fix: verhindert paralleles Einloesen desselben
    // Refresh-Tokens durch mehrere gleichzeitige Requests ("Token not found").
    $lockHandle = fopen($cacheFile . '.lock', 'c');
    if ($lockHandle !== false) { flock($lockHandle, LOCK_EX); }
    try {
        $cache = rc_ringcx_load_token_cache($cacheFile);
        if (!empty($cache['ringcx_access_token']) && !empty($cache['ringcx_token_type']) && !empty($cache['ringcx_expires_at']) && time() < (int)$cache['ringcx_expires_at']) {
            return [$cache['ringcx_access_token'], $cache['ringcx_token_type']];
        }
        try {
            if (!empty($cache['ringex_refresh_token']) && !empty($cache['ringex_refresh_expires_at']) && time() < (int)$cache['ringex_refresh_expires_at']) {
                $refreshed = rc_ringcx_refresh_ringex_token($ringcx, $cache);
                $cx = rc_ringcx_login_ringcx_with_ringex($ringcx, $refreshed['ringex_access_token'], $refreshed['ringex_token_type']);
                $newCache = array_merge($cache, $refreshed, $cx, ['ringcx_expires_at' => $refreshed['ringex_expires_at']]);
                rc_ringcx_save_token_cache($cacheFile, $newCache);
                return [$newCache['ringcx_access_token'], $newCache['ringcx_token_type']];
            }
        } catch (Throwable $e) {}
        $fresh = rc_ringcx_login_with_jwt($ringcx); rc_ringcx_save_token_cache($cacheFile, $fresh);
        return [$fresh['ringcx_access_token'], $fresh['ringcx_token_type']];
    } finally {
        if ($lockHandle !== false) { flock($lockHandle, LOCK_UN); fclose($lockHandle); }
    }
}

function rc_ringcx_get_agent_data(array $ringcx, string $cacheFile): array {
    [$accessToken, $tokenType] = rc_ringcx_get_valid_token($ringcx, $cacheFile);
    $baseUrl = rtrim($ringcx['BASE_URL'], '/'); $accountId = $ringcx['ACCOUNT_ID'];
    $headers = ['Authorization' => $tokenType . ' ' . $accessToken, 'Content-Type' => 'application/json'];
    $url = $baseUrl . '/voice/api/v1/admin/accounts/' . $accountId . '/realTimeData/inbound';
    $response = rc_ringcx_http_request($url, 'GET', $headers);
    if ($response['status'] === 401 && str_contains($response['body'], 'Jwt is expired')) {
        @unlink($cacheFile); [$accessToken, $tokenType] = rc_ringcx_get_valid_token($ringcx, $cacheFile);
        $headers['Authorization'] = $tokenType . ' ' . $accessToken; $response = rc_ringcx_http_request($url, 'GET', $headers);
    }
    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) throw new RuntimeException('Realtime API Fehler');
    $rows = [];
    foreach ($response['json'] as $item) {
        $calls = (int)($item['accepted'] ?? 0); $talkSec = (int)($item['totalTalkTime'] ?? 0); $waitSec = (int)($item['totalQueueTime'] ?? 0); $lngQueueTime = (int)($item['longestInQueue'] ?? 0);
        $rows[] = [
            'name' => $item['gateName'] ?? 'Unknown', 'state' => strtoupper((string)($item['state'] ?? 'UNKNOWN')), 'queued' => (int)($item['inQueue'] ?? 0),
            'calls' => $calls, 'offered' => (int)($item['presented'] ?? 0), 'talk' => $talkSec, 'wait' => $waitSec, 'avg' => $calls > 0 ? intdiv($talkSec, $calls) : 0,
            'avgQueue' => $calls > 0 ? intdiv($waitSec, $calls) : 0, 'lngQueue' => $lngQueueTime, 'abn' => (int)($item['abandoned'] ?? 0), 'disconnect' => (int)($item['deflected'] ?? 0),
        ];
    }
    return $rows;
}

function rc_ringcx_get_agent_realtime_data(array $ringcx, string $cacheFile): array {
    [$accessToken, $tokenType] = rc_ringcx_get_valid_token($ringcx, $cacheFile);
    $baseUrl = rtrim($ringcx['BASE_URL'], '/'); $accountId = $ringcx['ACCOUNT_ID'];
    $headers = ['Authorization' => $tokenType . ' ' . $accessToken, 'Content-Type' => 'application/json'];
    $url = $baseUrl . '/voice/api/v1/admin/accounts/' . $accountId . '/realTimeData/agent';
    $response = rc_ringcx_http_request($url, 'GET', $headers);
    if ($response['status'] === 401 && str_contains($response['body'], 'Jwt is expired')) {
        @unlink($cacheFile); [$accessToken, $tokenType] = rc_ringcx_get_valid_token($ringcx, $cacheFile);
        $headers['Authorization'] = $tokenType . ' ' . $accessToken; $response = rc_ringcx_http_request($url, 'GET', $headers);
    }
    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) throw new RuntimeException('Agent Realtime Fehler');
    $rows = [];
    foreach ($response['json'] as $item) {
        $firstName = trim((string)($item['firstName'] ?? '')); $lastName = trim((string)($item['lastName'] ?? '')); $fullName = trim($firstName . ' ' . $lastName);
        $rows[] = [
            'id' => (string)($item['agentId'] ?? $item['id'] ?? $item['userId'] ?? ''), 'name' => $item['agentName'] ?? $item['name'] ?? ($fullName !== '' ? $fullName : 'Unknown'),
            'state' => strtoupper((string)($item['state'] ?? $item['agentState'] ?? 'UNKNOWN')), 'acd' => $item['callsHandled'] ?? '0', 'rna' => $item['rna'] ?? '0',
            'agn_talk_time' => (int)($item['totalTalkTime'] ?? 0), 'statusTime' => (int)($item['stateTime'] ?? 0),
        ];
    }
    return $rows;
}

function rc_ringcx_get_all_group_agents(array $ringcx, string $cacheFile, int $agentGroupId): array {
    [$accessToken, $tokenType] = rc_ringcx_get_valid_token($ringcx, $cacheFile);
    $baseUrl = rtrim($ringcx['BASE_URL'], '/'); $accountId = $ringcx['ACCOUNT_ID'];
    $headers = ['Authorization' => $tokenType . ' ' . $accessToken, 'Content-Type' => 'application/json'];
    $url = $baseUrl . '/voice/api/v1/admin/accounts/' . $accountId . '/agentGroups/' . $agentGroupId . '/agents';
    $response = rc_ringcx_http_request($url, 'GET', $headers);
    if ($response['status'] === 401 && str_contains($response['body'], 'Jwt is expired')) {
        @unlink($cacheFile); [$accessToken, $tokenType] = rc_ringcx_get_valid_token($ringcx, $cacheFile);
        $headers['Authorization'] = $tokenType . ' ' . $accessToken; $response = rc_ringcx_http_request($url, 'GET', $headers);
    }
    if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) throw new RuntimeException('Agent Groups Fehler');
    $rows = [];
    foreach ($response['json'] as $item) {
        $firstName = trim((string)($item['firstName'] ?? '')); $lastName  = trim((string)($item['lastName'] ?? '')); $fullName  = trim($firstName . ' ' . $lastName);
        $rows[] = [
            'id' => (string)($item['agentId'] ?? $item['id'] ?? $item['userId'] ?? ''), 'name' => $item['agentName'] ?? $item['name'] ?? ($fullName !== '' ? $fullName : 'Unknown'),
            'state' => 'NICHT ANGEMELDET', 'acd' => $item['callsHandled'] ?? '0', 'rna' => $item['rna'] ?? '0',
            'agn_talk_time' => (int)($item['totalTalkTime'] ?? 0), 'statusTime' => (int)($item['stateTime'] ?? 0),
        ];
    }
    return $rows;
}

function rc_ringcx_merge_agents_with_realtime(array $allAgents, array $liveAgents): array {
    $merged = []; $liveById = []; $liveByName = [];
    foreach ($liveAgents as $agent) {
        if (!empty($agent['id'])) $liveById[(string)$agent['id']] = $agent;
        $liveByName[mb_strtolower(trim((string)$agent['name']))] = $agent;
    }
    foreach ($allAgents as $agent) {
        $match = null;
        if (!empty($agent['id']) && isset($liveById[(string)$agent['id']])) { $match = $liveById[(string)$agent['id']]; }
        else {
            $nameKey = mb_strtolower(trim((string)$agent['name']));
            if (isset($liveByName[$nameKey])) $match = $liveByName[$nameKey];
        }
        $merged[] = ($match !== null) ? array_merge($agent, $match) : $agent;
    }
    usort($merged, function ($a, $b) { return strcasecmp((string)$a['name'], (string)$b['name']); });
    return $merged;
}

// --- Ende portierte Funktionen ---

$error = null;
$data = [];
$agents = [];

try {
    $data = rc_ringcx_get_agent_data($config['RingCX'], $TOKEN_CACHE_FILE);
    $allAgents = rc_ringcx_get_all_group_agents($config['RingCX'], $TOKEN_CACHE_FILE, (int)$config['RingCX']['AGENT_GROUP_ID']);
    $liveAgents = rc_ringcx_get_agent_realtime_data($config['RingCX'], $TOKEN_CACHE_FILE);
    $agents = rc_ringcx_merge_agents_with_realtime($allAgents, $liveAgents);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$lastUpdate = date('H:i:s');

ob_start();
if ($error !== null): ?>
    <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php else: ?>

    <div class="content">
        <h3 class="section-title">Queue Performance</h3>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Queue</th><th>State</th><th>Offered</th><th>Calls</th><th>Abandon</th>
                        <th>Disconnect</th><th>Queued</th><th>Total Talk</th><th>Avg Talk</th>
                        <th>Total Queue</th><th>Avg Queue</th><th>Longest Wait</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $row): ?>
                        <tr>
                            <td style="font-weight: 500; color: #2c3e50;"><?= htmlspecialchars((string)$row['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="<?= str_contains($row['state'], 'OPEN') ? 'state-open' : 'state-closed' ?>">
                                <span><?= htmlspecialchars((string)$row['state'], ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td><?= (int)$row['offered'] ?></td>
                            <td><?= (int)$row['calls'] ?></td>
                            <td><?= (int)$row['abn'] ?></td>
                            <td><?= (int)$row['disconnect'] ?></td>
                            <td class="<?= (int)$row['queued'] > 0 ? 'alert-queued' : '' ?>"><?= (int)$row['queued'] ?></td>
                            <td class="num"><?= rc_ringcx_format_duration($row['talk']) ?></td>
                            <td class="num"><?= rc_ringcx_format_duration($row['avg']) ?></td>
                            <td class="num"><?= rc_ringcx_format_duration($row['wait']) ?></td>
                            <td class="num"><?= rc_ringcx_format_duration($row['avgQueue']) ?></td>
                            <td class="num"><?= rc_ringcx_format_duration($row['lngQueue']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="content">
        <h3 class="section-title">Agents</h3>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Agent</th><th>State</th><th>ACD</th><th>RONA</th><th>Total Talk Time</th><th>Status Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agents as $agent): ?>
                        <tr>
                            <td style="font-weight: 500; color: #2c3e50;"><?= htmlspecialchars((string)$agent['name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="<?= (str_contains($agent['state'], 'AVAILABLE') || str_contains($agent['state'], 'OPEN') || stripos($agent['state'], 'verfügbar') !== false) ? 'state-open' : 'state-closed' ?>">
                                <span><?= htmlspecialchars((string)$agent['state'], ENT_QUOTES, 'UTF-8') ?></span>
                            </td>
                            <td><?= htmlspecialchars((string)$agent['acd'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)$agent['rna'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="num"><?= rc_ringcx_format_duration($agent['agn_talk_time']) ?></td>
                            <td class="num"><?= rc_ringcx_format_duration($agent['statusTime']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif;

$dynamicHtml = ob_get_clean();

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['html' => $dynamicHtml, 'lastUpdate' => $lastUpdate]);
    sugar_cleanup(true);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>RingCX Live Performance Dashboard</title>
<style>
    body { font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #eef1f5; margin: 0; padding: 24px; }
    .ringcx-wrapper { font-family: inherit; background: #ffffff; color: #333333; padding: 0; margin: 0 auto; max-width: 1500px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); border-radius: 8px; border: 1px solid #ebd5b9; overflow: hidden; }
    .ringcx-wrapper *, .ringcx-wrapper *::before, .ringcx-wrapper *::after { box-sizing: border-box; }
    .ringcx-wrapper .header-bar { background: #f8fafc; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #dcd3c1; flex-wrap: wrap; gap: 8px; }
    .ringcx-wrapper .header-title { color: #0f172a; font-size: 1.25rem; font-weight: 600; margin: 0; letter-spacing: -0.025em; }
    .ringcx-wrapper .header-title a { color: #64748b; font-size: 0.7em; font-weight: 500; text-decoration: none; margin-left: 12px; }
    .ringcx-wrapper .header-title a:hover { text-decoration: underline; }
    .ringcx-wrapper .timer-stats { text-align: right; }
    .ringcx-wrapper .timer-label { color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 600; }
    .ringcx-wrapper .timer-val { color: #0f172a; font-family: inherit; font-size: 1.1em; font-weight: 700; margin-left: 4px; }
    .ringcx-wrapper .content { padding: 24px; border-bottom: 1px solid #ebd5b9; }
    .ringcx-wrapper .content:last-child { border-bottom: none; }
    .ringcx-wrapper .section-title { color: #334155; font-size: 1.1em; font-weight: 600; margin: 0 0 16px 0; text-transform: uppercase; letter-spacing: 0.05em; }
    .ringcx-wrapper .error { margin: 20px; padding: 15px 20px; background: #fef2f2; border: 1px solid #f87171; border-radius: 6px; color: #b91c1c; }
    .ringcx-wrapper .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .ringcx-wrapper table { width: 100%; border-collapse: collapse; margin: 0; font-size: 14px; }
    .ringcx-wrapper th { color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 600; padding: 12px 10px; text-align: left; white-space: nowrap; border-bottom: 2px solid #ebd5b9; background: #f8fafc; }
    .ringcx-wrapper td { padding: 12px 10px; white-space: nowrap; border-bottom: 1px solid #ebd5b9; color: #475569; }
    .ringcx-wrapper tr:hover td { background-color: #f8fafc; }
    .ringcx-wrapper .state-open span { background: #f3e6d5; color: #1a1a1a; padding: 4px 8px; border-radius: 12px; font-size: 0.85em; font-weight: 600; }
    .ringcx-wrapper .state-closed span { background: #f1f5f9; color: #64748b; padding: 4px 8px; border-radius: 12px; font-size: 0.85em; font-weight: 600; }
    .ringcx-wrapper .alert-queued { color: #dc2626; font-weight: bold; }
    .ringcx-wrapper .num { font-family: inherit; font-size: 0.95em; }
</style>
</head>
<body>
<div class="ringcx-wrapper">
    <div class="header-bar">
        <h2 class="header-title">RingCX Live Performance Dashboard<a href="/index.php">&larr; Zur&uuml;ck zu SuiteCRM</a></h2>
        <div class="timer-stats">
            <div class="timer-label">Last Data Sync: <span id="sync-time" class="timer-val"><?= htmlspecialchars($lastUpdate, ENT_QUOTES, 'UTF-8') ?></span></div>
            <div class="timer-label" style="margin-top: 4px;">Time Since Refresh: <span id="sec-counter" class="timer-val">0</span>s</div>
        </div>
    </div>

    <div id="ringcx-dynamic-content">
        <?= $dynamicHtml ?>
    </div>

    <script>
        let seconds = 0;
        setInterval(function () {
            seconds++;
            document.getElementById('sec-counter').textContent = seconds;
        }, 1000);

        setInterval(function() {
            const ajaxUrl = '/legacy/index.php?entryPoint=RC_RingCX_Wallboard&ajax=1';
            fetch(ajaxUrl)
                .then(function (response) {
                    if (!response.ok) throw new Error('Netzwerkfehler');
                    return response.json();
                })
                .then(function (data) {
                    if (data.html) {
                        document.getElementById('ringcx-dynamic-content').innerHTML = data.html;
                        document.getElementById('sync-time').textContent = data.lastUpdate;
                        seconds = 0;
                        document.getElementById('sec-counter').textContent = seconds;
                    }
                })
                .catch(function (error) { console.error('RingCX Wallboard Fetch Fehler:', error); });
        }, <?= RC_RINGCX_WALLBOARD_REFRESH_SECONDS * 1000 ?>);
    </script>
</div>
</body>
</html>
<?php
sugar_cleanup(true);
