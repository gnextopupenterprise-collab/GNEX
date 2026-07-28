<?php
declare(strict_types=1);

$rootDir = dirname(__DIR__);
date_default_timezone_set('Asia/Kuala_Lumpur');
set_exception_handler(static function (Throwable $error): void {
    error_log($error->__toString());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'ok' => false,
        'message' => 'Server error: ' . $error->getMessage(),
    ]);
});

session_start();

$dbConfig = require $rootDir . DIRECTORY_SEPARATOR . 'scrim-db-config.php';
$pushConfigPath = $rootDir . DIRECTORY_SEPARATOR . 'scrim-push-config.php';
$pushConfig = is_file($pushConfigPath) ? require $pushConfigPath : [];
$adminUsername = trim((string) ($dbConfig['admin_username'] ?? ''));
$adminPassword = (string) ($dbConfig['admin_password'] ?? '');
$dbHost = $dbConfig['host'];
$dbName = $dbConfig['database'];
$dbUser = $dbConfig['username'];
$dbPass = $dbConfig['password'];

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (PDOException $error) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => false,
        'message' => 'Database belum connect: ' . $error->getMessage(),
    ]);
    exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("SET time_zone = '+08:00'");

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function column_is_nullable(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT IS_NULLABLE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return strtoupper((string) $stmt->fetchColumn()) === 'YES';
}

function index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND INDEX_NAME = ?
    ");
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
}

function normalize_team_name_key(string $value): string
{
    $value = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? ''), 'UTF-8');
    return mb_substr(preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '', 0, 80);
}

function normalize_player_id_key(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    return mb_substr(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '', 0, 80);
}

$schemaStatements = [
    "CREATE TABLE IF NOT EXISTS teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_name VARCHAR(80) NOT NULL UNIQUE,
    team_name_key VARCHAR(80) NULL UNIQUE,
    captain_name VARCHAR(80) NULL,
    phone_number VARCHAR(30) NULL,
    player_ign VARCHAR(80) NULL,
    player_game_id VARCHAR(80) NULL,
    player_game_id_key VARCHAR(80) NULL,
    password_hash VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_teams_player_game_id_key (player_game_id_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS team_stats (
    team_id INT PRIMARY KEY,
    total_scrim INT NOT NULL DEFAULT 0,
    total_win INT NOT NULL DEFAULT 0,
    total_lose INT NOT NULL DEFAULT 0,
    total_point INT NOT NULL DEFAULT 0,
    tier VARCHAR(30) NOT NULL DEFAULT 'UNRANKED',
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS scrims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    creator_team_id INT NULL,
    opponent_team_id INT NULL,
    title VARCHAR(120) NOT NULL,
    date_time DATETIME NOT NULL,
    format VARCHAR(20) NOT NULL,
    notes VARCHAR(255) NULL,
    point_mode VARCHAR(20) NOT NULL DEFAULT 'normal',
    admin_open_slots TINYINT(1) NOT NULL DEFAULT 0,
    challenger_team_id INT NULL,
    defender_team_id INT NULL,
    winner_point_delta INT NULL,
    loser_point_delta INT NULL,
    status ENUM('open','pending','confirmed','completed') NOT NULL DEFAULT 'open',
    room_id VARCHAR(60) NULL,
    room_password VARCHAR(60) NULL,
    winner_team_id INT NULL,
    result_score VARCHAR(30) NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (creator_team_id) REFERENCES teams(id),
    FOREIGN KEY (opponent_team_id) REFERENCES teams(id),
    FOREIGN KEY (winner_team_id) REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS scrim_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scrim_id INT NOT NULL,
    requester_team_id INT NOT NULL,
    status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
    message VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME NULL,
    UNIQUE(scrim_id, requester_team_id),
    FOREIGN KEY (scrim_id) REFERENCES scrims(id) ON DELETE CASCADE,
    FOREIGN KEY (requester_team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS result_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scrim_id INT NOT NULL,
    reporter_team_id INT NOT NULL,
    submitted_score VARCHAR(30) NULL,
    reported_score VARCHAR(30) NOT NULL,
    message VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    FOREIGN KEY (scrim_id) REFERENCES scrims(id) ON DELETE CASCADE,
    FOREIGN KEY (reporter_team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS scrim_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scrim_id INT NOT NULL,
    sender_team_id INT NULL,
    sender_type VARCHAR(20) NOT NULL DEFAULT 'team',
    sender_name VARCHAR(80) NULL,
    message VARCHAR(500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (scrim_id) REFERENCES scrims(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_team_id) REFERENCES teams(id) ON DELETE CASCADE,
    INDEX idx_scrim_messages_room (scrim_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    endpoint_hash CHAR(64) NOT NULL UNIQUE,
    endpoint TEXT NOT NULL,
    p256dh VARCHAR(255) NULL,
    auth VARCHAR(255) NULL,
    fetch_token CHAR(64) NULL UNIQUE,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    INDEX idx_push_team (team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS notification_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    title VARCHAR(120) NOT NULL,
    body VARCHAR(500) NOT NULL,
    url VARCHAR(255) NOT NULL DEFAULT 'scrim.html',
    tag VARCHAR(120) NOT NULL DEFAULT 'scrim-update',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    INDEX idx_notification_team (team_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS support_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    guest_key VARCHAR(64) NOT NULL,
    guest_name VARCHAR(80) NOT NULL,
    sender_type ENUM('guest','admin') NOT NULL,
    message VARCHAR(500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_support_guest (guest_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS group_chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_team_id INT NULL,
    sender_name VARCHAR(80) NOT NULL,
    sender_type ENUM('guest','team','admin') NOT NULL DEFAULT 'guest',
    message VARCHAR(500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_group_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($schemaStatements as $schemaStatement) {
    $pdo->exec($schemaStatement);
}

if (!column_exists($pdo, 'teams', 'password_hash')) {
    $pdo->exec('ALTER TABLE teams ADD COLUMN password_hash VARCHAR(255) NULL');
}
if (!column_exists($pdo, 'push_subscriptions', 'fetch_token')) {
    $pdo->exec('ALTER TABLE push_subscriptions ADD COLUMN fetch_token CHAR(64) NULL UNIQUE AFTER auth');
}

if (!column_exists($pdo, 'teams', 'team_name_key')) {
    $pdo->exec('ALTER TABLE teams ADD COLUMN team_name_key VARCHAR(80) NULL AFTER team_name');
    $pdo->exec('ALTER TABLE teams ADD UNIQUE INDEX uq_teams_name_key (team_name_key)');
}

$missingTeamKeys = $pdo->query("SELECT id, team_name FROM teams WHERE team_name_key IS NULL OR team_name_key = ''")->fetchAll();
if ($missingTeamKeys) {
    $updateTeamKey = $pdo->prepare('UPDATE teams SET team_name_key = ? WHERE id = ?');
    foreach ($missingTeamKeys as $missingTeamKey) {
        $normalizedKey = normalize_team_name_key((string) $missingTeamKey['team_name']);
        if ($normalizedKey === '') {
            $normalizedKey = 'legacyteam' . (int) $missingTeamKey['id'];
        }
        try {
            $updateTeamKey->execute([$normalizedKey, $missingTeamKey['id']]);
        } catch (PDOException $error) {
            if ((string) $error->getCode() !== '23000') {
                throw $error;
            }
            $updateTeamKey->execute(['legacyduplicate' . (int) $missingTeamKey['id'], $missingTeamKey['id']]);
        }
    }
}

if (!column_exists($pdo, 'teams', 'captain_name')) {
    $pdo->exec('ALTER TABLE teams ADD COLUMN captain_name VARCHAR(80) NULL AFTER team_name');
}

if (!column_exists($pdo, 'teams', 'phone_number')) {
    $pdo->exec('ALTER TABLE teams ADD COLUMN phone_number VARCHAR(30) NULL AFTER captain_name');
}

if (!column_exists($pdo, 'teams', 'player_ign')) {
    $pdo->exec('ALTER TABLE teams ADD COLUMN player_ign VARCHAR(80) NULL AFTER phone_number');
}

if (!column_exists($pdo, 'teams', 'player_game_id')) {
    $pdo->exec('ALTER TABLE teams ADD COLUMN player_game_id VARCHAR(80) NULL AFTER player_ign');
}

if (!column_exists($pdo, 'teams', 'player_game_id_key')) {
    $pdo->exec('ALTER TABLE teams ADD COLUMN player_game_id_key VARCHAR(80) NULL AFTER player_game_id');
}

$playersMissingKeys = $pdo->query("
    SELECT id, player_game_id
    FROM teams
    WHERE player_game_id IS NOT NULL
        AND player_game_id != ''
        AND (player_game_id_key IS NULL OR player_game_id_key = '')
    ORDER BY id ASC
")->fetchAll();
if ($playersMissingKeys) {
    $usedPlayerKeys = array_fill_keys(
        $pdo->query("SELECT player_game_id_key FROM teams WHERE player_game_id_key IS NOT NULL AND player_game_id_key != ''")->fetchAll(PDO::FETCH_COLUMN),
        true
    );
    $updatePlayerKey = $pdo->prepare('UPDATE teams SET player_game_id_key = ? WHERE id = ?');
    foreach ($playersMissingKeys as $playerMissingKey) {
        $playerKey = normalize_player_id_key((string) $playerMissingKey['player_game_id']);
        if ($playerKey === '' || isset($usedPlayerKeys[$playerKey])) {
            continue;
        }
        $updatePlayerKey->execute([$playerKey, $playerMissingKey['id']]);
        $usedPlayerKeys[$playerKey] = true;
    }
}
if (!index_exists($pdo, 'teams', 'uq_teams_player_game_id_key')) {
    $pdo->exec('ALTER TABLE teams ADD UNIQUE INDEX uq_teams_player_game_id_key (player_game_id_key)');
}

if (!column_exists($pdo, 'teams', 'password_code')) {
    $pdo->exec('ALTER TABLE teams ADD COLUMN password_code VARCHAR(50) NULL');
}

if (!column_exists($pdo, 'teams', 'created_at')) {
    $pdo->exec('ALTER TABLE teams ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
}

if (!column_exists($pdo, 'group_chat_messages', 'sender_team_id')) {
    $pdo->exec('ALTER TABLE group_chat_messages ADD COLUMN sender_team_id INT NULL AFTER id');
}

if (!column_exists($pdo, 'scrim_requests', 'responded_at')) {
    $pdo->exec('ALTER TABLE scrim_requests ADD COLUMN responded_at DATETIME NULL');
}

if (!column_exists($pdo, 'scrim_messages', 'sender_type')) {
    $pdo->exec("ALTER TABLE scrim_messages MODIFY sender_team_id INT NULL");
    $pdo->exec("ALTER TABLE scrim_messages ADD COLUMN sender_type VARCHAR(20) NOT NULL DEFAULT 'team' AFTER sender_team_id");
}

if (!column_exists($pdo, 'scrim_messages', 'sender_name')) {
    $pdo->exec("ALTER TABLE scrim_messages ADD COLUMN sender_name VARCHAR(80) NULL AFTER sender_type");
}

if (!column_exists($pdo, 'scrims', 'opponent_team_id')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN opponent_team_id INT NULL AFTER creator_team_id");
}

if (!column_is_nullable($pdo, 'scrims', 'creator_team_id')) {
    $pdo->exec("ALTER TABLE scrims MODIFY creator_team_id INT NULL");
}

if (!column_exists($pdo, 'scrims', 'title')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN title VARCHAR(120) NULL AFTER opponent_team_id");
}

if (!column_exists($pdo, 'scrims', 'date_time')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN date_time DATETIME NULL AFTER title");
}

if (!column_exists($pdo, 'scrims', 'format')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN format VARCHAR(20) NOT NULL DEFAULT 'BO3' AFTER date_time");
}

if (!column_exists($pdo, 'scrims', 'notes')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN notes VARCHAR(255) NULL AFTER format");
}

if (!column_exists($pdo, 'scrims', 'point_mode')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN point_mode VARCHAR(20) NOT NULL DEFAULT 'normal' AFTER notes");
}

if (!column_exists($pdo, 'scrims', 'admin_open_slots')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN admin_open_slots TINYINT(1) NOT NULL DEFAULT 0 AFTER point_mode");
}

if (!column_exists($pdo, 'scrims', 'challenger_team_id')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN challenger_team_id INT NULL AFTER admin_open_slots");
}

if (!column_exists($pdo, 'scrims', 'defender_team_id')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN defender_team_id INT NULL AFTER challenger_team_id");
}

if (!column_exists($pdo, 'scrims', 'winner_point_delta')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN winner_point_delta INT NULL AFTER defender_team_id");
}

if (!column_exists($pdo, 'scrims', 'loser_point_delta')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN loser_point_delta INT NULL AFTER winner_point_delta");
}

if (!column_exists($pdo, 'scrims', 'room_password')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN room_password VARCHAR(60) NULL AFTER room_id");
}

if (!column_exists($pdo, 'scrims', 'winner_team_id')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN winner_team_id INT NULL AFTER room_password");
}

if (!column_exists($pdo, 'scrims', 'result_score')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN result_score VARCHAR(30) NULL AFTER winner_team_id");
}

if (!column_exists($pdo, 'scrims', 'completed_at')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN completed_at DATETIME NULL AFTER result_score");
}

if (!column_exists($pdo, 'scrims', 'pending_winner_team_id')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN pending_winner_team_id INT NULL AFTER winner_team_id");
}

if (!column_exists($pdo, 'scrims', 'pending_result_score')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN pending_result_score VARCHAR(30) NULL AFTER result_score");
}

if (!column_exists($pdo, 'scrims', 'result_status')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN result_status VARCHAR(20) NULL AFTER pending_result_score");
}

if (!column_exists($pdo, 'scrims', 'result_submitted_by')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN result_submitted_by INT NULL AFTER result_status");
}

if (!column_exists($pdo, 'scrims', 'result_submitted_at')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN result_submitted_at DATETIME NULL AFTER result_submitted_by");
}

if (!column_exists($pdo, 'scrims', 'result_reviewed_at')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN result_reviewed_at DATETIME NULL AFTER result_submitted_at");
}

$titleFallback = column_exists($pdo, 'scrims', 'game_name') ? "game_name" : "'MLBB Scrim'";
$dateFallback = column_exists($pdo, 'scrims', 'scrim_date') && column_exists($pdo, 'scrims', 'scrim_time')
    ? "TIMESTAMP(COALESCE(scrim_date, CURRENT_DATE), COALESCE(scrim_time, CURRENT_TIME))"
    : "CURRENT_TIMESTAMP";
$notesFallback = column_exists($pdo, 'scrims', 'note') ? "note" : "NULL";

$pdo->exec("
    UPDATE scrims
    SET
        title = COALESCE(title, {$titleFallback}, 'MLBB Scrim'),
        date_time = COALESCE(date_time, {$dateFallback}),
        notes = COALESCE(notes, {$notesFallback})
");

$pdo->exec('INSERT IGNORE INTO team_stats (team_id) SELECT id FROM teams');

if ($adminUsername !== '' && $adminPassword !== '') {
    $stmt = $pdo->prepare('SELECT id, password_hash FROM admin_users WHERE username = ?');
    $stmt->execute([$adminUsername]);
    $adminSeed = $stmt->fetch();
    if (!$adminSeed) {
        $stmt = $pdo->prepare('INSERT INTO admin_users (username, password_hash) VALUES (?, ?)');
        $stmt->execute([$adminUsername, password_hash($adminPassword, PASSWORD_DEFAULT)]);
    } elseif (!password_verify($adminPassword, (string) $adminSeed['password_hash'])) {
        $stmt = $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($adminPassword, PASSWORD_DEFAULT), $adminSeed['id']]);
    }
}

function json_response(array $payload, int $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function current_team(PDO $pdo): ?array
{
    if (empty($_SESSION['team_id'])) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, team_name AS name, captain_name, phone_number, player_ign, player_game_id, player_game_id_key, created_at FROM teams WHERE id = ?');
    $stmt->execute([$_SESSION['team_id']]);
    return $stmt->fetch() ?: null;
}

function current_admin(PDO $pdo): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, username FROM admin_users WHERE id = ?');
    $stmt->execute([$_SESSION['admin_id']]);
    return $stmt->fetch() ?: null;
}

function require_admin(PDO $pdo): array
{
    $admin = current_admin($pdo);
    if (!$admin) {
        json_response(['ok' => false, 'message' => 'Sila login admin dulu.'], 401);
    }
    return $admin;
}

function require_team(PDO $pdo): array
{
    $team = current_team($pdo);
    if (!$team) {
        json_response(['ok' => false, 'message' => 'Sila login team dulu.'], 401);
    }
    return $team;
}

function clean_text(string $value, int $max = 120): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    return mb_substr($value, 0, $max);
}

function clean_phone(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/[^0-9+\-\s()]/', '', $value) ?? '';
    return clean_text($value, 30);
}

function password_is_strong(string $password): bool
{
    return strlen($password) >= 4
        && preg_match('/\p{Lu}/u', $password) === 1
        && preg_match('/\d/', $password) === 1
        && preg_match('/[^\p{L}\p{N}\s]/u', $password) === 1;
}

function team_has_phone(?array $team): bool
{
    return trim((string) ($team['phone_number'] ?? '')) !== '';
}

function team_is_scrim_ready(?array $team): bool
{
    return team_has_phone($team)
        && trim((string) ($team['captain_name'] ?? '')) !== ''
        && trim((string) ($team['player_ign'] ?? '')) !== ''
        && trim((string) ($team['player_game_id'] ?? '')) !== ''
        && trim((string) ($team['player_game_id_key'] ?? '')) !== '';
}

function require_scrim_ready(array $team): void
{
    if (!team_is_scrim_ready($team)) {
        json_response([
            'ok' => false,
            'message' => 'Lengkapkan Captain, telefon, Player IGN dan Player ID dalam Edit Profile sebelum create atau join scrim.',
            'needs_phone' => true,
            'needs_profile' => true,
        ], 403);
    }
}

function require_no_open_host_scrim(PDO $pdo, int $teamId): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM scrims WHERE creator_team_id = ? AND status = "open"');
    $stmt->execute([$teamId]);
    if ((int) $stmt->fetchColumn() > 0) {
        json_response(['ok' => false, 'message' => 'Team ini sudah ada 1 open scrim aktif. Tunggu request diterima atau selesaikan dulu sebelum create scrim baru.'], 409);
    }
}

function require_no_schedule_conflict(PDO $pdo, int $teamId, string $dateTime, ?int $excludeScrimId = null): void
{
    $sql = '
        SELECT title, date_time
        FROM scrims
        WHERE status IN ("open", "pending", "confirmed")
            AND (creator_team_id = ? OR opponent_team_id = ?)
            AND ABS(TIMESTAMPDIFF(MINUTE, date_time, ?)) < 120
    ';
    $params = [$teamId, $teamId, $dateTime];
    if ($excludeScrimId !== null) {
        $sql .= ' AND id != ?';
        $params[] = $excludeScrimId;
    }
    $sql .= ' ORDER BY date_time ASC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $conflict = $stmt->fetch();
    if ($conflict) {
        json_response([
            'ok' => false,
            'message' => 'Jadual clash dengan scrim aktif lain dalam window 2 jam: ' . $conflict['title'] . ' (' . $conflict['date_time'] . ').',
        ], 409);
    }
}

function require_no_pending_request_conflict(PDO $pdo, int $teamId, string $dateTime, ?int $excludeScrimId = null): void
{
    $sql = '
        SELECT s.title, s.date_time
        FROM scrim_requests r
        JOIN scrims s ON s.id = r.scrim_id
        WHERE r.requester_team_id = ?
            AND r.status = "pending"
            AND s.status = "open"
            AND ABS(TIMESTAMPDIFF(MINUTE, s.date_time, ?)) < 120
    ';
    $params = [$teamId, $dateTime];
    if ($excludeScrimId !== null) {
        $sql .= ' AND s.id != ?';
        $params[] = $excludeScrimId;
    }
    $sql .= ' ORDER BY s.date_time ASC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $conflict = $stmt->fetch();
    if ($conflict) {
        json_response([
            'ok' => false,
            'message' => 'Jadual clash dengan pending request lain dalam window 2 jam: ' . $conflict['title'] . ' (' . $conflict['date_time'] . ').',
        ], 409);
    }
}

function prune_scrim_messages(PDO $pdo, int $scrimId, int $keep = 10): void
{
    $stmt = $pdo->prepare('
        SELECT id
        FROM scrim_messages
        WHERE scrim_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 18446744073709551615 OFFSET ' . (int) $keep
    );
    $stmt->execute([$scrimId]);
    $oldMessageIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$oldMessageIds) {
        return;
    }

    $placeholders = implode(', ', array_fill(0, count($oldMessageIds), '?'));
    $delete = $pdo->prepare('DELETE FROM scrim_messages WHERE scrim_id = ? AND id IN (' . $placeholders . ')');
    $delete->execute(array_merge([$scrimId], $oldMessageIds));
}

function delete_expired_scrim_chats(PDO $pdo): void
{
    $pdo->exec('
        DELETE m
        FROM scrim_messages m
        JOIN scrims s ON s.id = m.scrim_id
        WHERE s.date_time IS NOT NULL
            AND s.date_time < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 3 DAY)
    ');
}

function remove_expired_open_scrims(PDO $pdo, array $pushConfig): void
{
    $stmt = $pdo->query('
        SELECT id, creator_team_id, title, date_time
        FROM scrims
        WHERE status = "open"
            AND opponent_team_id IS NULL
            AND date_time IS NOT NULL
            AND date_time < CURRENT_TIMESTAMP
        ORDER BY date_time ASC
        LIMIT 50
    ');
    $expiredScrims = $stmt->fetchAll();
    if (!$expiredScrims) {
        return;
    }

    $delete = $pdo->prepare('
        DELETE FROM scrims
        WHERE id = ?
            AND status = "open"
            AND opponent_team_id IS NULL
            AND date_time < CURRENT_TIMESTAMP
    ');
    foreach ($expiredScrims as $scrim) {
        $delete->execute([$scrim['id']]);
        if (!$delete->rowCount()) {
            continue;
        }
        $teamId = (int) ($scrim['creator_team_id'] ?? 0);
        if ($teamId <= 0) {
            continue;
        }
        $playTime = date_create((string) $scrim['date_time']);
        $formattedPlayTime = $playTime ? $playTime->format('d/m/Y, h:i A') : (string) $scrim['date_time'];
        try {
            queue_team_notification(
                $pdo,
                $pushConfig,
                $teamId,
                'Scrim Tamat Tanpa Lawan',
                'Tiada team menyertai scrim untuk bermain pada ' . $formattedPlayTime . '. Sila join scrim seterusnya.',
                'scrim-expired-' . (int) $scrim['id']
            );
        } catch (Throwable $pushError) {
            error_log('Expired scrim push failed: ' . $pushError->getMessage());
        }
    }
}

function complete_no_show_scrim(PDO $pdo, array $scrim, int $winnerId): bool
{
    if (!in_array($winnerId, [(int) $scrim['creator_team_id'], (int) $scrim['opponent_team_id']], true)) {
        return false;
    }

    $loserId = $winnerId === (int) $scrim['creator_team_id']
        ? (int) $scrim['opponent_team_id']
        : (int) $scrim['creator_team_id'];

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('
        UPDATE scrims
        SET winner_team_id = ?,
            result_score = "Forfeit",
            result_status = "no_show_accepted",
            result_reviewed_at = CURRENT_TIMESTAMP,
            winner_point_delta = 1,
            loser_point_delta = -2,
            status = "completed",
            completed_at = CURRENT_TIMESTAMP
        WHERE id = ? AND status = "confirmed" AND result_status = "no_show_pending"
    ');
    $stmt->execute([$winnerId, $scrim['id']]);

    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        return false;
    }

    $stmt = $pdo->prepare('INSERT IGNORE INTO team_stats (team_id) VALUES (?), (?)');
    $stmt->execute([$winnerId, $loserId]);

    $stmt = $pdo->prepare('UPDATE team_stats SET total_scrim = total_scrim + 1, total_win = total_win + 1, total_point = total_point + 1 WHERE team_id = ?');
    $stmt->execute([$winnerId]);

    $stmt = $pdo->prepare('UPDATE team_stats SET total_scrim = total_scrim + 1, total_lose = total_lose + 1, total_point = total_point - 2 WHERE team_id = ?');
    $stmt->execute([$loserId]);
    $pdo->commit();
    return true;
}

function auto_complete_expired_no_shows(PDO $pdo): void
{
    $stmt = $pdo->query('
        SELECT *
        FROM scrims
        WHERE status = "confirmed"
            AND result_status = "no_show_pending"
            AND result_submitted_at IS NOT NULL
            AND result_submitted_at <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)
        LIMIT 10
    ');
    foreach ($stmt->fetchAll() as $scrim) {
        complete_no_show_scrim($pdo, $scrim, (int) $scrim['pending_winner_team_id']);
    }
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
    $signed = openssl_sign($input, $signature, $pushConfig['private_key_pem'], OPENSSL_ALGO_SHA256);
    if (!$signed) {
        return null;
    }

    return $input . '.' . base64url_encode(der_to_jose($signature));
}

function send_empty_web_push(PDO $pdo, array $pushConfig, int $teamId): array
{
    $summary = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'statuses' => []];
    if ($teamId <= 0 || empty($pushConfig['public_key']) || empty($pushConfig['private_key_pem']) || !function_exists('curl_init')) {
        return $summary;
    }

    $stmt = $pdo->prepare('SELECT id, endpoint FROM push_subscriptions WHERE team_id = ?');
    $stmt->execute([$teamId]);
    $subscriptions = $stmt->fetchAll();

    foreach ($subscriptions as $subscription) {
        $summary['attempted']++;
        $endpoint = (string) $subscription['endpoint'];
        $jwt = vapid_jwt($pushConfig, $endpoint);
        if (!$jwt) {
            $summary['failed']++;
            $summary['statuses'][] = 'jwt_failed';
            continue;
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
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $summary['statuses'][] = $error !== '' ? $error : $status;

        if ($status >= 200 && $status < 300) {
            $summary['sent']++;
        } else {
            $summary['failed']++;
        }

        if (in_array($status, [404, 410], true)) {
            $delete = $pdo->prepare('DELETE FROM push_subscriptions WHERE id = ?');
            $delete->execute([$subscription['id']]);
        }
    }

    return $summary;
}

function queue_team_notification(PDO $pdo, array $pushConfig, int $teamId, string $title, string $body, string $tag = 'scrim-update', string $url = 'scrim.html'): array
{
    if ($teamId <= 0) return ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'statuses' => []];
    $stmt = $pdo->prepare('INSERT INTO notification_events (team_id, title, body, url, tag) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$teamId, clean_text($title, 120), clean_text($body, 500), clean_text($url, 255), clean_text($tag, 120)]);
    $pdo->prepare('DELETE FROM notification_events WHERE team_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM notification_events WHERE team_id = ? ORDER BY id DESC LIMIT 50) keep_events)')->execute([$teamId, $teamId]);
    return send_empty_web_push($pdo, $pushConfig, $teamId);
}

function fetch_state(PDO $pdo): array
{
    global $pushConfig;
    auto_complete_expired_no_shows($pdo);
    remove_expired_open_scrims($pdo, $pushConfig);
    delete_expired_scrim_chats($pdo);
    $team = current_team($pdo);
    $admin = current_admin($pdo);
    $teamId = $team['id'] ?? 0;
    $messages = [];
    if (empty($_SESSION['guest_chat_key'])) {
        $_SESSION['guest_chat_key'] = bin2hex(random_bytes(16));
    }
    $guestChatKey = (string) $_SESSION['guest_chat_key'];

    $scrims = $pdo->query("
        SELECT s.*,
            c.team_name AS creator_name,
            o.team_name AS opponent_name,
            w.team_name AS winner_name,
            pw.team_name AS pending_winner_name
        FROM scrims s
        LEFT JOIN teams c ON c.id = s.creator_team_id
        LEFT JOIN teams o ON o.id = s.opponent_team_id
        LEFT JOIN teams w ON w.id = s.winner_team_id
        LEFT JOIN teams pw ON pw.id = s.pending_winner_team_id
        ORDER BY
            CASE s.status
                WHEN 'open' THEN 1
                WHEN 'pending' THEN 2
                WHEN 'confirmed' THEN 3
                WHEN 'completed' THEN 4
                ELSE 5
            END,
            s.date_time ASC
    ")->fetchAll();

    $requests = $pdo->query("
        SELECT r.*,
            s.title AS scrim_title,
            s.creator_team_id,
            s.opponent_team_id,
            t.team_name AS requester_name
        FROM scrim_requests r
        JOIN scrims s ON s.id = r.scrim_id
        JOIN teams t ON t.id = r.requester_team_id
        ORDER BY r.created_at DESC
    ")->fetchAll();

    $stats = $pdo->query("
        SELECT
            t.id,
            t.team_name AS name,
            t.captain_name,
            t.phone_number,
            COALESCE(ts.total_win, 0) AS wins,
            COALESCE(ts.total_lose, 0) AS losses,
            COALESCE(ts.total_scrim, 0) AS played,
            COALESCE(ts.total_point, 0) AS points,
            COALESCE(ts.tier, 'UNRANKED') AS tier
        FROM teams t
        LEFT JOIN team_stats ts ON ts.team_id = t.id
        ORDER BY points DESC, wins DESC, played DESC, t.team_name ASC
    ")->fetchAll();

    $history = $pdo->query("
        SELECT s.id, s.title, s.date_time, s.format, s.result_score, s.completed_at,
            c.team_name AS creator_name,
            o.team_name AS opponent_name,
            w.team_name AS winner_name
        FROM scrims s
        LEFT JOIN teams c ON c.id = s.creator_team_id
        LEFT JOIN teams o ON o.id = s.opponent_team_id
        LEFT JOIN teams w ON w.id = s.winner_team_id
        WHERE s.status = 'completed'
        ORDER BY s.completed_at DESC
        LIMIT 20
    ")->fetchAll();

    foreach ($scrims as &$scrim) {
        $scrim['can_view_room'] = $admin || ($teamId && $scrim['status'] !== 'open' && in_array($teamId, [(int) $scrim['creator_team_id'], (int) ($scrim['opponent_team_id'] ?? 0)], true));
        if (!$scrim['can_view_room']) {
            $scrim['room_id'] = null;
            $scrim['room_password'] = null;
        }
    }

    if ($teamId || $admin) {
        $messageSql = "
            SELECT m.id, m.scrim_id, m.sender_team_id, m.message, m.created_at,
                m.sender_type, COALESCE(m.sender_name, t.team_name, 'ADMIN') AS sender_name
            FROM scrim_messages m
            JOIN scrims s ON s.id = m.scrim_id
            LEFT JOIN teams t ON t.id = m.sender_team_id
            WHERE s.status IN ('pending', 'confirmed', 'completed')
        ";
        if ($admin) {
            $stmt = $pdo->query($messageSql . ' ORDER BY m.created_at ASC, m.id ASC');
        } else {
            $stmt = $pdo->prepare($messageSql . ' AND (s.creator_team_id = ? OR s.opponent_team_id = ?) ORDER BY m.created_at ASC, m.id ASC');
            $stmt->execute([$teamId, $teamId]);
        }
        $messages = $stmt->fetchAll();
    }

    if ($admin) {
        $supportMessages = $pdo->query('SELECT * FROM support_messages ORDER BY created_at DESC, id DESC LIMIT 200')->fetchAll();
        $supportMessages = array_reverse($supportMessages);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM support_messages WHERE guest_key = ? ORDER BY created_at ASC, id ASC LIMIT 100');
        $stmt->execute([$guestChatKey]);
        $supportMessages = $stmt->fetchAll();
    }
    $groupMessages = $pdo->query('SELECT * FROM (SELECT * FROM group_chat_messages ORDER BY created_at DESC, id DESC LIMIT 50) recent ORDER BY created_at ASC, id ASC')->fetchAll();

    return [
        'ok' => true,
        'team' => $team,
        'admin' => $admin,
        'scrims' => $scrims,
        'requests' => $requests,
        'stats' => $stats,
        'history' => $history,
        'messages' => $messages,
        'support_messages' => $supportMessages,
        'group_messages' => $groupMessages,
        'push_public_key' => $pushConfig['public_key'] ?? null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['push_latest'])) {
    $token = clean_text((string) ($_GET['token'] ?? ''), 64);
    if ($token !== '') {
        $stmt = $pdo->prepare('SELECT t.id, t.team_name AS name FROM push_subscriptions p JOIN teams t ON t.id = p.team_id WHERE p.fetch_token = ?');
        $stmt->execute([$token]);
        $team = $stmt->fetch();
        if (!$team) json_response(['ok' => false, 'message' => 'Push device token tidak sah.'], 401);
    } else {
        $team = require_team($pdo);
    }
    $isTest = isset($_GET['test']);
    if ($isTest) {
        json_response([
            'ok' => true,
            'notification' => [
                'title' => 'GNEX Scrim',
                'body' => 'Phone notification aktif untuk team ' . $team['name'] . '.',
                'url' => 'scrim.html',
                'tag' => 'scrim-chat-test',
            ],
        ]);
    }
    $stmt = $pdo->prepare('SELECT title, body, url, tag FROM notification_events WHERE team_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$team['id']]);
    $notification = $stmt->fetch();
    json_response([
        'ok' => true,
        'notification' => $notification ?: null,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $registrationOpen = true;

    try {
        if ($action === 'register' && !$registrationOpen) {
            json_response(['ok' => false, 'message' => 'Registration scrim belum dibuka.'], 403);
        }

        if ($action === 'register' || $action === 'login') {
            $name = clean_text((string) ($_POST['team_name'] ?? ''), 40);
            $nameKey = normalize_team_name_key($name);
            $password = (string) ($_POST['password'] ?? '');
            $captainName = clean_text((string) ($_POST['captain_name'] ?? ''), 80);
            $phoneNumber = clean_phone((string) ($_POST['phone_number'] ?? ''));
            $playerIgn = clean_text((string) ($_POST['player_ign'] ?? ''), 80);
            $playerGameId = clean_text((string) ($_POST['player_game_id'] ?? ''), 80);
            $playerGameIdKey = normalize_player_id_key($playerGameId);

            if ($name === '' || $nameKey === '' || strlen($password) < 4) {
                json_response(['ok' => false, 'message' => 'Nama team wajib diisi dan password minimum 4 aksara.'], 422);
            }

            if ($action === 'register') {
                if (!password_is_strong($password)) {
                    json_response(['ok' => false, 'message' => 'Password mesti ada sekurang-kurangnya 1 huruf besar, 1 nombor dan 1 simbol.'], 422);
                }
                if ($playerIgn === '' || $playerGameId === '' || $playerGameIdKey === '') {
                    json_response(['ok' => false, 'message' => 'Player IGN dan Player ID wajib diisi untuk register.'], 422);
                }
                $stmt = $pdo->prepare('SELECT id FROM teams WHERE team_name_key = ?');
                $stmt->execute([$nameKey]);
                $existingTeam = $stmt->fetch();

                if ($existingTeam) {
                    json_response(['ok' => false, 'message' => 'Nama team ini sudah didaftarkan. Gunakan nama team lain.'], 409);
                }
                $stmt = $pdo->prepare('SELECT team_name FROM teams WHERE player_game_id_key = ? LIMIT 1');
                $stmt->execute([$playerGameIdKey]);
                $playerTeamName = $stmt->fetchColumn();
                if ($playerTeamName !== false) {
                    json_response(['ok' => false, 'message' => 'Player ID ini sudah menyertai team ' . $playerTeamName . '. Seorang player hanya boleh menyertai satu team.'], 409);
                }

                $stmt = $pdo->prepare('
                    INSERT INTO teams
                        (team_name, team_name_key, captain_name, phone_number, player_ign, player_game_id, player_game_id_key, password_hash)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $name,
                    $nameKey,
                    $captainName ?: null,
                    $phoneNumber ?: null,
                    $playerIgn,
                    $playerGameId,
                    $playerGameIdKey,
                    password_hash($password, PASSWORD_DEFAULT),
                ]);
                $_SESSION['team_id'] = (int) $pdo->lastInsertId();
                $stmt = $pdo->prepare('INSERT IGNORE INTO team_stats (team_id) VALUES (?)');
                $stmt->execute([$_SESSION['team_id']]);
                json_response(fetch_state($pdo) + ['message' => 'Team berjaya didaftar.']);
            }

            $stmt = $pdo->prepare('SELECT * FROM teams WHERE team_name_key = ?');
            $stmt->execute([$nameKey]);
            $team = $stmt->fetch();
            $hashMatches = $team && !empty($team['password_hash']) && password_verify($password, $team['password_hash']);
            $legacyMatches = $team && isset($team['password_code']) && hash_equals((string) $team['password_code'], $password);

            if (!$hashMatches && !$legacyMatches) {
                json_response(['ok' => false, 'message' => 'Nama team atau password salah.'], 401);
            }

            if ($legacyMatches && empty($team['password_hash'])) {
                $stmt = $pdo->prepare('UPDATE teams SET password_hash = ?, password_code = NULL WHERE id = ?');
                $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $team['id']]);
            } elseif (!empty($team['password_code'])) {
                $stmt = $pdo->prepare('UPDATE teams SET password_code = NULL WHERE id = ?');
                $stmt->execute([$team['id']]);
            }

            $_SESSION['team_id'] = (int) $team['id'];
            json_response(fetch_state($pdo) + ['message' => 'Login berjaya.']);
        }

        if ($action === 'admin_login') {
            $username = clean_text((string) ($_POST['team_name'] ?? ''), 80);
            $password = (string) ($_POST['password'] ?? '');
            if ($username === '' || $password === '') {
                json_response(['ok' => false, 'message' => 'Username dan password admin wajib diisi.'], 422);
            }

            $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE username = ?');
            $stmt->execute([$username]);
            $admin = $stmt->fetch();
            if (!$admin || !password_verify($password, (string) $admin['password_hash'])) {
                json_response(['ok' => false, 'message' => 'Login admin salah.'], 401);
            }

            unset($_SESSION['team_id']);
            $_SESSION['admin_id'] = (int) $admin['id'];
            json_response(fetch_state($pdo) + ['message' => 'Admin login berjaya.']);
        }

        if ($action === 'logout') {
            session_destroy();
            json_response(['ok' => true, 'message' => 'Logout berjaya.']);
        }

        if ($action === 'send_support_message' || $action === 'send_group_message') {
            if (empty($_SESSION['guest_chat_key'])) {
                $_SESSION['guest_chat_key'] = bin2hex(random_bytes(16));
            }
            $team = current_team($pdo);
            $admin = current_admin($pdo);
            $guestName = clean_text((string) ($_POST['guest_name'] ?? ($_SESSION['guest_chat_name'] ?? '')), 40);
            $senderName = $team['name'] ?? ($admin['username'] ?? $guestName);
            $message = clean_text((string) ($_POST['message'] ?? ''), 500);
            if ($senderName === '' || $message === '') {
                json_response(['ok' => false, 'message' => 'Masukkan nama dan mesej.'], 422);
            }
            $_SESSION['guest_chat_name'] = $guestName ?: $senderName;
            if ($action === 'send_support_message') {
                $stmt = $pdo->prepare("INSERT INTO support_messages (guest_key, guest_name, sender_type, message) VALUES (?, ?, 'guest', ?)");
                $stmt->execute([$_SESSION['guest_chat_key'], $senderName, $message]);
                json_response(fetch_state($pdo) + ['message' => 'Mesej support dihantar.']);
            }
            $senderType = $admin ? 'admin' : ($team ? 'team' : 'guest');
            $stmt = $pdo->prepare('INSERT INTO group_chat_messages (sender_team_id, sender_name, sender_type, message) VALUES (?, ?, ?, ?)');
            $stmt->execute([$team['id'] ?? null, $senderName, $senderType, $message]);
            $pdo->exec('DELETE FROM group_chat_messages WHERE id NOT IN (SELECT id FROM (SELECT id FROM group_chat_messages ORDER BY id DESC LIMIT 100) keep_rows)');
            json_response(fetch_state($pdo) + ['message' => 'Mesej group dihantar.']);
        }

        if ($action === 'send_message') {
            $team = current_team($pdo);
            $admin = current_admin($pdo);
            $scrimId = (int) ($_POST['scrim_id'] ?? 0);
            $message = clean_text((string) ($_POST['message'] ?? ''), 500);

            if (!$team && !$admin) {
                json_response(['ok' => false, 'message' => 'Sila login untuk akses Deal Room.'], 401);
            }
            if ($message === '') {
                json_response(['ok' => false, 'message' => 'Tulis mesej dulu.'], 422);
            }

            $stmt = $pdo->prepare('SELECT id, title, creator_team_id, opponent_team_id FROM scrims WHERE id = ? AND status IN ("pending", "confirmed")');
            $stmt->execute([$scrimId]);
            $scrim = $stmt->fetch();
            if (!$scrim || (!$admin && !in_array((int) $team['id'], [(int) $scrim['creator_team_id'], (int) $scrim['opponent_team_id']], true))) {
                json_response(['ok' => false, 'message' => 'Chat hanya untuk team deal ini atau admin.'], 403);
            }

            $senderTeamId = $team ? (int) $team['id'] : null;
            $senderType = $admin ? 'admin' : 'team';
            $senderName = $admin ? 'ADMIN' : (string) $team['name'];
            $stmt = $pdo->prepare('INSERT INTO scrim_messages (scrim_id, sender_team_id, sender_type, sender_name, message) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$scrimId, $senderTeamId, $senderType, $senderName, $message]);
            prune_scrim_messages($pdo, $scrimId, 10);

            $receivers = $admin
                ? [(int) $scrim['creator_team_id'], (int) $scrim['opponent_team_id']]
                : [((int) $scrim['creator_team_id'] === (int) $team['id'] ? (int) $scrim['opponent_team_id'] : (int) $scrim['creator_team_id'])];
            foreach (array_unique(array_filter($receivers)) as $receiverTeamId) {
                try {
                    queue_team_notification($pdo, $pushConfig, (int) $receiverTeamId, 'Chat Deal Baharu', $senderName . ': ' . mb_substr($message, 0, 120), 'scrim-chat-' . $scrimId);
                } catch (Throwable $pushError) {
                    error_log('Push send failed: ' . $pushError->getMessage());
                }
            }
            json_response(fetch_state($pdo) + ['message' => $admin ? 'Mesej admin dihantar ke Deal Room.' : 'Mesej dihantar.']);
        }

        if (substr((string) $action, 0, 6) === 'admin_') {
            require_admin($pdo);

            if ($action === 'admin_reply_support') {
                $guestKey = clean_text((string) ($_POST['guest_key'] ?? ''), 64);
                $guestName = clean_text((string) ($_POST['guest_name'] ?? 'Guest'), 80);
                $message = clean_text((string) ($_POST['message'] ?? ''), 500);
                if ($guestKey === '' || $message === '') {
                    json_response(['ok' => false, 'message' => 'Mesej reply diperlukan.'], 422);
                }
                $stmt = $pdo->prepare("INSERT INTO support_messages (guest_key, guest_name, sender_type, message) VALUES (?, ?, 'admin', ?)");
                $stmt->execute([$guestKey, $guestName, $message]);
                json_response(fetch_state($pdo) + ['message' => 'Reply support dihantar.']);
            }

            if ($action === 'admin_create_team') {
                $name = clean_text((string) ($_POST['team_name'] ?? ''), 40);
                $nameKey = normalize_team_name_key($name);
                $captainName = clean_text((string) ($_POST['captain_name'] ?? ''), 80);
                $phoneNumber = clean_phone((string) ($_POST['phone_number'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');
                $points = filter_var($_POST['points'] ?? 0, FILTER_VALIDATE_INT);
                if ($name === '' || $nameKey === '' || !password_is_strong($password) || $points === false) {
                    json_response(['ok' => false, 'message' => 'Password mesti ada 1 huruf besar, 1 nombor dan 1 simbol.'], 422);
                }
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM teams WHERE team_name_key = ?');
                $stmt->execute([$nameKey]);
                if ((int) $stmt->fetchColumn()) {
                    json_response(['ok' => false, 'message' => 'Nama team sudah digunakan.'], 409);
                }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO teams (team_name, team_name_key, captain_name, phone_number, password_hash) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$name, $nameKey, $captainName ?: null, $phoneNumber ?: null, password_hash($password, PASSWORD_DEFAULT)]);
                $teamId = (int) $pdo->lastInsertId();
                $stmt = $pdo->prepare('INSERT INTO team_stats (team_id, total_point) VALUES (?, ?)');
                $stmt->execute([$teamId, $points]);
                $pdo->commit();
                json_response(fetch_state($pdo) + ['message' => 'Team baharu berjaya ditambah.']);
            }

            if ($action === 'admin_update_team') {
                $teamId = (int) ($_POST['team_id'] ?? 0);
                $name = clean_text((string) ($_POST['team_name'] ?? ''), 40);
                $nameKey = normalize_team_name_key($name);
                $captainName = clean_text((string) ($_POST['captain_name'] ?? ''), 80);
                $phoneNumber = clean_phone((string) ($_POST['phone_number'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');
                $points = filter_var($_POST['points'] ?? null, FILTER_VALIDATE_INT);
                $wins = filter_var($_POST['wins'] ?? null, FILTER_VALIDATE_INT);
                $losses = filter_var($_POST['losses'] ?? null, FILTER_VALIDATE_INT);
                $played = filter_var($_POST['played'] ?? null, FILTER_VALIDATE_INT);
                if ($teamId <= 0 || $name === '' || $nameKey === '' || $points === false || $wins === false || $losses === false || $played === false || $wins < 0 || $losses < 0 || $played < 0 || ($password !== '' && !password_is_strong($password))) {
                    json_response(['ok' => false, 'message' => 'Maklumat team tidak sah. Password baharu mesti ada 1 huruf besar, 1 nombor dan 1 simbol.'], 422);
                }
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM teams WHERE team_name_key = ? AND id != ?');
                $stmt->execute([$nameKey, $teamId]);
                if ((int) $stmt->fetchColumn()) {
                    json_response(['ok' => false, 'message' => 'Nama team sudah digunakan.'], 409);
                }
                $pdo->beginTransaction();
                if ($password !== '') {
                    $stmt = $pdo->prepare('UPDATE teams SET team_name = ?, team_name_key = ?, captain_name = ?, phone_number = ?, password_hash = ?, password_code = NULL WHERE id = ?');
                    $stmt->execute([$name, $nameKey, $captainName ?: null, $phoneNumber ?: null, password_hash($password, PASSWORD_DEFAULT), $teamId]);
                } else {
                    $stmt = $pdo->prepare('UPDATE teams SET team_name = ?, team_name_key = ?, captain_name = ?, phone_number = ? WHERE id = ?');
                    $stmt->execute([$name, $nameKey, $captainName ?: null, $phoneNumber ?: null, $teamId]);
                }
                if (!$stmt->rowCount()) {
                    $check = $pdo->prepare('SELECT COUNT(*) FROM teams WHERE id = ?');
                    $check->execute([$teamId]);
                    if (!(int) $check->fetchColumn()) {
                        $pdo->rollBack();
                        json_response(['ok' => false, 'message' => 'Team tidak dijumpai.'], 404);
                    }
                }
                $stmt = $pdo->prepare('INSERT INTO team_stats (team_id, total_scrim, total_win, total_lose, total_point) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE total_scrim = VALUES(total_scrim), total_win = VALUES(total_win), total_lose = VALUES(total_lose), total_point = VALUES(total_point)');
                $stmt->execute([$teamId, $played, $wins, $losses, $points]);
                $pdo->commit();
                json_response(fetch_state($pdo) + ['message' => 'Info, total played, point, win dan lose team berjaya dikemaskini.']);
            }

            if ($action === 'admin_delete_team') {
                $teamId = (int) ($_POST['team_id'] ?? 0);
                if ($teamId <= 0) {
                    json_response(['ok' => false, 'message' => 'Team tidak sah.'], 422);
                }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('DELETE FROM scrims WHERE creator_team_id = ? OR opponent_team_id = ? OR winner_team_id = ?');
                $stmt->execute([$teamId, $teamId, $teamId]);
                $removedScrims = $stmt->rowCount();
                $stmt = $pdo->prepare('DELETE FROM teams WHERE id = ?');
                $stmt->execute([$teamId]);
                if (!$stmt->rowCount()) {
                    $pdo->rollBack();
                    json_response(['ok' => false, 'message' => 'Team tidak dijumpai.'], 404);
                }
                $pdo->commit();
                json_response(fetch_state($pdo) + ['message' => 'Team dibuang bersama ' . $removedScrims . ' rekod scrim berkaitan.']);
            }

            if ($action === 'admin_delete_scrim') {
                $scrimId = (int) ($_POST['scrim_id'] ?? 0);
                $stmt = $pdo->prepare('DELETE FROM scrims WHERE id = ?');
                $stmt->execute([$scrimId]);
                json_response(fetch_state($pdo) + ['message' => $stmt->rowCount() ? 'Scrim dipadam oleh admin.' : 'Scrim tidak dijumpai.']);
            }

            if ($action === 'admin_update_scrim') {
                $scrimId = (int) ($_POST['scrim_id'] ?? 0);
                $opponentTeamId = (int) ($_POST['opponent_team_id'] ?? 0);
                $title = clean_text((string) ($_POST['title'] ?? ''), 80);
                $dateTime = str_replace('T', ' ', clean_text((string) ($_POST['date_time'] ?? ''), 40));
                $format = clean_text((string) ($_POST['format'] ?? 'BO3'), 12);
                $pointMode = ($_POST['point_mode'] ?? 'normal') === 'challenge' ? 'challenge' : 'normal';
                $notes = clean_text((string) ($_POST['notes'] ?? ''), 180);

                $parsedDate = date_create($dateTime);
                if ($scrimId <= 0 || $title === '' || !$parsedDate) {
                    json_response(['ok' => false, 'message' => 'Data edit scrim tidak lengkap.'], 422);
                }

                $stmt = $pdo->prepare('SELECT creator_team_id, opponent_team_id, status FROM scrims WHERE id = ?');
                $stmt->execute([$scrimId]);
                $scrim = $stmt->fetch();
                if (!$scrim) {
                    json_response(['ok' => false, 'message' => 'Scrim tidak dijumpai.'], 404);
                }
                if ($opponentTeamId === (int) $scrim['creator_team_id']) {
                    json_response(['ok' => false, 'message' => 'Opponent mesti berbeza daripada host.'], 422);
                }
                if ($scrim['status'] === 'completed' && $opponentTeamId !== (int) $scrim['opponent_team_id']) {
                    json_response(['ok' => false, 'message' => 'Opponent scrim yang sudah completed tidak boleh ditukar.'], 409);
                }
                if ($opponentTeamId > 0) {
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM teams WHERE id = ?');
                    $stmt->execute([$opponentTeamId]);
                    if (!(int) $stmt->fetchColumn()) {
                        json_response(['ok' => false, 'message' => 'Opponent tidak sah.'], 422);
                    }
                }

                $nextStatus = $scrim['status'] === 'completed'
                    ? 'completed'
                    : ($opponentTeamId > 0 ? 'confirmed' : 'open');
                $opponentValue = $opponentTeamId > 0 ? $opponentTeamId : null;
                $challengerId = $pointMode === 'challenge' && $opponentTeamId > 0 ? $opponentTeamId : null;
                $defenderId = $pointMode === 'challenge' ? (int) $scrim['creator_team_id'] : null;
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('
                    UPDATE scrims
                    SET opponent_team_id = ?, title = ?, date_time = ?, format = ?, notes = ?, point_mode = ?,
                        challenger_team_id = ?, defender_team_id = ?, status = ?
                    WHERE id = ?
                ');
                $stmt->execute([
                    $opponentValue,
                    $title,
                    $parsedDate->format('Y-m-d H:i:s'),
                    $format,
                    $notes,
                    $pointMode,
                    $challengerId,
                    $defenderId,
                    $nextStatus,
                    $scrimId,
                ]);
                if ($nextStatus === 'confirmed') {
                    $stmt = $pdo->prepare("UPDATE scrim_requests SET status = CASE WHEN requester_team_id = ? THEN 'accepted' ELSE 'rejected' END, responded_at = NOW() WHERE scrim_id = ? AND status = 'pending'");
                    $stmt->execute([$opponentTeamId, $scrimId]);
                }
                $pdo->commit();
                if ($opponentTeamId > 0) {
                    queue_team_notification($pdo, $pushConfig, $opponentTeamId, 'Match Ditetapkan Admin', 'Anda telah dipilih untuk lawan ' . $title . '.', 'admin-match-' . $scrimId);
                    json_response(fetch_state($pdo) + ['message' => 'Opponent ditambah dan scrim terus confirmed.']);
                }
                json_response(fetch_state($pdo) + ['message' => 'Opponent dikosongkan. Scrim kini dipromosikan di All Scrim.']);
            }

            if ($action === 'admin_record_result') {
                $scrimId = (int) ($_POST['scrim_id'] ?? 0);
                $score = clean_text((string) ($_POST['result_score'] ?? ''), 12);
                if ($scrimId <= 0 || !preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})$/', $score, $scoreParts)) {
                    json_response(['ok' => false, 'message' => 'Masukkan score dalam format 2-1.'], 422);
                }
                $creatorScore = (int) $scoreParts[1];
                $opponentScore = (int) $scoreParts[2];
                if ($creatorScore === $opponentScore) {
                    json_response(['ok' => false, 'message' => 'Score seri tidak boleh menentukan win dan lose.'], 422);
                }
                $score = $creatorScore . '-' . $opponentScore;
                $stmt = $pdo->prepare('SELECT * FROM scrims WHERE id = ?');
                $stmt->execute([$scrimId]);
                $scrim = $stmt->fetch();
                if (!$scrim || !(int) $scrim['opponent_team_id']) {
                    json_response(['ok' => false, 'message' => 'Tetapkan opponent dahulu sebelum update result.'], 422);
                }
                if ($scrim['status'] === 'completed') {
                    json_response(['ok' => false, 'message' => 'Result scrim ini sudah dikira dalam ranking.'], 409);
                }
                $creatorId = (int) $scrim['creator_team_id'];
                $opponentId = (int) $scrim['opponent_team_id'];
                $winnerId = $creatorScore > $opponentScore ? $creatorId : $opponentId;
                $loserId = $winnerId === $creatorId ? $opponentId : $creatorId;
                $winnerPointDelta = 1;
                $loserPointDelta = -1;
                if (($scrim['point_mode'] ?? 'normal') === 'challenge') {
                    $challengerId = (int) ($scrim['challenger_team_id'] ?? 0);
                    if ($challengerId > 0 && $winnerId === $challengerId) {
                        $winnerPointDelta = 2;
                        $loserPointDelta = -2;
                    }
                }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE scrims SET winner_team_id = ?, result_score = ?, result_status = 'accepted', winner_point_delta = ?, loser_point_delta = ?, status = 'completed', completed_at = CURRENT_TIMESTAMP, result_reviewed_at = CURRENT_TIMESTAMP WHERE id = ? AND status != 'completed'");
                $stmt->execute([$winnerId, $score, $winnerPointDelta, $loserPointDelta, $scrimId]);
                if (!$stmt->rowCount()) {
                    $pdo->rollBack();
                    json_response(['ok' => false, 'message' => 'Result sudah diproses. Refresh dan cuba semula.'], 409);
                }
                $stmt = $pdo->prepare('INSERT IGNORE INTO team_stats (team_id) VALUES (?), (?)');
                $stmt->execute([$winnerId, $loserId]);
                $stmt = $pdo->prepare('UPDATE team_stats SET total_scrim = total_scrim + 1, total_win = total_win + 1, total_point = total_point + ? WHERE team_id = ?');
                $stmt->execute([$winnerPointDelta, $winnerId]);
                $stmt = $pdo->prepare('UPDATE team_stats SET total_scrim = total_scrim + 1, total_lose = total_lose + 1, total_point = total_point + ? WHERE team_id = ?');
                $stmt->execute([$loserPointDelta, $loserId]);
                $pdo->commit();
                json_response(fetch_state($pdo) + ['message' => 'Result ' . $score . ' disimpan. Win, lose dan point ranking berjaya dikemaskini.']);
            }

            if ($action === 'admin_create_match') {
                $teamOneId = (int) ($_POST['team_one_id'] ?? 0);
                $teamTwoId = (int) ($_POST['team_two_id'] ?? 0);
                $title = clean_text((string) ($_POST['title'] ?? ''), 80);
                $dateTime = str_replace('T', ' ', clean_text((string) ($_POST['date_time'] ?? ''), 40));
                $format = clean_text((string) ($_POST['format'] ?? 'BO3'), 12);
                $pointMode = ($_POST['point_mode'] ?? 'normal') === 'challenge' ? 'challenge' : 'normal';
                $notes = clean_text((string) ($_POST['notes'] ?? ''), 180);
                $parsedDate = date_create($dateTime);

                if (($teamTwoId > 0 && $teamOneId <= 0) || ($teamTwoId > 0 && $teamOneId === $teamTwoId) || $title === '' || !$parsedDate) {
                    json_response(['ok' => false, 'message' => 'Isi tajuk dan masa scrim. Jika Team 2 dipilih, Team 1 mesti dipilih dan berbeza.'], 422);
                }

                $teamIds = array_values(array_filter([$teamOneId, $teamTwoId], static fn (int $id): bool => $id > 0));
                if ($teamIds) {
                    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM teams WHERE id IN (' . $placeholders . ')');
                    $stmt->execute($teamIds);
                    if ((int) $stmt->fetchColumn() !== count($teamIds)) {
                        json_response(['ok' => false, 'message' => 'Team pilihan tidak sah.'], 422);
                    }
                }

                $hasHost = $teamOneId > 0;
                $hasOpponent = $teamTwoId > 0;
                $creatorValue = $hasHost ? $teamOneId : null;
                $opponentValue = $hasOpponent ? $teamTwoId : null;
                $status = $hasOpponent ? 'confirmed' : 'open';
                $challengerId = $pointMode === 'challenge' && $hasOpponent ? $teamTwoId : null;
                $defenderId = $pointMode === 'challenge' && $hasHost ? $teamOneId : null;
                $stmt = $pdo->prepare('
                    INSERT INTO scrims
                        (creator_team_id, opponent_team_id, title, date_time, format, notes, point_mode, admin_open_slots, challenger_team_id, defender_team_id, status)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $creatorValue,
                    $opponentValue,
                    $title,
                    $parsedDate->format('Y-m-d H:i:s'),
                    $format,
                    $notes,
                    $pointMode,
                    $hasHost ? 0 : 1,
                    $challengerId,
                    $defenderId,
                    $status,
                ]);
                $newScrimId = (int) $pdo->lastInsertId();
                if ($hasOpponent) {
                    queue_team_notification($pdo, $pushConfig, $teamOneId, 'Match Baharu', 'Admin menetapkan match ' . $title . '.', 'admin-match-' . $newScrimId);
                    queue_team_notification($pdo, $pushConfig, $teamTwoId, 'Match Baharu', 'Admin menetapkan match ' . $title . '.', 'admin-match-' . $newScrimId);
                    json_response(fetch_state($pdo) + ['message' => 'Admin berjaya create confirmed scrim untuk 2 team.']);
                }
                if ($hasHost) {
                    queue_team_notification($pdo, $pushConfig, $teamOneId, 'Scrim Dipromosikan', $title . ' sudah dibuka untuk team lain request join.', 'admin-open-' . $newScrimId);
                }
                json_response(fetch_state($pdo) + ['message' => $hasHost
                    ? 'Open scrim berjaya dibuat dan dipaparkan di All Scrim.'
                    : 'Open scrim 2 slot berjaya dibuat. Mana-mana team boleh ambil slot pertama.']);
            }

            json_response(['ok' => false, 'message' => 'Admin action tidak dikenali.'], 400);
        }

        $team = require_team($pdo);

        if ($action === 'create_scrim') {
            require_scrim_ready($team);
            require_no_open_host_scrim($pdo, (int) $team['id']);

            $title = clean_text((string) ($_POST['title'] ?? ''), 80);
            $dateTime = str_replace('T', ' ', clean_text((string) ($_POST['date_time'] ?? ''), 40));
            $format = clean_text((string) ($_POST['format'] ?? 'BO3'), 12);
            $pointMode = ($_POST['point_mode'] ?? 'normal') === 'challenge' ? 'challenge' : 'normal';
            $notes = clean_text((string) ($_POST['notes'] ?? ''), 180);

            if ($title === '' || $dateTime === '') {
                json_response(['ok' => false, 'message' => 'Tajuk dan masa scrim wajib diisi.'], 422);
            }

            $parsedDate = date_create($dateTime);
            if (!$parsedDate) {
                json_response(['ok' => false, 'message' => 'Format tarikh scrim tidak sah.'], 422);
            }
            require_no_schedule_conflict($pdo, (int) $team['id'], $parsedDate->format('Y-m-d H:i:s'));
            require_no_pending_request_conflict($pdo, (int) $team['id'], $parsedDate->format('Y-m-d H:i:s'));

            $columns = ['creator_team_id', 'title', 'date_time', 'format', 'notes', 'point_mode'];
            $values = [$team['id'], $title, $parsedDate->format('Y-m-d H:i:s'), $format, $notes, $pointMode];

            if (column_exists($pdo, 'scrims', 'game_name')) {
                $columns[] = 'game_name';
                $values[] = 'Mobile Legends';
            }

            if (column_exists($pdo, 'scrims', 'scrim_date')) {
                $columns[] = 'scrim_date';
                $values[] = $parsedDate->format('Y-m-d');
            }

            if (column_exists($pdo, 'scrims', 'scrim_time')) {
                $columns[] = 'scrim_time';
                $values[] = $parsedDate->format('H:i:s');
            }

            if (column_exists($pdo, 'scrims', 'note')) {
                $columns[] = 'note';
                $values[] = $notes;
            }

            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $stmt = $pdo->prepare('INSERT INTO scrims (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
            $stmt->execute($values);
            json_response(fetch_state($pdo) + ['message' => 'Scrim baru sudah dipaparkan.']);
        }

        if ($action === 'update_profile') {
            $captainName = clean_text((string) ($_POST['captain_name'] ?? ''), 80);
            $phoneNumber = clean_phone((string) ($_POST['phone_number'] ?? ''));
            $playerIgn = clean_text((string) ($_POST['player_ign'] ?? ''), 80);
            $playerGameId = clean_text((string) ($_POST['player_game_id'] ?? ''), 80);
            $playerGameIdKey = normalize_player_id_key($playerGameId);
            if ($playerGameIdKey !== '') {
                $stmt = $pdo->prepare('SELECT team_name FROM teams WHERE player_game_id_key = ? AND id != ? LIMIT 1');
                $stmt->execute([$playerGameIdKey, $team['id']]);
                $playerTeamName = $stmt->fetchColumn();
                if ($playerTeamName !== false) {
                    json_response(['ok' => false, 'message' => 'Player ID ini sudah menyertai team ' . $playerTeamName . '. Seorang player hanya boleh menyertai satu team.'], 409);
                }
            }
            $stmt = $pdo->prepare('UPDATE teams SET captain_name = ?, phone_number = ?, player_ign = ?, player_game_id = ?, player_game_id_key = ? WHERE id = ?');
            $stmt->execute([
                $captainName !== '' ? $captainName : null,
                $phoneNumber !== '' ? $phoneNumber : null,
                $playerIgn !== '' ? $playerIgn : null,
                $playerGameId !== '' ? $playerGameId : null,
                $playerGameIdKey !== '' ? $playerGameIdKey : null,
                $team['id'],
            ]);

            json_response(fetch_state($pdo) + ['message' => 'Profile team dikemaskini.']);
        }

        if ($action === 'save_push_subscription') {
            $subscriptionJson = (string) ($_POST['subscription'] ?? '');
            $subscription = json_decode($subscriptionJson, true);
            $endpoint = clean_text((string) ($subscription['endpoint'] ?? ''), 2048);
            $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
            $p256dh = clean_text((string) ($keys['p256dh'] ?? ''), 255);
            $auth = clean_text((string) ($keys['auth'] ?? ''), 255);

            if ($endpoint === '') {
                json_response(['ok' => false, 'message' => 'Push subscription tidak sah.'], 422);
            }

            $endpointHash = hash('sha256', $endpoint);
            $fetchToken = bin2hex(random_bytes(32));
            $userAgent = clean_text((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 255);
            $stmt = $pdo->prepare('
                INSERT INTO push_subscriptions (team_id, endpoint_hash, endpoint, p256dh, auth, fetch_token, user_agent, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE
                    team_id = VALUES(team_id),
                    p256dh = VALUES(p256dh),
                    auth = VALUES(auth),
                    fetch_token = VALUES(fetch_token),
                    user_agent = VALUES(user_agent),
                    updated_at = CURRENT_TIMESTAMP
            ');
            $stmt->execute([$team['id'], $endpointHash, $endpoint, $p256dh, $auth, $fetchToken, $userAgent]);

            json_response(fetch_state($pdo) + ['message' => 'Phone notification aktif untuk team ini.', 'push_device_token' => $fetchToken]);
        }

        if ($action === 'test_push') {
            $pushSummary = queue_team_notification($pdo, $pushConfig, (int) $team['id'], 'GNEX Scrim', 'Notification Android/iPhone aktif untuk team ' . $team['name'] . '.', 'scrim-test');
            $message = $pushSummary['sent'] > 0
                ? 'Test push dihantar ke device subscribed.'
                : 'Test push belum berjaya. Pastikan tekan CHAT NOTI dari phone/browser penerima.';
            json_response(fetch_state($pdo) + [
                'message' => $message,
                'push_summary' => $pushSummary,
            ]);
        }

        if ($action === 'request_join') {
            require_scrim_ready($team);

            $scrimId = (int) ($_POST['scrim_id'] ?? 0);
            $message = clean_text((string) ($_POST['message'] ?? ''), 160);

            $stmt = $pdo->prepare('SELECT * FROM scrims WHERE id = ? AND status = "open"');
            $stmt->execute([$scrimId]);
            $scrim = $stmt->fetch();
            if (!$scrim || (int) $scrim['creator_team_id'] === (int) $team['id']) {
                json_response(['ok' => false, 'message' => 'Scrim ini tidak boleh direquest.'], 403);
            }
            require_no_schedule_conflict($pdo, (int) $team['id'], (string) $scrim['date_time'], $scrimId);
            require_no_pending_request_conflict($pdo, (int) $team['id'], (string) $scrim['date_time'], $scrimId);

            if (empty($scrim['creator_team_id'])) {
                $stmt = $pdo->prepare('UPDATE scrims SET creator_team_id = ? WHERE id = ? AND status = "open" AND creator_team_id IS NULL');
                $stmt->execute([$team['id'], $scrimId]);
                if ($stmt->rowCount()) {
                    json_response(fetch_state($pdo) + ['message' => 'Team anda berjaya ambil slot pertama. Scrim masih terbuka untuk team kedua join.']);
                }
                json_response(['ok' => false, 'message' => 'Slot pertama baru sahaja diambil team lain. Refresh dan cuba join sebagai opponent.'], 409);
            }

            if (!empty($scrim['admin_open_slots']) && empty($scrim['opponent_team_id'])) {
                $challengerId = ($scrim['point_mode'] ?? 'normal') === 'challenge' ? (int) $team['id'] : null;
                $defenderId = ($scrim['point_mode'] ?? 'normal') === 'challenge' ? (int) $scrim['creator_team_id'] : null;
                $stmt = $pdo->prepare('
                    UPDATE scrims
                    SET opponent_team_id = ?, challenger_team_id = ?, defender_team_id = ?, status = "confirmed"
                    WHERE id = ? AND status = "open" AND opponent_team_id IS NULL AND creator_team_id != ?
                ');
                $stmt->execute([$team['id'], $challengerId, $defenderId, $scrimId, $team['id']]);
                if ($stmt->rowCount()) {
                    queue_team_notification($pdo, $pushConfig, (int) $scrim['creator_team_id'], 'Open Scrim Penuh', $team['name'] . ' telah mengambil slot kedua untuk ' . $scrim['title'] . '.', 'admin-open-full-' . $scrimId);
                    queue_team_notification($pdo, $pushConfig, (int) $team['id'], 'Scrim Confirmed', 'Anda berjaya mengambil slot kedua untuk ' . $scrim['title'] . '.', 'admin-open-confirmed-' . $scrimId);
                    json_response(fetch_state($pdo) + ['message' => 'Team anda berjaya ambil slot kedua. Scrim kini confirmed dan Deal Room sudah dibuka.']);
                }
                json_response(['ok' => false, 'message' => 'Slot kedua baru sahaja diambil team lain.'], 409);
            }

            $stmt = $pdo->prepare('INSERT IGNORE INTO scrim_requests (scrim_id, requester_team_id, message) VALUES (?, ?, ?)');
            $stmt->execute([$scrimId, $team['id'], $message]);
            if ($stmt->rowCount()) queue_team_notification($pdo, $pushConfig, (int) $scrim['creator_team_id'], 'Request Join Baharu', $team['name'] . ' mahu join ' . $scrim['title'] . '.', 'scrim-request-' . $scrimId);
            json_response(fetch_state($pdo) + ['message' => 'Request sudah dihantar.']);
        }

        if ($action === 'respond_request') {
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $decision = $_POST['decision'] === 'accept' ? 'accepted' : 'rejected';

            $stmt = $pdo->prepare("
                SELECT r.*, s.creator_team_id, s.status AS scrim_status
                FROM scrim_requests r
                JOIN scrims s ON s.id = r.scrim_id
                WHERE r.id = ?
            ");
            $stmt->execute([$requestId]);
            $request = $stmt->fetch();

            if (!$request || (int) $request['creator_team_id'] !== (int) $team['id']) {
                json_response(['ok' => false, 'message' => 'Request ini tidak boleh diubah.'], 403);
            }

            if ($request['status'] !== 'pending' || $request['scrim_status'] !== 'open') {
                json_response(fetch_state($pdo) + ['message' => 'Request ini sudah diproses. Deal room sudah dikemaskini.']);
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE scrim_requests SET status = ?, responded_at = CURRENT_TIMESTAMP WHERE id = ? AND status = "pending"');
            $stmt->execute([$decision, $requestId]);

            if ($decision === 'accepted') {
                $stmt = $pdo->prepare('SELECT point_mode FROM scrims WHERE id = ?');
                $stmt->execute([$request['scrim_id']]);
                $pointMode = (string) ($stmt->fetchColumn() ?: 'normal');
                $challengerId = $pointMode === 'challenge' ? (int) $request['requester_team_id'] : null;
                $defenderId = $pointMode === 'challenge' ? (int) $request['creator_team_id'] : null;

                $stmt = $pdo->prepare('UPDATE scrims SET opponent_team_id = ?, challenger_team_id = ?, defender_team_id = ?, status = "pending" WHERE id = ?');
                $stmt->execute([$request['requester_team_id'], $challengerId, $defenderId, $request['scrim_id']]);

                $stmt = $pdo->prepare('UPDATE scrim_requests SET status = "rejected", responded_at = CURRENT_TIMESTAMP WHERE scrim_id = ? AND id != ? AND status = "pending"');
                $stmt->execute([$request['scrim_id'], $requestId]);
            }
            $pdo->commit();

            queue_team_notification($pdo, $pushConfig, (int) $request['requester_team_id'], $decision === 'accepted' ? 'Request Diterima' : 'Request Ditolak', $decision === 'accepted' ? 'Host menerima request anda. Deal room sudah dibuka.' : 'Host menolak request scrim anda.', 'scrim-request-result-' . $request['scrim_id']);

            json_response(fetch_state($pdo) + ['message' => $decision === 'accepted' ? 'Request diterima. Deal room dibuka.' : 'Request ditolak.']);
        }

        if ($action === 'set_room') {
            $scrimId = (int) ($_POST['scrim_id'] ?? 0);
            $roomId = clean_text((string) ($_POST['room_id'] ?? ''), 40);

            if ($roomId === '') {
                json_response(['ok' => false, 'message' => 'Room ID wajib diisi.'], 422);
            }

            $stmt = $pdo->prepare('UPDATE scrims SET room_id = ?, room_password = NULL, status = "confirmed" WHERE id = ? AND creator_team_id = ? AND status IN ("pending", "confirmed")');
            $stmt->execute([$roomId, $scrimId, $team['id']]);

            if ($stmt->rowCount() === 0) {
                json_response(['ok' => false, 'message' => 'Hanya team host boleh masukkan room untuk deal ini.'], 403);
            }

            $stmt = $pdo->prepare('SELECT opponent_team_id, title FROM scrims WHERE id = ?');
            $stmt->execute([$scrimId]);
            $roomScrim = $stmt->fetch();
            if ($roomScrim && (int) $roomScrim['opponent_team_id'] > 0) queue_team_notification($pdo, $pushConfig, (int) $roomScrim['opponent_team_id'], 'Scrim Confirmed', 'Room untuk ' . $roomScrim['title'] . ' sudah tersedia.', 'scrim-room-' . $scrimId);

            json_response(fetch_state($pdo) + ['message' => 'Room ID disimpan. Scrim confirmed.']);
        }

        if ($action === 'update_result') {
            $scrimId = (int) ($_POST['scrim_id'] ?? 0);
            $winnerId = (int) ($_POST['winner_team_id'] ?? 0);
            $score = clean_text((string) ($_POST['result_score'] ?? ''), 30);

            $stmt = $pdo->prepare('SELECT * FROM scrims WHERE id = ? AND status = "confirmed"');
            $stmt->execute([$scrimId]);
            $scrim = $stmt->fetch();

            if (!$scrim || (int) $team['id'] !== (int) $scrim['creator_team_id']) {
                json_response(['ok' => false, 'message' => 'Hanya host scrim boleh submit result untuk opponent confirm.'], 403);
            }

            if (!in_array($winnerId, [(int) $scrim['creator_team_id'], (int) $scrim['opponent_team_id']], true)) {
                json_response(['ok' => false, 'message' => 'Pilih winner yang sah.'], 422);
            }

            if ($score === '') {
                json_response(['ok' => false, 'message' => 'Score result wajib diisi.'], 422);
            }

            $stmt = $pdo->prepare('
                UPDATE scrims
                SET pending_winner_team_id = ?,
                    pending_result_score = ?,
                    result_status = "pending",
                    result_submitted_by = ?,
                    result_submitted_at = CURRENT_TIMESTAMP,
                    result_reviewed_at = NULL
                WHERE id = ? AND creator_team_id = ? AND status = "confirmed"
            ');
            $stmt->execute([$winnerId, $score, $team['id'], $scrimId, $team['id']]);

            if ($stmt->rowCount() === 0) {
                json_response(['ok' => false, 'message' => 'Result tidak dapat dihantar. Pastikan scrim masih confirmed.'], 403);
            }

            queue_team_notification($pdo, $pushConfig, (int) $scrim['opponent_team_id'], 'Result Perlu Disahkan', $team['name'] . ' menghantar result ' . $score . '. Sila confirm atau reject.', 'scrim-result-' . $scrimId);

            json_response(fetch_state($pdo) + ['message' => 'Result dihantar kepada opponent untuk confirm.']);
        }

        if ($action === 'report_no_show') {
            $scrimId = (int) ($_POST['scrim_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM scrims WHERE id = ? AND status = "confirmed" AND date_time <= CURRENT_TIMESTAMP');
            $stmt->execute([$scrimId]);
            $scrim = $stmt->fetch();

            if (!$scrim || !in_array((int) $team['id'], [(int) $scrim['creator_team_id'], (int) $scrim['opponent_team_id']], true)) {
                json_response(['ok' => false, 'message' => 'No-show hanya boleh direport oleh team dalam confirmed scrim selepas masa match bermula.'], 403);
            }

            if (in_array((string) ($scrim['result_status'] ?? ''), ['pending', 'reported', 'no_show_pending'], true)) {
                json_response(['ok' => false, 'message' => 'Result/report untuk scrim ini masih pending.'], 409);
            }

            $stmt = $pdo->prepare('
                UPDATE scrims
                SET pending_winner_team_id = ?,
                    pending_result_score = "Forfeit",
                    result_status = "no_show_pending",
                    result_submitted_by = ?,
                    result_submitted_at = CURRENT_TIMESTAMP,
                    result_reviewed_at = NULL
                WHERE id = ? AND status = "confirmed"
            ');
            $stmt->execute([$team['id'], $team['id'], $scrimId]);

            json_response(fetch_state($pdo) + ['message' => 'No-show report dihantar. Lawan ada 15 minit untuk dispute sebelum auto-complete.']);
        }

        if ($action === 'respond_no_show') {
            $scrimId = (int) ($_POST['scrim_id'] ?? 0);
            $decision = $_POST['decision'] === 'accept' ? 'accept' : 'dispute';
            $stmt = $pdo->prepare('SELECT * FROM scrims WHERE id = ? AND status = "confirmed" AND result_status = "no_show_pending"');
            $stmt->execute([$scrimId]);
            $scrim = $stmt->fetch();

            if (!$scrim || !in_array((int) $team['id'], [(int) $scrim['creator_team_id'], (int) $scrim['opponent_team_id']], true)) {
                json_response(['ok' => false, 'message' => 'No-show report ini tidak sah.'], 403);
            }

            if ((int) $scrim['pending_winner_team_id'] === (int) $team['id']) {
                json_response(['ok' => false, 'message' => 'Team yang report no-show tidak boleh confirm/dispute sendiri.'], 403);
            }

            if ($decision === 'dispute') {
                $stmt = $pdo->prepare('UPDATE scrims SET result_status = "reported", result_reviewed_at = CURRENT_TIMESTAMP WHERE id = ? AND status = "confirmed" AND result_status = "no_show_pending"');
                $stmt->execute([$scrimId]);
                json_response(fetch_state($pdo) + ['message' => 'No-show disputed. Menunggu admin review.']);
            }

            $completed = complete_no_show_scrim($pdo, $scrim, (int) $scrim['pending_winner_team_id']);
            json_response(fetch_state($pdo) + ['message' => $completed ? 'No-show disahkan. Point penalty dimasukkan.' : 'No-show sudah diproses.']);
        }

        if ($action === 'confirm_result') {
            $scrimId = (int) ($_POST['scrim_id'] ?? 0);
            $decision = $_POST['decision'] === 'accept' ? 'accepted' : 'rejected';

            $stmt = $pdo->prepare('SELECT * FROM scrims WHERE id = ? AND status = "confirmed" AND result_status = "pending"');
            $stmt->execute([$scrimId]);
            $scrim = $stmt->fetch();

            if (!$scrim || (int) $team['id'] !== (int) $scrim['opponent_team_id']) {
                json_response(['ok' => false, 'message' => 'Hanya opponent boleh confirm result ini.'], 403);
            }

            if ($decision === 'rejected') {
                $stmt = $pdo->prepare('UPDATE scrims SET result_status = "rejected", result_reviewed_at = CURRENT_TIMESTAMP WHERE id = ? AND opponent_team_id = ? AND status = "confirmed"');
                $stmt->execute([$scrimId, $team['id']]);
                queue_team_notification($pdo, $pushConfig, (int) $scrim['creator_team_id'], 'Result Ditolak', 'Opponent menolak result. Sila semak dan submit semula.', 'scrim-result-rejected-' . $scrimId);
                json_response(fetch_state($pdo) + ['message' => 'Result ditolak. Host perlu submit semula score yang betul.']);
            }

            $winnerId = (int) $scrim['pending_winner_team_id'];
            $score = (string) $scrim['pending_result_score'];

            if (!in_array($winnerId, [(int) $scrim['creator_team_id'], (int) $scrim['opponent_team_id']], true)) {
                json_response(['ok' => false, 'message' => 'Pending winner tidak sah. Minta host submit semula result.'], 422);
            }

            $loserId = $winnerId === (int) $scrim['creator_team_id']
                ? (int) $scrim['opponent_team_id']
                : (int) $scrim['creator_team_id'];

            $winnerPointDelta = 1;
            $loserPointDelta = -1;
            if (($scrim['point_mode'] ?? 'normal') === 'challenge') {
                $challengerId = (int) ($scrim['challenger_team_id'] ?? 0);
                $defenderId = (int) ($scrim['defender_team_id'] ?? 0);
                if ($challengerId <= 0 || $defenderId <= 0) {
                    json_response(['ok' => false, 'message' => 'Role challenger dan defender belum lengkap.'], 422);
                }
                if ($winnerId === $challengerId) {
                    $winnerPointDelta = 2;
                    $loserPointDelta = -2;
                }
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('
                UPDATE scrims
                SET winner_team_id = ?,
                    result_score = ?,
                    result_status = "accepted",
                    result_reviewed_at = CURRENT_TIMESTAMP,
                    winner_point_delta = ?,
                    loser_point_delta = ?,
                    status = "completed",
                    completed_at = CURRENT_TIMESTAMP
                WHERE id = ? AND opponent_team_id = ? AND status = "confirmed" AND result_status = "pending"
            ');
            $stmt->execute([$winnerId, $score, $winnerPointDelta, $loserPointDelta, $scrimId, $team['id']]);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                json_response(['ok' => false, 'message' => 'Result sudah berubah. Sila refresh dan cuba lagi.'], 409);
            }

            $stmt = $pdo->prepare('INSERT IGNORE INTO team_stats (team_id) VALUES (?), (?)');
            $stmt->execute([$winnerId, $loserId]);

            $stmt = $pdo->prepare('UPDATE team_stats SET total_scrim = total_scrim + 1, total_win = total_win + 1, total_point = total_point + ? WHERE team_id = ?');
            $stmt->execute([$winnerPointDelta, $winnerId]);

            $stmt = $pdo->prepare('UPDATE team_stats SET total_scrim = total_scrim + 1, total_lose = total_lose + 1, total_point = total_point + ? WHERE team_id = ?');
            $stmt->execute([$loserPointDelta, $loserId]);
            $pdo->commit();
            queue_team_notification($pdo, $pushConfig, (int) $scrim['creator_team_id'], 'Result Disahkan', 'Result ' . $score . ' sudah disahkan. Ranking telah dikemaskini.', 'scrim-result-confirmed-' . $scrimId);
            json_response(fetch_state($pdo) + ['message' => 'Result disahkan. Point masuk ranking dan history team.']);
        }

        if ($action === 'report_result') {
            $scrimId = (int) ($_POST['scrim_id'] ?? 0);
            $reportedScore = clean_text((string) ($_POST['reported_score'] ?? ''), 30);
            $message = clean_text((string) ($_POST['message'] ?? ''), 220);

            if ($reportedScore === '') {
                json_response(['ok' => false, 'message' => 'Masukkan score sebenar untuk report.'], 422);
            }

            $stmt = $pdo->prepare('SELECT * FROM scrims WHERE id = ? AND status = "confirmed" AND result_status = "pending"');
            $stmt->execute([$scrimId]);
            $scrim = $stmt->fetch();

            if (!$scrim || (int) $team['id'] !== (int) $scrim['opponent_team_id']) {
                json_response(['ok' => false, 'message' => 'Hanya opponent boleh report result pending ini.'], 403);
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('
                INSERT INTO result_reports (scrim_id, reporter_team_id, submitted_score, reported_score, message)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([$scrimId, $team['id'], $scrim['pending_result_score'], $reportedScore, $message]);

            $stmt = $pdo->prepare('
                UPDATE scrims
                SET result_status = "reported",
                    result_reviewed_at = CURRENT_TIMESTAMP
                WHERE id = ? AND opponent_team_id = ? AND status = "confirmed" AND result_status = "pending"
            ');
            $stmt->execute([$scrimId, $team['id']]);
            $pdo->commit();

            json_response(fetch_state($pdo) + ['message' => 'Report result dihantar kepada admin untuk review.']);
        }

        json_response(['ok' => false, 'message' => 'Action tidak dikenali.'], 400);
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
            $databaseMessage = strtolower((string) ($error->errorInfo[2] ?? $error->getMessage()));
            if (str_contains($databaseMessage, 'player_game_id')) {
                json_response(['ok' => false, 'message' => 'Player ID ini sudah menyertai team lain. Seorang player hanya boleh menyertai satu team.'], 409);
            }
            json_response(['ok' => false, 'message' => 'Nama team sudah digunakan. Pilih nama team lain.'], 409);
        }
        json_response(['ok' => false, 'message' => 'Database error: ' . $error->getMessage()], 500);
    }
}

json_response(fetch_state($pdo));
