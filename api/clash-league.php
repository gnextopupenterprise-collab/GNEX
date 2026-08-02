<?php
declare(strict_types=1);

$rootDir = dirname(__DIR__);
ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Kuala_Lumpur');
const CL_REMEMBER_COOKIE = 'clash_league_remember';
const CL_REMEMBER_DAYS = 365;
const CL_REMEMBER_REFRESH_SECONDS = 43200;
ini_set('session.gc_maxlifetime', (string) (CL_REMEMBER_DAYS * 86400));
ini_set('session.use_strict_mode', '1');
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

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array((int) $error['type'], $fatalTypes, true)) {
        return;
    }

    error_log('Fatal Clash League API error: ' . ($error['message'] ?? 'Unknown error'));
    if (headers_sent()) {
        return;
    }

    json_response([
        'ok' => false,
        'message' => 'Server fatal error. Check api/clash-league.php upload/config.',
    ], 500);
});

$dbConfigPath = $rootDir . DIRECTORY_SEPARATOR . 'scrim-db-config.php';
if (!is_file($dbConfigPath)) {
    json_response([
        'ok' => false,
        'message' => 'DB config belum upload. Masukkan scrim-db-config.php dalam htdocs domain Clash League.',
    ], 500);
}
$dbConfig = require $dbConfigPath;
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

ensure_schema_once($pdo, $rootDir, (string) ($dbConfig['database'] ?? 'clash'));
seed_admin($pdo, $dbConfig);
seed_web_tester($pdo);

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'teams') {
    json_response(['ok' => true, 'teams' => get_public_teams($pdo)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'state') {
    json_response(get_state($pdo));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'pushConfig') {
    if (empty($pushConfig['public_key'])) {
        json_response([
            'ok' => false,
            'message' => 'Push config belum upload. Masukkan scrim-push-config.php dalam htdocs.',
            'push_public_key' => null,
        ], 500);
    }

    json_response([
        'ok' => true,
        'push_public_key' => $pushConfig['public_key'],
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'testNotification') {
    test_notification($pdo, $pushConfig);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'confirmNotificationTest') {
    confirm_notification_test($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'acknowledgeNotification') {
    acknowledge_notification($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'setTeamStatus') {
    set_team_status($pdo, $dbConfig);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'dispatchTeamStatusPush') {
    dispatch_team_status_push($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'updateTeamInfo') {
    update_team_info($pdo, $rootDir);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'removeTeam') {
    remove_team($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'generateRandomMatches') {
    generate_random_matches($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'resetAllMatches') {
    reset_all_matches($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'updateMatch') {
    update_match($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reopenMatch') {
    reopen_match($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'approveMatchResult') {
    approve_match_result($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'adminSetMatchResult') {
    admin_set_match_result($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'updateMatchTeams') {
    update_match_teams($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    login_user($pdo, $dbConfig);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'changeTeamPassword') {
    change_team_password($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'requestPasswordChange') {
    request_password_change($pdo, $dbConfig);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reviewPasswordChange') {
    review_password_change($pdo, $dbConfig);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'setTeamCheck') {
    set_team_check($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'setNotificationCheck') {
    set_notification_check($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'markRoomRead') {
    mark_room_read($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'confirmMatchAttendance') {
    confirm_match_attendance($pdo);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'savePinnedInfo') {
    save_pinned_info($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'acknowledgePinnedInfo') {
    acknowledge_pinned_info($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'pinChatMessage') {
    pin_chat_message($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'repushChatMessage') {
    repush_chat_message($pdo);
}

json_response([
    'ok' => true,
    'message' => 'Clash League PHP API live.',
]);

function json_response(array $payload, int $status = 200): void
{
    if (ob_get_level() > 0 && ob_get_length() !== false) {
        ob_clean();
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
            chat_token_hash CHAR(64) NULL,
            coach_name VARCHAR(100) NULL,
            manager_name VARCHAR(100) NULL,
            status ENUM('pending','accepted','rejected','removed') NOT NULL DEFAULT 'pending',
            slot_no INT NULL,
            admin_note VARCHAR(255) NULL,
            admin_checked TINYINT(1) NOT NULL DEFAULT 0,
            notification_checked TINYINT(1) NOT NULL DEFAULT 0,
            is_test_account TINYINT(1) NOT NULL DEFAULT 0,
            last_seen_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_cl_teams_status_slot (status, slot_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!column_exists($pdo, 'cl_teams', 'password_hash')) {
        $pdo->exec('ALTER TABLE cl_teams ADD COLUMN password_hash VARCHAR(255) NULL AFTER phone');
    }
    if (!column_exists($pdo, 'cl_teams', 'chat_token_hash')) {
        $pdo->exec('ALTER TABLE cl_teams ADD COLUMN chat_token_hash CHAR(64) NULL AFTER password_hash');
    }
    if (!column_exists($pdo, 'cl_teams', 'admin_checked')) {
        $pdo->exec('ALTER TABLE cl_teams ADD COLUMN admin_checked TINYINT(1) NOT NULL DEFAULT 0 AFTER admin_note');
    }
    if (!column_exists($pdo, 'cl_teams', 'last_seen_at')) {
        $pdo->exec('ALTER TABLE cl_teams ADD COLUMN last_seen_at DATETIME NULL AFTER admin_checked');
    }
    if (!column_exists($pdo, 'cl_teams', 'notification_checked')) {
        $pdo->exec('ALTER TABLE cl_teams ADD COLUMN notification_checked TINYINT(1) NOT NULL DEFAULT 0 AFTER admin_checked');
    }
    if (!column_exists($pdo, 'cl_teams', 'is_test_account')) {
        $pdo->exec('ALTER TABLE cl_teams ADD COLUMN is_test_account TINYINT(1) NOT NULL DEFAULT 0 AFTER notification_checked');
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
            room_type ENUM('admin','deal','match','group') NOT NULL DEFAULT 'admin',
            team_a_id INT NULL,
            team_b_id INT NULL,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            FOREIGN KEY (team_a_id) REFERENCES cl_teams(id) ON DELETE SET NULL,
            FOREIGN KEY (team_b_id) REFERENCES cl_teams(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $roomTypeColumn = $pdo->query("SHOW COLUMNS FROM cl_rooms LIKE 'room_type'")->fetch();
    if (!$roomTypeColumn || strpos((string) ($roomTypeColumn['Type'] ?? ''), "'group'") === false) {
        $pdo->exec("ALTER TABLE cl_rooms MODIFY room_type ENUM('admin','deal','match','group') NOT NULL DEFAULT 'admin'");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_id INT NOT NULL,
            sender_type ENUM('admin','team','guest','system') NOT NULL DEFAULT 'team',
            sender_team_id INT NULL,
            guest_name VARCHAR(60) NULL,
            reply_to_message_id INT NULL,
            action_target VARCHAR(20) NULL,
            message VARCHAR(700) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (room_id) REFERENCES cl_rooms(id) ON DELETE CASCADE,
            FOREIGN KEY (sender_team_id) REFERENCES cl_teams(id) ON DELETE SET NULL,
            INDEX idx_cl_messages_room (room_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messageSenderColumn = $pdo->query("SHOW COLUMNS FROM cl_messages LIKE 'sender_type'")->fetch();
    if (!$messageSenderColumn || strpos((string) ($messageSenderColumn['Type'] ?? ''), "'guest'") === false) {
        $pdo->exec("ALTER TABLE cl_messages MODIFY sender_type ENUM('admin','team','guest','system') NOT NULL DEFAULT 'team'");
    }
    if (!column_exists($pdo, 'cl_messages', 'guest_name')) {
        $pdo->exec('ALTER TABLE cl_messages ADD COLUMN guest_name VARCHAR(60) NULL AFTER sender_team_id');
    }
    if (!column_exists($pdo, 'cl_messages', 'reply_to_message_id')) {
        $pdo->exec('ALTER TABLE cl_messages ADD COLUMN reply_to_message_id INT NULL AFTER guest_name');
    }
    if (!column_exists($pdo, 'cl_messages', 'action_target')) {
        $pdo->exec('ALTER TABLE cl_messages ADD COLUMN action_target VARCHAR(20) NULL AFTER reply_to_message_id');
    }

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
        CREATE TABLE IF NOT EXISTS cl_match_attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            match_id INT NOT NULL,
            team_id INT NOT NULL,
            confirmed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cl_match_attendance (match_id, team_id),
            INDEX idx_cl_match_attendance_team (team_id, match_id),
            FOREIGN KEY (match_id) REFERENCES cl_matches(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES cl_teams(id) ON DELETE CASCADE
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
        CREATE TABLE IF NOT EXISTS cl_room_reads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_type ENUM('team','admin') NOT NULL,
            team_id INT NULL,
            admin_id INT NULL,
            room_id INT NOT NULL,
            last_message_id INT NOT NULL DEFAULT 0,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_cl_room_read_admin (admin_id, room_id),
            UNIQUE KEY uniq_cl_room_read_team (team_id, room_id),
            FOREIGN KEY (team_id) REFERENCES cl_teams(id) ON DELETE CASCADE,
            FOREIGN KEY (admin_id) REFERENCES cl_admin_users(id) ON DELETE CASCADE,
            FOREIGN KEY (room_id) REFERENCES cl_rooms(id) ON DELETE CASCADE
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
        CREATE TABLE IF NOT EXISTS cl_pinned_info_acknowledgements (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            pinned_version CHAR(64) NOT NULL,
            team_id INT NOT NULL,
            acknowledged_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cl_pinned_ack (pinned_version, team_id),
            INDEX idx_cl_pinned_ack_version (pinned_version, id),
            FOREIGN KEY (team_id) REFERENCES cl_teams(id) ON DELETE CASCADE
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
            platform VARCHAR(30) NULL,
            device_label VARCHAR(80) NULL,
            permission_state VARCHAR(20) NULL,
            is_standalone TINYINT(1) NOT NULL DEFAULT 0,
            last_success_at DATETIME NULL,
            last_failure_at DATETIME NULL,
            last_status VARCHAR(80) NULL,
            test_confirmed_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            FOREIGN KEY (team_id) REFERENCES cl_teams(id) ON DELETE CASCADE,
            FOREIGN KEY (admin_id) REFERENCES cl_admin_users(id) ON DELETE CASCADE,
            INDEX idx_cl_push_team (team_id),
            INDEX idx_cl_push_admin (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    foreach ([
        'platform' => 'VARCHAR(30) NULL AFTER user_agent',
        'device_label' => 'VARCHAR(80) NULL AFTER platform',
        'permission_state' => 'VARCHAR(20) NULL AFTER device_label',
        'is_standalone' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER permission_state',
        'last_success_at' => 'DATETIME NULL AFTER is_standalone',
        'last_failure_at' => 'DATETIME NULL AFTER last_success_at',
        'last_status' => 'VARCHAR(80) NULL AFTER last_failure_at',
        'test_confirmed_at' => 'DATETIME NULL AFTER last_status',
    ] as $column => $definition) {
        if (!column_exists($pdo, 'cl_push_subscriptions', $column)) {
            $pdo->exec("ALTER TABLE cl_push_subscriptions ADD COLUMN {$column} {$definition}");
        }
    }

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
        CREATE TABLE IF NOT EXISTS cl_push_delivery_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            subscription_id INT NULL,
            event_id INT NULL,
            result_status VARCHAR(80) NOT NULL,
            http_status INT NOT NULL DEFAULT 0,
            attempt_no TINYINT NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cl_push_delivery_subscription (subscription_id, id),
            INDEX idx_cl_push_delivery_event (event_id, id),
            FOREIGN KEY (subscription_id) REFERENCES cl_push_subscriptions(id) ON DELETE SET NULL,
            FOREIGN KEY (event_id) REFERENCES cl_push_events(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_notification_acknowledgements (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            subscription_id INT NULL,
            acknowledged_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cl_notification_ack (event_id, subscription_id),
            FOREIGN KEY (event_id) REFERENCES cl_push_events(id) ON DELETE CASCADE,
            FOREIGN KEY (subscription_id) REFERENCES cl_push_subscriptions(id) ON DELETE SET NULL
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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_password_change_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            team_id INT NOT NULL,
            registered_phone_snapshot VARCHAR(40) NOT NULL,
            submitted_phone VARCHAR(40) NOT NULL,
            password_cipher TEXT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            reviewed_by_admin_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            FOREIGN KEY (team_id) REFERENCES cl_teams(id) ON DELETE CASCADE,
            FOREIGN KEY (reviewed_by_admin_id) REFERENCES cl_admin_users(id) ON DELETE SET NULL,
            INDEX idx_cl_password_requests_status (status, created_at),
            INDEX idx_cl_password_requests_team (team_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function ensure_schema_once(PDO $pdo, string $rootDir, string $databaseName): void
{
    $schemaVersion = '20260731-web-tester-v10';
    $markerId = hash('sha256', $rootDir . '|' . $databaseName);
    $markerPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'gnex-clash-schema-' . $markerId . '.txt';
    $lockPath = $markerPath . '.lock';

    if (is_file($markerPath) && trim((string) @file_get_contents($markerPath)) === $schemaVersion) {
        return;
    }

    $lock = @fopen($lockPath, 'c+');
    if ($lock === false) {
        ensure_schema($pdo);
        cleanup_premature_auto_finals($pdo);
        return;
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            ensure_schema($pdo);
            cleanup_premature_auto_finals($pdo);
            return;
        }
        clearstatcache(true, $markerPath);
        if (!is_file($markerPath) || trim((string) @file_get_contents($markerPath)) !== $schemaVersion) {
            ensure_schema($pdo);
            cleanup_premature_auto_finals($pdo);
            @file_put_contents($markerPath, $schemaVersion, LOCK_EX);
        }
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function cleanup_premature_auto_finals(PDO $pdo): void
{
    $stmt = $pdo->prepare('
        SELECT id, team_a_id, team_b_id
        FROM cl_matches
        WHERE match_name = "Grandfinal Match"
          AND match_time = "2026-09-11 22:00:00"
          AND status = "up_next"
    ');
    $stmt->execute();
    $matches = $stmt->fetchAll();
    if (!$matches) {
        return;
    }

    $deleteRoom = $pdo->prepare('
        DELETE FROM cl_rooms
        WHERE room_type = "match"
          AND (
            (team_a_id = ? AND team_b_id = ?)
            OR (team_a_id = ? AND team_b_id = ?)
          )
    ');
    $deleteMatch = $pdo->prepare('DELETE FROM cl_matches WHERE id = ?');

    $pdo->beginTransaction();
    try {
        foreach ($matches as $match) {
            $teamAId = (int) ($match['team_a_id'] ?? 0);
            $teamBId = (int) ($match['team_b_id'] ?? 0);
            if ($teamAId > 0 && $teamBId > 0) {
                $deleteRoom->execute([$teamAId, $teamBId, $teamBId, $teamAId]);
            }
            $deleteMatch->execute([(int) $match['id']]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
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

function seed_web_tester(PDO $pdo): void
{
    $teamName = 'GNEX WEB TESTER';
    $testerPassword = 'GnexDesign1!';
    $stmt = $pdo->prepare('SELECT id, password_hash FROM cl_teams WHERE team_name = ? LIMIT 1');
    $stmt->execute([$teamName]);
    $tester = $stmt->fetch();
    $teamId = (int) ($tester['id'] ?? 0);

    if ($teamId <= 0) {
        $stmt = $pdo->prepare('
            INSERT INTO cl_teams
                (team_name, phone, password_hash, status, slot_no, admin_checked, notification_checked, is_test_account)
            VALUES (?, ?, ?, "accepted", NULL, 1, 1, 1)
        ');
        $stmt->execute([$teamName, 'WEB-TESTER', password_hash($testerPassword, PASSWORD_DEFAULT)]);
        return;
    }

    $passwordHash = (string) ($tester['password_hash'] ?? '');
    $nextPasswordHash = $passwordHash !== '' && password_verify($testerPassword, $passwordHash)
        ? $passwordHash
        : password_hash($testerPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('
        UPDATE cl_teams
        SET password_hash = ?, is_test_account = 1, status = "accepted", slot_no = NULL, admin_checked = 1
        WHERE id = ?
    ');
    $stmt->execute([$nextPasswordHash, $teamId]);
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

function device_login_token_from_request(): string
{
    return trim((string) ($_COOKIE[CL_REMEMBER_COOKIE] ?? $_SERVER['HTTP_X_CLASH_DEVICE_TOKEN'] ?? $_POST['device_login_token'] ?? ''));
}

function issue_persistent_login(PDO $pdo, string $ownerType, ?int $teamId = null, ?int $adminId = null): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expires = time() + (CL_REMEMBER_DAYS * 86400);
    $agent = clean_text($_SERVER['HTTP_USER_AGENT'] ?? '', 255);

    $cleanup = $pdo->prepare('DELETE FROM cl_login_tokens WHERE expires_at < NOW()');
    $cleanup->execute();

    $stmt = $pdo->prepare('
        INSERT INTO cl_login_tokens (owner_type, team_id, admin_id, token_hash, user_agent, expires_at, updated_at)
        VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), CURRENT_TIMESTAMP)
    ');
    $stmt->execute([$ownerType, $teamId, $adminId, $hash, $agent, $expires]);

    setcookie(CL_REMEMBER_COOKIE, $token, remember_cookie_options($expires));
    $_COOKIE[CL_REMEMBER_COOKIE] = $token;
    return $token;
}

function forget_persistent_login(PDO $pdo): void
{
    $token = device_login_token_from_request();
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

    $token = device_login_token_from_request();
    if ($token === '') {
        return;
    }

    $stmt = $pdo->prepare('
        SELECT id, owner_type, team_id, admin_id, updated_at
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
    $_COOKIE[CL_REMEMBER_COOKIE] = $token;
}

function refresh_persistent_login(PDO $pdo): void
{
    static $refreshed = false;
    if ($refreshed || (empty($_SESSION['cl_team_id']) && empty($_SESSION['cl_admin_id']))) {
        return;
    }
    $refreshed = true;
    $token = device_login_token_from_request();
    if ($token === '') {
        if (!empty($_SESSION['cl_team_id'])) {
            issue_persistent_login($pdo, 'team', (int) $_SESSION['cl_team_id'], null);
        } elseif (!empty($_SESSION['cl_admin_id'])) {
            issue_persistent_login($pdo, 'admin', null, (int) $_SESSION['cl_admin_id']);
        }
        return;
    }
    $stmt = $pdo->prepare('SELECT id, updated_at FROM cl_login_tokens WHERE token_hash = ? AND expires_at > NOW() LIMIT 1');
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row) {
        if (!empty($_SESSION['cl_team_id'])) {
            issue_persistent_login($pdo, 'team', (int) $_SESSION['cl_team_id'], null);
        } elseif (!empty($_SESSION['cl_admin_id'])) {
            issue_persistent_login($pdo, 'admin', null, (int) $_SESSION['cl_admin_id']);
        }
        return;
    }
    $lastRefresh = strtotime((string) ($row['updated_at'] ?? '')) ?: 0;
    if ($lastRefresh >= time() - CL_REMEMBER_REFRESH_SECONDS) {
        return;
    }
    $expires = time() + (CL_REMEMBER_DAYS * 86400);
    $update = $pdo->prepare('UPDATE cl_login_tokens SET expires_at = FROM_UNIXTIME(?), updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $update->execute([$expires, (int) $row['id']]);
    setcookie(CL_REMEMBER_COOKIE, $token, remember_cookie_options($expires));
    $_COOKIE[CL_REMEMBER_COOKIE] = $token;
}

function clean_text(?string $value, int $max = 120): string
{
    $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    return mb_substr($value, 0, $max);
}

function to_upper_text(string $value): string
{
    return mb_strtoupper($value, 'UTF-8');
}

function is_valid_team_name(string $value): bool
{
    return $value !== '' && preg_match('/^[A-Z0-9 ]+$/u', $value) === 1;
}

function is_valid_team_password(string $value): bool
{
    return strlen($value) <= 120
        && preg_match('/[A-Z]/', $value) === 1
        && preg_match('/[0-9]/', $value) === 1
        && preg_match('/[^A-Za-z0-9]/', $value) === 1;
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

function send_empty_web_push_to_subscription(PDO $pdo, array $pushConfig, array $subscription, ?int $eventId = null, int $attemptNo = 1): array
{
    $result = [
        'ok' => false,
        'status' => 'not_sent',
        'subscription_id' => (int) ($subscription['id'] ?? 0),
    ];

    if (empty($pushConfig['public_key']) || empty($pushConfig['private_key_pem']) || !function_exists('curl_init')) {
        $result['status'] = empty($pushConfig['public_key']) || empty($pushConfig['private_key_pem'])
            ? 'push_config_missing'
            : 'curl_missing';
        return $result;
    }

    $endpoint = (string) ($subscription['endpoint'] ?? '');
    $jwt = vapid_jwt($pushConfig, $endpoint);
    if ($endpoint === '' || !$jwt) {
        $result['status'] = $endpoint === '' ? 'endpoint_missing' : 'vapid_failed';
        return $result;
    }

    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'GNEX-Clash-League-Push/1.0',
        CURLOPT_HTTPHEADER => [
            'TTL: 86400',
            'Urgency: high',
            'Topic: clash-league',
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

    $result['status'] = $error !== '' ? $error : $status;
    $result['ok'] = $status >= 200 && $status < 300;

    $health = $pdo->prepare('
        UPDATE cl_push_subscriptions
        SET last_status = ?,
            last_success_at = CASE WHEN ? = 1 THEN CURRENT_TIMESTAMP ELSE last_success_at END,
            last_failure_at = CASE WHEN ? = 0 THEN CURRENT_TIMESTAMP ELSE last_failure_at END,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ');
    $health->execute([
        clean_text((string) $result['status'], 80),
        $result['ok'] ? 1 : 0,
        $result['ok'] ? 1 : 0,
        (int) $subscription['id'],
    ]);
    $log = $pdo->prepare('
        INSERT INTO cl_push_delivery_logs (subscription_id, event_id, result_status, http_status, attempt_no)
        VALUES (?, ?, ?, ?, ?)
    ');
    $log->execute([
        (int) $subscription['id'],
        $eventId,
        clean_text((string) $result['status'], 80),
        $status,
        $attemptNo,
    ]);

    if (in_array($status, [404, 410], true)) {
        $delete = $pdo->prepare('DELETE FROM cl_push_subscriptions WHERE id = ?');
        $delete->execute([(int) $subscription['id']]);
        $result['deleted'] = true;
    }

    return $result;
}

function queue_push_event(PDO $pdo, string $ownerType, ?int $teamId, ?int $adminId, string $title, string $body, string $url = 'clash-league.html', string $tag = 'clash-league'): int
{
    $stmt = $pdo->prepare('
        INSERT INTO cl_push_events (owner_type, team_id, admin_id, title, body, url, tag)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$ownerType, $teamId, $adminId, clean_text($title, 120), clean_text($body, 500), clean_text($url, 255), clean_text($tag, 80)]);
    return (int) $pdo->lastInsertId();
}

function send_push_to_owner(PDO $pdo, array $pushConfig, string $ownerType, ?int $teamId = null, ?int $adminId = null, ?int $eventId = null): array
{
    $summary = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'statuses' => []];
    if ($ownerType === 'team') {
        $stmt = $pdo->prepare('SELECT * FROM cl_push_subscriptions WHERE owner_type = "team" AND team_id = ?');
        $stmt->execute([$teamId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM cl_push_subscriptions WHERE owner_type = "admin" AND admin_id = ?');
        $stmt->execute([$adminId]);
    }

    foreach ($stmt->fetchAll() as $subscription) {
        $summary['attempted']++;
        $pushResult = send_empty_web_push_to_subscription($pdo, $pushConfig, $subscription, $eventId, 1);
        $statusCode = is_int($pushResult['status']) ? $pushResult['status'] : 0;
        if (empty($pushResult['ok']) && empty($pushResult['deleted'])
            && ($statusCode === 0 || $statusCode === 408 || $statusCode === 429 || $statusCode >= 500)) {
            $pushResult = send_empty_web_push_to_subscription($pdo, $pushConfig, $subscription, $eventId, 2);
        }
        $summary['statuses'][] = $pushResult['status'];
        if (!empty($pushResult['ok'])) {
            $summary['sent']++;
        } else {
            $summary['failed']++;
        }
    }
    return $summary;
}

function merge_push_summary(array $base, array $next): array
{
    $base['attempted'] = (int) ($base['attempted'] ?? 0) + (int) ($next['attempted'] ?? 0);
    $base['sent'] = (int) ($base['sent'] ?? 0) + (int) ($next['sent'] ?? 0);
    $base['failed'] = (int) ($base['failed'] ?? 0) + (int) ($next['failed'] ?? 0);
    $base['statuses'] = array_merge($base['statuses'] ?? [], $next['statuses'] ?? []);
    return $base;
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
    refresh_persistent_login($pdo);
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
    $platform = clean_text((string) ($_POST['platform'] ?? ''), 30);
    $deviceLabel = clean_text((string) ($_POST['device_label'] ?? ''), 80);
    $permissionState = clean_text((string) ($_POST['permission_state'] ?? ''), 20);
    $isStandalone = !empty($_POST['is_standalone']) ? 1 : 0;
    $stmt = $pdo->prepare('
        INSERT INTO cl_push_subscriptions
            (owner_type, team_id, admin_id, endpoint_hash, endpoint, p256dh, auth, user_agent,
             platform, device_label, permission_state, is_standalone, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            owner_type = VALUES(owner_type),
            team_id = VALUES(team_id),
            admin_id = VALUES(admin_id),
            p256dh = VALUES(p256dh),
            auth = VALUES(auth),
            user_agent = VALUES(user_agent),
            platform = VALUES(platform),
            device_label = VALUES(device_label),
            permission_state = VALUES(permission_state),
            is_standalone = VALUES(is_standalone),
            updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([
        $ownerType, $teamId ?: null, $adminId, $endpointHash, $endpoint, $p256dh, $auth, $userAgent,
        $platform, $deviceLabel, $permissionState, $isStandalone,
    ]);

    $readiness = notification_readiness_for_owner($pdo, $ownerType, $teamId ?: null, $adminId);
    json_response(['ok' => true, 'message' => 'Device notification berjaya disambungkan.', 'notification_readiness' => $readiness]);
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
        $eventStmt = $pdo->prepare('SELECT id AS event_id, title, body, url, tag FROM cl_push_events WHERE owner_type = "admin" AND admin_id = ? ORDER BY id DESC LIMIT 1');
        $eventStmt->execute([(int) $saved['admin_id']]);
    } else {
        $eventStmt = $pdo->prepare('SELECT id AS event_id, title, body, url, tag FROM cl_push_events WHERE owner_type = "team" AND team_id = ? ORDER BY id DESC LIMIT 1');
        $eventStmt->execute([(int) $saved['team_id']]);
    }
    $event = $eventStmt->fetch();

    json_response([
        'ok' => true,
        'notification' => $event ?: default_push_notification(),
    ]);
}

function subscription_from_request(PDO $pdo): array
{
    $subscription = json_decode((string) ($_POST['subscription'] ?? ''), true);
    $endpoint = clean_text((string) ($subscription['endpoint'] ?? ''), 2048);
    if ($endpoint === '') {
        json_response(['ok' => false, 'message' => 'Device notification tidak dijumpai. On notification dahulu.'], 422);
    }
    $stmt = $pdo->prepare('SELECT * FROM cl_push_subscriptions WHERE endpoint_hash = ? LIMIT 1');
    $stmt->execute([hash('sha256', $endpoint)]);
    $saved = $stmt->fetch();
    if (!$saved) {
        json_response(['ok' => false, 'message' => 'Device belum disimpan. Cuba On Notification semula.'], 404);
    }
    return $saved;
}

function notification_readiness_for_owner(PDO $pdo, string $ownerType, ?int $teamId, ?int $adminId): array
{
    if ($ownerType === 'admin') {
        $stmt = $pdo->prepare('SELECT * FROM cl_push_subscriptions WHERE owner_type = "admin" AND admin_id = ?');
        $stmt->execute([$adminId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM cl_push_subscriptions WHERE owner_type = "team" AND team_id = ?');
        $stmt->execute([$teamId]);
    }
    $rows = $stmt->fetchAll();
    $healthy = array_filter($rows, static fn(array $row): bool =>
        !empty($row['last_success_at'])
        && (empty($row['last_failure_at']) || strtotime((string) $row['last_success_at']) >= strtotime((string) $row['last_failure_at']))
    );
    $tested = array_filter($rows, static fn(array $row): bool => !empty($row['test_confirmed_at']));
    return [
        'devices' => count($rows),
        'healthy_devices' => count($healthy),
        'tested_devices' => count($tested),
        'ready' => count($tested) > 0 && count($healthy) > 0,
        'last_status' => $rows ? (string) ($rows[0]['last_status'] ?? '') : '',
    ];
}

function test_notification(PDO $pdo, array $pushConfig): void
{
    $saved = subscription_from_request($pdo);
    $team = current_team($pdo);
    $admin = current_admin($pdo);
    $ownsDevice = ($admin && $saved['owner_type'] === 'admin' && (int) $saved['admin_id'] === (int) $admin['id'])
        || ($team && $saved['owner_type'] === 'team' && (int) $saved['team_id'] === (int) $team['id']);
    if (!$ownsDevice) {
        json_response(['ok' => false, 'message' => 'Device ini bukan milik akaun yang sedang login.'], 403);
    }
    $eventId = queue_push_event(
        $pdo,
        (string) $saved['owner_type'],
        $saved['team_id'] === null ? null : (int) $saved['team_id'],
        $saved['admin_id'] === null ? null : (int) $saved['admin_id'],
        'Test Notification Clash League',
        'Kalau noti ini keluar, tekan noti kemudian sahkan READY dalam profile.',
        'clash-league.html#profile',
        'clash-test-' . (int) $saved['id'] . '-' . time()
    );
    $result = send_empty_web_push_to_subscription($pdo, $pushConfig, $saved, $eventId, 1);
    if (empty($result['ok']) && empty($result['deleted'])) {
        $result = send_empty_web_push_to_subscription($pdo, $pushConfig, $saved, $eventId, 2);
    }
    json_response([
        'ok' => !empty($result['ok']),
        'message' => !empty($result['ok'])
            ? 'Test noti sudah dihantar. Tekan “Saya Dah Terima” selepas noti keluar.'
            : 'Test noti gagal dihantar. Cuba Off/On notification atau semak permission browser.',
        'event_id' => $eventId,
        'delivery_status' => $result['status'],
    ], !empty($result['ok']) ? 200 : 502);
}

function confirm_notification_test(PDO $pdo): void
{
    $saved = subscription_from_request($pdo);
    $stmt = $pdo->prepare('UPDATE cl_push_subscriptions SET test_confirmed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([(int) $saved['id']]);
    json_response([
        'ok' => true,
        'message' => 'Notification device ini disahkan READY.',
        'notification_readiness' => notification_readiness_for_owner(
            $pdo,
            (string) $saved['owner_type'],
            $saved['team_id'] === null ? null : (int) $saved['team_id'],
            $saved['admin_id'] === null ? null : (int) $saved['admin_id']
        ),
    ]);
}

function acknowledge_notification(PDO $pdo): void
{
    $saved = subscription_from_request($pdo);
    $eventId = (int) ($_POST['event_id'] ?? 0);
    if ($eventId <= 0) {
        json_response(['ok' => false, 'message' => 'Event noti tidak sah.'], 422);
    }
    $stmt = $pdo->prepare('
        INSERT INTO cl_notification_acknowledgements (event_id, subscription_id)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE acknowledged_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([$eventId, (int) $saved['id']]);
    json_response(['ok' => true]);
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

    $teamName = to_upper_text(clean_text($_POST['team_name'] ?? '', 100));
    $phone = clean_text($_POST['phone'] ?? '', 40);
    $password = (string) ($_POST['team_password'] ?? '');
    $coachName = clean_text($_POST['coach_name'] ?? '', 100);
    $managerName = clean_text($_POST['manager_name'] ?? '', 100);

    if ($teamName === '' || $phone === '') {
        json_response(['ok' => false, 'message' => 'Nama team dan nombor telefon wajib isi.'], 422);
    }

    if (!is_valid_team_name($teamName)) {
        json_response(['ok' => false, 'message' => 'Nama team tidak boleh ada simbol.'], 422);
    }

    if (!is_valid_team_password($password)) {
        json_response(['ok' => false, 'message' => 'Password mesti mempunyai sekurang-kurangnya 1 huruf besar, 1 nombor dan 1 simbol.'], 422);
    }

    $requiredPlayers = [];
    $submittedPlayerIds = [];
    for ($slot = 1; $slot <= 4; $slot++) {
        $ign = clean_text($_POST['p' . $slot . '_ign'] ?? '', 100);
        $playerId = clean_text($_POST['p' . $slot . '_id'] ?? '', 80);
        if ($ign === '' || $playerId === '') {
            json_response(['ok' => false, 'message' => 'P' . $slot . ' IGN dan P' . $slot . ' ID wajib isi. Lengkapkan 4 pemain utama dulu.'], 422);
        }
        $requiredPlayers[$slot] = [$ign, $playerId];
        $submittedPlayerIds[$slot] = $playerId;
    }
    for ($slot = 5; $slot <= 6; $slot++) {
        $playerId = clean_text($_POST['p' . $slot . '_id'] ?? '', 80);
        if ($playerId !== '') {
            $submittedPlayerIds[$slot] = $playerId;
        }
    }

    $normalizedIds = array_map(static fn(string $id): string => strtolower(trim($id)), $submittedPlayerIds);
    if (count($normalizedIds) !== count(array_unique($normalizedIds))) {
        json_response(['ok' => false, 'code' => 'duplicate_player_id', 'message' => 'Player ID yang sama tidak boleh dimasukkan dua kali dalam satu team.'], 409);
    }

    $stmt = $pdo->prepare('SELECT id, team_name FROM cl_teams WHERE LOWER(team_name) = LOWER(?) LIMIT 1');
    $stmt->execute([$teamName]);
    $existingTeam = $stmt->fetch();
    if ($existingTeam) {
        $loggedTeam = current_team($pdo);
        $isOwner = $loggedTeam && (int) $loggedTeam['id'] === (int) $existingTeam['id'];
        json_response([
            'ok' => false,
            'code' => $isOwner ? 'own_team_exists' : 'team_name_exists',
            'message' => $isOwner
                ? 'Team ini sudah didaftarkan. Jika mahu tukar maklumat, pergi ke Profile team anda.'
                : 'Nama team ini sudah digunakan. Nama team yang sama tidak boleh daftar dua kali.',
            'go_to_profile' => $isOwner,
        ], 409);
    }

    if ($submittedPlayerIds) {
        $placeholders = implode(',', array_fill(0, count($submittedPlayerIds), '?'));
        $stmt = $pdo->prepare('
            SELECT p.player_id, p.player_slot, t.team_name
            FROM cl_players p
            INNER JOIN cl_teams t ON t.id = p.team_id
            WHERE t.status != "removed" AND LOWER(TRIM(p.player_id)) IN (' . $placeholders . ')
            LIMIT 1
        ');
        $stmt->execute(array_values($normalizedIds));
        $conflict = $stmt->fetch();
        if ($conflict) {
            json_response([
                'ok' => false,
                'code' => 'player_id_exists',
                'message' => 'Player ID ' . (string) $conflict['player_id'] . ' sudah berdaftar dalam team ' . (string) $conflict['team_name'] . '. Seorang player tidak boleh berada dalam dua team.',
            ], 409);
        }
    }

    [$logoPath, $logoUrl] = save_logo($_FILES['team_logo'] ?? [], $teamName, $rootDir);

    try {
        $pdo->beginTransaction();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $chatToken = bin2hex(random_bytes(32));
        $chatTokenHash = hash('sha256', $chatToken);
        $stmt = $pdo->prepare('
            INSERT INTO cl_teams (team_name, logo_url, logo_path, phone, password_hash, chat_token_hash, coach_name, manager_name, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending")
        ');
        $stmt->execute([$teamName, $logoUrl, $logoPath, $phone, $passwordHash, $chatTokenHash, $coachName, $managerName]);
        $teamId = (int) $pdo->lastInsertId();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($error->getCode() === '23000') {
            json_response(['ok' => false, 'message' => 'Nama team yang sama tidak boleh daftar dua kali.'], 409);
        }
        throw $error;
    }

    $stmt = $pdo->prepare('DELETE FROM cl_players WHERE team_id = ?');
    $stmt->execute([$teamId]);

    $stmt = $pdo->prepare('
        INSERT INTO cl_players (team_id, player_slot, ign, player_id)
        VALUES (?, ?, ?, ?)
    ');
    for ($slot = 1; $slot <= 6; $slot++) {
        if (isset($requiredPlayers[$slot])) {
            [$ign, $playerId] = $requiredPlayers[$slot];
        } else {
            $ign = clean_text($_POST['p' . $slot . '_ign'] ?? '', 100);
            $playerId = clean_text($_POST['p' . $slot . '_id'] ?? '', 80);
        }
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
        'chat_token' => $chatToken,
        'push_public_key' => $pushConfig['public_key'] ?? null,
    ]);
}

function get_public_teams(PDO $pdo): array
{
    $stmt = $pdo->query('
        SELECT t.id, t.team_name, t.logo_url, t.slot_no, t.status, t.phone, t.coach_name, t.manager_name,
               t.last_seen_at,
               t.admin_note, t.admin_checked, t.notification_checked, t.updated_at,
               EXISTS(
                   SELECT 1 FROM cl_matches m
                   WHERE m.team_a_id = t.id OR m.team_b_id = t.id
               ) AS has_schedule,
               (SELECT COUNT(*) FROM cl_push_subscriptions ps WHERE ps.owner_type = "team" AND ps.team_id = t.id) AS push_devices,
               (SELECT COUNT(*) FROM cl_push_subscriptions ps WHERE ps.owner_type = "team" AND ps.team_id = t.id AND ps.test_confirmed_at IS NOT NULL) AS push_tested_devices,
               (SELECT COUNT(*) FROM cl_push_subscriptions ps
                  WHERE ps.owner_type = "team" AND ps.team_id = t.id
                    AND ps.last_success_at IS NOT NULL
                    AND (ps.last_failure_at IS NULL OR ps.last_success_at >= ps.last_failure_at)) AS push_healthy_devices
        FROM cl_teams t
        WHERE t.status IN ("accepted", "pending") AND t.is_test_account = 0
        ORDER BY FIELD(t.status, "accepted", "pending"), COALESCE(t.slot_no, 999999), t.created_at DESC
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
        'admin_note' => (string) ($team['admin_note'] ?? ''),
        'admin_checked' => !empty($team['admin_checked']),
        'notification_checked' => !empty($team['notification_checked']),
        'has_schedule' => !empty($team['has_schedule']),
        'online' => !empty($team['last_seen_at']) && strtotime((string) $team['last_seen_at']) >= time() - 90,
        'last_seen_at' => (string) ($team['last_seen_at'] ?? ''),
        'notification' => [
            'devices' => (int) ($team['push_devices'] ?? 0),
            'tested_devices' => (int) ($team['push_tested_devices'] ?? 0),
            'healthy_devices' => (int) ($team['push_healthy_devices'] ?? 0),
            'ready' => (int) ($team['push_tested_devices'] ?? 0) > 0 && (int) ($team['push_healthy_devices'] ?? 0) > 0,
        ],
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
        SELECT id, team_name, logo_url, slot_no, status, phone, coach_name, manager_name, admin_note, created_at, updated_at
        FROM cl_teams
        WHERE status != "removed" AND is_test_account = 0
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
            'admin_note' => (string) ($team['admin_note'] ?? ''),
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
        SELECT id, team_name, logo_url, slot_no, status, phone, coach_name, manager_name, admin_note, updated_at
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

function current_chat_team(PDO $pdo): ?array
{
    $loggedTeam = current_team($pdo);
    if ($loggedTeam) {
        return $loggedTeam;
    }

    $token = trim((string) ($_GET['chat_token'] ?? $_POST['chat_token'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, team_name, logo_url, slot_no, status, phone, coach_name, manager_name, admin_note, updated_at FROM cl_teams WHERE chat_token_hash = ? AND status IN ("pending", "accepted") LIMIT 1');
    $stmt->execute([hash('sha256', strtolower($token))]);
    $team = $stmt->fetch();
    return $team ? serialize_team($pdo, $team) : null;
}

function get_group_room_id(PDO $pdo): int
{
    $roomId = (int) ($pdo->query('SELECT id FROM cl_rooms WHERE room_type = "group" AND status = "open" ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
    if ($roomId > 0) return $roomId;
    $pdo->exec('INSERT INTO cl_rooms (room_type, status, updated_at) VALUES ("group", "open", CURRENT_TIMESTAMP)');
    return (int) $pdo->lastInsertId();
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
                   ta.status AS team_a_status, ta.slot_no AS team_a_slot, ta.last_seen_at AS team_a_last_seen,
                   tb.team_name AS team_b_name, tb.logo_url AS team_b_logo, tb.last_seen_at AS team_b_last_seen,
                   tb.status AS team_b_status
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
        $teamARemoved = (string) ($match['team_a_status'] ?? '') === 'removed';
        $teamBRemoved = (string) ($match['team_b_status'] ?? '') === 'removed';
        $matches[] = [
            'id' => (int) $match['id'],
            'team_a_id' => $match['team_a_id'] === null || $teamARemoved ? 0 : (int) $match['team_a_id'],
            'team_b_id' => $match['team_b_id'] === null || $teamBRemoved ? 0 : (int) $match['team_b_id'],
            'match_name' => (string) $match['match_name'],
            'match_time' => (string) ($match['match_time'] ?? ''),
            'status' => (string) $match['status'],
            'team_a_name' => $teamARemoved ? 'TBD' : (string) ($match['team_a_name'] ?? ($team['team_name'] ?? 'TBD')),
            'team_a_logo' => $teamARemoved ? '' : (string) ($match['team_a_logo'] ?? ($team['logo_url'] ?? '')),
            'team_b_name' => $teamBRemoved ? 'TBD' : (string) ($match['team_b_name'] ?? 'TBD'),
            'team_b_logo' => $teamBRemoved ? '' : (string) ($match['team_b_logo'] ?? ''),
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

function get_deal_rooms(PDO $pdo, ?array $team, ?array $admin, bool $allowPersonal = false): array
{
    $groupRoomId = get_group_room_id($pdo);
    if ($admin) {
        initialize_admin_room_reads($pdo, (int) $admin['id']);
    }
    $ownerReadSql = $admin
        ? '(SELECT rr.last_message_id FROM cl_room_reads rr WHERE rr.room_id = r.id AND rr.owner_type = "admin" AND rr.admin_id = ' . (int) $admin['id'] . ' LIMIT 1)'
        : ($team
            ? '(SELECT rr.last_message_id FROM cl_room_reads rr WHERE rr.room_id = r.id AND rr.owner_type = "team" AND rr.team_id = ' . (int) $team['id'] . ' LIMIT 1)'
            : '0');
    $selectSql = '
        SELECT r.id, r.room_type, r.team_a_id, r.team_b_id, r.status,
                   ta.team_name AS team_a_name, ta.logo_url AS team_a_logo,
                   ta.status AS team_a_status, ta.slot_no AS team_a_slot, ta.last_seen_at AS team_a_last_seen,
                   tb.team_name AS team_b_name, tb.logo_url AS team_b_logo, tb.last_seen_at AS team_b_last_seen,
                   (SELECT message FROM cl_messages cm WHERE cm.room_id = r.id ORDER BY cm.id DESC LIMIT 1) AS last_message,
                   (SELECT id FROM cl_messages cm WHERE cm.room_id = r.id ORDER BY cm.id DESC LIMIT 1) AS last_message_id,
                   (SELECT sender_type FROM cl_messages cm WHERE cm.room_id = r.id ORDER BY cm.id DESC LIMIT 1) AS last_sender_type,
                   ' . $ownerReadSql . ' AS seen_message_id,
                   (SELECT m.match_name FROM cl_matches m
                    WHERE (m.team_a_id = r.team_a_id AND m.team_b_id = r.team_b_id)
                       OR (m.team_a_id = r.team_b_id AND m.team_b_id = r.team_a_id)
                    ORDER BY m.id DESC LIMIT 1) AS match_name,
                   (SELECT m.match_time FROM cl_matches m
                    WHERE (m.team_a_id = r.team_a_id AND m.team_b_id = r.team_b_id)
                       OR (m.team_a_id = r.team_b_id AND m.team_b_id = r.team_a_id)
                    ORDER BY m.id DESC LIMIT 1) AS match_time,
                    (SELECT m.id FROM cl_matches m
                     WHERE (m.team_a_id = r.team_a_id AND m.team_b_id = r.team_b_id)
                        OR (m.team_a_id = r.team_b_id AND m.team_b_id = r.team_a_id)
                     ORDER BY m.id DESC LIMIT 1) AS match_id,
                    (SELECT m.status FROM cl_matches m
                     WHERE (m.team_a_id = r.team_a_id AND m.team_b_id = r.team_b_id)
                        OR (m.team_a_id = r.team_b_id AND m.team_b_id = r.team_a_id)
                     ORDER BY m.id DESC LIMIT 1) AS match_status
            FROM cl_rooms r
            LEFT JOIN cl_teams ta ON ta.id = r.team_a_id
            LEFT JOIN cl_teams tb ON tb.id = r.team_b_id
    ';
    if ($admin) {
        $stmt = $pdo->prepare($selectSql . '
            ORDER BY
                FIELD(r.status, "open", "closed"),
                FIELD(r.room_type, "group", "admin", "deal", "match"),
                r.updated_at DESC,
                r.id DESC
        ');
        $stmt->execute();
    } elseif ($allowPersonal) {
        $teamId = (int) $team['id'];
        $stmt = $pdo->prepare($selectSql . '
            WHERE r.status = "open" AND (r.id = ? OR r.team_a_id = ? OR r.team_b_id = ?)
            ORDER BY FIELD(r.room_type, "group", "admin", "deal", "match"), r.updated_at DESC, r.id DESC
        ');
        $stmt->execute([$groupRoomId, $teamId, $teamId]);
    } else {
        $stmt = $pdo->prepare($selectSql . ' WHERE r.id = ? AND r.status = "open"');
        $stmt->execute([$groupRoomId]);
    }

    $rawRooms = $stmt->fetchAll();
    $matchIds = array_values(array_unique(array_filter(array_map(
        static fn(array $room): int => (int) ($room['match_id'] ?? 0),
        $rawRooms
    ))));
    $attendanceByMatch = [];
    if ($matchIds) {
        $attendancePlaceholders = implode(',', array_fill(0, count($matchIds), '?'));
        $attendanceStmt = $pdo->prepare("
            SELECT match_id, team_id, confirmed_at
            FROM cl_match_attendance
            WHERE match_id IN ($attendancePlaceholders)
        ");
        $attendanceStmt->execute($matchIds);
        foreach ($attendanceStmt->fetchAll() as $attendance) {
            $attendanceByMatch[(int) $attendance['match_id']][(int) $attendance['team_id']] = (string) $attendance['confirmed_at'];
        }
    }

    $rooms = [];
    foreach ($rawRooms as $room) {
        $isGroupRoom = $room['room_type'] === 'group';
        $isAdminRoom = $room['room_type'] === 'admin';
        $teamName = (string) ($room['team_a_name'] ?? 'Team');
        $teamStatus = (string) ($room['team_a_status'] ?? '');
        $teamSlot = (int) ($room['team_a_slot'] ?? 0);
        $teamDisplayName = $teamStatus === 'accepted' && $teamSlot > 0
            ? $teamName . ' #' . $teamSlot
            : ($teamStatus === 'pending' ? $teamName . ' · BELUM CONFIRM' : $teamName);
        $teamLogo = (string) ($room['team_a_logo'] ?? '');
        $matchId = (int) ($room['match_id'] ?? 0);
        $matchTime = (string) ($room['match_time'] ?? '');
        $attendanceOpensAt = '';
        $attendanceOpen = false;
        $attendanceClosed = false;
        if ($matchId > 0 && $matchTime !== '') {
            $matchAt = new DateTimeImmutable($matchTime, new DateTimeZone('Asia/Kuala_Lumpur'));
            $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur'));
            $attendanceOpen = $now <= $matchAt;
            $attendanceClosed = $now > $matchAt;
        }
        $roomAttendance = $attendanceByMatch[$matchId] ?? [];
        $title = $isGroupRoom ? 'PERTANYAAN / QUESTION' : ($isAdminRoom ? ($admin ? $teamDisplayName : 'ADMIN') : ($teamName . ' vs ' . (string) ($room['team_b_name'] ?? 'TBD')));
        $avatar = $isGroupRoom ? 'Q' : ($isAdminRoom ? ($admin ? make_initials($teamName) : 'AD') : 'VS');
        $rooms[] = [
            'id' => (int) $room['id'],
            'room_type' => (string) $room['room_type'],
            'team_id' => (int) ($room['team_a_id'] ?? 0),
            'team_status' => $teamStatus,
            'team_slot' => $teamSlot,
            'team_a_id' => (int) ($room['team_a_id'] ?? 0),
            'team_b_id' => (int) ($room['team_b_id'] ?? 0),
            'team_a_name' => (string) ($room['team_a_name'] ?? 'TBD'),
            'team_b_name' => (string) ($room['team_b_name'] ?? 'TBD'),
            'team_a_online' => !empty($room['team_a_last_seen']) && strtotime((string) $room['team_a_last_seen']) >= time() - 90,
            'team_b_online' => !empty($room['team_b_last_seen']) && strtotime((string) $room['team_b_last_seen']) >= time() - 90,
            'title' => $title,
            'subtitle' => $isGroupRoom ? 'Pertanyaan awam · semua boleh chat' : ($isAdminRoom ? ($admin ? 'Chat ke admin' : $teamName) : 'Clash League Deal'),
            'avatar' => $avatar,
            'avatar_logo' => $isGroupRoom ? '/images/question-chat-profile.png?v=20260726-2' : ($isAdminRoom && $admin ? $teamLogo : ''),
            'status' => (string) $room['status'],
            'last_message' => (string) ($room['last_message'] ?? 'Belum ada chat.'),
            'last_message_id' => (int) ($room['last_message_id'] ?? 0),
            'last_sender_type' => (string) ($room['last_sender_type'] ?? ''),
            'seen_message_id' => (int) ($room['seen_message_id'] ?? 0),
            'match_name' => (string) ($room['match_name'] ?? ''),
            'match_id' => $matchId,
            'match_status' => (string) ($room['match_status'] ?? ''),
            'match_time' => $matchTime,
            'match_date' => $matchTime !== '' ? substr($matchTime, 0, 10) : '',
            'attendance' => $roomAttendance,
            'attendance_opens_at' => $attendanceOpensAt,
            'attendance_open' => $attendanceOpen,
            'attendance_closed' => $attendanceClosed,
            'my_attendance' => $team ? (string) ($roomAttendance[(int) $team['id']] ?? '') : '',
        ];
    }

    return $rooms;
}

function initialize_admin_room_reads(PDO $pdo, int $adminId): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM cl_room_reads WHERE owner_type = "admin" AND admin_id = ?');
    $stmt->execute([$adminId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $stmt = $pdo->prepare('
        INSERT INTO cl_room_reads (owner_type, admin_id, room_id, last_message_id, updated_at)
        SELECT "admin", ?, r.id, COALESCE(MAX(m.id), 0), CURRENT_TIMESTAMP
        FROM cl_rooms r
        LEFT JOIN cl_messages m ON m.room_id = r.id
        GROUP BY r.id
    ');
    $stmt->execute([$adminId]);
}

function mark_room_read(PDO $pdo): void
{
    $admin = current_admin($pdo);
    $team = $admin ? null : current_chat_team($pdo);
    if (!$admin && !$team) {
        json_response(['ok' => false, 'message' => 'Login diperlukan.'], 401);
    }

    $roomId = (int) ($_POST['room_id'] ?? 0);
    if ($roomId <= 0) {
        json_response(['ok' => false, 'message' => 'Room tidak valid.'], 422);
    }

    $rooms = get_deal_rooms($pdo, $team, $admin, $team !== null);
    if (!array_filter($rooms, static fn(array $room): bool => (int) $room['id'] === $roomId)) {
        json_response(['ok' => false, 'message' => 'Akses room ditolak.'], 403);
    }

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM cl_messages WHERE room_id = ?');
    $stmt->execute([$roomId]);
    $lastMessageId = (int) $stmt->fetchColumn();
    $ownerType = $admin ? 'admin' : 'team';
    $adminId = $admin ? (int) $admin['id'] : null;
    $teamId = $team ? (int) $team['id'] : null;

    $stmt = $pdo->prepare('
        INSERT INTO cl_room_reads (owner_type, team_id, admin_id, room_id, last_message_id, updated_at)
        VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE last_message_id = GREATEST(last_message_id, VALUES(last_message_id)), updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([$ownerType, $teamId, $adminId, $roomId, $lastMessageId]);

    json_response(['ok' => true, 'room_id' => $roomId, 'last_message_id' => $lastMessageId]);
}

function confirm_match_attendance(PDO $pdo): void
{
    $team = require_team($pdo);
    $matchId = (int) ($_POST['match_id'] ?? 0);
    if ($matchId <= 0) {
        json_response(['ok' => false, 'message' => 'Match tidak valid.'], 422);
    }

    $stmt = $pdo->prepare('
        SELECT id, team_a_id, team_b_id, match_time
        FROM cl_matches
        WHERE id = ? AND status != "hidden"
        LIMIT 1
    ');
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();
    if (!$match) {
        json_response(['ok' => false, 'message' => 'Match tidak dijumpai.'], 404);
    }

    $teamId = (int) $team['id'];
    if ($teamId !== (int) ($match['team_a_id'] ?? 0) && $teamId !== (int) ($match['team_b_id'] ?? 0)) {
        json_response(['ok' => false, 'message' => 'Team anda bukan peserta match ini.'], 403);
    }
    if (empty($match['match_time'])) {
        json_response(['ok' => false, 'message' => 'Tarikh dan masa match belum ditetapkan.'], 422);
    }

    $timezone = new DateTimeZone('Asia/Kuala_Lumpur');
    $matchAt = new DateTimeImmutable((string) $match['match_time'], $timezone);
    $now = new DateTimeImmutable('now', $timezone);
    if ($now > $matchAt) {
        json_response(['ok' => false, 'message' => 'Masa pengesahan hadir sudah tamat.'], 422);
    }

    $stmt = $pdo->prepare('
        INSERT INTO cl_match_attendance (match_id, team_id, confirmed_at)
        VALUES (?, ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE confirmed_at = confirmed_at
    ');
    $stmt->execute([$matchId, $teamId]);

    json_response(get_state($pdo) + ['message' => 'Kehadiran team berjaya disahkan. Ada wakil.']);
}

function get_messages(PDO $pdo, array $rooms): array
{
    if (!$rooms) {
        return [];
    }
    $roomIds = array_map(static fn($room) => (int) $room['id'], $rooms);
    $placeholders = implode(',', array_fill(0, count($roomIds), '?'));
    $stmt = $pdo->prepare("
        SELECT m.id, m.room_id, m.sender_type, m.sender_team_id, m.guest_name, m.reply_to_message_id, m.action_target, m.message, m.created_at,
               t.team_name AS sender_team_name, t.status AS sender_team_status, t.slot_no AS sender_team_slot,
               rm.message AS reply_message, rm.sender_type AS reply_sender_type, rm.guest_name AS reply_guest_name,
               rt.team_name AS reply_team_name, rt.status AS reply_team_status, rt.slot_no AS reply_team_slot
        FROM cl_messages m
        LEFT JOIN cl_teams t ON t.id = m.sender_team_id
        LEFT JOIN cl_messages rm ON rm.id = m.reply_to_message_id AND rm.room_id = m.room_id
        LEFT JOIN cl_teams rt ON rt.id = rm.sender_team_id
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

    return "1. Requirements\nMinimum Rank Platinum ke atas, level akaun 40+, minimum 3 Heroic Emblem CS, bermain menggunakan telefon atau peranti mudah alih sahaja dan bebas blacklist Player Panel.\n\n2. Gameplay\nSemua style pepeng dibenarkan. Tembakan boleh dilakukan dalam keadaan prone atau meniarap serta squat atau jongkok.\n\n3. Third-Party Apps\nMacro dan aplikasi yang mengubah struktur permainan tidak dibenarkan. Crosshair dibenarkan jika tidak memberi kesan dalam perlawanan.\n\n4. Kedudukan\nTidak boleh menembak jika berada di kawasan yang tinggi.\n\n5. Gloo Wall\nBoleh membuat gloo wall bulat dan boleh memecahkan gloo wall musuh.\n\n6. Bug Game\nTidak boleh menggunakan dan memanfaatkan bug di dalam game untuk mendapatkan sesuatu kelebihan.\n\n7. Safezone\nTidak boleh menghalang musuh dari masuk ke dalam zone dan akan dikenakan amaran disqualified jika dikesan ingin bermain zone.\n\n8. Tayar Lompat\nJika tidak sengaja masih boleh dimaafkan. Tidak boleh melepaskan tembakan dengan sengaja jika sudah berada di atas.\n\n9. Interaction Ingame\nTiada tindakan dikenakan jika player melakukan emote dan seangkatan dengan itu.";
}

function get_pinned_info(PDO $pdo): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM cl_settings WHERE setting_key = "pinned_info" LIMIT 1');
    $stmt->execute();
    return trim((string) ($stmt->fetchColumn() ?: ''));
}

function get_pinned_info_version(PDO $pdo): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM cl_settings WHERE setting_key = "pinned_info_version" LIMIT 1');
    $stmt->execute();
    return trim((string) ($stmt->fetchColumn() ?: ''));
}

function get_pinned_action_target(PDO $pdo): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM cl_settings WHERE setting_key = "pinned_info_action_target" LIMIT 1');
    $stmt->execute();
    $target = trim((string) ($stmt->fetchColumn() ?: ''));
    if (in_array($target, ['jadual', 'rules', 'profile', 'all-team', 'deal'], true)) {
        return $target;
    }
    $pinnedInfo = get_pinned_info($pdo);
    if ($pinnedInfo === '') {
        return '';
    }
    $fallback = $pdo->prepare('
        SELECT action_target FROM cl_messages
        WHERE message = ? AND action_target IS NOT NULL AND action_target != ""
        ORDER BY id DESC LIMIT 1
    ');
    $fallback->execute([$pinnedInfo]);
    $target = (string) ($fallback->fetchColumn() ?: '');
    return in_array($target, ['jadual', 'rules', 'profile', 'all-team', 'deal'], true) ? $target : '';
}

function get_pinned_acknowledgement_state(PDO $pdo, ?array $team, ?array $chatTeam): array
{
    $version = get_pinned_info_version($pdo);
    $total = (int) $pdo->query('SELECT COUNT(*) FROM cl_teams WHERE status = "accepted" AND is_test_account = 0')->fetchColumn();
    $accepted = 0;
    $teamAccepted = false;
    if ($version !== '') {
        $count = $pdo->prepare('SELECT COUNT(*) FROM cl_pinned_info_acknowledgements WHERE pinned_version = ?');
        $count->execute([$version]);
        $accepted = (int) $count->fetchColumn();
        $activeTeam = $team ?: $chatTeam;
        if ($activeTeam) {
            $check = $pdo->prepare('
                SELECT 1 FROM cl_pinned_info_acknowledgements
                WHERE pinned_version = ? AND team_id = ? LIMIT 1
            ');
            $check->execute([$version, (int) $activeTeam['id']]);
            $teamAccepted = (bool) $check->fetchColumn();
        }
    }
    return [
        'version' => $version,
        'accepted' => $accepted,
        'total' => $total,
        'team_accepted' => $teamAccepted,
    ];
}

function get_state(PDO $pdo): array
{
    global $pushConfig, $dbConfig;

    $team = current_team($pdo);
    $admin = current_admin($pdo);
    refresh_persistent_login($pdo);
    if ($admin) {
        repair_legacy_tbd_schedule($pdo);
    }
    $chatTeam = $team ?: current_chat_team($pdo);
    $presenceTeam = $team ?: $chatTeam;
    if ($presenceTeam) {
        $presenceStmt = $pdo->prepare('
            UPDATE cl_teams SET last_seen_at = CURRENT_TIMESTAMP
            WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < CURRENT_TIMESTAMP - INTERVAL 20 SECOND)
        ');
        $presenceStmt->execute([(int) $presenceTeam['id']]);
    }
    if ($team && $team['status'] === 'accepted') {
        get_or_create_admin_room($pdo, (int) $team['id']);
    }

    $rooms = get_deal_rooms($pdo, $chatTeam, $admin, $team !== null);
    $notificationOwner = $admin ? 'admin' : ($team ? 'team' : '');
    $notificationReadiness = $notificationOwner === ''
        ? ['devices' => 0, 'healthy_devices' => 0, 'tested_devices' => 0, 'ready' => false, 'last_status' => '']
        : notification_readiness_for_owner(
            $pdo,
            $notificationOwner,
            $team ? (int) $team['id'] : null,
            $admin ? (int) $admin['id'] : null
        );
    return [
        'ok' => true,
        'team' => $team,
        'admin' => $admin,
        'chat_team' => $chatTeam,
        'teams' => get_public_teams($pdo),
        'matches' => get_team_matches($pdo, $team, $admin),
        'results' => get_match_results($pdo, $team, $admin),
        'password_requests' => get_password_change_requests($pdo, $admin, $dbConfig),
        'rooms' => $rooms,
        'messages' => get_messages($pdo, $rooms),
        'rules_text' => get_rules_text($pdo),
        'pinned_info' => get_pinned_info($pdo),
        'pinned_info_action_target' => get_pinned_action_target($pdo),
        'pinned_acknowledgement' => get_pinned_acknowledgement_state($pdo, $team, $chatTeam),
        'push_public_key' => $pushConfig['public_key'] ?? null,
        'notification_readiness' => $notificationReadiness,
    ];
}

function repair_legacy_tbd_schedule(PDO $pdo): void
{
    if ($pdo->inTransaction()) {
        return;
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query('
            SELECT m.id, m.team_a_id, m.team_b_id,
                   ta.status AS team_a_status, tb.status AS team_b_status
            FROM cl_matches m
            LEFT JOIN cl_teams ta ON ta.id = m.team_a_id
            LEFT JOIN cl_teams tb ON tb.id = m.team_b_id
            WHERE m.status IN ("up_next", "live")
              AND (ta.status = "removed" OR tb.status = "removed")
            FOR UPDATE
        ');
        $updateMatch = $pdo->prepare('
            UPDATE cl_matches
            SET team_a_id = ?, team_b_id = ?, status = "up_next",
                team_a_point = NULL, team_b_point = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $updateRoom = $pdo->prepare('
            UPDATE cl_rooms
            SET team_a_id = ?, team_b_id = ?, status = "open", updated_at = CURRENT_TIMESTAMP
            WHERE room_type = "match" AND team_a_id <=> ? AND team_b_id <=> ?
        ');
        foreach ($stmt->fetchAll() as $match) {
            $oldA = $match['team_a_id'] === null ? null : (int) $match['team_a_id'];
            $oldB = $match['team_b_id'] === null ? null : (int) $match['team_b_id'];
            $newA = (string) ($match['team_a_status'] ?? '') === 'removed' ? null : $oldA;
            $newB = (string) ($match['team_b_status'] ?? '') === 'removed' ? null : $oldB;
            $updateMatch->execute([$newA, $newB, (int) $match['id']]);
            $updateRoom->execute([$newA, $newB, $oldA, $oldB]);
            $pdo->prepare('DELETE FROM cl_match_results WHERE match_id = ?')->execute([(int) $match['id']]);
            $pdo->prepare('
                DELETE FROM cl_match_attendance
                WHERE match_id = ? AND team_id NOT IN (
                    SELECT participant_id FROM (
                        SELECT team_a_id AS participant_id FROM cl_matches WHERE id = ?
                        UNION ALL
                        SELECT team_b_id AS participant_id FROM cl_matches WHERE id = ?
                    ) participants
                    WHERE participant_id IS NOT NULL
                )
            ')->execute([(int) $match['id'], (int) $match['id'], (int) $match['id']]);
        }

        while (true) {
            $vacancy = $pdo->query('
                SELECT id, team_a_id, team_b_id
                FROM cl_matches
                WHERE status IN ("up_next", "live")
                  AND ((team_a_id IS NULL AND team_b_id IS NOT NULL) OR (team_a_id IS NOT NULL AND team_b_id IS NULL))
                ORDER BY COALESCE(match_time, "2999-12-31") ASC, id ASC
                LIMIT 1 FOR UPDATE
            ')->fetch();
            if (!$vacancy) {
                break;
            }
            $vacancyId = (int) $vacancy['id'];
            $vacancyA = $vacancy['team_a_id'] === null ? null : (int) $vacancy['team_a_id'];
            $vacancyB = $vacancy['team_b_id'] === null ? null : (int) $vacancy['team_b_id'];
            $donorStmt = $pdo->prepare('
                SELECT id, team_a_id, team_b_id
                FROM cl_matches
                WHERE id != ? AND status IN ("up_next", "live")
                  AND ((team_a_id IS NULL AND team_b_id IS NOT NULL) OR (team_a_id IS NOT NULL AND team_b_id IS NULL))
                ORDER BY COALESCE(match_time, "2999-12-31") DESC, id DESC
                LIMIT 1 FOR UPDATE
            ');
            $donorStmt->execute([$vacancyId]);
            $donor = $donorStmt->fetch();
            if (!$donor) {
                break;
            }
            $donorId = (int) $donor['id'];
            $donorA = $donor['team_a_id'] === null ? null : (int) $donor['team_a_id'];
            $donorB = $donor['team_b_id'] === null ? null : (int) $donor['team_b_id'];
            $incoming = $donorA ?? $donorB;
            $newA = $vacancyA ?? $incoming;
            $newB = $vacancyA === null ? $vacancyB : $incoming;
            $updateMatch->execute([$newA, $newB, $vacancyId]);
            $updateRoom->execute([$newA, $newB, $vacancyA, $vacancyB]);
            $pdo->prepare('DELETE FROM cl_match_results WHERE match_id IN (?, ?)')->execute([$vacancyId, $donorId]);
            $pdo->prepare('DELETE FROM cl_match_attendance WHERE match_id = ?')->execute([$donorId]);
            $pdo->prepare('
                UPDATE cl_matches
                SET team_a_id = NULL, team_b_id = NULL, status = "hidden",
                    team_a_point = NULL, team_b_point = NULL, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ')->execute([$donorId]);
            $pdo->prepare('
                UPDATE cl_rooms SET status = "closed", updated_at = CURRENT_TIMESTAMP
                WHERE room_type = "match" AND team_a_id <=> ? AND team_b_id <=> ?
            ')->execute([$donorA, $donorB]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function set_team_status(PDO $pdo, array $dbConfig): void
{
    $adminPassword = (string) ($_POST['admin_password'] ?? '');
    $admin = current_admin($pdo);
    if (!$admin && ($adminPassword === '' || $adminPassword !== (string) ($dbConfig['admin_password'] ?? ''))) {
        json_response(['ok' => false, 'message' => 'Admin password salah.'], 401);
    }

    $teamId = (int) ($_POST['team_id'] ?? 0);
    $status = clean_text($_POST['status'] ?? '', 20);
    $adminNote = clean_text($_POST['admin_note'] ?? '', 255);
    if ($teamId <= 0 || !in_array($status, ['pending', 'accepted', 'rejected', 'removed'], true)) {
        json_response(['ok' => false, 'message' => 'Data status tidak valid.'], 422);
    }
    if ($status === 'rejected' && $adminNote === '') {
        json_response(['ok' => false, 'message' => 'Tulis sebab reject dulu.'], 422);
    }

    $pdo->beginTransaction();
    $teamStmt = $pdo->prepare('SELECT id, team_name, status, slot_no FROM cl_teams WHERE id = ? AND status != "removed" LIMIT 1 FOR UPDATE');
    $teamStmt->execute([$teamId]);
    $existingTeam = $teamStmt->fetch();
    if (!$existingTeam) {
        $pdo->rollBack();
        json_response(['ok' => false, 'message' => 'Team tidak dijumpai.'], 404);
    }
    $oldStatus = (string) $existingTeam['status'];

    try {
        $slotNo = null;
        if ($status === 'accepted') {
            $currentSlot = $existingTeam['slot_no'];
            if ($currentSlot !== null && (int) $currentSlot > 0) {
                $slotNo = (int) $currentSlot;
            } else {
                // Lock accepted rows while assigning the next slot so two admins
                // cannot receive the same slot during simultaneous confirmations.
                $pdo->query('SELECT id FROM cl_teams WHERE status = "accepted" FOR UPDATE')->fetchAll();
                $slotNo = (int) $pdo->query('SELECT COALESCE(MAX(slot_no), 0) + 1 FROM cl_teams WHERE status = "accepted"')->fetchColumn();
            }
        }

        $storedNote = $status === 'rejected' ? $adminNote : null;
        $stmt = $pdo->prepare('UPDATE cl_teams SET status = ?, slot_no = ?, admin_note = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$status, $slotNo, $storedNote, $teamId]);
        renumber_accepted_slots($pdo);

        $pushEventId = null;
    if ($status === 'accepted' && $oldStatus !== 'accepted') {
        $pushEventId = queue_push_event(
            $pdo,
            'team',
            $teamId,
            null,
            'Clash League',
            'Pasukan anda berjaya menyertai tournament Clash League, sila login untuk bermain.',
            'clash-league.html#login',
            'clash-team-accepted-' . $teamId
        );
    } elseif ($status === 'rejected' && $oldStatus !== 'rejected') {
        $rejectBody = $adminNote !== ''
            ? 'Pendaftaran anda tidak berjaya kerana ' . $adminNote . ', sila daftar semula.'
            : 'Pendaftaran anda tidak berjaya, sila daftar semula.';
        $pushEventId = queue_push_event(
            $pdo,
            'team',
            $teamId,
            null,
            'Pendaftaran Clash League',
            $rejectBody,
            'clash-league.html#home',
            'clash-team-rejected-' . $teamId
        );
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    json_response([
        'ok' => true,
        'message' => 'Status team berjaya update.',
        'team_update' => [
            'id' => $teamId,
            'status' => $status,
            'status_label' => $status === 'accepted' ? 'Confirm' : ($status === 'pending' ? 'Sedang Disemak' : 'Rejected'),
            'slot_no' => $slotNo,
            'admin_note' => $storedNote,
        ],
        'push_event_id' => $pushEventId,
    ]);
}

function dispatch_team_status_push(PDO $pdo): void
{
    global $pushConfig;
    $admin = current_admin($pdo);
    if (!$admin) {
        json_response(['ok' => false, 'message' => 'Admin sahaja.'], 401);
    }
    $teamId = (int) ($_POST['team_id'] ?? 0);
    $eventId = (int) ($_POST['event_id'] ?? 0);
    if ($teamId <= 0 || $eventId <= 0) {
        json_response(['ok' => false, 'message' => 'Data notification tidak valid.'], 422);
    }
    $check = $pdo->prepare('SELECT id FROM cl_push_events WHERE id = ? AND owner_type = "team" AND team_id = ? LIMIT 1');
    $check->execute([$eventId, $teamId]);
    if (!$check->fetchColumn()) {
        json_response(['ok' => false, 'message' => 'Notification tidak dijumpai.'], 404);
    }
    json_response(['ok' => true, 'push_summary' => send_push_to_owner($pdo, $pushConfig, 'team', $teamId, null, $eventId)]);
}

function update_team_info(PDO $pdo, string $rootDir): void
{
    $admin = current_admin($pdo);
    $loggedTeam = current_team($pdo);
    if (!$admin && !$loggedTeam) {
        json_response(['ok' => false, 'message' => 'Login diperlukan untuk edit team.'], 401);
    }

    $teamId = $admin ? (int) ($_POST['team_id'] ?? 0) : (int) $loggedTeam['id'];
    if ($teamId <= 0) {
        json_response(['ok' => false, 'message' => 'Team tidak valid.'], 422);
    }

    $existingStmt = $pdo->prepare('
        SELECT id, team_name, logo_url, logo_path, phone, coach_name, manager_name, slot_no
        FROM cl_teams WHERE id = ? AND status != "removed" LIMIT 1
    ');
    $existingStmt->execute([$teamId]);
    $existingTeam = $existingStmt->fetch();
    if (!$existingTeam) {
        json_response(['ok' => false, 'message' => 'Team tidak dijumpai atau sudah dikeluarkan.'], 404);
    }

    $teamName = to_upper_text(clean_text($_POST['team_name'] ?? '', 100));
    $phone = clean_text($_POST['phone'] ?? '', 40);
    $coachName = clean_text($_POST['coach_name'] ?? '', 100);
    $managerName = clean_text($_POST['manager_name'] ?? '', 100);
    $logoUrl = (string) ($existingTeam['logo_url'] ?? '');
    $slotRaw = trim((string) ($_POST['slot_no'] ?? ''));
    $slotNo = $admin
        ? ($slotRaw === '' ? null : max(1, (int) $slotRaw))
        : ($loggedTeam['slot_no'] === '' ? null : (int) $loggedTeam['slot_no']);

    if ($teamName === '') {
        json_response(['ok' => false, 'message' => 'Nama team wajib isi.'], 422);
    }

    $stmt = $pdo->prepare('SELECT id FROM cl_teams WHERE LOWER(team_name) = LOWER(?) AND id != ? LIMIT 1');
    $stmt->execute([$teamName, $teamId]);
    if ($stmt->fetch()) {
        json_response(['ok' => false, 'code' => 'team_name_exists', 'message' => 'Nama team ini sudah digunakan oleh team lain.'], 409);
    }

    $hasPlayerPayload = false;
    for ($slot = 1; $slot <= 6; $slot++) {
        if (array_key_exists('p' . $slot . '_ign', $_POST) || array_key_exists('p' . $slot . '_id', $_POST)) {
            $hasPlayerPayload = true;
            break;
        }
    }
    $players = [];
    $playerIds = [];
    for ($slot = 1; $slot <= 6; $slot++) {
        $ign = clean_text($_POST['p' . $slot . '_ign'] ?? '', 100);
        $playerId = clean_text($_POST['p' . $slot . '_id'] ?? '', 80);
        if ($ign === '' && $playerId === '') {
            continue;
        }
        $players[] = ['slot' => $slot, 'ign' => $ign, 'id' => $playerId];
        if ($playerId !== '') {
            $playerIds[] = strtolower(trim($playerId));
        }
    }

    if (count($playerIds) !== count(array_unique($playerIds))) {
        json_response(['ok' => false, 'code' => 'duplicate_player_id', 'message' => 'Player ID yang sama tidak boleh digunakan dua kali dalam satu team.'], 409);
    }
    if ($playerIds) {
        $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
        $stmt = $pdo->prepare('
            SELECT p.player_id, t.team_name
            FROM cl_players p
            INNER JOIN cl_teams t ON t.id = p.team_id
            WHERE t.status IN ("accepted", "pending") AND p.team_id != ? AND LOWER(TRIM(p.player_id)) IN (' . $placeholders . ')
            LIMIT 1
        ');
        $stmt->execute(array_merge([$teamId], $playerIds));
        $conflict = $stmt->fetch();
        if ($conflict) {
            json_response([
                'ok' => false,
                'code' => 'player_id_exists',
                'message' => 'Player ID ' . (string) $conflict['player_id'] . ' sudah digunakan oleh team ' . (string) $conflict['team_name'] . '.',
            ], 409);
        }
    }

    $logoPath = null;
    if (($_FILES['team_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        [$logoPath, $logoUrl] = save_logo($_FILES['team_logo'], $teamName, $rootDir);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('
            UPDATE cl_teams
            SET team_name = ?, logo_url = ?, logo_path = COALESCE(?, logo_path), phone = ?, coach_name = ?, manager_name = ?, slot_no = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND status != "removed"
        ');
        $stmt->execute([$teamName, $logoUrl, $logoPath, $phone, $coachName, $managerName, $slotNo, $teamId]);
        if ($stmt->rowCount() === 0) {
            $existsStmt = $pdo->prepare('SELECT id FROM cl_teams WHERE id = ? AND status != "removed"');
            $existsStmt->execute([$teamId]);
            if (!$existsStmt->fetch()) {
                throw new RuntimeException('Team tidak dijumpai atau sudah dikeluarkan.');
            }
        }

        if ($hasPlayerPayload) {
            $activeSlots = array_map(static fn(array $player): int => (int) $player['slot'], $players);
            if ($activeSlots) {
                $slotPlaceholders = implode(',', array_fill(0, count($activeSlots), '?'));
                $stmt = $pdo->prepare('DELETE FROM cl_players WHERE team_id = ? AND player_slot NOT IN (' . $slotPlaceholders . ')');
                $stmt->execute(array_merge([$teamId], $activeSlots));
            } else {
                $stmt = $pdo->prepare('DELETE FROM cl_players WHERE team_id = ?');
                $stmt->execute([$teamId]);
            }

            $stmt = $pdo->prepare('
                INSERT INTO cl_players (team_id, player_slot, ign, player_id)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE ign = VALUES(ign), player_id = VALUES(player_id)
            ');
            foreach ($players as $player) {
                $stmt->execute([$teamId, $player['slot'], $player['ign'], $player['id']]);
            }
        }

        renumber_accepted_slots($pdo);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    json_response(get_state($pdo) + ['message' => 'Maklumat team berjaya update.']);
}

function remove_team(PDO $pdo): void
{
    global $pushConfig;

    $admin = current_admin($pdo);
    if (!$admin) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan untuk remove team.'], 401);
    }

    $teamId = (int) ($_POST['team_id'] ?? 0);
    if ($teamId <= 0) {
        json_response(['ok' => false, 'message' => 'Team tidak valid.'], 422);
    }

    $teamStmt = $pdo->prepare('SELECT id, team_name FROM cl_teams WHERE id = ? AND status != "removed" LIMIT 1');
    $teamStmt->execute([$teamId]);
    $removedTeam = $teamStmt->fetch();
    if (!$removedTeam) {
        json_response(['ok' => false, 'message' => 'Team tidak dijumpai atau sudah diremove.'], 404);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE cl_teams SET status = "removed", slot_no = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$teamId]);
        $roomStmt = $pdo->prepare('
            UPDATE cl_rooms
            SET status = "closed", updated_at = CURRENT_TIMESTAMP
            WHERE room_type NOT IN ("group", "match") AND status = "open" AND (team_a_id = ? OR team_b_id = ?)
        ');
        $roomStmt->execute([$teamId, $teamId]);
        $closedRooms = $roomStmt->rowCount();
        $scheduleUpdate = apply_team_withdrawal_to_schedule($pdo, $teamId);
        renumber_accepted_slots($pdo);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    foreach ($scheduleUpdate['opponent_ids'] as $opponentId) {
        $body = (string) $removedTeam['team_name'] . ' telah dikeluarkan. Slot lawan kini TBD dan akan diganti dengan team baharu.';
        queue_push_event($pdo, 'team', $opponentId, null, 'Lawan TBD Clash League', $body, 'clash-league.html#jadual', 'clash-tbd-' . $teamId);
        send_push_to_owner($pdo, $pushConfig, 'team', $opponentId);
    }

    $message = 'Team berjaya remove.';
    if ($scheduleUpdate['vacancies'] > 0) {
        $message .= ' ' . $scheduleUpdate['vacancies'] . ' slot jadual ditukar kepada TBD.';
    }
    if ($closedRooms > 0) {
        $message .= ' ' . $closedRooms . ' chat room ditutup.';
    }

    json_response(get_state($pdo) + [
        'message' => $message,
        'schedule_update' => $scheduleUpdate,
        'closed_rooms' => $closedRooms,
    ]);
}

function apply_team_withdrawal_to_schedule(PDO $pdo, int $removedTeamId): array
{
    $stmt = $pdo->prepare('
        SELECT m.id, m.team_a_id, m.team_b_id,
               ta.status AS team_a_status, tb.status AS team_b_status
        FROM cl_matches m
        LEFT JOIN cl_teams ta ON ta.id = m.team_a_id
        LEFT JOIN cl_teams tb ON tb.id = m.team_b_id
        WHERE m.status IN ("up_next", "live")
          AND (m.team_a_id = ? OR m.team_b_id = ?)
    ');
    $stmt->execute([$removedTeamId, $removedTeamId]);

    $vacancyStmt = $pdo->prepare('
        UPDATE cl_matches
        SET team_a_id = ?, team_b_id = ?, status = "up_next",
            team_a_point = NULL, team_b_point = NULL, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ');
    $roomStmt = $pdo->prepare('
        UPDATE cl_rooms
        SET team_a_id = ?, team_b_id = ?, status = "open", updated_at = CURRENT_TIMESTAMP
        WHERE room_type = "match"
          AND ((team_a_id <=> ? AND team_b_id <=> ?) OR (team_a_id <=> ? AND team_b_id <=> ?))
    ');

    $vacancies = 0;
    $opponentIds = [];
    foreach ($stmt->fetchAll() as $match) {
        $removedIsTeamA = (int) ($match['team_a_id'] ?? 0) === $removedTeamId;
        $opponentId = (int) ($removedIsTeamA ? ($match['team_b_id'] ?? 0) : ($match['team_a_id'] ?? 0));
        $opponentStatus = (string) ($removedIsTeamA ? ($match['team_b_status'] ?? '') : ($match['team_a_status'] ?? ''));
        $oldTeamA = $match['team_a_id'] === null ? null : (int) $match['team_a_id'];
        $oldTeamB = $match['team_b_id'] === null ? null : (int) $match['team_b_id'];
        $teamA = $removedIsTeamA ? null : $oldTeamA;
        $teamB = $removedIsTeamA ? $oldTeamB : null;
        $vacancyStmt->execute([$teamA, $teamB, (int) $match['id']]);
        $roomStmt->execute([$teamA, $teamB, $oldTeamA, $oldTeamB, $oldTeamB, $oldTeamA]);
        $vacancies++;
        if ($opponentId > 0 && $opponentStatus !== 'removed') {
            $opponentIds[$opponentId] = $opponentId;
        }
    }

    return [
        'vacancies' => $vacancies,
        'opponent_ids' => array_values($opponentIds),
    ];
}

function renumber_accepted_slots(PDO $pdo): void
{
    $stmt = $pdo->query('
        SELECT id, slot_no
        FROM cl_teams
        WHERE status = "accepted" AND is_test_account = 0
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

    repair_legacy_tbd_schedule($pdo);

    $limit = max(1, min(128, (int) ($_POST['team_limit'] ?? 10)));
    $prefix = clean_text($_POST['match_prefix'] ?? 'Qualifier Match', 120);
    if ($prefix === '') {
        $prefix = 'Qualifier Match';
    }

    $matchTimeSql = normalize_match_time($_POST['match_time'] ?? '');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            SELECT t.id
            FROM cl_teams t
            WHERE t.status = "accepted" AND t.is_test_account = 0
              AND NOT EXISTS (
                  SELECT 1
                  FROM cl_matches m
                  WHERE m.team_a_id = t.id OR m.team_b_id = t.id
              )
            ORDER BY COALESCE(t.slot_no, 999999), t.created_at ASC, t.id ASC
            LIMIT ?
            FOR UPDATE
        ');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $teamIds = array_map(static fn($row) => (int) $row['id'], $stmt->fetchAll());

        if (count($teamIds) < $limit) {
            $available = count($teamIds);
            $pdo->rollBack();
            json_response([
                'ok' => false,
                'message' => 'Batch belum cukup. Ada ' . $available . ' team baharu, perlukan ' . $limit . ' team untuk proses.',
            ], 422);
        }

        shuffle($teamIds);
        $processedCount = count($teamIds);
        $filledTbd = 0;

        while ($teamIds) {
            $tbdStmt = $pdo->query('
                SELECT id, team_a_id, team_b_id
                FROM cl_matches
                WHERE status IN ("up_next", "live")
                  AND (team_a_id IS NULL OR team_b_id IS NULL)
                ORDER BY COALESCE(match_time, "2999-12-31") ASC, id ASC
                LIMIT 1
                FOR UPDATE
            ');
            $tbdMatch = $tbdStmt->fetch();
            if (!$tbdMatch) {
                break;
            }
            $newOpponentId = (int) array_shift($teamIds);
            $matchId = (int) $tbdMatch['id'];
            $existingTeamA = $tbdMatch['team_a_id'] === null ? null : (int) $tbdMatch['team_a_id'];
            $existingTeamB = $tbdMatch['team_b_id'] === null ? null : (int) $tbdMatch['team_b_id'];
            $teamA = $existingTeamA !== null ? $existingTeamA : $newOpponentId;
            $teamB = $existingTeamA === null ? $existingTeamB : $newOpponentId;

            $updateTbd = $pdo->prepare('
                UPDATE cl_matches
                SET team_a_id = ?, team_b_id = ?, status = "up_next",
                    team_a_point = NULL, team_b_point = NULL, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ');
            $updateTbd->execute([$teamA, $teamB, $matchId]);

            $roomLookup = $pdo->prepare('
                SELECT id FROM cl_rooms
                WHERE room_type = "match"
                  AND team_a_id <=> ? AND team_b_id <=> ?
                ORDER BY id DESC LIMIT 1 FOR UPDATE
            ');
            $roomLookup->execute([$existingTeamA, $existingTeamB]);
            $roomId = (int) ($roomLookup->fetchColumn() ?: 0);
            if ($roomId > 0) {
                $updateRoom = $pdo->prepare('
                    UPDATE cl_rooms SET team_a_id = ?, team_b_id = ?, status = "open", updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ');
                $updateRoom->execute([$teamA, $teamB, $roomId]);
            } else {
                $createRoom = $pdo->prepare('
                    INSERT INTO cl_rooms (room_type, team_a_id, team_b_id, status, updated_at)
                    VALUES ("match", ?, ?, "open", CURRENT_TIMESTAMP)
                ');
                $createRoom->execute([$teamA, $teamB]);
            }
            $filledTbd++;
        }

        $nameStmt = $pdo->prepare('SELECT match_name FROM cl_matches WHERE match_name LIKE ?');
        $nameStmt->execute([$prefix . ' %']);
        $highestMatchNo = 0;
        $namePattern = '/^' . preg_quote($prefix, '/') . '\s+(\d+)$/i';
        foreach ($nameStmt->fetchAll(PDO::FETCH_COLUMN) as $existingName) {
            if (preg_match($namePattern, (string) $existingName, $matches)) {
                $highestMatchNo = max($highestMatchNo, (int) $matches[1]);
            }
        }
        $matchNo = $highestMatchNo + 1;

        $matchStmt = $pdo->prepare('
            INSERT INTO cl_matches (team_a_id, team_b_id, match_name, match_time, status, updated_at)
            VALUES (?, ?, ?, ?, "up_next", CURRENT_TIMESTAMP)
        ');
        $roomStmt = $pdo->prepare('
            INSERT INTO cl_rooms (room_type, team_a_id, team_b_id, status, updated_at)
            VALUES ("match", ?, ?, "open", CURRENT_TIMESTAMP)
        ');

        for ($index = 0; $index < count($teamIds); $index += 2) {
            $teamA = $teamIds[$index];
            $teamB = $teamIds[$index + 1] ?? null;
            $matchName = $prefix . ' ' . $matchNo;
            $matchStmt->execute([$teamA, $teamB, $matchName, $matchTimeSql]);
            $roomStmt->execute([$teamA, $teamB]);
            $matchNo++;
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    json_response(get_state($pdo) + [
        'message' => $filledTbd > 0
            ? 'Batch ' . $processedCount . ' team berjaya diproses. ' . $filledTbd . ' slot TBD telah diisi.'
            : 'Batch ' . $processedCount . ' team baharu berjaya dibuat. Jika seorang sahaja, jadual ditetapkan sebagai vs TBD.',
    ]);
}

function reset_all_matches(PDO $pdo): void
{
    $admin = current_admin($pdo);
    if (!$admin) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan untuk reset jadual.'], 401);
    }

    $pdo->beginTransaction();
    try {
        $deletedMatches = (int) $pdo->query('SELECT COUNT(*) FROM cl_matches')->fetchColumn();
        $pdo->exec('DELETE FROM cl_rooms WHERE room_type IN ("match", "deal")');
        $pdo->exec('DELETE FROM cl_matches');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    json_response(get_state($pdo) + [
        'message' => $deletedMatches . ' jadual berjaya dipadam. Team pendaftaran tidak terjejas.',
    ]);
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
    if ($status === 'completed') {
        json_response([
            'ok' => false,
            'message' => 'Status completed hanya boleh ditetapkan melalui pengesahan result kedua-dua team.',
        ], 422);
    }

    $matchTimeSql = normalize_match_time($_POST['match_time'] ?? '');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            UPDATE cl_matches
            SET match_name = ?, match_time = ?, status = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $stmt->execute([$matchName, $matchTimeSql, $status, $matchId]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    json_response(get_state($pdo) + ['message' => 'Match berjaya update.']);
}

function reopen_match(PDO $pdo): void
{
    if (!current_admin($pdo)) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan.'], 401);
    }
    $matchId = (int) ($_POST['match_id'] ?? 0);
    if ($matchId <= 0) {
        json_response(['ok' => false, 'message' => 'Match tidak valid.'], 422);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            UPDATE cl_matches
            SET status = "up_next", team_a_point = NULL, team_b_point = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $stmt->execute([$matchId]);
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('Match tidak dijumpai.');
        }
        $stmt = $pdo->prepare('
            UPDATE cl_match_results
            SET status = "pending", admin_note = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE match_id = ?
        ');
        $stmt->execute([$matchId]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    json_response(get_state($pdo) + ['message' => 'Match dibuka semula. Point rasmi telah dikosongkan.']);
}

function approve_match_result(PDO $pdo): void
{
    if (!current_admin($pdo)) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan.'], 401);
    }
    $matchId = (int) ($_POST['match_id'] ?? 0);
    if ($matchId <= 0) {
        json_response(['ok' => false, 'message' => 'Match tidak valid.'], 422);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM cl_matches WHERE id = ? FOR UPDATE');
        $stmt->execute([$matchId]);
        $match = $stmt->fetch();
        if (!$match || !(int) $match['team_a_id'] || !(int) $match['team_b_id']) {
            throw new RuntimeException('Data team untuk match ini tidak lengkap.');
        }

        $stmt = $pdo->prepare('
            SELECT team_id, team_a_point, team_b_point
            FROM cl_match_results
            WHERE match_id = ? AND team_id IN (?, ?)
            FOR UPDATE
        ');
        $stmt->execute([$matchId, $match['team_a_id'], $match['team_b_id']]);
        $submissions = $stmt->fetchAll();
        if (count($submissions) !== 2) {
            throw new RuntimeException('Belum boleh sahkan: kedua-dua team wajib submit result.');
        }
        $first = $submissions[0];
        $second = $submissions[1];
        if ((int) $first['team_a_point'] !== (int) $second['team_a_point']
            || (int) $first['team_b_point'] !== (int) $second['team_b_point']) {
            throw new RuntimeException('Result kedua-dua team tidak sama. Semak screenshot sebelum sahkan.');
        }
        $teamAPoint = (int) $first['team_a_point'];
        $teamBPoint = (int) $first['team_b_point'];
        if ($teamAPoint === $teamBPoint) {
            throw new RuntimeException('Result seri tidak boleh disahkan sebagai completed.');
        }

        $stmt = $pdo->prepare('
            UPDATE cl_matches
            SET status = "completed", team_a_point = ?, team_b_point = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $stmt->execute([$teamAPoint, $teamBPoint, $matchId]);
        $stmt = $pdo->prepare('
            UPDATE cl_match_results
            SET status = "approved", admin_note = "Disahkan selepas kedua-dua submission sepadan", updated_at = CURRENT_TIMESTAMP
            WHERE match_id = ? AND team_id IN (?, ?)
        ');
        $stmt->execute([$matchId, $match['team_a_id'], $match['team_b_id']]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        json_response(['ok' => false, 'message' => $error->getMessage()], 409);
    }

    json_response(get_state($pdo) + ['message' => 'Result disahkan. Match kini completed.']);
}

function admin_set_match_result(PDO $pdo): void
{
    if (!current_admin($pdo)) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan.'], 401);
    }
    $matchId = (int) ($_POST['match_id'] ?? 0);
    $teamAPoint = normalize_match_point($_POST['team_a_point'] ?? '');
    $teamBPoint = normalize_match_point($_POST['team_b_point'] ?? '');
    if ($matchId <= 0) {
        json_response(['ok' => false, 'message' => 'Match tidak valid.'], 422);
    }
    if ($teamAPoint === $teamBPoint) {
        json_response(['ok' => false, 'message' => 'Result seri tidak boleh ditetapkan sebagai completed.'], 422);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, match_time FROM cl_matches WHERE id = ? FOR UPDATE');
        $stmt->execute([$matchId]);
        $match = $stmt->fetch();
        if (!$match) {
            throw new RuntimeException('Match tidak dijumpai.');
        }
        $matchTime = trim((string) ($match['match_time'] ?? ''));
        if ($matchTime === '') {
            throw new RuntimeException('Masa match belum ditetapkan.');
        }
        $matchStartsAt = new DateTimeImmutable($matchTime, new DateTimeZone('Asia/Kuala_Lumpur'));
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur'));
        if ($now < $matchStartsAt) {
            throw new RuntimeException('Admin hanya boleh update point selepas match bermula pada ' . $matchStartsAt->format('d/m/Y, h:i A') . '.');
        }

        $stmt = $pdo->prepare('
            UPDATE cl_matches
            SET status = "completed", team_a_point = ?, team_b_point = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $stmt->execute([$teamAPoint, $teamBPoint, $matchId]);
        $stmt = $pdo->prepare('
            UPDATE cl_match_results
            SET status = "approved", admin_note = "Keputusan rasmi ditetapkan oleh admin", updated_at = CURRENT_TIMESTAMP
            WHERE match_id = ?
        ');
        $stmt->execute([$matchId]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        json_response(['ok' => false, 'message' => $error->getMessage()], 409);
    }

    json_response(get_state($pdo) + ['message' => 'Point kedua-dua team berjaya ditetapkan oleh admin.']);
}

function update_match_teams(PDO $pdo): void
{
    if (!current_admin($pdo)) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan.'], 401);
    }
    $matchId = (int) ($_POST['match_id'] ?? 0);
    $desiredA = (int) ($_POST['team_a_id'] ?? 0);
    $desiredB = (int) ($_POST['team_b_id'] ?? 0);
    $desiredA = $desiredA > 0 ? $desiredA : null;
    $desiredB = $desiredB > 0 ? $desiredB : null;
    if ($matchId <= 0 || ($desiredA !== null && $desiredA === $desiredB)) {
        json_response(['ok' => false, 'message' => 'Pilihan peserta match tidak valid.'], 422);
    }

    $pdo->beginTransaction();
    try {
        $targetStmt = $pdo->prepare('SELECT id, team_a_id, team_b_id FROM cl_matches WHERE id = ? FOR UPDATE');
        $targetStmt->execute([$matchId]);
        $target = $targetStmt->fetch();
        if (!$target) {
            throw new RuntimeException('Match tidak dijumpai.');
        }
        foreach (array_filter([$desiredA, $desiredB]) as $teamId) {
            $teamStmt = $pdo->prepare('SELECT COUNT(*) FROM cl_teams WHERE id = ? AND status = "accepted" AND is_test_account = 0');
            $teamStmt->execute([$teamId]);
            if ((int) $teamStmt->fetchColumn() !== 1) {
                throw new RuntimeException('Hanya team aktif yang sudah confirm boleh dimasukkan ke jadual.');
            }
        }

        $oldA = $target['team_a_id'] === null ? null : (int) $target['team_a_id'];
        $oldB = $target['team_b_id'] === null ? null : (int) $target['team_b_id'];
        $changes = [
            $matchId => ['old_a' => $oldA, 'old_b' => $oldB, 'new_a' => $desiredA, 'new_b' => $desiredB],
        ];
        $findOther = $pdo->prepare('
            SELECT id, team_a_id, team_b_id
            FROM cl_matches
            WHERE id != ? AND status IN ("up_next", "live") AND (team_a_id = ? OR team_b_id = ?)
            ORDER BY COALESCE(match_time, "2999-12-31") ASC, id ASC
            LIMIT 1 FOR UPDATE
        ');
        foreach ([[$desiredA, $oldA], [$desiredB, $oldB]] as [$incomingTeam, $displacedTeam]) {
            if ($incomingTeam === null || $incomingTeam === $oldA || $incomingTeam === $oldB) {
                continue;
            }
            $findOther->execute([$matchId, $incomingTeam, $incomingTeam]);
            $other = $findOther->fetch();
            if (!$other) {
                continue;
            }
            $otherId = (int) $other['id'];
            if (!isset($changes[$otherId])) {
                $otherA = $other['team_a_id'] === null ? null : (int) $other['team_a_id'];
                $otherB = $other['team_b_id'] === null ? null : (int) $other['team_b_id'];
                $changes[$otherId] = ['old_a' => $otherA, 'old_b' => $otherB, 'new_a' => $otherA, 'new_b' => $otherB];
            }
            if ($changes[$otherId]['new_a'] === $incomingTeam) {
                $changes[$otherId]['new_a'] = $displacedTeam;
            } elseif ($changes[$otherId]['new_b'] === $incomingTeam) {
                $changes[$otherId]['new_b'] = $displacedTeam;
            }
        }

        $updateMatch = $pdo->prepare('
            UPDATE cl_matches
            SET team_a_id = ?, team_b_id = ?, status = "up_next",
                team_a_point = NULL, team_b_point = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $findRoom = $pdo->prepare('
            SELECT id FROM cl_rooms
            WHERE room_type = "match" AND team_a_id <=> ? AND team_b_id <=> ?
            ORDER BY id DESC LIMIT 1 FOR UPDATE
        ');
        $updateRoom = $pdo->prepare('
            UPDATE cl_rooms
            SET team_a_id = ?, team_b_id = ?, status = "open", updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        foreach ($changes as $changedMatchId => $change) {
            $updateMatch->execute([$change['new_a'], $change['new_b'], $changedMatchId]);
            $findRoom->execute([$change['old_a'], $change['old_b']]);
            $roomId = (int) ($findRoom->fetchColumn() ?: 0);
            if ($roomId > 0) {
                $updateRoom->execute([$change['new_a'], $change['new_b'], $roomId]);
            }
        }
        $changedIds = array_map('intval', array_keys($changes));
        $placeholders = implode(',', array_fill(0, count($changedIds), '?'));
        $pdo->prepare("DELETE FROM cl_match_results WHERE match_id IN ($placeholders)")->execute($changedIds);
        $pdo->prepare("DELETE FROM cl_match_attendance WHERE match_id IN ($placeholders)")->execute($changedIds);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        json_response(['ok' => false, 'message' => $error->getMessage()], 409);
    }

    json_response(get_state($pdo) + [
        'message' => count($changes) > 1
            ? 'Team berjaya diswap antara jadual. Point dan kehadiran match terlibat telah direset.'
            : 'Peserta match berjaya dikemas kini. Point dan kehadiran telah direset.',
    ]);
}

function normalize_match_point($value): int
{
    $point = trim((string) $value);
    if ($point === '' || !preg_match('/^\d{1,2}$/', $point)) {
        json_response(['ok' => false, 'message' => 'Point match tidak valid.'], 422);
    }
    return max(0, min(99, (int) $point));
}

function auto_seed_next_match(PDO $pdo): void
{
    $stmt = $pdo->query('
        SELECT
            m.id,
            CASE
                WHEN m.team_a_point > m.team_b_point THEN m.team_a_id
                WHEN m.team_b_point > m.team_a_point THEN m.team_b_id
                ELSE NULL
            END AS winner_id
        FROM cl_matches m
        WHERE m.status = "completed"
          AND m.team_a_point IS NOT NULL
          AND m.team_b_point IS NOT NULL
          AND m.team_a_point != m.team_b_point
        ORDER BY COALESCE(m.match_time, m.created_at) ASC, m.id ASC
    ');
    $winners = [];
    foreach ($stmt->fetchAll() as $row) {
        $winnerId = (int) ($row['winner_id'] ?? 0);
        if ($winnerId > 0) {
            $winners[$winnerId] = $winnerId;
        }
    }
    if (count($winners) < 2) {
        return;
    }

    $activeStmt = $pdo->query('
        SELECT team_a_id, team_b_id
        FROM cl_matches
        WHERE status IN ("up_next", "live")
    ');
    foreach ($activeStmt->fetchAll() as $row) {
        unset($winners[(int) ($row['team_a_id'] ?? 0)], $winners[(int) ($row['team_b_id'] ?? 0)]);
    }

    $winnerIds = array_values($winners);
    if (count($winnerIds) < 2) {
        return;
    }
    shuffle($winnerIds);

    $teamA = (int) $winnerIds[0];
    $teamB = (int) $winnerIds[1];
    $slot = next_auto_match_slot($pdo);

    $exists = $pdo->prepare('
        SELECT COUNT(*)
        FROM cl_matches
        WHERE status != "hidden"
          AND ((team_a_id = ? AND team_b_id = ?) OR (team_a_id = ? AND team_b_id = ?))
    ');
    $exists->execute([$teamA, $teamB, $teamB, $teamA]);
    if ((int) $exists->fetchColumn() > 0) {
        return;
    }

    $matchStmt = $pdo->prepare('
        INSERT INTO cl_matches (team_a_id, team_b_id, match_name, match_time, status, updated_at)
        VALUES (?, ?, ?, ?, "up_next", CURRENT_TIMESTAMP)
    ');
    $matchStmt->execute([$teamA, $teamB, $slot['name'], $slot['time']]);

    $roomStmt = $pdo->prepare('
        INSERT INTO cl_rooms (room_type, team_a_id, team_b_id, status, updated_at)
        VALUES ("match", ?, ?, "open", CURRENT_TIMESTAMP)
    ');
    $roomStmt->execute([$teamA, $teamB]);
}

function next_auto_match_slot(PDO $pdo): array
{
    $slots = clash_timeline_match_slots();
    $count = (int) $pdo->query('SELECT COUNT(*) FROM cl_matches WHERE status != "hidden"')->fetchColumn();
    $slot = $slots[min($count, count($slots) - 1)];
    return $slot;
}

function clash_timeline_match_slots(): array
{
    return [
        ['name' => 'Qualifier 1 / Fasa 1 / BO3', 'time' => '2026-08-05 21:30:00'],
        ['name' => 'Qualifier 1 / Fasa 2 / BO3', 'time' => '2026-08-07 21:30:00'],
        ['name' => 'Qualifier 1 / Fasa 3 / BO3', 'time' => '2026-08-09 21:30:00'],
        ['name' => 'Qualifier 1 / Fasa 4 / BO3', 'time' => '2026-08-11 21:30:00'],
        ['name' => 'Fasa Qualifier', 'time' => '2026-08-13 21:30:00'],
        ['name' => 'Group Stage A', 'time' => '2026-08-20 21:00:00'],
        ['name' => 'Group Stage B', 'time' => '2026-08-21 21:00:00'],
        ['name' => 'Group Stage C', 'time' => '2026-08-22 21:00:00'],
        ['name' => 'Group Stage D', 'time' => '2026-08-23 21:00:00'],
        ['name' => 'Penentuan Slot Knockout Stage', 'time' => '2026-08-25 23:30:00'],
        ['name' => 'Knockout Stage M1 - M2', 'time' => '2026-08-26 22:00:00'],
        ['name' => 'Knockout Stage M3 - M4', 'time' => '2026-08-27 22:00:00'],
        ['name' => 'Knockout Stage M5 - M6', 'time' => '2026-08-29 22:00:00'],
        ['name' => 'Knockout Stage UBF', 'time' => '2026-09-01 22:00:00'],
        ['name' => 'Lower Bracket M7 - M8', 'time' => '2026-09-03 22:00:00'],
        ['name' => 'Lower Bracket M9 - M10', 'time' => '2026-09-05 22:00:00'],
        ['name' => 'Lower Bracket M11', 'time' => '2026-09-07 22:00:00'],
        ['name' => 'Lower Bracket LBF', 'time' => '2026-09-09 22:00:00'],
        ['name' => 'Grandfinal Match', 'time' => '2026-09-11 22:00:00'],
    ];
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
        session_regenerate_id(true);
        $_SESSION['cl_admin_id'] = (int) $admin['id'];
        unset($_SESSION['cl_team_id']);
        $deviceLoginToken = issue_persistent_login($pdo, 'admin', null, (int) $admin['id']);
        json_response(get_state($pdo) + [
            'message' => 'Admin login berjaya.',
            'device_login_token' => $deviceLoginToken,
        ]);
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
    $chatToken = bin2hex(random_bytes(32));
    $tokenStmt = $pdo->prepare('UPDATE cl_teams SET chat_token_hash = ? WHERE id = ?');
    $tokenStmt->execute([hash('sha256', $chatToken), (int) $team['id']]);
    if ((string) $team['status'] === 'pending') {
        json_response(get_state($pdo) + [
            'message' => 'Slot masih menunggu admin. Ruang Pertanyaan / Question sudah dibuka.',
            'chat_token' => $chatToken,
            'chat_only' => true,
        ]);
    }
    if ((string) $team['status'] !== 'accepted') {
        json_response(['ok' => false, 'message' => 'Pendaftaran team tidak aktif. Hubungi admin untuk semakan.'], 403);
    }

    session_regenerate_id(true);
    $_SESSION['cl_team_id'] = (int) $team['id'];
    unset($_SESSION['cl_admin_id']);
    $deviceLoginToken = issue_persistent_login($pdo, 'team', (int) $team['id'], null);
    get_or_create_admin_room($pdo, (int) $team['id']);
    json_response(get_state($pdo) + [
        'message' => 'Login team berjaya.',
        'chat_token' => $chatToken,
        'device_login_token' => $deviceLoginToken,
    ]);
}

function change_team_password(PDO $pdo): void
{
    $admin = current_admin($pdo);
    if (!$admin) {
        json_response(['ok' => false, 'message' => 'Admin login diperlukan.'], 401);
    }

    $teamId = (int) ($_POST['team_id'] ?? 0);
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($teamId <= 0) {
        json_response(['ok' => false, 'message' => 'Team tidak valid.'], 422);
    }
    if (!is_valid_team_password($newPassword)) {
        json_response(['ok' => false, 'message' => 'Password mesti mempunyai sekurang-kurangnya 1 huruf besar, 1 nombor dan 1 simbol.'], 422);
    }
    if ($newPassword !== $confirmPassword) {
        json_response(['ok' => false, 'message' => 'Pengesahan password baru tidak sama.'], 422);
    }

    $stmt = $pdo->prepare('SELECT team_name FROM cl_teams WHERE id = ? AND status != "removed" LIMIT 1');
    $stmt->execute([$teamId]);
    $teamName = (string) ($stmt->fetchColumn() ?: '');
    if ($teamName === '') {
        json_response(['ok' => false, 'message' => 'Team tidak dijumpai.'], 404);
    }

    $stmt = $pdo->prepare('UPDATE cl_teams SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $teamId]);

    $stmt = $pdo->prepare('DELETE FROM cl_login_tokens WHERE team_id = ?');
    $stmt->execute([$teamId]);

    json_response(get_state($pdo) + ['message' => 'Password ' . $teamName . ' berjaya ditukar.']);
}

function normalize_team_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($digits, '60')) {
        $digits = '0' . substr($digits, 2);
    }
    return $digits;
}

function password_request_key(array $dbConfig): string
{
    return hash(
        'sha256',
        'gnex-clash-password-request|' . (string) ($dbConfig['database'] ?? '') . '|' . (string) ($dbConfig['password'] ?? ''),
        true
    );
}

function encrypt_requested_password(string $password, array $dbConfig): string
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL diperlukan untuk lindungi password request.');
    }
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $password,
        'aes-256-gcm',
        password_request_key($dbConfig),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    if ($ciphertext === false) {
        throw new RuntimeException('Password request gagal diencrypt.');
    }
    return base64_encode($iv . $tag . $ciphertext);
}

function decrypt_requested_password(string $payload, array $dbConfig): string
{
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 29 || !function_exists('openssl_decrypt')) {
        return '';
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $password = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        password_request_key($dbConfig),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    return $password === false ? '' : $password;
}

function request_password_change(PDO $pdo, array $dbConfig): void
{
    $submittedPhone = clean_text($_POST['phone'] ?? '', 40);
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $normalizedPhone = normalize_team_phone($submittedPhone);
    if (strlen($normalizedPhone) < 9) {
        json_response(['ok' => false, 'message' => 'Masukkan nombor telefon team yang sah.'], 422);
    }
    if (!is_valid_team_password($newPassword)) {
        json_response(['ok' => false, 'message' => 'Password mesti mempunyai sekurang-kurangnya 1 huruf besar, 1 nombor dan 1 simbol.'], 422);
    }

    $stmt = $pdo->query('SELECT id, team_name, phone FROM cl_teams WHERE status != "removed"');
    $matches = array_values(array_filter($stmt->fetchAll(), static function (array $team) use ($normalizedPhone): bool {
        return normalize_team_phone((string) ($team['phone'] ?? '')) === $normalizedPhone;
    }));
    if (count($matches) !== 1) {
        json_response([
            'ok' => false,
            'message' => count($matches) > 1
                ? 'Nombor ini digunakan oleh lebih daripada satu team. Hubungi admin untuk semakan.'
                : 'Nombor telefon tidak sepadan dengan mana-mana team berdaftar.',
        ], 422);
    }

    $team = $matches[0];
    $teamId = (int) $team['id'];
    $cipher = encrypt_requested_password($newPassword, $dbConfig);
    $pdo->beginTransaction();
    try {
        $cancel = $pdo->prepare('
            UPDATE cl_password_change_requests
            SET status = "rejected", password_cipher = NULL, reviewed_at = CURRENT_TIMESTAMP
            WHERE team_id = ? AND status = "pending"
        ');
        $cancel->execute([$teamId]);
        $insert = $pdo->prepare('
            INSERT INTO cl_password_change_requests
                (team_id, registered_phone_snapshot, submitted_phone, password_cipher, status)
            VALUES (?, ?, ?, ?, "pending")
        ');
        $insert->execute([$teamId, (string) $team['phone'], $submittedPhone, $cipher]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    json_response([
        'ok' => true,
        'message' => 'Berjaya menghantar request, sila tunggu admin sahkan pertukaran pass team anda.',
    ]);
}

function get_password_change_requests(PDO $pdo, ?array $admin, array $dbConfig): array
{
    if (!$admin) {
        return [];
    }
    $stmt = $pdo->query('
        SELECT r.id, r.team_id, r.registered_phone_snapshot, r.submitted_phone, r.password_cipher, r.created_at,
               t.team_name, t.phone AS current_registered_phone
        FROM cl_password_change_requests r
        INNER JOIN cl_teams t ON t.id = r.team_id
        WHERE r.status = "pending"
        ORDER BY r.created_at ASC, r.id ASC
    ');
    $requests = [];
    foreach ($stmt->fetchAll() as $request) {
        $registeredPhone = (string) ($request['current_registered_phone'] ?: $request['registered_phone_snapshot']);
        $submittedPhone = (string) $request['submitted_phone'];
        $requests[] = [
            'id' => (int) $request['id'],
            'team_id' => (int) $request['team_id'],
            'team_name' => (string) $request['team_name'],
            'registered_phone' => $registeredPhone,
            'submitted_phone' => $submittedPhone,
            'phone_matches' => normalize_team_phone($registeredPhone) === normalize_team_phone($submittedPhone),
            'new_password' => decrypt_requested_password((string) ($request['password_cipher'] ?? ''), $dbConfig),
            'created_at' => (string) $request['created_at'],
        ];
    }
    return $requests;
}

function review_password_change(PDO $pdo, array $dbConfig): void
{
    global $pushConfig;

    $admin = current_admin($pdo);
    if (!$admin) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan.'], 401);
    }
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $decision = clean_text($_POST['decision'] ?? '', 20);
    if ($requestId <= 0 || !in_array($decision, ['approved', 'rejected'], true)) {
        json_response(['ok' => false, 'message' => 'Keputusan request tidak valid.'], 422);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            SELECT id, team_id, password_cipher
            FROM cl_password_change_requests
            WHERE id = ? AND status = "pending"
            LIMIT 1
            FOR UPDATE
        ');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        if (!$request) {
            $pdo->rollBack();
            json_response(['ok' => false, 'message' => 'Request sudah diproses atau tidak dijumpai.'], 404);
        }

        $teamId = (int) $request['team_id'];
        if ($decision === 'approved') {
            $newPassword = decrypt_requested_password((string) $request['password_cipher'], $dbConfig);
            if ($newPassword === '') {
                throw new RuntimeException('Password request gagal dibaca. Minta team hantar request baharu.');
            }
            $updateTeam = $pdo->prepare('
                UPDATE cl_teams
                SET password_hash = ?, chat_token_hash = NULL, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ');
            $updateTeam->execute([password_hash($newPassword, PASSWORD_DEFAULT), $teamId]);
            $deleteTokens = $pdo->prepare('DELETE FROM cl_login_tokens WHERE team_id = ?');
            $deleteTokens->execute([$teamId]);
        }

        $updateRequest = $pdo->prepare('
            UPDATE cl_password_change_requests
            SET status = ?, reviewed_by_admin_id = ?, password_cipher = NULL, reviewed_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ');
        $updateRequest->execute([$decision, (int) $admin['id'], $requestId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    $pushSummary = null;
    if ($decision === 'approved') {
        queue_push_event(
            $pdo,
            'team',
            $teamId,
            null,
            'Password Team Disahkan',
            'Pertukaran password team anda telah disahkan. Sila login menggunakan password baru.',
            'clash-league.html#login',
            'clash-password-approved-' . $requestId
        );
        $pushSummary = send_push_to_owner($pdo, $pushConfig, 'team', $teamId);
    }

    json_response(get_state($pdo) + [
        'message' => $decision === 'approved' ? 'Password baru telah diaktifkan.' : 'Request tukar password telah ditolak.',
        'push_summary' => $pushSummary,
    ]);
}

function set_team_check(PDO $pdo): void
{
    $admin = current_admin($pdo);
    if (!$admin) {
        json_response(['ok' => false, 'message' => 'Admin login diperlukan.'], 401);
    }

    $teamId = (int) ($_POST['team_id'] ?? 0);
    $checked = filter_var($_POST['checked'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($teamId <= 0) {
        json_response(['ok' => false, 'message' => 'Team tidak valid.'], 422);
    }

    $stmt = $pdo->prepare('UPDATE cl_teams SET admin_checked = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status != "removed"');
    $stmt->execute([$checked ? 1 : 0, $teamId]);
    if ($stmt->rowCount() === 0) {
        $existsStmt = $pdo->prepare('SELECT id FROM cl_teams WHERE id = ? AND status != "removed"');
        $existsStmt->execute([$teamId]);
        if (!$existsStmt->fetch()) {
            json_response(['ok' => false, 'message' => 'Team tidak dijumpai.'], 404);
        }
    }

    json_response(get_state($pdo) + ['message' => $checked ? 'Team ditanda.' : 'Tanda team dibuang.']);
}

function set_notification_check(PDO $pdo): void
{
    if (!current_admin($pdo)) {
        json_response(['ok' => false, 'message' => 'Admin login diperlukan.'], 401);
    }
    $teamId = (int) ($_POST['team_id'] ?? 0);
    $checked = filter_var($_POST['checked'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($teamId <= 0) {
        json_response(['ok' => false, 'message' => 'Team tidak valid.'], 422);
    }
    $stmt = $pdo->prepare('
        UPDATE cl_teams
        SET notification_checked = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ? AND status = "accepted"
    ');
    $stmt->execute([$checked ? 1 : 0, $teamId]);
    json_response(get_state($pdo) + ['message' => $checked ? 'Semakan noti ditanda.' : 'Tanda semakan noti dibuang.']);
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

    $loggedTeam = current_team($pdo);
    $team = $loggedTeam ?: current_chat_team($pdo);
    $admin = current_admin($pdo);
    $adminPushMode = $admin && (string) ($_POST['push_mode'] ?? 'none') === 'all' ? 'all' : 'none';
    $isGuest = !$team && !$admin;
    $guestName = $isGuest ? clean_text($_POST['guest_name'] ?? '', 60) : '';
    if ($isGuest && mb_strlen($guestName) < 2) {
        json_response(['ok' => false, 'message' => 'Isi nama anda sekurang-kurangnya 2 huruf.'], 422);
    }
    if ($isGuest) {
        $lastGuestMessage = (int) ($_SESSION['cl_guest_message_time'] ?? 0);
        if ($lastGuestMessage > 0 && time() - $lastGuestMessage < 3) {
            json_response(['ok' => false, 'message' => 'Tunggu 3 saat sebelum hantar mesej seterusnya.'], 429);
        }
    }

    $roomId = (int) ($_POST['room_id'] ?? 0);
    $replyToMessageId = (int) ($_POST['reply_to_message_id'] ?? 0);
    $actionTarget = $admin ? clean_text($_POST['action_target'] ?? '', 20) : '';
    if (!in_array($actionTarget, ['', 'jadual', 'rules', 'profile', 'all-team', 'deal'], true)) {
        json_response(['ok' => false, 'message' => 'Tag admin tidak valid.'], 422);
    }
    $message = clean_text($_POST['message'] ?? '', 700);
    if ($roomId <= 0 || $message === '') {
        json_response(['ok' => false, 'message' => 'Room dan mesej wajib isi.'], 422);
    }

    if ($isGuest) {
        $stmt = $pdo->prepare('SELECT id FROM cl_rooms WHERE id = ? AND room_type = "group" AND status = "open" LIMIT 1');
        $stmt->execute([$roomId]);
        if (!$stmt->fetch()) {
            json_response(['ok' => false, 'message' => 'Pelawat hanya boleh menggunakan ruang Pertanyaan / Question.'], 403);
        }
    } elseif (!$admin) {
        $stmt = $pdo->prepare('
            SELECT id FROM cl_rooms
            WHERE id = ? AND status = "open"
              AND (room_type = "group" OR (? = 1 AND (team_a_id = ? OR team_b_id = ?)))
        ');
        $stmt->execute([$roomId, $loggedTeam ? 1 : 0, (int) $team['id'], (int) $team['id']]);
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
    $replyTarget = null;
    if ($replyToMessageId > 0) {
        $replyStmt = $pdo->prepare('
            SELECT m.id, m.sender_type, m.sender_team_id, m.guest_name, t.team_name
            FROM cl_messages m
            LEFT JOIN cl_teams t ON t.id = m.sender_team_id
            WHERE m.id = ? AND m.room_id = ?
            LIMIT 1
        ');
        $replyStmt->execute([$replyToMessageId, $roomId]);
        $replyTarget = $replyStmt->fetch();
        if (!$replyTarget) {
            json_response(['ok' => false, 'message' => 'Mesej reply tidak dijumpai dalam room ini.'], 422);
        }
    }

    $senderType = $admin ? 'admin' : ($team ? 'team' : 'guest');
    $senderTeamId = $team ? (int) $team['id'] : null;
    $stmt = $pdo->prepare('
        INSERT INTO cl_messages (room_id, sender_type, sender_team_id, guest_name, reply_to_message_id, action_target, message)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$roomId, $senderType, $senderTeamId, $isGuest ? $guestName : null, $replyToMessageId ?: null, $actionTarget ?: null, $message]);
    if ($isGuest) {
        $_SESSION['cl_guest_message_time'] = time();
    }

    $stmt = $pdo->prepare('UPDATE cl_rooms SET updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$roomId]);

    $shortMessage = clean_text($message, 120);
    $pushSummary = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'statuses' => []];
    $isGroupRoom = (string) $room['room_type'] === 'group';
    if ($admin) {
        if ($adminPushMode === 'all') {
            $teamStmt = $pdo->query('SELECT DISTINCT team_id FROM cl_push_subscriptions WHERE owner_type = "team" AND team_id IS NOT NULL');
            foreach ($teamStmt->fetchAll() as $teamRow) {
                $targetTeamId = (int) $teamRow['team_id'];
                queue_push_event($pdo, 'team', $targetTeamId, null, 'INFO PENTING CLASH LEAGUE', 'Admin: ' . $shortMessage, 'clash-league.html#deal', 'clash-important-' . $roomId);
                $pushSummary = merge_push_summary($pushSummary, send_push_to_owner($pdo, $pushConfig, 'team', $targetTeamId));
            }
        } elseif (!$isGroupRoom) {
            $targetIds = array_values(array_unique(array_filter([
                (int) ($room['team_a_id'] ?? 0),
                (int) ($room['team_b_id'] ?? 0),
            ])));
            foreach ($targetIds as $targetTeamId) {
                queue_push_event(
                    $pdo,
                    'team',
                    $targetTeamId,
                    null,
                    'Mesej Admin Clash League',
                    'Admin: ' . $shortMessage,
                    'clash-league.html#deal',
                    'clash-personal-' . $roomId . '-' . $targetTeamId
                );
                $pushSummary = merge_push_summary(
                    $pushSummary,
                    send_push_to_owner($pdo, $pushConfig, 'team', $targetTeamId)
                );
            }
        }
    } elseif ($team) {
        $adminStmt = $pdo->query('SELECT DISTINCT admin_id FROM cl_push_subscriptions WHERE owner_type = "admin" AND admin_id IS NOT NULL');
        foreach ($adminStmt->fetchAll() as $adminRow) {
            $adminId = (int) $adminRow['admin_id'];
            queue_push_event($pdo, 'admin', null, $adminId, 'Mesej Clash League', ((string) $team['team_name']) . ': ' . $shortMessage, 'clash-league.html#deal', 'clash-chat-' . $roomId);
            $pushSummary = merge_push_summary($pushSummary, send_push_to_owner($pdo, $pushConfig, 'admin', null, $adminId));
        }
    } else {
        $adminStmt = $pdo->query('SELECT DISTINCT admin_id FROM cl_push_subscriptions WHERE owner_type = "admin" AND admin_id IS NOT NULL');
        foreach ($adminStmt->fetchAll() as $adminRow) {
            $adminId = (int) $adminRow['admin_id'];
            queue_push_event($pdo, 'admin', null, $adminId, 'Pertanyaan baru Clash League', $guestName . ': ' . $shortMessage, 'clash-league.html#deal', 'clash-guest-' . $roomId);
            $pushSummary = merge_push_summary($pushSummary, send_push_to_owner($pdo, $pushConfig, 'admin', null, $adminId));
        }
    }

    $replyTargetTeamId = $replyTarget && (string) ($replyTarget['sender_type'] ?? '') === 'team'
        ? (int) ($replyTarget['sender_team_id'] ?? 0)
        : 0;
    $currentSenderTeamId = $team ? (int) $team['id'] : 0;
    if (
        !$admin
        && $isGroupRoom
        && $replyToMessageId > 0
        && $replyTargetTeamId > 0
        && $replyTargetTeamId !== $currentSenderTeamId
    ) {
        $replySenderName = $team ? (string) $team['team_name'] : $guestName;
        queue_push_event(
            $pdo,
            'team',
            $replyTargetTeamId,
            null,
            'Balasan Pertanyaan Clash League',
            $replySenderName . ': ' . $shortMessage,
            'clash-league.html#deal',
            'clash-reply-' . $roomId . '-' . $replyTargetTeamId
        );
        $pushSummary = merge_push_summary(
            $pushSummary,
            send_push_to_owner($pdo, $pushConfig, 'team', $replyTargetTeamId)
        );
    }

    json_response(get_state($pdo) + ['message' => 'Mesej dihantar.', 'push_summary' => $pushSummary]);
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
        SELECT id, team_a_id, team_b_id, match_time
        FROM cl_matches
        WHERE id = ? AND status NOT IN ("hidden", "completed") AND (team_a_id = ? OR team_b_id = ?)
        LIMIT 1
    ');
    $stmt->execute([$matchId, $team['id'], $team['id']]);
    $match = $stmt->fetch();
    if (!$match) {
        json_response(['ok' => false, 'message' => 'Match tidak boleh disubmit, sudah completed, atau bukan jadual team anda.'], 403);
    }
    $matchTime = trim((string) ($match['match_time'] ?? ''));
    if ($matchTime === '') {
        json_response(['ok' => false, 'message' => 'Update point belum dibuka kerana masa match belum ditetapkan.'], 403);
    }
    $matchStartsAt = new DateTimeImmutable($matchTime, new DateTimeZone('Asia/Kuala_Lumpur'));
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur'));
    if ($now < $matchStartsAt) {
        json_response([
            'ok' => false,
            'message' => 'Update point hanya dibuka selepas match bermula pada ' . $matchStartsAt->format('d/m/Y, h:i A') . '.',
        ], 403);
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

function save_pinned_info(PDO $pdo): void
{
    $admin = current_admin($pdo);
    if (!$admin) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan untuk edit pinned info.'], 401);
    }

    $pinnedInfo = clean_text($_POST['pinned_info'] ?? '', 500);
    $stmt = $pdo->prepare('
        INSERT INTO cl_settings (setting_key, setting_value, updated_at)
        VALUES ("pinned_info", ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([$pinnedInfo]);
    $version = $pinnedInfo === '' ? '' : hash('sha256', $pinnedInfo . '|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX));
    $versionStmt = $pdo->prepare('
        INSERT INTO cl_settings (setting_key, setting_value, updated_at)
        VALUES ("pinned_info_version", ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP
    ');
    $versionStmt->execute([$version]);
    $actionStmt = $pdo->prepare('
        INSERT INTO cl_settings (setting_key, setting_value, updated_at)
        VALUES ("pinned_info_action_target", "", CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE setting_value = "", updated_at = CURRENT_TIMESTAMP
    ');
    $actionStmt->execute();

    json_response(get_state($pdo) + [
        'message' => $pinnedInfo === '' ? 'Pinned info telah dikosongkan.' : 'Pinned info berjaya dikemas kini.',
    ]);
}

function pin_chat_message(PDO $pdo): void
{
    if (!current_admin($pdo)) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan.'], 401);
    }
    $messageId = (int) ($_POST['message_id'] ?? 0);
    $stmt = $pdo->prepare('
        SELECT m.message, m.action_target, r.room_type
        FROM cl_messages m
        INNER JOIN cl_rooms r ON r.id = m.room_id
        WHERE m.id = ? LIMIT 1
    ');
    $stmt->execute([$messageId]);
    $message = $stmt->fetch();
    if (!$message) {
        json_response(['ok' => false, 'message' => 'Mesej tidak dijumpai.'], 404);
    }
    if ((string) $message['room_type'] !== 'group') {
        json_response(['ok' => false, 'message' => 'Pin mesej kini hanya untuk room Pertanyaan / Question.'], 422);
    }
    $pinnedInfo = clean_text((string) $message['message'], 500);
    $stmt = $pdo->prepare('
        INSERT INTO cl_settings (setting_key, setting_value, updated_at)
        VALUES ("pinned_info", ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([$pinnedInfo]);
    $version = hash('sha256', $messageId . '|' . $pinnedInfo . '|' . microtime(true));
    $versionStmt = $pdo->prepare('
        INSERT INTO cl_settings (setting_key, setting_value, updated_at)
        VALUES ("pinned_info_version", ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP
    ');
    $versionStmt->execute([$version]);
    $actionTarget = in_array((string) ($message['action_target'] ?? ''), ['jadual', 'rules', 'profile', 'all-team', 'deal'], true)
        ? (string) $message['action_target']
        : '';
    $actionStmt = $pdo->prepare('
        INSERT INTO cl_settings (setting_key, setting_value, updated_at)
        VALUES ("pinned_info_action_target", ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP
    ');
    $actionStmt->execute([$actionTarget]);
    json_response(get_state($pdo) + ['message' => 'Mesej berjaya dipin sebagai INFO SEMASA.']);
}

function acknowledge_pinned_info(PDO $pdo): void
{
    $team = current_team($pdo) ?: current_chat_team($pdo);
    if (!$team || (string) ($team['status'] ?? '') !== 'accepted') {
        json_response(['ok' => false, 'message' => 'Login team diperlukan untuk sahkan penerimaan.'], 401);
    }
    $version = get_pinned_info_version($pdo);
    if ($version === '' || get_pinned_info($pdo) === '') {
        json_response(['ok' => false, 'message' => 'Tiada info semasa untuk disahkan.'], 422);
    }
    $stmt = $pdo->prepare('
        INSERT INTO cl_pinned_info_acknowledgements (pinned_version, team_id)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE acknowledged_at = acknowledged_at
    ');
    $stmt->execute([$version, (int) $team['id']]);
    json_response(get_state($pdo) + ['message' => 'Penerimaan info semasa telah direkodkan.']);
}

function repush_chat_message(PDO $pdo): void
{
    global $pushConfig;
    if (!current_admin($pdo)) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan.'], 401);
    }
    $messageId = (int) ($_POST['message_id'] ?? 0);
    $stmt = $pdo->prepare('
        SELECT m.message, r.id AS room_id, r.room_type, r.team_a_id, r.team_b_id
        FROM cl_messages m
        INNER JOIN cl_rooms r ON r.id = m.room_id
        WHERE m.id = ? LIMIT 1
    ');
    $stmt->execute([$messageId]);
    $message = $stmt->fetch();
    if (!$message) {
        json_response(['ok' => false, 'message' => 'Mesej tidak dijumpai.'], 404);
    }

    if ((string) $message['room_type'] === 'group') {
        $teamStmt = $pdo->query('
            SELECT DISTINCT ps.team_id
            FROM cl_push_subscriptions ps
            INNER JOIN cl_teams t ON t.id = ps.team_id
            WHERE ps.owner_type = "team" AND ps.team_id IS NOT NULL AND t.status = "accepted" AND t.is_test_account = 0
        ');
        $targetIds = array_map(static fn(array $row): int => (int) $row['team_id'], $teamStmt->fetchAll());
        $title = 'INFO PENTING CLASH LEAGUE';
    } else {
        $targetIds = array_values(array_unique(array_filter([
            (int) ($message['team_a_id'] ?? 0),
            (int) ($message['team_b_id'] ?? 0),
        ])));
        $title = 'Mesej Admin Clash League';
    }
    $body = 'Admin: ' . clean_text((string) $message['message'], 120);
    $summary = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'statuses' => []];
    foreach ($targetIds as $teamId) {
        queue_push_event(
            $pdo, 'team', $teamId, null, $title, $body, 'clash-league.html#deal',
            'clash-repush-' . $messageId . '-' . $teamId . '-' . time()
        );
        $summary = merge_push_summary($summary, send_push_to_owner($pdo, $pushConfig, 'team', $teamId));
    }
    json_response(get_state($pdo) + [
        'message' => 'Push noti dihantar semula kepada ' . count($targetIds) . ' team.',
        'push_summary' => $summary,
    ]);
}
