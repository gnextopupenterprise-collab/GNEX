<?php
declare(strict_types=1);

$rootDir = dirname(__DIR__);
date_default_timezone_set('Asia/Kuala_Lumpur');
const CL_REMEMBER_COOKIE = 'clash_league_remember';
const CL_REMEMBER_DAYS = 30;
session_set_cookie_params([
    'lifetime' => CL_REMEMBER_DAYS * 86400,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

set_exception_handler(static function (Throwable $error): void {
    error_log($error->__toString());
    json_response([
        'ok' => false,
        'message' => 'Server error: ' . $error->getMessage(),
    ], 500);
});

$dbConfig = require $rootDir . DIRECTORY_SEPARATOR . 'scrim-db-config.php';
$pushConfigPath = $rootDir . DIRECTORY_SEPARATOR . 'scrim-push-config.php';
$pushConfig = is_file($pushConfigPath) ? require $pushConfigPath : [];

try {
    $pdo = new PDO(
        'mysql:host=' . $dbConfig['host'] . ';dbname=' . $dbConfig['database'] . ';charset=utf8mb4',
        $dbConfig['username'],
        $dbConfig['password'],
        [
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $error) {
    json_response([
        'ok' => false,
        'message' => 'Database belum connect: ' . $error->getMessage(),
    ], 500);
}

ensure_schema($pdo);
seed_admin($pdo, $dbConfig);

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'teams') {
    renumber_accepted_slots($pdo);
    json_response(['ok' => true, 'teams' => get_public_teams($pdo)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'state') {
    renumber_accepted_slots($pdo);
    json_response(get_state($pdo));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'pushConfig') {
    json_response([
        'ok' => true,
        'push_public_key' => $pushConfig['public_key'] ?? null,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'pushLatest') {
    push_latest_notification($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'registrations') {
    json_response(['ok' => true, 'teams' => get_admin_registrations($pdo, $dbConfig)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'registerTeam') {
    register_team($pdo, $rootDir);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'savePushSubscription') {
    save_push_subscription($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'setTeamStatus') {
    set_team_status($pdo, $dbConfig);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'updateTeamInfo') {
    update_team_info($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'removeTeam') {
    remove_team($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'generateRandomMatches') {
    generate_random_matches($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'updateMatch') {
    update_match($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    login_user($pdo, $dbConfig);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'logout') {
    forget_persistent_login($pdo);
    $_SESSION = [];
    session_destroy();
    json_response(['ok' => true, 'message' => 'Logout berjaya.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'openAdminChat') {
    open_admin_chat($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'sendMessage') {
    send_message($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'submitResult') {
    submit_result($pdo, $rootDir);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveRules') {
    save_rules($pdo);
}

json_response([
    'ok' => true,
    'message' => 'Clash League PHP API live.',
]);

function json_response(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }
    echo json_encode($payload);
    exit;
}

function ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_teams (
            id INT AUTO_INCREMENT PRIMARY KEY,
            team_name VARCHAR(100) NOT NULL UNIQUE,
            logo_url VARCHAR(500) NULL,
            logo_path VARCHAR(255) NULL,
            phone VARCHAR(40) NOT NULL,
            password_hash VARCHAR(255) NULL,
            coach_name VARCHAR(100) NULL,
            manager_name VARCHAR(100) NULL,
            status ENUM('pending','accepted','rejected','removed') NOT NULL DEFAULT 'pending',
            slot_no INT NULL,
            admin_note VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_cl_teams_status_slot (status, slot_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!column_exists($pdo, 'cl_teams', 'password_hash')) {
        $pdo->exec('ALTER TABLE cl_teams ADD COLUMN password_hash VARCHAR(255) NULL AFTER phone');
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_players (
            id INT AUTO_INCREMENT PRIMARY KEY,
            team_id INT NOT NULL,
            player_slot TINYINT NOT NULL,
            ign VARCHAR(100) NULL,
            player_id VARCHAR(80) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cl_player_slot (team_id, player_slot),
            FOREIGN KEY (team_id) REFERENCES cl_teams(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_rooms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_type ENUM('admin','deal','match') NOT NULL DEFAULT 'admin',
            team_a_id INT NULL,
            team_b_id INT NULL,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            FOREIGN KEY (team_a_id) REFERENCES cl_teams(id) ON DELETE SET NULL,
            FOREIGN KEY (team_b_id) REFERENCES cl_teams(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_id INT NOT NULL,
            sender_type ENUM('admin','team','system') NOT NULL DEFAULT 'team',
            sender_team_id INT NULL,
            message VARCHAR(700) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (room_id) REFERENCES cl_rooms(id) ON DELETE CASCADE,
            FOREIGN KEY (sender_team_id) REFERENCES cl_teams(id) ON DELETE SET NULL,
            INDEX idx_cl_messages_room (room_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_matches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            team_a_id INT NULL,
            team_b_id INT NULL,
            match_name VARCHAR(120) NOT NULL DEFAULT 'Next Match',
            match_time DATETIME NULL,
            status ENUM('up_next','live','completed','hidden') NOT NULL DEFAULT 'hidden',
            team_a_point INT NULL,
            team_b_point INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            FOREIGN KEY (team_a_id) REFERENCES cl_teams(id) ON DELETE SET NULL,
            FOREIGN KEY (team_b_id) REFERENCES cl_teams(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_match_results (
            id INT AUTO_INCREMENT PRIMARY KEY,
            match_id INT NOT NULL,
            team_id INT NOT NULL,
            team_a_point INT NOT NULL,
            team_b_point INT NOT NULL,
            screenshot_url VARCHAR(500) NULL,
            screenshot_path VARCHAR(255) NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            admin_note VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_cl_match_result_team (match_id, team_id),
            FOREIGN KEY (match_id) REFERENCES cl_matches(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES cl_teams(id) ON DELETE CASCADE,
            INDEX idx_cl_match_results_status (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_admin_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(80) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_settings (
            setting_key VARCHAR(80) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_push_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_type ENUM('team','admin') NOT NULL DEFAULT 'team',
            team_id INT NULL,
            admin_id INT NULL,
            endpoint_hash CHAR(64) NOT NULL UNIQUE,
            endpoint TEXT NOT NULL,
            p256dh VARCHAR(255) NULL,
            auth VARCHAR(255) NULL,
            user_agent VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            FOREIGN KEY (team_id) REFERENCES cl_teams(id) ON DELETE CASCADE,
            FOREIGN KEY (admin_id) REFERENCES cl_admin_users(id) ON DELETE CASCADE,
            INDEX idx_cl_push_team (team_id),
            INDEX idx_cl_push_admin (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_push_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_type ENUM('team','admin') NOT NULL DEFAULT 'team',
            team_id INT NULL,
            admin_id INT NULL,
            title VARCHAR(120) NOT NULL,
            body VARCHAR(500) NOT NULL,
            url VARCHAR(255) NOT NULL DEFAULT 'clash-league.html',
            tag VARCHAR(80) NOT NULL DEFAULT 'clash-league',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (team_id) REFERENCES cl_teams(id) ON DELETE CASCADE,
            FOREIGN KEY (admin_id) REFERENCES cl_admin_users(id) ON DELETE CASCADE,
            INDEX idx_cl_push_events_team (team_id, id),
            INDEX idx_cl_push_events_admin (admin_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_login_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_type ENUM('team','admin') NOT NULL DEFAULT 'team',
            team_id INT NULL,
            admin_id INT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            user_agent VARCHAR(255) NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            FOREIGN KEY (team_id) REFERENCES cl_teams(id) ON DELETE CASCADE,
            FOREIGN KEY (admin_id) REFERENCES cl_admin_users(id) ON DELETE CASCADE,
            INDEX idx_cl_login_team (team_id),
            INDEX idx_cl_login_admin (admin_id),
            INDEX idx_cl_login_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function seed_admin(PDO $pdo, array $dbConfig): void
{
    $username = clean_text($dbConfig['admin_username'] ?? 'admin', 80);
    $password = (string) ($dbConfig['admin_password'] ?? '');
    if ($username === '' || $password === '') {
        return;
    }

    $stmt = $pdo->prepare('SELECT id, password_hash FROM cl_admin_users WHERE username = ?');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();
    $hash = password_hash($password, PASSWORD_DEFAULT);

    if (!$admin) {
        $stmt = $pdo->prepare('INSERT INTO cl_admin_users (username, password_hash) VALUES (?, ?)');
        $stmt->execute([$username, $hash]);
        return;
    }

    if (!password_verify($password, (string) $admin['password_hash'])) {
        $stmt = $pdo->prepare('UPDATE cl_admin_users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$hash, $admin['id']]);
    }
}

function remember_cookie_options(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function issue_persistent_login(PDO $pdo, string $ownerType, ?int $teamId = null, ?int $adminId = null): void
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = time() + (CL_REMEMBER_DAYS * 86400);
    $agent = clean_text($_SERVER['HTTP_USER_AGENT'] ?? '', 255);

    $cleanup = $pdo->prepare('DELETE FROM cl_login_tokens WHERE expires_at < NOW()');
    $cleanup->execute();

    if ($ownerType === 'team' && $teamId !== null) {
        $delete = $pdo->prepare('DELETE FROM cl_login_tokens WHERE owner_type = "team" AND team_id = ?');
        $delete->execute([$teamId]);
    } elseif ($ownerType === 'admin' && $adminId !== null) {
        $delete = $pdo->prepare('DELETE FROM cl_login_tokens WHERE owner_type = "admin" AND admin_id = ?');
        $delete->execute([$adminId]);
    }

    $stmt = $pdo->prepare('
        INSERT INTO cl_login_tokens (owner_type, team_id, admin_id, token_hash, user_agent, expires_at, updated_at)
        VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), CURRENT_TIMESTAMP)
    ');
    $stmt->execute([$ownerType, $teamId, $adminId, $hash, $agent, $expires]);

    setcookie(CL_REMEMBER_COOKIE, $token, remember_cookie_options($expires));
    $_COOKIE[CL_REMEMBER_COOKIE] = $token;
}

function forget_persistent_login(PDO $pdo): void
{
    $token = (string) ($_COOKIE[CL_REMEMBER_COOKIE] ?? '');
    if ($token !== '') {
        $stmt = $pdo->prepare('DELETE FROM cl_login_tokens WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
    }
    setcookie(CL_REMEMBER_COOKIE, '', remember_cookie_options(time() - 3600));
    unset($_COOKIE[CL_REMEMBER_COOKIE]);
}

function restore_persistent_login(PDO $pdo): void
{
    static $attempted = false;
    if ($attempted || !empty($_SESSION['cl_team_id']) || !empty($_SESSION['cl_admin_id'])) {
        return;
    }
    $attempted = true;

    $token = (string) ($_COOKIE[CL_REMEMBER_COOKIE] ?? '');
    if ($token === '') {
        return;
    }

    $stmt = $pdo->prepare('
        SELECT id, owner_type, team_id, admin_id
        FROM cl_login_tokens
        WHERE token_hash = ? AND expires_at > NOW()
        LIMIT 1
    ');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row) {
        forget_persistent_login($pdo);
        return;
    }

    if ($row['owner_type'] === 'team' && !empty($row['team_id'])) {
        $teamStmt = $pdo->prepare('SELECT id FROM cl_teams WHERE id = ? AND status != "removed" LIMIT 1');
        $teamStmt->execute([(int) $row['team_id']]);
        if ($teamStmt->fetch()) {
            $_SESSION['cl_team_id'] = (int) $row['team_id'];
            unset($_SESSION['cl_admin_id']);
        }
    } elseif ($row['owner_type'] === 'admin' && !empty($row['admin_id'])) {
        $adminStmt = $pdo->prepare('SELECT id FROM cl_admin_users WHERE id = ? LIMIT 1');
        $adminStmt->execute([(int) $row['admin_id']]);
        if ($adminStmt->fetch()) {
            $_SESSION['cl_admin_id'] = (int) $row['admin_id'];
            unset($_SESSION['cl_team_id']);
        }
    }

    $expires = time() + (CL_REMEMBER_DAYS * 86400);
    $update = $pdo->prepare('UPDATE cl_login_tokens SET expires_at = FROM_UNIXTIME(?), updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $update->execute([$expires, (int) $row['id']]);
    setcookie(CL_REMEMBER_COOKIE, $token, remember_cookie_options($expires));
}

function clean_text(?string $value, int $max = 120): string
{
    $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    return mb_substr($value, 0, $max);
}

function make_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_substr($part, 0, 1);
    }
    return mb_strtoupper($initials !== '' ? $initials : 'TM');
}

function public_upload_url(string $relativePath): string
{
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $rootPath = preg_replace('#/api$#', '', $basePath) ?: '';
    return $rootPath . '/' . ltrim($relativePath, '/');
}

function base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function der_to_jose(string $derSignature): string
{
    $offset = 3;
    $rLength = ord($derSignature[$offset]);
    $r = substr($derSignature, $offset + 1, $rLength);
    $offset += $rLength + 2;
    $sLength = ord($derSignature[$offset]);
    $s = substr($derSignature, $offset + 1, $sLength);
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

function vapid_jwt(array $pushConfig, string $endpoint): ?string
{
    if (empty($pushConfig['private_key_pem']) || empty($pushConfig['subject'])) {
        return null;
    }

    $parts = parse_url($endpoint);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }

    $audience = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $header = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']) ?: '{}');
    $payload = base64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 43200,
        'sub' => $pushConfig['subject'],
    ]) ?: '{}');
    $input = $header . '.' . $payload;

    $signature = '';
    if (!openssl_sign($input, $signature, $pushConfig['private_key_pem'], OPENSSL_ALGO_SHA256)) {
        return null;
    }

    return $input . '.' . base64url_encode(der_to_jose($signature));
}

function send_empty_web_push_to_subscription(PDO $pdo, array $pushConfig, array $subscription): bool
{
    if (empty($pushConfig['public_key']) || empty($pushConfig['private_key_pem']) || !function_exists('curl_init')) {
        return false;
    }

    $endpoint = (string) ($subscription['endpoint'] ?? '');
    $jwt = vapid_jwt($pushConfig, $endpoint);
    if ($endpoint === '' || !$jwt) {
        return false;
    }

    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'TTL: 120',
            'Urgency: high',
            'Content-Length: 0',
            'Content-Type: application/octet-stream',
            'Authorization: vapid t=' . $jwt . ', k=' . $pushConfig['public_key'],
            'Crypto-Key: p256ecdsa=' . $pushConfig['public_key'],
        ],
    ]);
    curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if (in_array($status, [404, 410], true)) {
        $delete = $pdo->prepare('DELETE FROM cl_push_subscriptions WHERE id = ?');
        $delete->execute([(int) $subscription['id']]);
    }

    return $status >= 200 && $status < 300;
}

function queue_push_event(PDO $pdo, string $ownerType, ?int $teamId, ?int $adminId, string $title, string $body, string $url = 'clash-league.html', string $tag = 'clash-league'): void
{
    $stmt = $pdo->prepare('
        INSERT INTO cl_push_events (owner_type, team_id, admin_id, title, body, url, tag)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$ownerType, $teamId, $adminId, clean_text($title, 120), clean_text($body, 500), clean_text($url, 255), clean_text($tag, 80)]);
}

function send_push_to_owner(PDO $pdo, array $pushConfig, string $ownerType, ?int $teamId = null, ?int $adminId = null): array
{
    $summary = ['attempted' => 0, 'sent' => 0, 'failed' => 0];
    if ($ownerType === 'team') {
        $stmt = $pdo->prepare('SELECT * FROM cl_push_subscriptions WHERE owner_type = "team" AND team_id = ?');
        $stmt->execute([$teamId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM cl_push_subscriptions WHERE owner_type = "admin" AND admin_id = ?');
        $stmt->execute([$adminId]);
    }

    foreach ($stmt->fetchAll() as $subscription) {
        $summary['attempted']++;
        if (send_empty_web_push_to_subscription($pdo, $pushConfig, $subscription)) {
            $summary['sent']++;
        } else {
            $summary['failed']++;
        }
    }
    return $summary;
}

function save_push_subscription(PDO $pdo): void
{
    $subscriptionJson = (string) ($_POST['subscription'] ?? '');
    $subscription = json_decode($subscriptionJson, true);
    $endpoint = clean_text((string) ($subscription['endpoint'] ?? ''), 2048);
    $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
    $p256dh = clean_text((string) ($keys['p256dh'] ?? ''), 255);
    $auth = clean_text((string) ($keys['auth'] ?? ''), 255);

    if ($endpoint === '') {
        json_response(['ok' => false, 'message' => 'Push subscription tidak sah.'], 422);
    }

    $team = current_team($pdo);
    $admin = current_admin($pdo);
    $teamId = (int) ($_POST['team_id'] ?? 0);
    $ownerType = 'team';
    $adminId = null;

    if ($admin) {
        $ownerType = 'admin';
        $adminId = (int) $admin['id'];
        $teamId = null;
    } elseif ($team) {
        $teamId = (int) $team['id'];
    } elseif ($teamId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM cl_teams WHERE id = ? AND status != "removed"');
        $stmt->execute([$teamId]);
        if (!$stmt->fetch()) {
            json_response(['ok' => false, 'message' => 'Team untuk notification tidak dijumpai.'], 404);
        }
    } else {
        json_response(['ok' => false, 'message' => 'Team belum dikenal pasti untuk notification.'], 401);
    }

    $endpointHash = hash('sha256', $endpoint);
    $userAgent = clean_text((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 255);
    $stmt = $pdo->prepare('
        INSERT INTO cl_push_subscriptions (owner_type, team_id, admin_id, endpoint_hash, endpoint, p256dh, auth, user_agent, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            owner_type = VALUES(owner_type),
            team_id = VALUES(team_id),
            admin_id = VALUES(admin_id),
            p256dh = VALUES(p256dh),
            auth = VALUES(auth),
            user_agent = VALUES(user_agent),
            updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([$ownerType, $teamId ?: null, $adminId, $endpointHash, $endpoint, $p256dh, $auth, $userAgent]);

    json_response(['ok' => true, 'message' => 'Phone notification aktif untuk Clash League.']);
}

function push_latest_notification(PDO $pdo): void
{
    $subscriptionJson = (string) ($_POST['subscription'] ?? '');
    $subscription = json_decode($subscriptionJson, true);
    $endpoint = clean_text((string) ($subscription['endpoint'] ?? ''), 2048);
    if ($endpoint === '') {
        json_response(['ok' => false, 'message' => 'Subscription kosong.'], 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM cl_push_subscriptions WHERE endpoint_hash = ? LIMIT 1');
    $stmt->execute([hash('sha256', $endpoint)]);
    $saved = $stmt->fetch();
    if (!$saved) {
        json_response(['ok' => true, 'notification' => default_push_notification()]);
    }

    if ($saved['owner_type'] === 'admin') {
        $eventStmt = $pdo->prepare('SELECT title, body, url, tag FROM cl_push_events WHERE owner_type = "admin" AND admin_id = ? ORDER BY id DESC LIMIT 1');
        $eventStmt->execute([(int) $saved['admin_id']]);
    } else {
        $eventStmt = $pdo->prepare('SELECT title, body, url, tag FROM cl_push_events WHERE owner_type = "team" AND team_id = ? ORDER BY id DESC LIMIT 1');
        $eventStmt->execute([(int) $saved['team_id']]);
    }
    $event = $eventStmt->fetch();

    json_response([
        'ok' => true,
        'notification' => $event ?: default_push_notification(),
    ]);
}

function default_push_notification(): array
{
    return [
        'title' => 'Clash League',
        'body' => 'Update baru Clash League. Buka page untuk semak.',
        'url' => 'clash-league.html',
        'tag' => 'clash-league',
    ];
}

function save_logo(array $file, string $teamName, string $rootDir): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['', ''];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Logo gagal upload.');
    }

    if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Logo maksimum 2MB sahaja.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $mime = mime_content_type($tmpName) ?: '';
    $extensions = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Logo wajib format PNG, JPG, WEBP atau GIF.');
    }

    $uploadDir = $rootDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'clash-league' . DIRECTORY_SEPARATOR . 'logos';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Folder upload logo gagal dibuat.');
    }

    $safeName = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $teamName) ?? 'team');
    $safeName = trim($safeName, '-') ?: 'team';
    $fileName = $safeName . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $extensions[$mime];
    $target = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpName, $target)) {
        throw new RuntimeException('Logo gagal disimpan.');
    }

    $relativePath = 'uploads/clash-league/logos/' . $fileName;
    return [$relativePath, public_upload_url($relativePath)];
}

function save_result_screenshot(array $file, string $teamName, int $matchId, string $rootDir): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Screenshot result wajib upload.');
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Screenshot result gagal upload.');
    }

    if ((int) ($file['size'] ?? 0) > 4 * 1024 * 1024) {
        throw new RuntimeException('Screenshot maksimum 4MB sahaja.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $mime = mime_content_type($tmpName) ?: '';
    $extensions = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Screenshot wajib format PNG, JPG atau WEBP.');
    }

    $uploadDir = $rootDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'clash-league' . DIRECTORY_SEPARATOR . 'results';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Folder upload result gagal dibuat.');
    }

    $safeName = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $teamName) ?? 'team');
    $safeName = trim($safeName, '-') ?: 'team';
    $fileName = $safeName . '-match-' . $matchId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $extensions[$mime];
    $target = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpName, $target)) {
        throw new RuntimeException('Screenshot result gagal disimpan.');
    }

    $relativePath = 'uploads/clash-league/results/' . $fileName;
    return [$relativePath, public_upload_url($relativePath)];
}

function register_team(PDO $pdo, string $rootDir): void
{
    global $pushConfig;

    $teamName = clean_text($_POST['team_name'] ?? '', 100);
    $phone = clean_text($_POST['phone'] ?? '', 40);
    $password = (string) ($_POST['team_password'] ?? '');
    $coachName = clean_text($_POST['coach_name'] ?? '', 100);
    $managerName = clean_text($_POST['manager_name'] ?? '', 100);

    if ($teamName === '' || $phone === '') {
        json_response(['ok' => false, 'message' => 'Nama team dan nombor telefon wajib isi.'], 422);
    }

    if (strlen($password) < 4) {
        json_response(['ok' => false, 'message' => 'Password team minimum 4 aksara.'], 422);
    }

    if (clean_text($_POST['p1_ign'] ?? '', 100) === '' || clean_text($_POST['p1_id'] ?? '', 80) === '') {
        json_response(['ok' => false, 'message' => 'P1 IGN dan P1 ID wajib isi.'], 422);
    }

    [$logoPath, $logoUrl] = save_logo($_FILES['team_logo'] ?? [], $teamName, $rootDir);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, status, logo_url, logo_path FROM cl_teams WHERE team_name = ? FOR UPDATE');
    $stmt->execute([$teamName]);
    $existing = $stmt->fetch();

    if ($existing) {
        $teamId = (int) $existing['id'];
        $nextLogoUrl = $logoUrl !== '' ? $logoUrl : (string) $existing['logo_url'];
        $nextLogoPath = $logoPath !== '' ? $logoPath : (string) $existing['logo_path'];
        $nextStatus = $existing['status'] === 'accepted' ? 'accepted' : 'pending';

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('
            UPDATE cl_teams
            SET logo_url = ?, logo_path = ?, phone = ?, password_hash = ?, coach_name = ?, manager_name = ?, status = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $stmt->execute([$nextLogoUrl, $nextLogoPath, $phone, $passwordHash, $coachName, $managerName, $nextStatus, $teamId]);
    } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('
            INSERT INTO cl_teams (team_name, logo_url, logo_path, phone, password_hash, coach_name, manager_name, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, "pending")
        ');
        $stmt->execute([$teamName, $logoUrl, $logoPath, $phone, $passwordHash, $coachName, $managerName]);
        $teamId = (int) $pdo->lastInsertId();
    }

    $stmt = $pdo->prepare('DELETE FROM cl_players WHERE team_id = ?');
    $stmt->execute([$teamId]);

    $stmt = $pdo->prepare('
        INSERT INTO cl_players (team_id, player_slot, ign, player_id)
        VALUES (?, ?, ?, ?)
    ');
    for ($slot = 1; $slot <= 6; $slot++) {
        $ign = clean_text($_POST['p' . $slot . '_ign'] ?? '', 100);
        $playerId = clean_text($_POST['p' . $slot . '_id'] ?? '', 80);
        if ($ign === '' && $playerId === '') {
            continue;
        }
        $stmt->execute([$teamId, $slot, $ign, $playerId]);
    }

    $roomStmt = $pdo->prepare('SELECT id FROM cl_rooms WHERE room_type = "admin" AND team_a_id = ? LIMIT 1');
    $roomStmt->execute([$teamId]);
    if (!$roomStmt->fetch()) {
        $roomStmt = $pdo->prepare('INSERT INTO cl_rooms (room_type, team_a_id, status) VALUES ("admin", ?, "open")');
        $roomStmt->execute([$teamId]);
    }

    $pdo->commit();

    json_response([
        'ok' => true,
        'message' => 'Pendaftaran dihantar. Admin akan semak dalam database.',
        'team_id' => $teamId,
        'push_public_key' => $pushConfig['public_key'] ?? null,
    ]);
}

function get_public_teams(PDO $pdo): array
{
    $stmt = $pdo->query('
        SELECT id, team_name, logo_url, slot_no, status, phone, coach_name, manager_name, updated_at
        FROM cl_teams
        WHERE status != "removed"
        ORDER BY FIELD(status, "accepted", "pending", "rejected"), COALESCE(slot_no, 999999), created_at DESC
    ');

    $teams = [];
    foreach ($stmt->fetchAll() as $team) {
        $teams[] = serialize_team($pdo, $team);
    }

    return $teams;
}

function serialize_team(PDO $pdo, array $team): array
{
    $playersStmt = $pdo->prepare('
        SELECT player_slot, ign, player_id
        FROM cl_players
        WHERE team_id = ?
        ORDER BY player_slot
    ');
    $playersStmt->execute([(int) $team['id']]);

    $players = [];
    foreach ($playersStmt->fetchAll() as $player) {
        $players[] = [
            'slot' => 'P' . (int) $player['player_slot'],
            'ign' => (string) $player['ign'],
            'id' => (string) $player['player_id'],
        ];
    }

    $status = (string) $team['status'];
    return [
        'team_id' => (string) $team['id'],
        'id' => (int) $team['id'],
        'team_name' => (string) $team['team_name'],
        'logo_url' => (string) ($team['logo_url'] ?? ''),
        'slot_no' => $team['slot_no'] === null ? '' : (string) $team['slot_no'],
        'status' => $status,
        'status_label' => $status === 'accepted' ? 'Confirm' : ($status === 'pending' ? 'Sedang Disemak' : 'Rejected'),
        'phone' => (string) ($team['phone'] ?? ''),
        'coach_name' => (string) ($team['coach_name'] ?? ''),
        'manager_name' => (string) ($team['manager_name'] ?? ''),
        'players' => $players,
    ];
}

function get_admin_registrations(PDO $pdo, array $dbConfig): array
{
    $adminPassword = (string) ($_GET['admin_password'] ?? '');
    if ($adminPassword === '' || $adminPassword !== (string) ($dbConfig['admin_password'] ?? '')) {
        json_response(['ok' => false, 'message' => 'Admin password salah.'], 401);
    }

    $stmt = $pdo->query('
        SELECT id, team_name, logo_url, slot_no, status, phone, coach_name, manager_name, created_at, updated_at
        FROM cl_teams
        WHERE status != "removed"
        ORDER BY FIELD(status, "pending", "accepted", "rejected"), created_at DESC
    ');

    $teams = [];
    foreach ($stmt->fetchAll() as $team) {
        $teams[] = [
            'id' => (int) $team['id'],
            'team_name' => (string) $team['team_name'],
            'logo_url' => (string) $team['logo_url'],
            'slot_no' => $team['slot_no'] === null ? '' : (string) $team['slot_no'],
            'status' => (string) $team['status'],
            'phone' => (string) $team['phone'],
            'coach_name' => (string) $team['coach_name'],
            'manager_name' => (string) $team['manager_name'],
            'created_at' => (string) $team['created_at'],
            'updated_at' => (string) $team['updated_at'],
        ];
    }

    return $teams;
}

function current_team(PDO $pdo): ?array
{
    restore_persistent_login($pdo);
    if (empty($_SESSION['cl_team_id'])) {
        return null;
    }

    $stmt = $pdo->prepare('
        SELECT id, team_name, logo_url, slot_no, status, phone, coach_name, manager_name, updated_at
        FROM cl_teams
        WHERE id = ? AND status != "removed"
    ');
    $stmt->execute([(int) $_SESSION['cl_team_id']]);
    $team = $stmt->fetch();
    return $team ? serialize_team($pdo, $team) : null;
}

function current_admin(PDO $pdo): ?array
{
    restore_persistent_login($pdo);
    if (empty($_SESSION['cl_admin_id'])) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, username FROM cl_admin_users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['cl_admin_id']]);
    $admin = $stmt->fetch();
    return $admin ? ['id' => (int) $admin['id'], 'username' => (string) $admin['username']] : null;
}

function require_team(PDO $pdo): array
{
    $team = current_team($pdo);
    if (!$team) {
        json_response(['ok' => false, 'message' => 'Sila login team dulu.'], 401);
    }
    if ($team['status'] !== 'accepted') {
        json_response(['ok' => false, 'message' => 'Team belum confirm slot. Tunggu admin approve dulu.'], 403);
    }
    return $team;
}

function get_or_create_admin_room(PDO $pdo, int $teamId): int
{
    $stmt = $pdo->prepare('SELECT id FROM cl_rooms WHERE room_type = "admin" AND team_a_id = ? LIMIT 1');
    $stmt->execute([$teamId]);
    $roomId = (int) ($stmt->fetchColumn() ?: 0);
    if ($roomId > 0) {
        return $roomId;
    }

    $stmt = $pdo->prepare('INSERT INTO cl_rooms (room_type, team_a_id, status) VALUES ("admin", ?, "open")');
    $stmt->execute([$teamId]);
    return (int) $pdo->lastInsertId();
}

function get_team_matches(PDO $pdo, ?array $team, ?array $admin = null): array
{
    if (!$team && !$admin) {
        return [];
    }

    if ($admin) {
        $stmt = $pdo->query('
            SELECT m.*, 
                   ta.team_name AS team_a_name, ta.logo_url AS team_a_logo,
                   tb.team_name AS team_b_name, tb.logo_url AS team_b_logo
            FROM cl_matches m
            LEFT JOIN cl_teams ta ON ta.id = m.team_a_id
            LEFT JOIN cl_teams tb ON tb.id = m.team_b_id
            WHERE m.status != "hidden"
            ORDER BY COALESCE(m.match_time, "2999-12-31") ASC, m.id DESC
        ');
    } else {
        $teamId = (int) $team['id'];
        $stmt = $pdo->prepare('
            SELECT m.*, 
                   ta.team_name AS team_a_name, ta.logo_url AS team_a_logo,
                   tb.team_name AS team_b_name, tb.logo_url AS team_b_logo
            FROM cl_matches m
            LEFT JOIN cl_teams ta ON ta.id = m.team_a_id
            LEFT JOIN cl_teams tb ON tb.id = m.team_b_id
            WHERE m.status != "hidden" AND (m.team_a_id = ? OR m.team_b_id = ?)
            ORDER BY COALESCE(m.match_time, "2999-12-31") ASC, m.id DESC
        ');
        $stmt->execute([$teamId, $teamId]);
    }

    $matches = [];
    foreach ($stmt->fetchAll() as $match) {
        $matches[] = [
            'id' => (int) $match['id'],
            'team_a_id' => $match['team_a_id'] === null ? 0 : (int) $match['team_a_id'],
            'team_b_id' => $match['team_b_id'] === null ? 0 : (int) $match['team_b_id'],
            'match_name' => (string) $match['match_name'],
            'match_time' => (string) ($match['match_time'] ?? ''),
            'status' => (string) $match['status'],
            'team_a_name' => (string) ($match['team_a_name'] ?? ($team['team_name'] ?? 'TBD')),
            'team_a_logo' => (string) ($match['team_a_logo'] ?? ($team['logo_url'] ?? '')),
            'team_b_name' => (string) ($match['team_b_name'] ?? 'TBD'),
            'team_b_logo' => (string) ($match['team_b_logo'] ?? ''),
            'team_a_point' => $match['team_a_point'],
            'team_b_point' => $match['team_b_point'],
            'my_result_submitted' => $team ? team_result_exists($pdo, (int) $match['id'], (int) $team['id']) : false,
        ];
    }

    return $matches;
}

function team_result_exists(PDO $pdo, int $matchId, int $teamId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM cl_match_results WHERE match_id = ? AND team_id = ?');
    $stmt->execute([$matchId, $teamId]);
    return (int) $stmt->fetchColumn() > 0;
}

function get_match_results(PDO $pdo, ?array $team, ?array $admin): array
{
    if (!$team && !$admin) {
        return [];
    }

    if ($admin) {
        $stmt = $pdo->query('
            SELECT r.*, m.match_name, m.match_time,
                   ta.team_name AS team_a_name, ta.logo_url AS team_a_logo,
                   tb.team_name AS team_b_name, tb.logo_url AS team_b_logo,
                   submitter.team_name AS submitter_name, submitter.logo_url AS submitter_logo
            FROM cl_match_results r
            JOIN cl_matches m ON m.id = r.match_id
            LEFT JOIN cl_teams ta ON ta.id = m.team_a_id
            LEFT JOIN cl_teams tb ON tb.id = m.team_b_id
            LEFT JOIN cl_teams submitter ON submitter.id = r.team_id
            ORDER BY r.created_at DESC, r.id DESC
        ');
    } else {
        $stmt = $pdo->prepare('
            SELECT r.*, m.match_name, m.match_time,
                   ta.team_name AS team_a_name, ta.logo_url AS team_a_logo,
                   tb.team_name AS team_b_name, tb.logo_url AS team_b_logo,
                   submitter.team_name AS submitter_name, submitter.logo_url AS submitter_logo
            FROM cl_match_results r
            JOIN cl_matches m ON m.id = r.match_id
            LEFT JOIN cl_teams ta ON ta.id = m.team_a_id
            LEFT JOIN cl_teams tb ON tb.id = m.team_b_id
            LEFT JOIN cl_teams submitter ON submitter.id = r.team_id
            WHERE r.team_id = ?
            ORDER BY r.created_at DESC, r.id DESC
        ');
        $stmt->execute([(int) $team['id']]);
    }

    $results = [];
    foreach ($stmt->fetchAll() as $result) {
        $results[] = [
            'id' => (int) $result['id'],
            'match_id' => (int) $result['match_id'],
            'team_id' => (int) $result['team_id'],
            'submitter_name' => (string) ($result['submitter_name'] ?? 'Team'),
            'submitter_logo' => (string) ($result['submitter_logo'] ?? ''),
            'match_name' => (string) $result['match_name'],
            'match_time' => (string) ($result['match_time'] ?? ''),
            'team_a_name' => (string) ($result['team_a_name'] ?? 'Team A'),
            'team_a_logo' => (string) ($result['team_a_logo'] ?? ''),
            'team_b_name' => (string) ($result['team_b_name'] ?? 'Team B'),
            'team_b_logo' => (string) ($result['team_b_logo'] ?? ''),
            'team_a_point' => (int) $result['team_a_point'],
            'team_b_point' => (int) $result['team_b_point'],
            'screenshot_url' => (string) ($result['screenshot_url'] ?? ''),
            'status' => (string) $result['status'],
            'created_at' => (string) $result['created_at'],
        ];
    }

    return $results;
}

function get_deal_rooms(PDO $pdo, ?array $team, ?array $admin): array
{
    if (!$team && !$admin) {
        return [];
    }

    if ($admin) {
        $stmt = $pdo->query('
        SELECT r.id, r.room_type, r.team_a_id, r.team_b_id, r.status,
                   ta.team_name AS team_a_name, ta.logo_url AS team_a_logo,
                   tb.team_name AS team_b_name, tb.logo_url AS team_b_logo,
                   (SELECT message FROM cl_messages cm WHERE cm.room_id = r.id ORDER BY cm.id DESC LIMIT 1) AS last_message,
                   (SELECT id FROM cl_messages cm WHERE cm.room_id = r.id ORDER BY cm.id DESC LIMIT 1) AS last_message_id,
                   (SELECT sender_type FROM cl_messages cm WHERE cm.room_id = r.id ORDER BY cm.id DESC LIMIT 1) AS last_sender_type
            FROM cl_rooms r
            LEFT JOIN cl_teams ta ON ta.id = r.team_a_id
            LEFT JOIN cl_teams tb ON tb.id = r.team_b_id
            WHERE r.status = "open"
            ORDER BY r.updated_at DESC, r.id DESC
        ');
    } else {
        $teamId = (int) $team['id'];
        $stmt = $pdo->prepare('
            SELECT r.id, r.room_type, r.team_a_id, r.team_b_id, r.status,
                   ta.team_name AS team_a_name, ta.logo_url AS team_a_logo,
                   tb.team_name AS team_b_name, tb.logo_url AS team_b_logo,
                   (SELECT message FROM cl_messages cm WHERE cm.room_id = r.id ORDER BY cm.id DESC LIMIT 1) AS last_message,
                   (SELECT id FROM cl_messages cm WHERE cm.room_id = r.id ORDER BY cm.id DESC LIMIT 1) AS last_message_id,
                   (SELECT sender_type FROM cl_messages cm WHERE cm.room_id = r.id ORDER BY cm.id DESC LIMIT 1) AS last_sender_type
            FROM cl_rooms r
            LEFT JOIN cl_teams ta ON ta.id = r.team_a_id
            LEFT JOIN cl_teams tb ON tb.id = r.team_b_id
            WHERE r.status = "open" AND (r.team_a_id = ? OR r.team_b_id = ?)
            ORDER BY FIELD(r.room_type, "admin", "deal", "match"), r.updated_at DESC, r.id DESC
        ');
        $stmt->execute([$teamId, $teamId]);
    }

    $rooms = [];
    foreach ($stmt->fetchAll() as $room) {
        $isAdminRoom = $room['room_type'] === 'admin';
        $teamName = (string) ($room['team_a_name'] ?? 'Team');
        $teamLogo = (string) ($room['team_a_logo'] ?? '');
        $title = $isAdminRoom ? ($admin ? $teamName : 'ADMIN') : ($teamName . ' vs ' . (string) ($room['team_b_name'] ?? 'TBD'));
        $avatar = $isAdminRoom ? ($admin ? make_initials($teamName) : 'AD') : 'VS';
        $rooms[] = [
            'id' => (int) $room['id'],
            'room_type' => (string) $room['room_type'],
            'team_id' => (int) ($room['team_a_id'] ?? 0),
            'title' => $title,
            'subtitle' => $isAdminRoom ? ($admin ? 'Chat ke admin' : $teamName) : 'Clash League Deal',
            'avatar' => $avatar,
            'avatar_logo' => $isAdminRoom && $admin ? $teamLogo : '',
            'status' => (string) $room['status'],
            'last_message' => (string) ($room['last_message'] ?? 'Belum ada chat.'),
            'last_message_id' => (int) ($room['last_message_id'] ?? 0),
            'last_sender_type' => (string) ($room['last_sender_type'] ?? ''),
        ];
    }

    return $rooms;
}

function get_messages(PDO $pdo, array $rooms): array
{
    if (!$rooms) {
        return [];
    }
    $roomIds = array_map(static fn($room) => (int) $room['id'], $rooms);
    $placeholders = implode(',', array_fill(0, count($roomIds), '?'));
    $stmt = $pdo->prepare("
        SELECT m.id, m.room_id, m.sender_type, m.sender_team_id, m.message, m.created_at,
               t.team_name AS sender_team_name
        FROM cl_messages m
        LEFT JOIN cl_teams t ON t.id = m.sender_team_id
        WHERE m.room_id IN ($placeholders)
        ORDER BY m.id ASC
    ");
    $stmt->execute($roomIds);
    return $stmt->fetchAll();
}

function get_rules_text(PDO $pdo): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM cl_settings WHERE setting_key = "rules_text" LIMIT 1');
    $stmt->execute();
    $value = $stmt->fetchColumn();
    if ($value !== false && trim((string) $value) !== '') {
        return (string) $value;
    }

    return "1. Requirements\nLevel account 20 keatas dan Rank BR atau CS harus Platinum keatas.\n\n2. Gameplay\nSemua style gameplay dibenarkan.\n\n3. Third-Party Apps\nPenggunaan Macro tidak dibenarkan. Aplikasi bantuan seperti crosshair marker dibenarkan jika tidak memberi kesan ke dalam perlawanan.\n\n4. Kedudukan\nTidak boleh menembak jika berada di kawasan yang tinggi. Baca rules lengkap di https://gnexdata.blogspot.com/2026/04/rule-gnex-laga-cs-glcs.html\n\n5. Gloo Wall\nBoleh membuat gloo wall bulat dan boleh memecahkan gloo wall musuh.\n\n6. Bug Game\nTidak boleh menggunakan dan memanfaatkan bug di dalam game untuk mendapatkan sesuatu kelebihan.\n\n7. Safezone\nTidak boleh menghalang musuh dari masuk ke dalam zone dan akan dikenakan amaran disqualified jika dikesan ingin bermain zone.\n\n8. Tayar Lompat\nJika tidak sengaja masih boleh dimaafkan. Tidak boleh melepaskan tembakan dengan sengaja jika sudah berada di atas.\n\n9. Interaction Ingame\nTiada tindakan dikenakan jika player melakukan emote dan seangkatan dengan itu.";
}

function get_state(PDO $pdo): array
{
    global $pushConfig;

    $team = current_team($pdo);
    $admin = current_admin($pdo);
    if ($team && $team['status'] === 'accepted') {
        get_or_create_admin_room($pdo, (int) $team['id']);
    }

    $rooms = get_deal_rooms($pdo, $team, $admin);
    return [
        'ok' => true,
        'team' => $team,
        'admin' => $admin,
        'teams' => get_public_teams($pdo),
        'matches' => get_team_matches($pdo, $team, $admin),
        'results' => get_match_results($pdo, $team, $admin),
        'rooms' => $rooms,
        'messages' => get_messages($pdo, $rooms),
        'rules_text' => get_rules_text($pdo),
        'push_public_key' => $pushConfig['public_key'] ?? null,
    ];
}

function set_team_status(PDO $pdo, array $dbConfig): void
{
    global $pushConfig;

    $adminPassword = (string) ($_POST['admin_password'] ?? '');
    $admin = current_admin($pdo);
    if (!$admin && ($adminPassword === '' || $adminPassword !== (string) ($dbConfig['admin_password'] ?? ''))) {
        json_response(['ok' => false, 'message' => 'Admin password salah.'], 401);
    }

    $teamId = (int) ($_POST['team_id'] ?? 0);
    $status = clean_text($_POST['status'] ?? '', 20);
    if ($teamId <= 0 || !in_array($status, ['pending', 'accepted', 'rejected', 'removed'], true)) {
        json_response(['ok' => false, 'message' => 'Data status tidak valid.'], 422);
    }

    $teamStmt = $pdo->prepare('SELECT id, team_name, status, slot_no FROM cl_teams WHERE id = ? AND status != "removed" LIMIT 1');
    $teamStmt->execute([$teamId]);
    $existingTeam = $teamStmt->fetch();
    if (!$existingTeam) {
        json_response(['ok' => false, 'message' => 'Team tidak dijumpai.'], 404);
    }
    $oldStatus = (string) $existingTeam['status'];

    $slotNo = null;
    if ($status === 'accepted') {
        $currentSlot = $existingTeam['slot_no'];
        if ($currentSlot !== null && (int) $currentSlot > 0) {
            $slotNo = (int) $currentSlot;
        } else {
            $slotNo = (int) $pdo->query('SELECT COALESCE(MAX(slot_no), 0) + 1 FROM cl_teams WHERE status = "accepted"')->fetchColumn();
        }
    }

    $stmt = $pdo->prepare('UPDATE cl_teams SET status = ?, slot_no = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$status, $slotNo, $teamId]);
    renumber_accepted_slots($pdo);

    $pushSummary = null;
    if ($status === 'accepted' && $oldStatus !== 'accepted') {
        queue_push_event(
            $pdo,
            'team',
            $teamId,
            null,
            'Clash League',
            'Pasukan anda berjaya menyertai tournament Clash League, sila login untuk bermain.',
            'clash-league.html#login',
            'clash-team-accepted-' . $teamId
        );
        $pushSummary = send_push_to_owner($pdo, $pushConfig, 'team', $teamId);
    } elseif ($status === 'rejected' && $oldStatus !== 'rejected') {
        queue_push_event(
            $pdo,
            'team',
            $teamId,
            null,
            'Pendaftaran Clash League',
            'Maaf, pendaftaran team anda belum berjaya. Sila hubungi admin untuk semakan.',
            'clash-league.html#deal',
            'clash-team-rejected-' . $teamId
        );
        $pushSummary = send_push_to_owner($pdo, $pushConfig, 'team', $teamId);
    }

    json_response(get_state($pdo) + [
        'message' => 'Status team berjaya update.',
        'push_summary' => $pushSummary,
    ]);
}

function update_team_info(PDO $pdo): void
{
    $admin = current_admin($pdo);
    if (!$admin) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan untuk edit team.'], 401);
    }

    $teamId = (int) ($_POST['team_id'] ?? 0);
    if ($teamId <= 0) {
        json_response(['ok' => false, 'message' => 'Team tidak valid.'], 422);
    }

    $teamName = clean_text($_POST['team_name'] ?? '', 100);
    $phone = clean_text($_POST['phone'] ?? '', 40);
    $coachName = clean_text($_POST['coach_name'] ?? '', 100);
    $managerName = clean_text($_POST['manager_name'] ?? '', 100);
    $logoUrl = trim((string) ($_POST['logo_url'] ?? ''));
    $slotRaw = trim((string) ($_POST['slot_no'] ?? ''));
    $slotNo = $slotRaw === '' ? null : max(1, (int) $slotRaw);

    if ($teamName === '' || $phone === '') {
        json_response(['ok' => false, 'message' => 'Nama team dan phone wajib isi.'], 422);
    }

    $stmt = $pdo->prepare('
        UPDATE cl_teams
        SET team_name = ?, logo_url = ?, phone = ?, coach_name = ?, manager_name = ?, slot_no = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ? AND status != "removed"
    ');
    $stmt->execute([$teamName, $logoUrl, $phone, $coachName, $managerName, $slotNo, $teamId]);

    $stmt = $pdo->prepare('DELETE FROM cl_players WHERE team_id = ?');
    $stmt->execute([$teamId]);

    $stmt = $pdo->prepare('INSERT INTO cl_players (team_id, player_slot, ign, player_id) VALUES (?, ?, ?, ?)');
    for ($slot = 1; $slot <= 6; $slot++) {
        $ign = clean_text($_POST['p' . $slot . '_ign'] ?? '', 100);
        $playerId = clean_text($_POST['p' . $slot . '_id'] ?? '', 80);
        if ($ign === '' && $playerId === '') {
            continue;
        }
        $stmt->execute([$teamId, $slot, $ign, $playerId]);
    }

    renumber_accepted_slots($pdo);

    json_response(get_state($pdo) + ['message' => 'Maklumat team berjaya update.']);
}

function remove_team(PDO $pdo): void
{
    $admin = current_admin($pdo);
    if (!$admin) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan untuk remove team.'], 401);
    }

    $teamId = (int) ($_POST['team_id'] ?? 0);
    if ($teamId <= 0) {
        json_response(['ok' => false, 'message' => 'Team tidak valid.'], 422);
    }

    $stmt = $pdo->prepare('UPDATE cl_teams SET status = "removed", slot_no = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$teamId]);
    renumber_accepted_slots($pdo);

    json_response(get_state($pdo) + ['message' => 'Team berjaya remove.']);
}

function renumber_accepted_slots(PDO $pdo): void
{
    $stmt = $pdo->query('
        SELECT id, slot_no
        FROM cl_teams
        WHERE status = "accepted"
        ORDER BY COALESCE(slot_no, 999999), updated_at ASC, created_at ASC, id ASC
    ');
    $update = $pdo->prepare('UPDATE cl_teams SET slot_no = ? WHERE id = ?');
    $slot = 1;
    foreach ($stmt->fetchAll() as $team) {
        if ((int) ($team['slot_no'] ?? 0) !== $slot) {
            $update->execute([$slot, (int) $team['id']]);
        }
        $slot++;
    }
}

function generate_random_matches(PDO $pdo): void
{
    $admin = current_admin($pdo);
    if (!$admin) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan untuk generate jadual.'], 401);
    }

    $limit = max(2, min(128, (int) ($_POST['team_limit'] ?? 10)));
    $prefix = clean_text($_POST['match_prefix'] ?? 'Qualifier Match', 120);
    if ($prefix === '') {
        $prefix = 'Qualifier Match';
    }

    $matchTimeSql = normalize_match_time($_POST['match_time'] ?? '');

    $stmt = $pdo->prepare('
        SELECT id
        FROM cl_teams
        WHERE status = "accepted"
        ORDER BY COALESCE(slot_no, 999999), created_at ASC, id ASC
        LIMIT ?
    ');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $teamIds = array_map(static fn($row) => (int) $row['id'], $stmt->fetchAll());

    if (count($teamIds) < 2) {
        json_response(['ok' => false, 'message' => 'Minimum 2 team confirm diperlukan untuk random jadual.'], 422);
    }

    shuffle($teamIds);
    $pdo->beginTransaction();
    try {
        $matchStmt = $pdo->prepare('
            INSERT INTO cl_matches (team_a_id, team_b_id, match_name, match_time, status, updated_at)
            VALUES (?, ?, ?, ?, "up_next", CURRENT_TIMESTAMP)
        ');
        $roomStmt = $pdo->prepare('
            INSERT INTO cl_rooms (room_type, team_a_id, team_b_id, status, updated_at)
            VALUES ("match", ?, ?, "open", CURRENT_TIMESTAMP)
        ');

        $matchNo = 1;
        for ($index = 0; $index < count($teamIds); $index += 2) {
            $teamA = $teamIds[$index];
            $teamB = $teamIds[$index + 1] ?? null;
            $matchName = $prefix . ' ' . $matchNo;
            $matchStmt->execute([$teamA, $teamB, $matchName, $matchTimeSql]);
            if ($teamB !== null) {
                $roomStmt->execute([$teamA, $teamB]);
            }
            $matchNo++;
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    json_response(get_state($pdo) + ['message' => 'Jadual random berjaya dibuat.']);
}

function update_match(PDO $pdo): void
{
    $admin = current_admin($pdo);
    if (!$admin) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan untuk update match.'], 401);
    }

    $matchId = (int) ($_POST['match_id'] ?? 0);
    $matchName = clean_text($_POST['match_name'] ?? 'Next Match', 120);
    $status = clean_text($_POST['status'] ?? 'up_next', 20);
    if ($matchId <= 0 || !in_array($status, ['up_next', 'live', 'completed', 'hidden'], true)) {
        json_response(['ok' => false, 'message' => 'Data match tidak valid.'], 422);
    }
    if ($matchName === '') {
        $matchName = 'Next Match';
    }

    $matchTimeSql = normalize_match_time($_POST['match_time'] ?? '');

    $stmt = $pdo->prepare('
        UPDATE cl_matches
        SET match_name = ?, match_time = ?, status = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ');
    $stmt->execute([$matchName, $matchTimeSql, $status, $matchId]);

    json_response(get_state($pdo) + ['message' => 'Match berjaya update.']);
}

function normalize_match_time($value): ?string
{
    $matchTime = trim((string) $value);
    if ($matchTime === '') {
        return null;
    }

    $matchTime = str_replace('T', ' ', $matchTime);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $matchTime)) {
        $matchTime .= ':00';
    }

    $date = DateTime::createFromFormat('Y-m-d H:i:s', $matchTime);
    if (!$date) {
        $timestamp = strtotime($matchTime);
        if ($timestamp === false) {
            json_response(['ok' => false, 'message' => 'Format tarikh/masa tidak valid.'], 422);
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    return $date->format('Y-m-d H:i:s');
}

function login_user(PDO $pdo, array $dbConfig): void
{
    $mode = clean_text($_POST['mode'] ?? 'team', 20);
    $name = clean_text($_POST['name'] ?? '', 100);
    $secret = clean_text($_POST['secret'] ?? '', 120);

    if ($mode === 'admin') {
        $stmt = $pdo->prepare('SELECT id, password_hash FROM cl_admin_users WHERE username = ?');
        $stmt->execute([$name]);
        $admin = $stmt->fetch();
        if (!$admin || !password_verify($secret, (string) $admin['password_hash'])) {
            json_response(['ok' => false, 'message' => 'Login admin salah.'], 401);
        }
        $_SESSION['cl_admin_id'] = (int) $admin['id'];
        unset($_SESSION['cl_team_id']);
        issue_persistent_login($pdo, 'admin', null, (int) $admin['id']);
        json_response(get_state($pdo) + ['message' => 'Admin login berjaya.']);
    }

    $stmt = $pdo->prepare('
        SELECT id, status, password_hash
        FROM cl_teams
        WHERE LOWER(team_name) = LOWER(?)
        LIMIT 1
    ');
    $stmt->execute([$name]);
    $team = $stmt->fetch();
    if (!$team || empty($team['password_hash']) || !password_verify($secret, (string) $team['password_hash'])) {
        json_response(['ok' => false, 'message' => 'Nama team atau password salah.'], 401);
    }
    if ((string) $team['status'] !== 'accepted') {
        json_response(['ok' => false, 'message' => 'Team belum confirm slot. Status masih menunggu admin.'], 403);
    }

    $_SESSION['cl_team_id'] = (int) $team['id'];
    unset($_SESSION['cl_admin_id']);
    issue_persistent_login($pdo, 'team', (int) $team['id'], null);
    get_or_create_admin_room($pdo, (int) $team['id']);
    json_response(get_state($pdo) + ['message' => 'Login team berjaya.']);
}

function open_admin_chat(PDO $pdo): void
{
    $admin = current_admin($pdo);
    if (!$admin) {
        json_response(['ok' => false, 'message' => 'Admin login diperlukan.'], 401);
    }

    $teamId = (int) ($_POST['team_id'] ?? 0);
    if ($teamId <= 0) {
        json_response(['ok' => false, 'message' => 'Pilih team dulu.'], 422);
    }

    $stmt = $pdo->prepare('SELECT id FROM cl_teams WHERE id = ? AND status != "removed" LIMIT 1');
    $stmt->execute([$teamId]);
    if (!$stmt->fetch()) {
        json_response(['ok' => false, 'message' => 'Team tidak dijumpai.'], 404);
    }

    $roomId = get_or_create_admin_room($pdo, $teamId);
    json_response(get_state($pdo) + [
        'message' => 'Chat team dibuka.',
        'room_id' => $roomId,
    ]);
}

function send_message(PDO $pdo): void
{
    global $pushConfig;

    $team = current_team($pdo);
    $admin = current_admin($pdo);
    if (!$team && !$admin) {
        json_response(['ok' => false, 'message' => 'Sila login dulu untuk chat.'], 401);
    }

    $roomId = (int) ($_POST['room_id'] ?? 0);
    $message = clean_text($_POST['message'] ?? '', 700);
    if ($roomId <= 0 || $message === '') {
        json_response(['ok' => false, 'message' => 'Room dan mesej wajib isi.'], 422);
    }

    if (!$admin) {
        if ($team['status'] !== 'accepted') {
            json_response(['ok' => false, 'message' => 'Team belum confirm slot.'], 403);
        }
        $stmt = $pdo->prepare('SELECT id FROM cl_rooms WHERE id = ? AND status = "open" AND (team_a_id = ? OR team_b_id = ?)');
        $stmt->execute([$roomId, $team['id'], $team['id']]);
        if (!$stmt->fetch()) {
            json_response(['ok' => false, 'message' => 'Room chat ini bukan untuk team anda.'], 403);
        }
    }

    $roomStmt = $pdo->prepare('
        SELECT r.*, ta.team_name AS team_a_name, tb.team_name AS team_b_name
        FROM cl_rooms r
        LEFT JOIN cl_teams ta ON ta.id = r.team_a_id
        LEFT JOIN cl_teams tb ON tb.id = r.team_b_id
        WHERE r.id = ? AND r.status = "open"
        LIMIT 1
    ');
    $roomStmt->execute([$roomId]);
    $room = $roomStmt->fetch();
    if (!$room) {
        json_response(['ok' => false, 'message' => 'Room chat tidak dijumpai.'], 404);
    }

    $senderType = $admin ? 'admin' : 'team';
    $senderTeamId = $admin ? null : (int) $team['id'];
    $stmt = $pdo->prepare('INSERT INTO cl_messages (room_id, sender_type, sender_team_id, message) VALUES (?, ?, ?, ?)');
    $stmt->execute([$roomId, $senderType, $senderTeamId, $message]);

    $stmt = $pdo->prepare('UPDATE cl_rooms SET updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$roomId]);

    $shortMessage = clean_text($message, 120);
    if ($admin) {
        foreach (['team_a_id', 'team_b_id'] as $column) {
            $targetTeamId = (int) ($room[$column] ?? 0);
            if ($targetTeamId <= 0) {
                continue;
            }
            queue_push_event($pdo, 'team', $targetTeamId, null, 'Mesej Clash League', 'Admin: ' . $shortMessage, 'clash-league.html#deal', 'clash-chat-' . $roomId);
            send_push_to_owner($pdo, $pushConfig, 'team', $targetTeamId);
        }
    } else {
        $adminStmt = $pdo->query('SELECT DISTINCT admin_id FROM cl_push_subscriptions WHERE owner_type = "admin" AND admin_id IS NOT NULL');
        foreach ($adminStmt->fetchAll() as $adminRow) {
            $adminId = (int) $adminRow['admin_id'];
            queue_push_event($pdo, 'admin', null, $adminId, 'Mesej Clash League', ((string) $team['team_name']) . ': ' . $shortMessage, 'clash-league.html#deal', 'clash-chat-' . $roomId);
            send_push_to_owner($pdo, $pushConfig, 'admin', null, $adminId);
        }
    }

    json_response(get_state($pdo) + ['message' => 'Mesej dihantar.']);
}

function submit_result(PDO $pdo, string $rootDir): void
{
    $team = require_team($pdo);
    $matchId = (int) ($_POST['match_id'] ?? 0);
    $teamAPoint = (int) ($_POST['team_a_point'] ?? -1);
    $teamBPoint = (int) ($_POST['team_b_point'] ?? -1);

    if ($matchId <= 0 || $teamAPoint < 0 || $teamBPoint < 0) {
        json_response(['ok' => false, 'message' => 'Pilih match dan isi point yang betul.'], 422);
    }

    if ($teamAPoint > 99 || $teamBPoint > 99) {
        json_response(['ok' => false, 'message' => 'Point terlalu besar. Check semula result.'], 422);
    }

    $stmt = $pdo->prepare('
        SELECT id, team_a_id, team_b_id
        FROM cl_matches
        WHERE id = ? AND status != "hidden" AND (team_a_id = ? OR team_b_id = ?)
        LIMIT 1
    ');
    $stmt->execute([$matchId, $team['id'], $team['id']]);
    $match = $stmt->fetch();
    if (!$match) {
        json_response(['ok' => false, 'message' => 'Match ini bukan jadual team anda.'], 403);
    }

    [$screenshotPath, $screenshotUrl] = save_result_screenshot($_FILES['result_screenshot'] ?? [], (string) $team['team_name'], $matchId, $rootDir);

    $stmt = $pdo->prepare('
        INSERT INTO cl_match_results (match_id, team_id, team_a_point, team_b_point, screenshot_url, screenshot_path, status, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, "pending", CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            team_a_point = VALUES(team_a_point),
            team_b_point = VALUES(team_b_point),
            screenshot_url = VALUES(screenshot_url),
            screenshot_path = VALUES(screenshot_path),
            status = "pending",
            updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([$matchId, $team['id'], $teamAPoint, $teamBPoint, $screenshotUrl, $screenshotPath]);

    json_response(get_state($pdo) + ['message' => 'Point dan screenshot result berjaya dihantar. Admin akan semak.']);
}

function save_rules(PDO $pdo): void
{
    $admin = current_admin($pdo);
    if (!$admin) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan untuk edit rules.'], 401);
    }

    $rulesText = trim((string) ($_POST['rules_text'] ?? ''));
    if ($rulesText === '') {
        json_response(['ok' => false, 'message' => 'Rules tidak boleh kosong.'], 422);
    }

    if (strlen($rulesText) > 5000) {
        json_response(['ok' => false, 'message' => 'Rules terlalu panjang.'], 422);
    }

    $stmt = $pdo->prepare('
        INSERT INTO cl_settings (setting_key, setting_value, updated_at)
        VALUES ("rules_text", ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([$rulesText]);

    json_response(get_state($pdo) + ['message' => 'Rules berjaya update.']);
}
