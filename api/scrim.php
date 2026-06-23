<?php
declare(strict_types=1);

$rootDir = dirname(__DIR__);
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

$schemaStatements = [
    "CREATE TABLE IF NOT EXISTS teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_name VARCHAR(80) NOT NULL UNIQUE,
    phone_number VARCHAR(30) NULL,
    password_hash VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
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
    creator_team_id INT NOT NULL,
    opponent_team_id INT NULL,
    title VARCHAR(120) NOT NULL,
    date_time DATETIME NOT NULL,
    format VARCHAR(20) NOT NULL,
    notes VARCHAR(255) NULL,
    point_mode VARCHAR(20) NOT NULL DEFAULT 'normal',
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
    sender_team_id INT NOT NULL,
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
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    INDEX idx_push_team (team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($schemaStatements as $schemaStatement) {
    $pdo->exec($schemaStatement);
}

if (!column_exists($pdo, 'teams', 'password_hash')) {
    $pdo->exec('ALTER TABLE teams ADD COLUMN password_hash VARCHAR(255) NULL');
}

if (!column_exists($pdo, 'teams', 'phone_number')) {
    $pdo->exec('ALTER TABLE teams ADD COLUMN phone_number VARCHAR(30) NULL AFTER team_name');
}

if (!column_exists($pdo, 'teams', 'password_code')) {
    $pdo->exec('ALTER TABLE teams ADD COLUMN password_code VARCHAR(50) NULL');
}

if (!column_exists($pdo, 'teams', 'created_at')) {
    $pdo->exec('ALTER TABLE teams ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
}

if (!column_exists($pdo, 'scrim_requests', 'responded_at')) {
    $pdo->exec('ALTER TABLE scrim_requests ADD COLUMN responded_at DATETIME NULL');
}

if (!column_exists($pdo, 'scrims', 'opponent_team_id')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN opponent_team_id INT NULL AFTER creator_team_id");
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

if (!column_exists($pdo, 'scrims', 'challenger_team_id')) {
    $pdo->exec("ALTER TABLE scrims ADD COLUMN challenger_team_id INT NULL AFTER point_mode");
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

function json_response(array $payload, int $status = 200): never
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

    $stmt = $pdo->prepare('SELECT id, team_name AS name, phone_number, created_at FROM teams WHERE id = ?');
    $stmt->execute([$_SESSION['team_id']]);
    return $stmt->fetch() ?: null;
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

function send_empty_web_push(PDO $pdo, array $pushConfig, int $teamId): void
{
    if ($teamId <= 0 || empty($pushConfig['public_key']) || empty($pushConfig['private_key_pem']) || !function_exists('curl_init')) {
        return;
    }

    $stmt = $pdo->prepare('SELECT id, endpoint FROM push_subscriptions WHERE team_id = ?');
    $stmt->execute([$teamId]);
    $subscriptions = $stmt->fetchAll();

    foreach ($subscriptions as $subscription) {
        $endpoint = (string) $subscription['endpoint'];
        $jwt = vapid_jwt($pushConfig, $endpoint);
        if (!$jwt) {
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
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (in_array($status, [404, 410], true)) {
            $delete = $pdo->prepare('DELETE FROM push_subscriptions WHERE id = ?');
            $delete->execute([$subscription['id']]);
        }
    }
}

function fetch_state(PDO $pdo): array
{
    global $pushConfig;
    $team = current_team($pdo);
    $teamId = $team['id'] ?? 0;
    $messages = [];

    $scrims = $pdo->query("
        SELECT s.*,
            c.team_name AS creator_name,
            o.team_name AS opponent_name,
            w.team_name AS winner_name,
            pw.team_name AS pending_winner_name
        FROM scrims s
        JOIN teams c ON c.id = s.creator_team_id
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
            t.team_name AS name,
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
        JOIN teams c ON c.id = s.creator_team_id
        LEFT JOIN teams o ON o.id = s.opponent_team_id
        LEFT JOIN teams w ON w.id = s.winner_team_id
        WHERE s.status = 'completed'
        ORDER BY s.completed_at DESC
        LIMIT 20
    ")->fetchAll();

    foreach ($scrims as &$scrim) {
        $scrim['can_view_room'] = $teamId && $scrim['status'] !== 'open' && in_array($teamId, [(int) $scrim['creator_team_id'], (int) ($scrim['opponent_team_id'] ?? 0)], true);
        if (!$scrim['can_view_room']) {
            $scrim['room_id'] = null;
            $scrim['room_password'] = null;
        }
    }

    if ($teamId) {
        $stmt = $pdo->prepare("
            SELECT m.id, m.scrim_id, m.sender_team_id, m.message, m.created_at,
                t.team_name AS sender_name
            FROM scrim_messages m
            JOIN scrims s ON s.id = m.scrim_id
            JOIN teams t ON t.id = m.sender_team_id
            WHERE s.status IN ('pending', 'confirmed')
                AND (s.creator_team_id = ? OR s.opponent_team_id = ?)
            ORDER BY m.created_at ASC, m.id ASC
        ");
        $stmt->execute([$teamId, $teamId]);
        $messages = $stmt->fetchAll();
    }

    return [
        'ok' => true,
        'team' => $team,
        'scrims' => $scrims,
        'requests' => $requests,
        'stats' => $stats,
        'history' => $history,
        'messages' => $messages,
        'push_public_key' => $pushConfig['public_key'] ?? null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['push_latest'])) {
    $team = require_team($pdo);
    $stmt = $pdo->prepare("
        SELECT m.id, m.scrim_id, m.sender_team_id, m.message, m.created_at,
            t.team_name AS sender_name,
            s.title AS scrim_title
        FROM scrim_messages m
        JOIN scrims s ON s.id = m.scrim_id
        JOIN teams t ON t.id = m.sender_team_id
        WHERE s.status IN ('pending', 'confirmed')
            AND (s.creator_team_id = ? OR s.opponent_team_id = ?)
            AND m.sender_team_id != ?
        ORDER BY m.created_at DESC, m.id DESC
        LIMIT 1
    ");
    $stmt->execute([$team['id'], $team['id'], $team['id']]);
    $message = $stmt->fetch();
    json_response([
        'ok' => true,
        'notification' => $message ? [
            'title' => 'GNEX Scrim: ' . $message['sender_name'],
            'body' => $message['message'],
            'url' => 'scrim.html',
            'tag' => 'scrim-chat-' . $message['scrim_id'],
        ] : null,
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
            $password = (string) ($_POST['password'] ?? '');

            if ($name === '' || strlen($password) < 4) {
                json_response(['ok' => false, 'message' => 'Nama team wajib diisi dan password minimum 4 aksara.'], 422);
            }

            if ($action === 'register') {
                $stmt = $pdo->prepare('SELECT id, password_hash FROM teams WHERE team_name = ?');
                $stmt->execute([$name]);
                $existingTeam = $stmt->fetch();

                if ($existingTeam && !empty($existingTeam['password_hash'])) {
                    json_response(['ok' => false, 'message' => 'Team ini sudah ada password. Sila login.'], 409);
                }

                if ($existingTeam) {
                    $stmt = $pdo->prepare('UPDATE teams SET password_hash = ?, password_code = NULL WHERE id = ?');
                    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $existingTeam['id']]);
                    $_SESSION['team_id'] = (int) $existingTeam['id'];
                    $stmt = $pdo->prepare('INSERT IGNORE INTO team_stats (team_id) VALUES (?)');
                    $stmt->execute([$_SESSION['team_id']]);
                    json_response(fetch_state($pdo) + ['message' => 'Password team lama berjaya diset.']);
                }

                $stmt = $pdo->prepare('INSERT INTO teams (team_name, password_hash) VALUES (?, ?)');
                $stmt->execute([$name, password_hash($password, PASSWORD_DEFAULT)]);
                $_SESSION['team_id'] = (int) $pdo->lastInsertId();
                $stmt = $pdo->prepare('INSERT IGNORE INTO team_stats (team_id) VALUES (?)');
                $stmt->execute([$_SESSION['team_id']]);
                json_response(fetch_state($pdo) + ['message' => 'Team berjaya didaftar.']);
            }

            $stmt = $pdo->prepare('SELECT * FROM teams WHERE team_name = ?');
            $stmt->execute([$name]);
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

        if ($action === 'logout') {
            session_destroy();
            json_response(['ok' => true, 'message' => 'Logout berjaya.']);
        }

        $team = require_team($pdo);

        if ($action === 'create_scrim') {
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

            $columns = ['creator_team_id', 'title', 'date_time', 'format', 'notes', 'point_mode'];
            $values = [$team['id'], $title, $dateTime, $format, $notes, $pointMode];

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
            $phoneNumber = clean_phone((string) ($_POST['phone_number'] ?? ''));
            $stmt = $pdo->prepare('UPDATE teams SET phone_number = ? WHERE id = ?');
            $stmt->execute([$phoneNumber !== '' ? $phoneNumber : null, $team['id']]);

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
            $userAgent = clean_text((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 255);
            $stmt = $pdo->prepare('
                INSERT INTO push_subscriptions (team_id, endpoint_hash, endpoint, p256dh, auth, user_agent, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE
                    team_id = VALUES(team_id),
                    p256dh = VALUES(p256dh),
                    auth = VALUES(auth),
                    user_agent = VALUES(user_agent),
                    updated_at = CURRENT_TIMESTAMP
            ');
            $stmt->execute([$team['id'], $endpointHash, $endpoint, $p256dh, $auth, $userAgent]);

            json_response(fetch_state($pdo) + ['message' => 'Phone notification aktif untuk team ini.']);
        }

        if ($action === 'request_join') {
            $scrimId = (int) ($_POST['scrim_id'] ?? 0);
            $message = clean_text((string) ($_POST['message'] ?? ''), 160);

            $stmt = $pdo->prepare('SELECT * FROM scrims WHERE id = ? AND status = "open"');
            $stmt->execute([$scrimId]);
            $scrim = $stmt->fetch();
            if (!$scrim || (int) $scrim['creator_team_id'] === (int) $team['id']) {
                json_response(['ok' => false, 'message' => 'Scrim ini tidak boleh direquest.'], 403);
            }

            $stmt = $pdo->prepare('INSERT IGNORE INTO scrim_requests (scrim_id, requester_team_id, message) VALUES (?, ?, ?)');
            $stmt->execute([$scrimId, $team['id'], $message]);
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

            json_response(fetch_state($pdo) + ['message' => 'Room ID disimpan. Scrim confirmed.']);
        }

        if ($action === 'send_message') {
            $scrimId = (int) ($_POST['scrim_id'] ?? 0);
            $message = clean_text((string) ($_POST['message'] ?? ''), 500);

            if ($message === '') {
                json_response(['ok' => false, 'message' => 'Tulis mesej dulu.'], 422);
            }

            $stmt = $pdo->prepare('
                SELECT id, creator_team_id, opponent_team_id
                FROM scrims
                WHERE id = ?
                    AND status IN ("pending", "confirmed")
                    AND (creator_team_id = ? OR opponent_team_id = ?)
            ');
            $stmt->execute([$scrimId, $team['id'], $team['id']]);
            $scrim = $stmt->fetch();
            if (!$scrim) {
                json_response(['ok' => false, 'message' => 'Chat hanya untuk team dalam private deal room ini.'], 403);
            }

            $stmt = $pdo->prepare('INSERT INTO scrim_messages (scrim_id, sender_team_id, message) VALUES (?, ?, ?)');
            $stmt->execute([$scrimId, $team['id'], $message]);
            $receiverTeamId = (int) $scrim['creator_team_id'] === (int) $team['id']
                ? (int) $scrim['opponent_team_id']
                : (int) $scrim['creator_team_id'];
            try {
                send_empty_web_push($pdo, $pushConfig, $receiverTeamId);
            } catch (Throwable $pushError) {
                error_log('Push send failed: ' . $pushError->getMessage());
            }

            json_response(fetch_state($pdo) + ['message' => 'Mesej dihantar.']);
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

            json_response(fetch_state($pdo) + ['message' => 'Result dihantar kepada opponent untuk confirm.']);
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
        json_response(['ok' => false, 'message' => 'Database error: ' . $error->getMessage()], 500);
    }
}

json_response(fetch_state($pdo));
