<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=300');

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$configFile = __DIR__ . '/analytics-config.php';
if (!is_file($configFile)) {
    respond(['ok' => false, 'configured' => false, 'message' => 'Google Analytics access is not configured yet.'], 503);
}

$config = require $configFile;
$propertyId = preg_replace('/\D/', '', (string)($config['property_id'] ?? ''));
$account = $config['service_account'] ?? [];
if (!$propertyId || empty($account['client_email']) || empty($account['private_key'])) {
    respond(['ok' => false, 'configured' => false, 'message' => 'The Analytics configuration is incomplete.'], 503);
}

function base64Url(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function googleAccessToken(array $account): string {
    $now = time();
    $header = base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = base64Url(json_encode([
        'iss' => $account['client_email'],
        'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
        'aud' => $account['token_uri'] ?? 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ]));
    $unsigned = $header . '.' . $claims;
    if (!openssl_sign($unsigned, $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign the Google access token.');
    }
    $jwt = $unsigned . '.' . base64Url($signature);
    $curl = curl_init($account['token_uri'] ?? 'https://oauth2.googleapis.com/token');
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt])]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $data = json_decode((string)$body, true);
    if ($status >= 300 || empty($data['access_token'])) throw new RuntimeException('Google authentication failed.');
    return $data['access_token'];
}

function runReport(string $propertyId, string $token, array $body): array {
    $url = 'https://analyticsdata.googleapis.com/v1beta/properties/' . $propertyId . ':runReport';
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body)]);
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $data = json_decode((string)$response, true);
    if ($status >= 300) throw new RuntimeException($data['error']['message'] ?? 'Analytics report request failed.');
    return $data ?: [];
}

function metricValue(array $report, int $row = 0, int $metric = 0): int {
    return (int)($report['rows'][$row]['metricValues'][$metric]['value'] ?? 0);
}

try {
    $token = googleAccessToken($account);
    $dateRanges = [['startDate' => 'today', 'endDate' => 'today'], ['startDate' => '7daysAgo', 'endDate' => 'today'], ['startDate' => '30daysAgo', 'endDate' => 'today'], ['startDate' => '2020-01-01', 'endDate' => 'today']];
    $views = [];
    foreach ($dateRanges as $range) {
        $views[] = metricValue(runReport($propertyId, $token, ['dateRanges' => [$range], 'metrics' => [['name' => 'screenPageViews']]]));
    }
    $trendReport = runReport($propertyId, $token, ['dateRanges' => [['startDate' => '90daysAgo', 'endDate' => 'today']], 'dimensions' => [['name' => 'date']], 'metrics' => [['name' => 'screenPageViews']], 'orderBys' => [['dimension' => ['dimensionName' => 'date']]], 'limit' => 100]);
    $userReport = runReport($propertyId, $token, ['dateRanges' => [['startDate' => '30daysAgo', 'endDate' => 'today']], 'metrics' => [['name' => 'totalUsers'], ['name' => 'newUsers']]]);
    $pageReport = runReport($propertyId, $token, ['dateRanges' => [['startDate' => '30daysAgo', 'endDate' => 'today']], 'dimensions' => [['name' => 'pageTitle'], ['name' => 'pagePath']], 'metrics' => [['name' => 'screenPageViews'], ['name' => 'totalUsers']], 'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]], 'limit' => 5]);
    $totalUsers = metricValue($userReport, 0, 0);
    $newUsers = metricValue($userReport, 0, 1);
    $trend = array_map(fn($row) => ['date' => $row['dimensionValues'][0]['value'] ?? '', 'views' => (int)($row['metricValues'][0]['value'] ?? 0)], $trendReport['rows'] ?? []);
    $pages = array_map(fn($row) => ['title' => $row['dimensionValues'][0]['value'] ?? 'Untitled page', 'path' => $row['dimensionValues'][1]['value'] ?? '/', 'views' => (int)($row['metricValues'][0]['value'] ?? 0), 'users' => (int)($row['metricValues'][1]['value'] ?? 0)], $pageReport['rows'] ?? []);
    respond(['ok' => true, 'source' => 'google-analytics', 'views' => ['today' => $views[0], 'week' => $views[1], 'month' => $views[2], 'allTime' => $views[3]], 'users' => ['total' => $totalUsers, 'returning' => max(0, $totalUsers - $newUsers)], 'trend' => $trend, 'pages' => $pages, 'updatedAt' => gmdate(DATE_ATOM)]);
} catch (Throwable $error) {
    respond(['ok' => false, 'configured' => true, 'message' => $error->getMessage()], 502);
}
