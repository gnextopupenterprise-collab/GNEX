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
$passwordConfigPath = $rootDir . DIRECTORY_SEPARATOR . 'scrim-password-config.php';
if (is_file($passwordConfigPath)) {
    $passwordConfig = require $passwordConfigPath;
    if (is_array($passwordConfig)) {
        $dbConfig = array_merge($dbConfig, $passwordConfig);
    }
}
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

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

// Restore the saved identity before running schema maintenance, seed jobs and
// tournament queries. The home screen only needs this small response to stop
// showing LOGIN; the full state continues loading separately afterward.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'authState') {
    $authTeam = current_team($pdo);
    $authAdmin = current_admin($pdo);
    refresh_persistent_login($pdo);
    $authChatTeam = $authTeam ?: current_chat_team($pdo);
    $authDeviceToken = ($authTeam || $authAdmin) ? device_login_token_from_request() : '';
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    json_response([
        'ok' => true,
        'team' => $authTeam,
        'admin' => $authAdmin,
        'chat_team' => $authChatTeam,
        'viewing_as_team' => !empty($_SESSION['cl_admin_view_as_team']),
        'device_login_token' => $authDeviceToken !== '' ? $authDeviceToken : null,
    ]);
}

ensure_schema_once($pdo, $rootDir, (string) ($dbConfig['database'] ?? 'clash'));
ensure_live_schema_compatibility($pdo);
seed_admin($pdo, $dbConfig);
seed_stock_worker($pdo);
seed_allocation_user($pdo);
repair_shared_admin_unread_history_once($pdo);
seed_web_tester($pdo);
seed_web_test_rival_match($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'teams') {
    json_response(['ok' => true, 'teams' => get_public_teams($pdo)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'state') {
    json_response(get_state($pdo));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'resumeOrderRecord') {
    $resumedOrderAdmin = current_admin($pdo);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Location: ' . ($resumedOrderAdmin ? '../order-record/' : '../order-record/?resume=1'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'orderRecords') {
    get_order_records($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'orderAccess') {
    $orderAccessAdmin=current_admin($pdo);
    if (!$orderAccessAdmin) json_response(['ok'=>false,'message'=>'Login admin diperlukan.'],401);
    json_response(['ok'=>true,'user'=>$orderAccessAdmin]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'orderSimAccounts') {
    get_order_sim_accounts($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'orderMoney') {
    get_order_money($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'orderTodo') {
    get_order_todo($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'orderBalances') {
    get_order_balances($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'orderStock') {
    get_order_stock($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'orderSheetData') {
    get_order_sheet_data($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'chatUpdates') {
    json_response(get_chat_updates($pdo));
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

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'groupStageAdmin') {
    group_stage_admin_data($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'registerTeam') {
    register_team($pdo, $rootDir);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'adminCreateTeam') {
    admin_create_team($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveGroupStageFixture') {
    save_group_stage_fixture($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'createOrderRecord') {
    create_order_record($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'setOrderRecordStatus') {
    set_order_record_status($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveOrderCodeRule') {
    save_order_code_rule($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveOrderBalances') {
    save_order_balances($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveOrderStock') {
    save_order_stock($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveOrderSimAccounts') {
    save_order_sim_accounts($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveProcessedSimBalance') {
    save_processed_sim_balance($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveOrderMoney') {
    save_order_money($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveOrderTodoTask') {
    save_order_todo_task($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'toggleOrderTodo') {
    toggle_order_todo($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'deleteOrderTodoTask') {
    delete_order_todo_task($pdo);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'createManualMatch') {
    create_manual_match($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'deleteMatch') {
    delete_match($pdo);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveQualifierFinalEntry') {
    save_qualifier_final_entry($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'updateMatchTeams') {
    update_match_teams($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    login_user($pdo, $dbConfig);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'adminViewAsTeam') {
    admin_view_as_team($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'orderRecordLogin') {
    login_order_record_admin($pdo);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'setRoomAdminCheck') {
    set_room_admin_check($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'setRoomReportMatch') {
    set_room_report_match($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'confirmMatchAttendance') {
    confirm_match_attendance($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'openMatchRoomNow') {
    open_match_room_now($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cancelMatchAttendance') {
    cancel_match_attendance($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'logout') {
    if (!empty($_SESSION['cl_admin_view_as_team']) && !empty($_SESSION['cl_admin_id'])) {
        unset($_SESSION['cl_team_id'], $_SESSION['cl_admin_view_as_team']);
        json_response(get_state($pdo) + [
            'message' => 'Kembali ke akaun admin.',
            'returned_to_admin' => true,
        ]);
    }
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reactMessage') {
    react_message($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'deleteMessage') {
    delete_chat_message($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'editMessage') {
    edit_chat_message($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'submitResult') {
    submit_result($pdo, $rootDir);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveRules') {
    save_rules($pdo);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveTimeline') {
    save_timeline($pdo);
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
            admin_added TINYINT(1) NOT NULL DEFAULT 0,
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
    if (!column_exists($pdo, 'cl_teams', 'profile_update_required')) {
        $pdo->exec('ALTER TABLE cl_teams ADD COLUMN profile_update_required TINYINT(1) NOT NULL DEFAULT 0 AFTER is_test_account');
    }
    if (!column_exists($pdo, 'cl_teams', 'admin_added')) {
        $pdo->exec('ALTER TABLE cl_teams ADD COLUMN admin_added TINYINT(1) NOT NULL DEFAULT 0 AFTER is_test_account');
        $pdo->exec('UPDATE cl_teams SET admin_added=1 WHERE is_test_account=0 AND admin_checked=1 AND profile_update_required=1 AND status="accepted"');
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
        CREATE TABLE IF NOT EXISTS cl_order_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            game_id VARCHAR(80) NOT NULL,
            carrier VARCHAR(30) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            process_code VARCHAR(80) NOT NULL,
            raw_message TEXT NOT NULL,
            status ENUM('queued','processed','cancelled') NOT NULL DEFAULT 'queued',
            created_by_admin_id INT NOT NULL,
            processed_by_admin_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME NULL,
            INDEX idx_cl_order_status_created (status,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_order_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message TEXT NOT NULL,
            status ENUM('normal','processed','pending','unrecorded') NOT NULL DEFAULT 'normal',
            created_by_admin_id INT NOT NULL,
            updated_by_admin_id INT NULL,
            paired_message_id INT NULL,
            process_code VARCHAR(80) NULL,
            sheet_sync_status VARCHAR(20) NOT NULL DEFAULT 'none',
            sheet_row INT NULL,
            sheet_synced_at DATETIME NULL,
            sheet_error VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            INDEX idx_cl_order_message_status (status,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_order_code_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_key VARCHAR(500) NOT NULL,
            raw_example TEXT NOT NULL,
            process_code VARCHAR(80) NOT NULL,
            created_by_admin_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_order_code_message (message_key),
            FOREIGN KEY (created_by_admin_id) REFERENCES cl_admin_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $orderMessageColumns = [
        'paired_message_id' => 'INT NULL AFTER updated_by_admin_id',
        'process_code' => 'VARCHAR(80) NULL AFTER paired_message_id',
        'sheet_sync_status' => 'VARCHAR(20) NOT NULL DEFAULT "none" AFTER process_code',
        'sheet_row' => 'INT NULL AFTER sheet_sync_status',
        'sheet_synced_at' => 'DATETIME NULL AFTER sheet_row',
        'sheet_error' => 'VARCHAR(500) NULL AFTER sheet_synced_at',
    ];
    foreach ($orderMessageColumns as $columnName => $definition) {
        if (!column_exists($pdo, 'cl_order_messages', $columnName)) {
            $pdo->exec("ALTER TABLE cl_order_messages ADD COLUMN {$columnName} {$definition}");
        }
    }
    $orderMessageStatusColumn=$pdo->query("SHOW COLUMNS FROM cl_order_messages LIKE 'status'")->fetch();
    if (!$orderMessageStatusColumn || strpos((string)($orderMessageStatusColumn['Type'] ?? ''),"'unrecorded'") === false) {
        $pdo->exec("ALTER TABLE cl_order_messages MODIFY status ENUM('normal','processed','pending','unrecorded') NOT NULL DEFAULT 'normal'");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_rooms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_type ENUM('admin','deal','match','group','info') NOT NULL DEFAULT 'admin',
            team_a_id INT NULL,
            team_b_id INT NULL,
            match_id INT NULL,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            FOREIGN KEY (team_a_id) REFERENCES cl_teams(id) ON DELETE SET NULL,
            FOREIGN KEY (team_b_id) REFERENCES cl_teams(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $roomTypeColumn = $pdo->query("SHOW COLUMNS FROM cl_rooms LIKE 'room_type'")->fetch();
    if (!$roomTypeColumn || strpos((string) ($roomTypeColumn['Type'] ?? ''), "'info'") === false) {
        $pdo->exec("ALTER TABLE cl_rooms MODIFY room_type ENUM('admin','deal','match','group','info') NOT NULL DEFAULT 'admin'");
    }
    if (!column_exists($pdo, 'cl_rooms', 'admin_checked')) {
        $pdo->exec('ALTER TABLE cl_rooms ADD COLUMN admin_checked TINYINT(1) NOT NULL DEFAULT 0 AFTER status');
    }
    if (!column_exists($pdo, 'cl_rooms', 'report_match')) {
        $pdo->exec('ALTER TABLE cl_rooms ADD COLUMN report_match TINYINT(1) NOT NULL DEFAULT 0 AFTER admin_checked');
    }
    if (!column_exists($pdo, 'cl_rooms', 'match_id')) {
        $pdo->exec('ALTER TABLE cl_rooms ADD COLUMN match_id INT NULL AFTER team_b_id');
        $pdo->exec('ALTER TABLE cl_rooms ADD INDEX idx_cl_rooms_match_id (match_id)');
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
    if (!column_exists($pdo, 'cl_messages', 'image_url')) {
        $pdo->exec('ALTER TABLE cl_messages ADD COLUMN image_url VARCHAR(500) NULL AFTER message');
    }
    if (!column_exists($pdo, 'cl_messages', 'edited_at')) {
        $pdo->exec('ALTER TABLE cl_messages ADD COLUMN edited_at TIMESTAMP NULL DEFAULT NULL AFTER image_url');
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_message_reactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            team_id INT NOT NULL,
            emoji VARCHAR(12) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cl_message_team_reaction (message_id, team_id),
            FOREIGN KEY (message_id) REFERENCES cl_messages(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES cl_teams(id) ON DELETE CASCADE
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
    if (!column_exists($pdo, 'cl_matches', 'stage_code')) {
        $pdo->exec('ALTER TABLE cl_matches ADD COLUMN stage_code VARCHAR(64) NULL AFTER match_name');
        $pdo->exec('ALTER TABLE cl_matches ADD INDEX idx_cl_matches_stage_code (stage_code, status)');
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_match_advancements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source_match_id INT NOT NULL,
            winner_team_id INT NOT NULL,
            next_match_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cl_advancement_source (source_match_id),
            INDEX idx_cl_advancement_winner (winner_team_id, next_match_id),
            FOREIGN KEY (source_match_id) REFERENCES cl_matches(id) ON DELETE CASCADE,
            FOREIGN KEY (winner_team_id) REFERENCES cl_teams(id) ON DELETE CASCADE,
            FOREIGN KEY (next_match_id) REFERENCES cl_matches(id) ON DELETE SET NULL
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
        CREATE TABLE IF NOT EXISTS cl_qualifier_final_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bracket_no TINYINT NOT NULL,
            slot_no TINYINT NOT NULL,
            team_id INT NULL,
            points INT NULL,
            placement TINYINT NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_cl_qf_bracket_slot (bracket_no, slot_no),
            UNIQUE KEY uniq_cl_qf_team (team_id),
            FOREIGN KEY (team_id) REFERENCES cl_teams(id) ON DELETE SET NULL
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
    if (!column_exists($pdo, 'cl_admin_users', 'access_scope')) {
        $pdo->exec("ALTER TABLE cl_admin_users ADD COLUMN access_scope VARCHAR(30) NOT NULL DEFAULT 'admin' AFTER password_hash");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cl_stock_updates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source_message_id INT NULL,
            phone VARCHAR(30) NULL,
            sim_label VARCHAR(40) NULL,
            amount_rm DECIMAL(10,2) NOT NULL DEFAULT 0,
            shell_amount DECIMAL(12,2) NULL,
            change_sim TINYINT(1) NOT NULL DEFAULT 0,
            raw_message TEXT NOT NULL,
            created_by_admin_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cl_stock_source_message (source_message_id),
            INDEX idx_cl_stock_created (created_at)
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
        CREATE TABLE IF NOT EXISTS cl_match_day_notifications (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            match_id INT NOT NULL,
            team_id INT NOT NULL,
            event_id INT NULL,
            dispatched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cl_match_day_team (match_id, team_id),
            INDEX idx_cl_match_day_dispatched (dispatched_at),
            FOREIGN KEY (match_id) REFERENCES cl_matches(id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES cl_teams(id) ON DELETE CASCADE,
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
    $schemaVersion = '20260810-admin-access-scope-v23';
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
        normalize_stable_tournament_timeline($pdo);
        backfill_match_stage_codes($pdo);
        repair_match_room_timeline_links($pdo);
        return;
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            ensure_schema($pdo);
            cleanup_premature_auto_finals($pdo);
            normalize_stable_tournament_timeline($pdo);
            backfill_match_stage_codes($pdo);
            repair_match_room_timeline_links($pdo);
            return;
        }
        clearstatcache(true, $markerPath);
        if (!is_file($markerPath) || trim((string) @file_get_contents($markerPath)) !== $schemaVersion) {
            ensure_schema($pdo);
            cleanup_premature_auto_finals($pdo);
            normalize_stable_tournament_timeline($pdo);
            backfill_match_stage_codes($pdo);
            repair_match_room_timeline_links($pdo);
            @file_put_contents($markerPath, $schemaVersion, LOCK_EX);
        }
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function ensure_live_schema_compatibility(PDO $pdo): void
{
    $column = $pdo->query("SHOW COLUMNS FROM cl_rooms LIKE 'room_type'")->fetch();
    $type = strtolower((string) ($column['Type'] ?? ''));
    $schemaIsStale = !$column || strpos($type, "'info'") === false;
    if (!$pdo->query("SHOW TABLES LIKE 'cl_order_records'")->fetchColumn()
        || !$pdo->query("SHOW TABLES LIKE 'cl_order_messages'")->fetchColumn()) {
        $schemaIsStale = true;
    }
    $orderStatusColumn=$pdo->query("SHOW COLUMNS FROM cl_order_messages LIKE 'status'")->fetch();
    $orderStatusType=strtolower((string)($orderStatusColumn['Type'] ?? ''));
    if (!$orderStatusColumn || strpos($orderStatusType,"'unrecorded'") === false) {
        $schemaIsStale=true;
    }

    $requiredColumns = [
        ['cl_admin_users', 'access_scope'],
        ['cl_teams', 'notification_checked'],
        ['cl_teams', 'is_test_account'],
        ['cl_teams', 'admin_added'],
        ['cl_teams', 'profile_update_required'],
        ['cl_teams', 'last_seen_at'],
        ['cl_rooms', 'admin_checked'],
        ['cl_rooms', 'report_match'],
        ['cl_rooms', 'match_id'],
        ['cl_messages', 'guest_name'],
        ['cl_messages', 'reply_to_message_id'],
        ['cl_messages', 'action_target'],
        ['cl_messages', 'image_url'],
        ['cl_messages', 'edited_at'],
        ['cl_matches', 'stage_code'],
        ['cl_order_messages', 'sheet_sync_status'],
        ['cl_order_messages', 'process_code'],
    ];
    foreach ($requiredColumns as [$table, $requiredColumn]) {
        if (!column_exists($pdo, $table, $requiredColumn)) {
            $schemaIsStale = true;
            break;
        }
    }

    if ($schemaIsStale) {
        // A version marker can survive a database restore/replacement. Re-run the
        // idempotent migration against the actual database before serving data.
        ensure_schema($pdo);
    }
    ensure_active_match_uniqueness_guard($pdo);
}

function ensure_active_match_uniqueness_guard(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS cl_active_match_teams (
            team_id INT NOT NULL PRIMARY KEY,
            match_id INT NOT NULL,
            side CHAR(1) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_cl_active_match_id (match_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    $triggerRows = $pdo->query('
        SELECT TRIGGER_NAME,ACTION_STATEMENT FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA = DATABASE()
          AND TRIGGER_NAME IN ("cl_match_active_after_insert", "cl_match_active_after_update", "cl_match_active_after_delete")
    ')->fetchAll();
    $existing=[];
    foreach ($triggerRows as $triggerRow) $existing[(string)$triggerRow['TRIGGER_NAME']] = (string)$triggerRow['ACTION_STATEMENT'];
    // Group-stage round robin memang mempunyai beberapa fixture akan datang
    // untuk team sama. Migrate guard lama supaya ia terus melindungi bracket
    // biasa tanpa menghalang jadual Group Stage rasmi.
    foreach (['cl_match_active_after_insert','cl_match_active_after_update'] as $triggerName) {
        if (isset($existing[$triggerName]) && stripos($existing[$triggerName], 'group_stage_2026') === false) {
            $pdo->exec('DROP TRIGGER '.$triggerName);
            unset($existing[$triggerName]);
        }
    }

    if (!isset($existing['cl_match_active_after_insert'])) {
        $pdo->exec('
            CREATE TRIGGER cl_match_active_after_insert AFTER INSERT ON cl_matches FOR EACH ROW
            BEGIN
                IF NEW.status IN ("up_next", "live") AND COALESCE(NEW.stage_code, "") != "group_stage_2026" THEN
                    IF NEW.team_a_id IS NOT NULL THEN
                        INSERT INTO cl_active_match_teams(team_id, match_id, side) VALUES(NEW.team_a_id, NEW.id, "A");
                    END IF;
                    IF NEW.team_b_id IS NOT NULL THEN
                        INSERT INTO cl_active_match_teams(team_id, match_id, side) VALUES(NEW.team_b_id, NEW.id, "B");
                    END IF;
                END IF;
            END
        ');
    }
    if (!isset($existing['cl_match_active_after_update'])) {
        $pdo->exec('
            CREATE TRIGGER cl_match_active_after_update AFTER UPDATE ON cl_matches FOR EACH ROW
            BEGIN
                DELETE FROM cl_active_match_teams WHERE match_id = OLD.id;
                IF NEW.status IN ("up_next", "live") AND COALESCE(NEW.stage_code, "") != "group_stage_2026" THEN
                    IF NEW.team_a_id IS NOT NULL THEN
                        INSERT INTO cl_active_match_teams(team_id, match_id, side) VALUES(NEW.team_a_id, NEW.id, "A");
                    END IF;
                    IF NEW.team_b_id IS NOT NULL THEN
                        INSERT INTO cl_active_match_teams(team_id, match_id, side) VALUES(NEW.team_b_id, NEW.id, "B");
                    END IF;
                END IF;
            END
        ');
    }
    if (!isset($existing['cl_match_active_after_delete'])) {
        $pdo->exec('
            CREATE TRIGGER cl_match_active_after_delete AFTER DELETE ON cl_matches FOR EACH ROW
            BEGIN
                DELETE FROM cl_active_match_teams WHERE match_id = OLD.id;
            END
        ');
    }

    // Rebuild the guard only when it is empty. A duplicate here is deliberate:
    // it stops startup loudly instead of silently allowing two active matches.
    if ((int) $pdo->query('SELECT COUNT(*) FROM cl_active_match_teams')->fetchColumn() === 0) {
        $pdo->exec('
            INSERT INTO cl_active_match_teams(team_id, match_id, side)
            SELECT team_a_id, id, "A" FROM cl_matches
            WHERE status IN ("up_next", "live") AND team_a_id IS NOT NULL
        ');
        $pdo->exec('
            INSERT INTO cl_active_match_teams(team_id, match_id, side)
            SELECT team_b_id, id, "B" FROM cl_matches
            WHERE status IN ("up_next", "live") AND team_b_id IS NOT NULL
        ');
    }
}

function friendly_match_write_error(Throwable $error): string
{
    if ($error instanceof PDOException && (string) $error->getCode() === '23000'
        && str_contains((string) $error->getMessage(), 'cl_active_match_teams')) {
        return 'Jadual ditolak: salah satu team sudah mempunyai match aktif. Selesaikan atau tutup match lama dahulu.';
    }
    return $error->getMessage();
}

function normalize_qualifier_fasa_one_timeline(PDO $pdo): void
{
    $timeline = get_timeline($pdo);
    $changed = false;
    $titlesByDate = [
        '5 august 2026' => 'Qualifier 1 / Fasa 1 / Group 1 / BO3',
        '7 august 2026' => 'Qualifier 1 / Fasa 1 / Group 2 / BO3',
        '8 august 2026' => 'Qualifier 2 / Group 1 VS Group 2 / BO3',
        '9 august 2026' => 'Qualifier 1 / Fasa 1 / Group 3 / BO3',
        '11 august 2026' => 'Qualifier 1 / Fasa 1 / Group 4 / BO3',
        '12 august 2026' => 'Qualifier 2 / Group 3 VS Group 4 / BO3',
        '14 august 2026' => 'Qualifier 3 / Winner Q2A VS Winner Q2B / BO3',
        '15 august 2026' => 'Qualifier Final / Round 2 / Secure Round',
        '16 august 2026' => 'Qualifier Final / Last Chance',
    ];
    foreach ($timeline as &$item) {
        $date = strtolower(trim((string) ($item['date'] ?? '')));
        $date = str_replace('ogos', 'august', $date);
        if (isset($titlesByDate[$date])) {
            if ((string) ($item['title'] ?? '') !== $titlesByDate[$date]) {
                $item['title'] = $titlesByDate[$date];
                $changed = true;
            }
        }
    }
    unset($item);
    $existingDates = array_map(static fn(array $item): string => str_replace('ogos', 'august', strtolower(trim((string) ($item['date'] ?? '')))), $timeline);
    foreach ([
        ['after' => '7 august 2026', 'date' => '8 August 2026'],
        ['after' => '11 august 2026', 'date' => '12 August 2026'],
        ['after' => '12 august 2026', 'date' => '14 August 2026'],
        ['after' => '14 august 2026', 'date' => '15 August 2026'],
        ['after' => '15 august 2026', 'date' => '16 August 2026'],
    ] as $newSlot) {
        $normalizedDate = strtolower($newSlot['date']);
        if (in_array($normalizedDate, $existingDates, true)) continue;
        $afterIndex = array_search($newSlot['after'], $existingDates, true);
        $insertAt = $afterIndex === false ? count($timeline) : ((int) $afterIndex + 1);
        array_splice($timeline, $insertAt, 0, [[
            'date' => $newSlot['date'],
            'title' => $titlesByDate[$normalizedDate],
            'note' => '9:30 PM',
            'final' => false,
        ]]);
        array_splice($existingDates, $insertAt, 0, [$normalizedDate]);
        $changed = true;
    }
    $roundOneTitle = 'Qualifier Final / Round 1';
    $hasRoundOne = false;
    foreach ($timeline as $item) {
        if (strcasecmp(trim((string) ($item['title'] ?? '')), $roundOneTitle) === 0) {
            $hasRoundOne = true;
            break;
        }
    }
    if (!$hasRoundOne) {
        $insertAt = count($timeline);
        foreach ($timeline as $index => $item) {
            if (str_replace('ogos', 'august', strtolower(trim((string) ($item['date'] ?? '')))) === '14 august 2026') {
                $insertAt = $index + 1;
            }
        }
        array_splice($timeline, $insertAt, 0, [[
            'date' => '14 August 2026',
            'title' => $roundOneTitle,
            'note' => '9:30 PM · 4 bracket × 8 team',
            'final' => false,
        ]]);
        $changed = true;
    }
    if ($changed) {
        $encoded = json_encode(array_values($timeline), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare('
            INSERT INTO cl_settings (setting_key, setting_value, updated_at)
            VALUES ("timeline_json", ?, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([$encoded]);
    }

    $matches = $pdo->query('
        SELECT id, match_name, DATE(match_time) AS match_date FROM cl_matches
        WHERE DATE(match_time) IN ("2026-08-05", "2026-08-07", "2026-08-08", "2026-08-09", "2026-08-11", "2026-08-12")
    ')->fetchAll();
    $matchPrefixesByDate = [
        '2026-08-05' => 'Qualifier 1 / Fasa 1 / Group 1 / BO3',
        '2026-08-07' => 'Qualifier 1 / Fasa 1 / Group 2 / BO3',
        '2026-08-08' => 'Qualifier 2 / Group 1 VS Group 2 / BO3',
        '2026-08-09' => 'Qualifier 1 / Fasa 1 / Group 3 / BO3',
        '2026-08-11' => 'Qualifier 1 / Fasa 1 / Group 4 / BO3',
        '2026-08-12' => 'Qualifier 2 / Group 3 VS Group 4 / BO3',
    ];
    $update = $pdo->prepare('UPDATE cl_matches SET match_name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    foreach ($matches as $match) {
        $name = (string) $match['match_name'];
        $matchDate = (string) ($match['match_date'] ?? '');
        $prefix = $matchPrefixesByDate[$matchDate] ?? '';
        $normalized = $prefix === '' ? $name : preg_replace('/^Qualifier\s*1\s*\/\s*Fasa\s*[1-4](?:\s*\/\s*Group\s*[^\/]+)?\s*\/\s*BO3/i', $prefix, $name);
        if (is_string($normalized) && $normalized !== $name) {
            $update->execute([$normalized, (int) $match['id']]);
        }
    }
}

function stable_tournament_schedule(): array
{
    return [
        ['stage_code'=>'q2_f1','date'=>'13 August 2026','title'=>'Qualifier 2 / Fasa 1 / BO3','note'=>'9:30 PM','final'=>false],
        ['stage_code'=>'q2_f2','date'=>'14 August 2026','title'=>'Qualifier 2 / Fasa 2 / BO3','note'=>'9:30 PM','final'=>false],
        ['stage_code'=>'q3_f1','date'=>'15 August 2026','title'=>'Qualifier 3 / Fasa 1 / BO3','note'=>'9:30 PM','final'=>false],
        ['stage_code'=>'qf_b1','date'=>'16 August 2026','title'=>'Qualifier Final / Bracket 1 / BO3','note'=>'9:00 PM · 8 team → Top 3 layak','final'=>false],
        ['stage_code'=>'qf_b2','date'=>'17 August 2026','title'=>'Qualifier Final / Bracket 2 / BO3','note'=>'9:00 PM · 8 team → Top 3 layak','final'=>false],
        ['stage_code'=>'qf_b3','date'=>'18 August 2026','title'=>'Qualifier Final / Bracket 3 / BO3','note'=>'9:00 PM · 8 team → Top 3 layak','final'=>false],
        ['stage_code'=>'qf_b4','date'=>'19 August 2026','title'=>'Qualifier Final / Bracket 4 / BO3','note'=>'9:00 PM · 8 team → Top 3 layak','final'=>false],
        ['stage_code'=>'gs_d1','date'=>'21 August 2026','title'=>'Group Stage Day 1','note'=>'9:00 PM','final'=>false],
        ['stage_code'=>'gs_d2','date'=>'22 August 2026','title'=>'Group Stage Day 2','note'=>'9:00 PM','final'=>false],
        ['stage_code'=>'gs_d3','date'=>'23 August 2026','title'=>'Group Stage Day 3','note'=>'9:00 PM','final'=>false],
        ['stage_code'=>'gs_d4','date'=>'24 August 2026','title'=>'Group Stage Day 4','note'=>'9:00 PM','final'=>false],
        ['stage_code'=>'gs_d5','date'=>'25 August 2026','title'=>'Group Stage Day 5','note'=>'9:00 PM','final'=>false],
        ['stage_code'=>'gs_d6','date'=>'26 August 2026','title'=>'Group Stage Day 6','note'=>'9:00 PM','final'=>false],
        ['stage_code'=>'ko_m1_m2','date'=>'1 September 2026','title'=>'Knockout Stage (M1 - M2)','note'=>'10:00 PM · Phase 1 · Best of 3 · 11 Round','final'=>false],
        ['stage_code'=>'ko_m3_m4','date'=>'2 September 2026','title'=>'Knockout Stage (M3 - M4)','note'=>'10:00 PM · Phase 1 · Best of 3 · 11 Round','final'=>false],
        ['stage_code'=>'ko_m5_m6','date'=>'4 September 2026','title'=>'Knockout Stage (M5 - M6)','note'=>'10:00 PM · Phase 2 · Best of 3 · 11 Round','final'=>false],
        ['stage_code'=>'ko_ubf','date'=>'6 September 2026','title'=>'Knockout Stage (UBF)','note'=>'10:00 PM · Phase 3 · Best of 7 · 11 Round','final'=>false],
        ['stage_code'=>'lb_m7_m8','date'=>'8 September 2026','title'=>'Lower Bracket (M7 - M8)','note'=>'10:00 PM · Phase 1 · Best of 3 · 11 Round','final'=>false],
        ['stage_code'=>'lb_m9_m10','date'=>'9 September 2026','title'=>'Lower Bracket (M9 - M10)','note'=>'10:00 PM · Phase 2 · Best of 3 · 11 Round','final'=>false],
        ['stage_code'=>'lb_m11','date'=>'11 September 2026','title'=>'Lower Bracket (M11)','note'=>'10:00 PM · Phase 3 · Best of 7 · 11 Round','final'=>false],
        ['stage_code'=>'lb_lbf','date'=>'12 September 2026','title'=>'Lower Bracket (LBF)','note'=>'10:00 PM · Phase 4 · Best of 7 · 11 Round','final'=>false],
        ['stage_code'=>'grand_final','date'=>'15 September 2026','title'=>'Grandfinal Match! 👑','note'=>'10:00 PM · Last Fight! · Best of 7 · 11 Round','final'=>true],
    ];
}

function infer_stage_code(string $name, ?string $matchTime = null): string
{
    $date = $matchTime ? substr($matchTime, 0, 10) : '';
    $byDate = ['2026-08-05'=>'q1_g1','2026-08-07'=>'q1_g2','2026-08-09'=>'q1_g3','2026-08-11'=>'q1_g4','2026-08-13'=>'q2_f1','2026-08-14'=>'q2_f2','2026-08-15'=>'q3_f1','2026-08-16'=>'qf_b1','2026-08-17'=>'qf_b2','2026-08-18'=>'qf_b3','2026-08-19'=>'qf_b4'];
    if (isset($byDate[$date])) return $byDate[$date];
    $n = strtolower($name);
    if (str_contains($n,'qualifier 1')) foreach ([1,2,3,4] as $g) if (str_contains($n,'group '.$g)) return 'q1_g'.$g;
    return '';
}

function normalize_stable_tournament_timeline(PDO $pdo): void
{
    $applied = $pdo->query('SELECT setting_value FROM cl_settings WHERE setting_key="stable_timeline_v19_applied" LIMIT 1')->fetchColumn();
    $timeline = get_timeline($pdo);
    if ($applied === false) {
        $kept = [];
        foreach ($timeline as $item) {
            $dateText = strtolower(str_ireplace('Ogos','August',trim((string)($item['date'] ?? ''))));
            $isRegistration = str_contains($dateText, 'july') || $dateText === '2 august 2026';
            $isProtectedQualifier = in_array($dateText, ['5 august 2026','7 august 2026','9 august 2026','11 august 2026'], true);
            if ($isRegistration || $isProtectedQualifier) $kept[] = $item;
        }
        $timeline = array_merge($kept, stable_tournament_schedule());
    }
    foreach ($timeline as &$item) {
        if (!empty($item['stage_code'])) continue;
        $dt = timeline_item_datetime($item);
        $item['stage_code'] = infer_stage_code((string)($item['title'] ?? ''), $dt);
    }
    unset($item);
    $encoded = json_encode(array_values($timeline), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare('INSERT INTO cl_settings(setting_key,setting_value,updated_at) VALUES(?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=CURRENT_TIMESTAMP');
    $stmt->execute(['timeline_json',$encoded]);
    $stmt->execute(['stable_timeline_v19_applied','1']);
}

function backfill_match_stage_codes(PDO $pdo): void
{
    $rows = $pdo->query('SELECT id,match_name,match_time FROM cl_matches WHERE stage_code IS NULL OR stage_code=""')->fetchAll();
    $update = $pdo->prepare('UPDATE cl_matches SET stage_code=? WHERE id=?');
    foreach ($rows as $row) {
        $code = infer_stage_code((string)$row['match_name'], (string)($row['match_time'] ?? ''));
        if ($code !== '') $update->execute([$code,(int)$row['id']]);
    }
}

function repair_match_room_timeline_links(PDO $pdo): void
{
    // Jadual awal Qualifier 2 pernah menggunakan 8/12 Ogos. Timeline rasmi
    // baharu memindahkan slot tersebut ke 13/14 Ogos. Jalankan sekali dan
    // hanya sentuh rekod legacy yang masih belum mempunyai stage yang betul.
    $repairs = [
        ['old' => '2026-08-08', 'new' => '2026-08-13 21:30:00', 'stage' => 'q2_f1'],
        ['old' => '2026-08-12', 'new' => '2026-08-14 21:30:00', 'stage' => 'q2_f2'],
    ];
    $repairMatch = $pdo->prepare('UPDATE cl_matches SET match_time=?, stage_code=?, updated_at=CURRENT_TIMESTAMP WHERE DATE(match_time)=? AND (stage_code IS NULL OR stage_code="" OR stage_code=?)');
    foreach ($repairs as $repair) {
        $repairMatch->execute([$repair['new'], $repair['stage'], $repair['old'], $repair['stage']]);
    }

    // Room lama tiada foreign key ke match. Padankan room dan match satu-ke-satu
    // supaya rematch pasangan team yang sama tidak lagi mengambil tarikh silap.
    $matches = $pdo->query('SELECT id,team_a_id,team_b_id,status FROM cl_matches WHERE team_a_id IS NOT NULL AND team_b_id IS NOT NULL ORDER BY id DESC')->fetchAll();
    $findLinkedRoom = $pdo->prepare('SELECT id,status FROM cl_rooms WHERE room_type="match" AND match_id=? ORDER BY id DESC LIMIT 1');
    $findRoom = $pdo->prepare('SELECT id FROM cl_rooms WHERE room_type="match" AND match_id IS NULL AND ((team_a_id=? AND team_b_id=?) OR (team_a_id=? AND team_b_id=?)) ORDER BY id DESC LIMIT 1');
    $linkRoom = $pdo->prepare('UPDATE cl_rooms SET match_id=? WHERE id=? AND match_id IS NULL');
    $openRoom = $pdo->prepare('UPDATE cl_rooms SET team_a_id=?,team_b_id=?,status="open",updated_at=CURRENT_TIMESTAMP WHERE id=?');
    $createRoom = $pdo->prepare('INSERT INTO cl_rooms (room_type,team_a_id,team_b_id,match_id,status,updated_at) VALUES ("match",?,?,?,"open",CURRENT_TIMESTAMP)');
    foreach ($matches as $match) {
        $matchId = (int)$match['id'];
        $teamAId = (int)$match['team_a_id'];
        $teamBId = (int)$match['team_b_id'];
        $findLinkedRoom->execute([$matchId]);
        $linkedRoom = $findLinkedRoom->fetch();
        if ($linkedRoom) {
            if (in_array((string)$match['status'], ['up_next','live'], true)) {
                $openRoom->execute([$teamAId,$teamBId,(int)$linkedRoom['id']]);
            }
            continue;
        }
        $findRoom->execute([(int)$match['team_a_id'], (int)$match['team_b_id'], (int)$match['team_b_id'], (int)$match['team_a_id']]);
        $roomId = (int)($findRoom->fetchColumn() ?: 0);
        if ($roomId > 0) {
            $linkRoom->execute([$matchId, $roomId]);
            if (in_array((string)$match['status'], ['up_next','live'], true)) {
                $openRoom->execute([$teamAId,$teamBId,$roomId]);
            }
        } elseif (in_array((string)$match['status'], ['up_next','live'], true)) {
            $createRoom->execute([$teamAId,$teamBId,$matchId]);
        }
    }
}

function ensure_active_match_rooms(PDO $pdo): void
{
    $locked = (int)($pdo->query("SELECT GET_LOCK('gnex_cl_active_match_rooms',2)")->fetchColumn() ?: 0) === 1;
    if (!$locked) return;
    try {
    $matches = $pdo->query('
        SELECT id,team_a_id,team_b_id
        FROM cl_matches
        WHERE status IN ("up_next","live")
          AND team_a_id IS NOT NULL AND team_b_id IS NOT NULL
        ORDER BY id ASC
    ')->fetchAll();
    if (!$matches) return;

    $findLinked = $pdo->prepare('SELECT id FROM cl_rooms WHERE room_type="match" AND match_id=? ORDER BY id DESC LIMIT 1');
    $findLegacy = $pdo->prepare('SELECT id FROM cl_rooms WHERE room_type="match" AND match_id IS NULL AND ((team_a_id=? AND team_b_id=?) OR (team_a_id=? AND team_b_id=?)) ORDER BY id DESC LIMIT 1');
    $activate = $pdo->prepare('UPDATE cl_rooms SET team_a_id=?,team_b_id=?,match_id=?,status="open",updated_at=CURRENT_TIMESTAMP WHERE id=?');
    $create = $pdo->prepare('INSERT INTO cl_rooms(room_type,team_a_id,team_b_id,match_id,status,updated_at) VALUES("match",?,?,?,"open",CURRENT_TIMESTAMP)');

    foreach ($matches as $match) {
        $matchId = (int)$match['id'];
        $teamAId = (int)$match['team_a_id'];
        $teamBId = (int)$match['team_b_id'];
        $findLinked->execute([$matchId]);
        $roomId = (int)($findLinked->fetchColumn() ?: 0);
        if ($roomId <= 0) {
            $findLegacy->execute([$teamAId,$teamBId,$teamBId,$teamAId]);
            $roomId = (int)($findLegacy->fetchColumn() ?: 0);
        }
        if ($roomId > 0) {
            $activate->execute([$teamAId,$teamBId,$matchId,$roomId]);
        } else {
            $create->execute([$teamAId,$teamBId,$matchId]);
        }
    }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('gnex_cl_active_match_rooms')");
    }
}

function open_match_room_now(PDO $pdo): void
{
    $team = current_team($pdo);
    $admin = current_admin($pdo);
    if (!$team && !$admin) {
        json_response(['ok' => false, 'message' => 'Login diperlukan untuk buka Group Match.'], 401);
    }
    if ($team && (!empty($team['profile_incomplete']) || (string)$team['status'] !== 'accepted')) {
        json_response(['ok' => false, 'message' => 'Lengkapkan profile dan pastikan slot team sudah confirmed dahulu.'], 403);
    }

    $matchId = (int)($_POST['match_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT id,team_a_id,team_b_id,status FROM cl_matches WHERE id=? AND status!="hidden" LIMIT 1');
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();
    if (!$match || empty($match['team_a_id']) || empty($match['team_b_id'])) {
        json_response(['ok' => false, 'message' => 'Kedua-dua team untuk match ini belum lengkap.'], 422);
    }
    $teamAId = (int)$match['team_a_id'];
    $teamBId = (int)$match['team_b_id'];
    if ($team && !in_array((int)$team['id'], [$teamAId,$teamBId], true)) {
        json_response(['ok' => false, 'message' => 'Team anda bukan peserta match ini.'], 403);
    }

    $find = $pdo->prepare('SELECT id FROM cl_rooms WHERE room_type="match" AND match_id=? ORDER BY id DESC LIMIT 1');
    $find->execute([$matchId]);
    $roomId = (int)($find->fetchColumn() ?: 0);
    if ($roomId <= 0) {
        $legacy = $pdo->prepare('SELECT id FROM cl_rooms WHERE room_type="match" AND match_id IS NULL AND ((team_a_id=? AND team_b_id=?) OR (team_a_id=? AND team_b_id=?)) ORDER BY id DESC LIMIT 1');
        $legacy->execute([$teamAId,$teamBId,$teamBId,$teamAId]);
        $roomId = (int)($legacy->fetchColumn() ?: 0);
    }
    if ($roomId > 0) {
        $update = $pdo->prepare('UPDATE cl_rooms SET team_a_id=?,team_b_id=?,match_id=?,status="open",updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $update->execute([$teamAId,$teamBId,$matchId,$roomId]);
    } else {
        $insert = $pdo->prepare('INSERT INTO cl_rooms(room_type,team_a_id,team_b_id,match_id,status,updated_at) VALUES("match",?,?,?,"open",CURRENT_TIMESTAMP)');
        $insert->execute([$teamAId,$teamBId,$matchId]);
        $roomId = (int)$pdo->lastInsertId();
    }

    json_response(array_merge(get_state($pdo), [
        'room_id' => $roomId,
        'opened_match_id' => $matchId,
        'message' => 'Group Match sudah dibuka.',
    ]));
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

function seed_stock_worker(PDO $pdo): void
{
    $username = 'izwan';
    $password = 'GnexStok186700371877';
    $stmt = $pdo->prepare('SELECT id,password_hash,access_scope FROM cl_admin_users WHERE username=? LIMIT 1');
    $stmt->execute([$username]);
    $worker = $stmt->fetch();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!$worker) {
        $stmt = $pdo->prepare('INSERT INTO cl_admin_users (username,password_hash,access_scope) VALUES (?,? ,"stock")');
        $stmt->execute([$username,$hash]);
        return;
    }
    if (!password_verify($password,(string)$worker['password_hash']) || (string)($worker['access_scope'] ?? '') !== 'stock') {
        $stmt = $pdo->prepare('UPDATE cl_admin_users SET password_hash=?,access_scope="stock",updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $stmt->execute([$hash,(int)$worker['id']]);
    }
}

function seed_allocation_user(PDO $pdo): void
{
    $username = 'nizam';
    $password = 'Gnexnizam1845549264442';
    $stmt = $pdo->prepare('SELECT id,password_hash,access_scope FROM cl_admin_users WHERE username=? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!$user) {
        $stmt = $pdo->prepare('INSERT INTO cl_admin_users (username,password_hash,access_scope) VALUES (?,? ,"allocation")');
        $stmt->execute([$username,$hash]);
        return;
    }
    if (!password_verify($password,(string)$user['password_hash']) || (string)($user['access_scope'] ?? '') !== 'allocation') {
        $stmt = $pdo->prepare('UPDATE cl_admin_users SET password_hash=?,access_scope="allocation",updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $stmt->execute([$hash,(int)$user['id']]);
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

function seed_web_test_rival_match(PDO $pdo): void
{
    $testerName = 'GNEX WEB TESTER';
    $legacyRivalName = 'GNEX TEST RIVAL';
    $rivalName = 'GNEX TEST TDB';
    $rivalPassword = 'GnexRival2!';

    $findTeam = $pdo->prepare('SELECT id, password_hash FROM cl_teams WHERE team_name = ? LIMIT 1');
    $findTeam->execute([$rivalName]);
    $rival = $findTeam->fetch();
    if (!$rival) {
        $findTeam->execute([$legacyRivalName]);
        $rival = $findTeam->fetch();
    }
    $rivalId = (int) ($rival['id'] ?? 0);

    if ($rivalId <= 0) {
        $insertTeam = $pdo->prepare('
            INSERT INTO cl_teams
                (team_name, phone, password_hash, status, slot_no, admin_checked, notification_checked, is_test_account)
            VALUES (?, ?, ?, "accepted", NULL, 1, 1, 1)
        ');
        $insertTeam->execute([$rivalName, 'WEB-TEST-RIVAL', password_hash($rivalPassword, PASSWORD_DEFAULT)]);
        $rivalId = (int) $pdo->lastInsertId();
    } else {
        $passwordHash = (string) ($rival['password_hash'] ?? '');
        $nextPasswordHash = $passwordHash !== '' && password_verify($rivalPassword, $passwordHash)
            ? $passwordHash
            : password_hash($rivalPassword, PASSWORD_DEFAULT);
        $updateTeam = $pdo->prepare('
            UPDATE cl_teams
            SET team_name = ?, password_hash = ?, is_test_account = 1, status = "accepted", slot_no = NULL, admin_checked = 1
            WHERE id = ?
        ');
        $updateTeam->execute([$rivalName, $nextPasswordHash, $rivalId]);
    }

    $findTeam->execute([$testerName]);
    $testerId = (int) ($findTeam->fetchColumn() ?: 0);
    if ($testerId <= 0 || $rivalId <= 0) {
        return;
    }

    // Remove test accounts from official tournament matches. The real opponent remains
    // in place and waits for its actual qualifier winner instead of a tester.
    $detachTest = $pdo->prepare('
        UPDATE cl_matches
        SET team_a_id = IF(team_a_id IN (?, ?), NULL, team_a_id),
            team_b_id = IF(team_b_id IN (?, ?), NULL, team_b_id),
            updated_at = CURRENT_TIMESTAMP
        WHERE stage_code NOT LIKE "test\\_%"
          AND ((team_a_id IN (?, ?) AND team_b_id NOT IN (?, ?))
            OR (team_b_id IN (?, ?) AND team_a_id NOT IN (?, ?)))
    ');
    $detachTest->execute([
        $testerId, $rivalId, $testerId, $rivalId,
        $testerId, $rivalId, $testerId, $rivalId,
        $testerId, $rivalId, $testerId, $rivalId,
    ]);

    $officialTestMatchName = 'Qualifier 1 / Fasa 1 / BO3';
    $findMatch = $pdo->prepare('
        SELECT id FROM cl_matches
        WHERE status != "hidden"
          AND ((team_a_id = ? AND team_b_id = ?) OR (team_a_id = ? AND team_b_id = ?))
        ORDER BY id ASC LIMIT 1
    ');
    $findMatch->execute([$testerId, $rivalId, $rivalId, $testerId]);
    $testMatchId = (int) ($findMatch->fetchColumn() ?: 0);
    if ($testMatchId <= 0) {
        $insertMatch = $pdo->prepare('
            INSERT INTO cl_matches (team_a_id, team_b_id, match_name, stage_code, match_time, status, updated_at)
            VALUES (?, ?, ?, "test_q1", "2026-08-04 21:00:00", "up_next", CURRENT_TIMESTAMP)
        ');
        $insertMatch->execute([$testerId, $rivalId, $officialTestMatchName]);
    } else {
        $updateMatchName = $pdo->prepare('
            UPDATE cl_matches SET match_name = ?, stage_code = "test_q1", updated_at = CURRENT_TIMESTAMP WHERE id = ?
        ');
        $updateMatchName->execute([$officialTestMatchName, $testMatchId]);
    }

    // A completely isolated Qualifier 2 playground. It behaves like a real match,
    // but both accounts are excluded from tournament counts and official routing.
    $findTestQ2 = $pdo->prepare('SELECT id FROM cl_matches WHERE stage_code = "test_q2" LIMIT 1');
    $findTestQ2->execute();
    if (!(int)($findTestQ2->fetchColumn() ?: 0)) {
        $insertTestQ2 = $pdo->prepare('
            INSERT INTO cl_matches (team_a_id, team_b_id, match_name, stage_code, match_time, status, updated_at)
            VALUES (?, ?, "Qualifier 2 / Fasa 1 / BO3", "test_q2", "2026-08-13 21:30:00", "up_next", CURRENT_TIMESTAMP)
        ');
        $insertTestQ2->execute([$testerId, $rivalId]);
    }

    $findRoom = $pdo->prepare('
        SELECT id FROM cl_rooms
        WHERE room_type IN ("match", "deal")
          AND ((team_a_id = ? AND team_b_id = ?) OR (team_a_id = ? AND team_b_id = ?))
        LIMIT 1
    ');
    $findRoom->execute([$testerId, $rivalId, $rivalId, $testerId]);
    if (!(int) ($findRoom->fetchColumn() ?: 0)) {
        $insertRoom = $pdo->prepare('
            INSERT INTO cl_rooms (room_type, team_a_id, team_b_id, status, updated_at)
            VALUES ("match", ?, ?, "open", CURRENT_TIMESTAMP)
        ');
        $insertRoom->execute([$testerId, $rivalId]);
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

function clean_multiline_text(?string $value, int $max = 700): string
{
    $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
    $value = preg_replace('/[ \t]+/u', ' ', $value) ?? '';
    $value = preg_replace('/ *\n */u', "\n", $value) ?? '';
    $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?? '';
    return mb_substr(trim($value), 0, $max);
}

function contains_external_contact(string $message): bool
{
    $lower = mb_strtolower($message);
    if (preg_match('~(?:https?://)?(?:wa\.me|api\.whatsapp\.com|whatsapp\.com/send)|\b(?:tel|phone)\s*:~iu', $lower)) {
        return true;
    }
    // Nombor telefon Malaysia: 01X-XXXXXXX/XXXXXXXX atau +60/60 1X-XXXXXXX.
    if (preg_match('/(?<!\d)(?:\+?6\s*0|6\s*0|0)[\s().-]*1[0-9](?:[\s().-]*[0-9]){7,8}(?!\d)/u', $message)) {
        return true;
    }
    // Cuba kesan nombor yang dieja untuk mengelak bypass seperti "kosong satu dua...".
    $wordMap = [
        'kosong' => '0', 'zero' => '0', 'satu' => '1', 'one' => '1',
        'dua' => '2', 'two' => '2', 'tiga' => '3', 'three' => '3',
        'empat' => '4', 'four' => '4', 'lima' => '5', 'five' => '5',
        'enam' => '6', 'six' => '6', 'tujuh' => '7', 'seven' => '7',
        'lapan' => '8', 'eight' => '8', 'sembilan' => '9', 'nine' => '9',
    ];
    $tokens = preg_split('/[^\p{L}\d]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $run = '';
    foreach ($tokens as $token) {
        if (isset($wordMap[$token])) {
            $run .= $wordMap[$token];
        } elseif (preg_match('/^\d$/', $token)) {
            $run .= $token;
        } else {
            if (preg_match('/^(?:01\d{7,8}|601\d{7,8})$/', $run)) return true;
            $run = '';
        }
    }
    return (bool) preg_match('/^(?:01\d{7,8}|601\d{7,8})$/', $run);
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

function dispatch_due_match_day_notifications(PDO $pdo, array $pushConfig, int $limit = 4): void
{
    static $ran = false;
    if ($ran || $limit <= 0) return;
    $ran = true;

    $stmt = $pdo->query('
        SELECT m.id, m.match_name, m.match_time,
               m.team_a_id, m.team_b_id,
               ta.team_name AS team_a_name, tb.team_name AS team_b_name
        FROM cl_matches m
        LEFT JOIN cl_teams ta ON ta.id = m.team_a_id
        LEFT JOIN cl_teams tb ON tb.id = m.team_b_id
        WHERE DATE(m.match_time) = CURDATE()
          AND LOWER(m.match_name) NOT LIKE "%final%"
          AND LOWER(m.match_name) REGEXP "qualifier[[:space:]]*[123]"
          AND m.status != "completed"
        ORDER BY m.match_time ASC, m.id ASC
    ');
    $matches = $stmt->fetchAll();
    $reserve = $pdo->prepare('INSERT IGNORE INTO cl_match_day_notifications (match_id, team_id) VALUES (?, ?)');
    $saveEvent = $pdo->prepare('UPDATE cl_match_day_notifications SET event_id = ? WHERE match_id = ? AND team_id = ?');
    $sent = 0;

    foreach ($matches as $match) {
        foreach ([
            [(int) ($match['team_a_id'] ?? 0), (string) ($match['team_b_name'] ?? 'TBD')],
            [(int) ($match['team_b_id'] ?? 0), (string) ($match['team_a_name'] ?? 'TBD')],
        ] as [$teamId, $opponent]) {
            if ($teamId <= 0 || $sent >= $limit) continue;
            $reserve->execute([(int) $match['id'], $teamId]);
            if ($reserve->rowCount() !== 1) continue;
            $matchTime = new DateTimeImmutable((string) $match['match_time']);
            $body = 'Match anda hari ini menentang ' . $opponent . ' pada ' . $matchTime->format('h:i A') . '. Buka Deal Room dan baca Cara Bermain.';
            $eventId = queue_push_event(
                $pdo,
                'team',
                $teamId,
                null,
                'Match Clash League Hari Ini!',
                $body,
                'clash-league.html#deal',
                'clash-match-day-' . (int) $match['id'] . '-' . $teamId
            );
            $saveEvent->execute([$eventId, (int) $match['id'], $teamId]);
            if (!empty($pushConfig['public_key']) && !empty($pushConfig['private_key'])) {
                send_push_to_owner($pdo, $pushConfig, 'team', $teamId, null, $eventId);
            }
            $sent++;
        }
        if ($sent >= $limit) break;
    }
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

function get_order_records(PDO $pdo): void
{
    $admin=current_admin($pdo);
    if (!$admin) json_response(['ok'=>false,'message'=>'Login admin diperlukan.'],401);
    $rows=$pdo->query('SELECT m.id,m.message,m.status,m.created_by_admin_id,m.updated_by_admin_id,m.paired_message_id,m.process_code,m.sheet_sync_status,m.sheet_row,m.sheet_synced_at,m.sheet_error,m.created_at,m.updated_at,COALESCE(a.username,"GNEX") AS created_by_name FROM cl_order_messages m LEFT JOIN cl_admin_users a ON a.id=m.created_by_admin_id ORDER BY m.id ASC LIMIT 1000')->fetchAll();
    json_response(['ok'=>true,'current_user'=>['id'=>(int)$admin['id'],'username'=>(string)$admin['username']],'orders'=>array_map(static function(array $row): array {
        $row['id']=(int)$row['id'];
        $row['paired_message_id']=$row['paired_message_id'] === null ? null : (int)$row['paired_message_id'];
        $row['sheet_row']=$row['sheet_row'] === null ? null : (int)$row['sheet_row'];
        return $row;
    },$rows)]);
}

function get_order_balances(PDO $pdo): void
{
    $admin=current_admin($pdo);
    if (!$admin) json_response(['ok'=>false,'message'=>'Login admin diperlukan.'],401);
    if (($admin['access_scope'] ?? 'admin') === 'stock') json_response(['ok'=>false,'message'=>'Akses baki akaun tidak dibenarkan.'],403);
    $stmt=$pdo->prepare('SELECT setting_value,updated_at FROM cl_settings WHERE setting_key=? LIMIT 1');
    $stmt->execute(['order_wallet_balances']);
    $row=$stmt->fetch();
    $stored=$row ? json_decode((string)($row['setting_value'] ?? ''),true) : null;
    if (!is_array($stored)) $stored=[];
    $walletUpdatedAt=is_array($stored['updated_at'] ?? null) ? $stored['updated_at'] : [];
    $walletUpdatedBy=is_array($stored['updated_by_wallet'] ?? null) ? $stored['updated_by_wallet'] : [];
    $legacyUpdatedAt=(string)($row['updated_at'] ?? '');
    foreach (['tng','digi','celcom'] as $wallet) {
        if (empty($walletUpdatedAt[$wallet]) && $legacyUpdatedAt !== '') $walletUpdatedAt[$wallet]=$legacyUpdatedAt;
        if (empty($walletUpdatedBy[$wallet]) && !empty($stored['updated_by'])) $walletUpdatedBy[$wallet]=(string)$stored['updated_by'];
    }
    json_response([
        'ok'=>true,
        'balances'=>[
            'tng'=>round((float)($stored['tng'] ?? 0),2),
            'digi'=>round((float)($stored['digi'] ?? 0),2),
            'celcom'=>round((float)($stored['celcom'] ?? 0),2),
        ],
        'updated_at'=>$walletUpdatedAt,
        'updated_by'=>$walletUpdatedBy,
    ]);
}

function save_order_balances(PDO $pdo): void
{
    $admin=current_admin($pdo);
    if (!$admin) json_response(['ok'=>false,'message'=>'Login admin diperlukan.'],401);
    if (($admin['access_scope'] ?? 'admin') === 'stock') json_response(['ok'=>false,'message'=>'Akses baki akaun tidak dibenarkan.'],403);
    $stmt=$pdo->prepare('SELECT setting_value,updated_at FROM cl_settings WHERE setting_key=? LIMIT 1');
    $stmt->execute(['order_wallet_balances']);
    $existingRow=$stmt->fetch();
    $existing=$existingRow ? json_decode((string)($existingRow['setting_value'] ?? ''),true) : null;
    if (!is_array($existing)) $existing=[];
    $balances=[];
    foreach (['tng','digi','celcom'] as $wallet) {
        $raw=trim((string)($_POST[$wallet] ?? ''));
        if ($raw === '' || !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/',$raw)) {
            json_response(['ok'=>false,'message'=>'Masukkan baki '.$wallet.' yang sah.'],422);
        }
        $value=(float)$raw;
        if ($value < 0 || $value > 9999999.99) {
            json_response(['ok'=>false,'message'=>'Nilai baki '.$wallet.' di luar julat.'],422);
        }
        $balances[$wallet]=round($value,2);
    }
    $editor=(string)($admin['username'] ?? $admin['display_name'] ?? 'Admin');
    $updatedAt=is_array($existing['updated_at'] ?? null) ? $existing['updated_at'] : [];
    $updatedByWallet=is_array($existing['updated_by_wallet'] ?? null) ? $existing['updated_by_wallet'] : [];
    $legacyUpdatedAt=(string)($existingRow['updated_at'] ?? '');
    $now=(new DateTimeImmutable('now',new DateTimeZone('Asia/Kuala_Lumpur')))->format('Y-m-d H:i:s');
    foreach (['tng','digi','celcom'] as $wallet) {
        $oldValue=round((float)($existing[$wallet] ?? 0),2);
        if (!array_key_exists($wallet,$updatedAt) && $legacyUpdatedAt !== '') $updatedAt[$wallet]=$legacyUpdatedAt;
        if (abs($oldValue-$balances[$wallet])>=0.005 || empty($updatedAt[$wallet])) {
            $updatedAt[$wallet]=$now;
            $updatedByWallet[$wallet]=$editor;
        }
    }
    $numericBalances=$balances;
    $balances['updated_at']=$updatedAt;
    $balances['updated_by_wallet']=$updatedByWallet;
    $balances['updated_by']=$editor;
    $json=json_encode($balances,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($json === false) json_response(['ok'=>false,'message'=>'Baki gagal disimpan.'],500);
    $stmt=$pdo->prepare('INSERT INTO cl_settings (setting_key,setting_value,updated_at) VALUES (?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=CURRENT_TIMESTAMP');
    $stmt->execute(['order_wallet_balances',$json]);
    json_response(['ok'=>true,'message'=>'Baki berjaya dikemas kini.','balances'=>$numericBalances,'updated_at'=>$updatedAt,'updated_by'=>$updatedByWallet]);
}

function get_order_stock(PDO $pdo): void
{
    $admin=current_admin($pdo);
    if (!$admin) json_response(['ok'=>false,'message'=>'Login admin diperlukan.'],401);
    if (($admin['access_scope'] ?? 'admin') === 'stock') json_response(['ok'=>false,'message'=>'Akses halaman stok tidak dibenarkan.'],403);
    $stmt=$pdo->prepare('SELECT setting_value FROM cl_settings WHERE setting_key="order_stock_state" LIMIT 1');
    $stmt->execute();
    $stored=json_decode((string)($stmt->fetchColumn() ?: '{}'),true);
    if (!is_array($stored)) $stored=[];
    $pdo->exec('UPDATE cl_stock_updates SET shell_amount=ROUND(amount_rm*(1300/108),2) WHERE shell_amount IS NULL');
    $updates=$pdo->query('SELECT s.id,s.phone,s.sim_label,s.amount_rm,s.shell_amount,s.change_sim,s.raw_message,s.created_at,a.username FROM cl_stock_updates s LEFT JOIN cl_admin_users a ON a.id=s.created_by_admin_id ORDER BY s.id DESC LIMIT 50')->fetchAll();
    $autoAddedShell=(float)$pdo->query('SELECT COALESCE(SUM(shell_amount),0) FROM cl_stock_updates')->fetchColumn();
    $manualAddedShell=round((float)($stored['manual_added_shell'] ?? 0),2);
    $pendingRm=(float)$pdo->query('SELECT COALESCE(SUM(amount_rm),0) FROM cl_stock_updates WHERE shell_amount IS NULL')->fetchColumn();
    json_response(['ok'=>true,'user'=>$admin,'stock'=>[
        'initial_shell'=>round((float)($stored['initial_shell'] ?? 0),2),
        'initial_web'=>round((float)($stored['initial_web'] ?? 0),2),
        'actual_shell'=>round((float)($stored['actual_shell'] ?? 0),2),
        'actual_web'=>round((float)($stored['actual_web'] ?? 0),2),
        'added_web'=>round((float)($stored['added_web'] ?? 0),2),
        'usage_start_date'=>(string)($stored['usage_start_date'] ?? ''),
        'usage_start_key'=>(string)($stored['usage_start_key'] ?? ''),
        'usage_start_at'=>(string)($stored['usage_start_at'] ?? ''),
        'auto_added_shell'=>round($autoAddedShell,2),
        'manual_added_shell'=>$manualAddedShell,
        'added_shell'=>round($autoAddedShell+$manualAddedShell,2),'pending_rm'=>round($pendingRm,2),
        'shell_rate'=>round(1300/108,8),
    ],'updates'=>$updates]);
}

function save_order_stock(PDO $pdo): void
{
    $admin=current_admin($pdo);
    if (!$admin) json_response(['ok'=>false,'message'=>'Login admin diperlukan.'],401);
    if (($admin['access_scope'] ?? 'admin') === 'stock') json_response(['ok'=>false,'message'=>'Akses kemas kini stok tidak dibenarkan.'],403);
    $storedStmt=$pdo->prepare('SELECT setting_value FROM cl_settings WHERE setting_key="order_stock_state" LIMIT 1');
    $storedStmt->execute();
    $stored=json_decode((string)($storedStmt->fetchColumn() ?: '{}'),true);
    if (!is_array($stored)) $stored=[];
    $values=[];
    foreach (['initial_shell','initial_web','actual_shell','actual_web','added_web'] as $field) {
        $raw=trim((string)($_POST[$field] ?? ''));
        if ($raw==='' || !preg_match('/^\d{1,9}(?:\.\d{1,2})?$/',$raw)) json_response(['ok'=>false,'message'=>'Nilai stok tidak sah.'],422);
        $values[$field]=round((float)$raw,2);
    }
    $targetRaw=trim((string)($_POST['added_shell_target'] ?? ''));
    if ($targetRaw!=='') {
        if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/',$targetRaw)) json_response(['ok'=>false,'message'=>'Nilai tambah stok shell tidak sah.'],422);
        $currentAuto=(float)$pdo->query('SELECT COALESCE(SUM(shell_amount),0) FROM cl_stock_updates')->fetchColumn();
        // Pelarasan menukar angka semasa tanpa memadam rekod update Izwan.
        $values['manual_added_shell']=round((float)$targetRaw-$currentAuto,2);
    } else {
        $values['manual_added_shell']=round((float)($stored['manual_added_shell'] ?? 0),2);
    }
    $startDate=trim((string)($_POST['usage_start_date'] ?? ''));
    if ($startDate!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$startDate)) json_response(['ok'=>false,'message'=>'Tarikh mula kira tidak sah.'],422);
    $values['usage_start_date']=$startDate;
    $startKey=trim((string)($_POST['usage_start_key'] ?? ''));
    $keepNew=$startKey==='KEEP_NEW';
    if ($keepNew) $startKey=(string)($stored['usage_start_key'] ?? 'NEW');
    if (strlen($startKey)>500 || preg_match('/[\x00-\x1F\x7F]/',$startKey)) json_response(['ok'=>false,'message'=>'Rekod mula kira tidak sah.'],422);
    $values['usage_start_key']=$startKey;
    $startAt=$keepNew ? (string)($stored['usage_start_at'] ?? '') : trim((string)($_POST['usage_start_at'] ?? ''));
    if (!$keepNew && str_starts_with($startKey,'NEW|') && $startAt==='') $startKey='NEW';
    if ($startKey==='NEW') {
        if ($startAt==='') $startAt=(new DateTimeImmutable('now',new DateTimeZone('Asia/Kuala_Lumpur')))->format('Y-m-d\TH:i:s');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/',$startAt)) json_response(['ok'=>false,'message'=>'Masa mula kira NEW tidak sah.'],422);
    } else {
        $startAt='';
    }
    $values['usage_start_at']=$startAt;
    $values['updated_by']=(string)$admin['username'];
    $values['updated_at']=date('Y-m-d H:i:s');
    $json=json_encode($values,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $stmt=$pdo->prepare('INSERT INTO cl_settings (setting_key,setting_value,updated_at) VALUES ("order_stock_state",?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=CURRENT_TIMESTAMP');
    $stmt->execute([$json]);
    json_response(['ok'=>true,'message'=>'Stok berjaya disimpan.']);
}

function default_order_sim_accounts(): array
{
    $file=dirname(__DIR__).DIRECTORY_SEPARATOR.'order-record.html';
    $html=is_file($file) ? (string)file_get_contents($file) : '';
    if ($html==='' || !preg_match_all('/<div class="sim-row"\s+data-sim-row\s+data-baki="([^"]*)"\s+data-rgg="([^"]*)"\s+data-shell="([^"]*)">(.*?)<\/div>/is',$html,$matches,PREG_SET_ORDER)) return [];
    $accounts=[];
    foreach ($matches as $match) {
        $content=(string)$match[4];
        preg_match('/<b>(.*?)<\/b>/is',$content,$name);
        preg_match('/<span class="number">(.*?)<\/span>/is',$content,$number);
        preg_match('/<span class="last[^"]*">(.*?)<\/span>/is',$content,$last);
        preg_match('/<span class="updated">(.*?)<\/span>/is',$content,$updated);
        $accounts[]=[
            'name'=>trim(html_entity_decode(strip_tags((string)($name[1] ?? '')),ENT_QUOTES|ENT_HTML5,'UTF-8')),
            'number'=>trim(html_entity_decode(strip_tags((string)($number[1] ?? '')),ENT_QUOTES|ENT_HTML5,'UTF-8')),
            'baki'=>round((float)$match[1],2),
            'rgg'=>round((float)$match[2],2),
            'shell'=>round((float)$match[3],2),
            'last_used'=>trim(html_entity_decode(strip_tags((string)($last[1] ?? '')),ENT_QUOTES|ENT_HTML5,'UTF-8')),
            'last_updated'=>trim(html_entity_decode(strip_tags((string)($updated[1] ?? '')),ENT_QUOTES|ENT_HTML5,'UTF-8')),
            'stock_balance_initialized'=>false,
        ];
    }
    return $accounts;
}

function load_order_sim_accounts_value(PDO $pdo, bool $forUpdate=false): array
{
    $sql='SELECT setting_value FROM cl_settings WHERE setting_key="order_sim_accounts" LIMIT 1'.($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);
    $stmt->execute();
    $accounts=json_decode((string)($stmt->fetchColumn() ?: '[]'),true);
    if (!is_array($accounts) || !$accounts) $accounts=default_order_sim_accounts();
    return $accounts;
}

function get_order_sim_accounts(PDO $pdo): void
{
    if (!current_admin($pdo)) json_response(['ok'=>false,'message'=>'Login admin diperlukan.'],401);
    $stmt=$pdo->prepare('SELECT setting_value,updated_at FROM cl_settings WHERE setting_key="order_sim_accounts" LIMIT 1');
    $stmt->execute();
    $row=$stmt->fetch();
    $accounts=$row ? json_decode((string)($row['setting_value'] ?? ''),true) : [];
    if (!is_array($accounts) || !$accounts) $accounts=default_order_sim_accounts();
    json_response(['ok'=>true,'accounts'=>array_values($accounts),'updated_at'=>(string)($row['updated_at'] ?? '')]);
}

function save_order_sim_accounts(PDO $pdo): void
{
    $admin=current_admin($pdo);
    if (!$admin) json_response(['ok'=>false,'message'=>'Login admin diperlukan.'],401);
    $decoded=json_decode((string)($_POST['accounts'] ?? ''),true);
    if (!is_array($decoded) || count($decoded)>100) json_response(['ok'=>false,'message'=>'Data akaun SIM tidak sah.'],422);
    $accounts=[];
    foreach ($decoded as $item) {
        if (!is_array($item)) continue;
        $number=preg_replace('/[^0-9+ -]/','',clean_text($item['number'] ?? '',30));
        $accounts[]=[
            'name'=>clean_text($item['name'] ?? '',100),
            'number'=>$number,
            'baki'=>round(max(0,min(9999999.99,(float)($item['baki'] ?? 0))),2),
            'rgg'=>round(max(0,min(9999999.99,(float)($item['rgg'] ?? 0))),2),
            'shell'=>round(max(0,min(9999999.99,(float)($item['shell'] ?? 0))),2),
            'last_used'=>clean_text($item['last_used'] ?? '',30),
            'last_updated'=>clean_text($item['last_updated'] ?? '',30),
            'stock_balance_initialized'=>!empty($item['stock_balance_initialized']),
        ];
    }
    $json=json_encode($accounts,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($json===false) json_response(['ok'=>false,'message'=>'Data SIM gagal diproses.'],500);
    $stmt=$pdo->prepare('INSERT INTO cl_settings (setting_key,setting_value,updated_at) VALUES ("order_sim_accounts",?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=CURRENT_TIMESTAMP');
    $stmt->execute([$json]);
    json_response(['ok'=>true,'message'=>'Maklumat SIM berjaya disimpan.','accounts'=>$accounts,'updated_by'=>(string)$admin['username']]);
}

function save_processed_sim_balance(PDO $pdo): void
{
    $admin=current_admin($pdo);
    if (!$admin || ($admin['access_scope'] ?? '') !== 'stock') json_response(['ok'=>false,'message'=>'Akaun Izwan diperlukan.'],403);
    $messageId=(int)($_POST['message_id'] ?? 0);
    $balance=filter_var($_POST['balance'] ?? null,FILTER_VALIDATE_FLOAT);
    if ($messageId<=0 || $balance===false || $balance<0 || $balance>9999999.99) json_response(['ok'=>false,'message'=>'Masukkan baki SIM yang sah.'],422);
    $stmt=$pdo->prepare('SELECT message,status,created_by_admin_id,created_at FROM cl_order_messages WHERE id=? LIMIT 1');
    $stmt->execute([$messageId]);
    $message=$stmt->fetch();
    if (!$message || (int)$message['created_by_admin_id'] !== (int)$admin['id'] || $message['status']!=='processed') json_response(['ok'=>false,'message'=>'Mesej stok belum diproses atau tidak sah.'],422);
    $stockUpdate=parse_stock_update_message((string)$message['message']);
    if (!$stockUpdate || empty($stockUpdate['phone'])) json_response(['ok'=>false,'message'=>'Nombor SIM tidak dapat dikesan.'],422);
    $pdo->beginTransaction();
    try {
        $accounts=load_order_sim_accounts_value($pdo,true);
        $rows=is_array($accounts['accounts'] ?? null) ? $accounts['accounts'] : $accounts;
        $matched=false;
        foreach ($rows as &$account) {
            if (!is_array($account) || preg_replace('/\D+/','',(string)($account['number'] ?? '')) !== $stockUpdate['phone']) continue;
            if (!empty($account['stock_balance_initialized'])) {
                $pdo->rollBack();
                json_response(['ok'=>true,'message'=>'Baki asas SIM ini sudah pernah diisi.','already_saved'=>true]);
            }
            $account['baki']=round((float)$balance,2);
            $account['stock_balance_initialized']=true;
            $account['last_used']=date('d/m/Y',strtotime((string)$message['created_at']));
            $matched=true;
            break;
        }
        unset($account);
        if (!$matched) throw new RuntimeException('Nombor SIM tidak ditemui dalam Senarai SIM.');
        if (is_array($accounts['accounts'] ?? null)) $accounts['accounts']=$rows; else $accounts=$rows;
        $json=json_encode($accounts,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $save=$pdo->prepare('INSERT INTO cl_settings (setting_key,setting_value,updated_at) VALUES ("order_sim_accounts",?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=CURRENT_TIMESTAMP');
        $save->execute([$json]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    json_response(['ok'=>true,'message'=>'Baki asas SIM berjaya disimpan. Selepas ini sistem akan kira sendiri.']);
}

function get_order_money(PDO $pdo): void
{
    if (!current_admin($pdo)) json_response(['ok'=>false,'message'=>'Login admin diperlukan.'],401);
    $stmt=$pdo->prepare('SELECT setting_value,updated_at FROM cl_settings WHERE setting_key="order_money_state" LIMIT 1');
    $stmt->execute();
    $row=$stmt->fetch();
    $state=$row ? json_decode((string)($row['setting_value'] ?? ''),true) : [];
    if (!is_array($state)) $state=[];
    $state=link_order_money_accounts($pdo,$state);
    json_response(['ok'=>true,'money'=>$state,'updated_at'=>(string)($row['updated_at'] ?? '')]);
}

function link_order_money_accounts(PDO $pdo, array $state): array
{
    $readSetting=static function(string $key) use ($pdo): array {
        $stmt=$pdo->prepare('SELECT setting_value FROM cl_settings WHERE setting_key=? LIMIT 1');
        $stmt->execute([$key]);
        $decoded=json_decode((string)($stmt->fetchColumn() ?: '{}'),true);
        return is_array($decoded) ? $decoded : [];
    };
    $wallets=$readSetting('order_wallet_balances');
    $simAccounts=$readSetting('order_sim_accounts');
    $stock=$readSetting('order_stock_state');
    $rggTotal=0.0;
    $simBalanceTotal=0.0;
    $simRows=is_array($simAccounts['accounts'] ?? null) ? $simAccounts['accounts'] : $simAccounts;
    if ($simRows) {
        foreach ($simRows as $sim) if (is_array($sim)) {
            $rggTotal+=(float)($sim['rgg'] ?? 0);
            $simBalanceTotal+=(float)($sim['baki'] ?? 0);
        }
    } else {
        // Sebelum senarai SIM pernah disimpan melalui editor, paparan SIM masih
        // menggunakan 21 baris asal dalam order-record.html. Gunakan sumber yang
        // sama supaya jumlah Lokasi Duit sepadan dengan kad JUMLAH RGG.
        $orderRecordHtml=dirname(__DIR__).DIRECTORY_SEPARATOR.'order-record.html';
        $html=is_file($orderRecordHtml) ? (string)file_get_contents($orderRecordHtml) : '';
        if ($html !== '' && preg_match_all('/\bdata-rgg="([0-9]+(?:\.[0-9]+)?)"/i',$html,$rggMatches)) {
            foreach ($rggMatches[1] as $rggValue) $rggTotal+=(float)$rggValue;
        }
        if ($html !== '' && preg_match_all('/\bdata-baki="([0-9]+(?:\.[0-9]+)?)"/i',$html,$balanceMatches)) {
            foreach ($balanceMatches[1] as $balanceValue) $simBalanceTotal+=(float)$balanceValue;
        }
    }
    $simConversionRate=12.04/14.61;
    $linked=[
        'duit touch n go'=>['amount'=>(float)($wallets['tng'] ?? 0),'source'=>'Chat · TNG 1'],
        'stok digi sim 1'=>['amount'=>(float)($wallets['digi'] ?? 0)*$simConversionRate,'source'=>'Chat Digi × 12.04 ÷ 14.61'],
        'stok semua sim'=>['amount'=>$simBalanceTotal*$simConversionRate,'source'=>'Jumlah Baki SIM × 12.04 ÷ 14.61'],
        'celcom sim 2'=>['amount'=>(float)($wallets['celcom'] ?? 0)*$simConversionRate,'source'=>'Chat Celcom × 12.04 ÷ 14.61'],
        'stok rgg'=>['amount'=>$rggTotal*$simConversionRate,'source'=>'Jumlah RGG SIM × 12.04 ÷ 14.61'],
        'stok garena shell'=>['amount'=>(float)($stock['actual_shell'] ?? 0)*108/1300,'source'=>'Shell semasa × 108 ÷ 1300'],
        'stok web'=>['amount'=>(float)($stock['actual_web'] ?? 0),'source'=>'Stok web semasa'],
    ];
    $normalize=static function(string $name): string {
        $name=mb_strtolower(str_replace(['’',"'"],'',$name),'UTF-8');
        $name=preg_replace('/[^a-z0-9]+/u',' ',$name) ?? $name;
        return trim((string)(preg_replace('/\s+/u',' ',$name) ?? $name));
    };
    $accounts=is_array($state['accounts'] ?? null) ? $state['accounts'] : [];
    foreach ($accounts as &$account) {
        if (!is_array($account)) continue;
        $key=$normalize((string)($account['name'] ?? ''));
        if (in_array($key,['stok digi sim pc','celcom sim 1'],true)) {
            $rawAmount=(float)($account['raw_amount'] ?? $account['amount'] ?? 0);
            $account['raw_amount']=round($rawAmount,2);
            $account['amount']=round($rawAmount*$simConversionRate,2);
            $account['converted']=true;
            $account['source']=($key==='celcom sim 1'?'Celcom SIM 1':'Digi SIM PC').' × 12.04 ÷ 14.61';
            continue;
        }
        if (!isset($linked[$key])) continue;
        $account['amount']=round((float)$linked[$key]['amount'],2);
        $account['linked']=true;
        $account['source']=$linked[$key]['source'];
    }
    unset($account);
    $state['accounts']=$accounts;
    return $state;
}

function save_order_money(PDO $pdo): void
{
    $admin=current_admin($pdo);
    if (!$admin) json_response(['ok'=>false,'message'=>'Login admin diperlukan.'],401);
    if (($admin['access_scope'] ?? 'admin') === 'stock') json_response(['ok'=>false,'message'=>'Akses kewangan tidak dibenarkan.'],403);
    $input=json_decode((string)($_POST['money'] ?? ''),true);
    if (!is_array($input)) json_response(['ok'=>false,'message'=>'Data kewangan tidak sah.'],422);
    $cleanMoney=static fn($value): float=>round(max(-999999999.99,min(999999999.99,(float)$value)),2);
    $allocations=[];
    foreach (array_slice(is_array($input['allocations'] ?? null)?$input['allocations']:[],0,50) as $item) {
        if (!is_array($item)) continue;
        $allocations[]=['code'=>clean_text($item['code'] ?? '',6),'name'=>clean_text($item['name'] ?? '',80),'amount'=>$cleanMoney($item['amount'] ?? 0),'note'=>clean_text($item['note'] ?? '',2000)];
    }
    $accounts=[];
    foreach (array_slice(is_array($input['accounts'] ?? null)?$input['accounts']:[],0,100) as $item) {
        if (!is_array($item)) continue;
        $name=clean_text($item['name'] ?? '',100);
        $account=['name'=>$name,'amount'=>$cleanMoney($item['amount'] ?? 0)];
        $accountKey=trim((string)(preg_replace('/\s+/u',' ',preg_replace('/[^a-z0-9]+/u',' ',mb_strtolower(str_replace(['’',"'"],'',$name),'UTF-8')) ?? '') ?? ''));
        if ($accountKey==='stok digi sim pc') {
            $rawAmount=$cleanMoney($item['raw_amount'] ?? $item['amount'] ?? 0);
            $account['raw_amount']=$rawAmount;
            $account['amount']=$rawAmount;
        }
        $accounts[]=$account;
    }
    $commitments=[];
    foreach (array_slice(is_array($input['commitments'] ?? null)?$input['commitments']:[],0,100) as $item) {
        if (!is_array($item)) continue;
        $shares=[];
        foreach (($item['shares'] ?? []) as $share) {
            if (!is_array($share) || count($shares)>=20) continue;
            $shares[]=['name'=>clean_text($share['name'] ?? '',80),'amount'=>$cleanMoney($share['amount'] ?? 0),'paid'=>!empty($share['paid'])];
        }
        $commitments[]=['name'=>clean_text($item['name'] ?? '',100),'date'=>clean_text($item['date'] ?? '',30),'amount'=>$cleanMoney($item['amount'] ?? 0),'paid'=>!empty($item['paid']),'payment'=>clean_text($item['payment'] ?? '',100),'note'=>clean_text($item['note'] ?? '',300),'shares'=>$shares];
    }
    $state=['month'=>clean_text($input['month'] ?? '',20),'current_total'=>$cleanMoney($input['current_total'] ?? 0),'allocations'=>$allocations,'accounts'=>$accounts,'commitments'=>$commitments,'updated_by'=>(string)$admin['username'],'updated_at'=>date('Y-m-d H:i:s')];
    $json=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($json===false) json_response(['ok'=>false,'message'=>'Data kewangan gagal diproses.'],500);
    $stmt=$pdo->prepare('INSERT INTO cl_settings (setting_key,setting_value,updated_at) VALUES ("order_money_state",?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=CURRENT_TIMESTAMP');
    $stmt->execute([$json]);
    json_response(['ok'=>true,'message'=>'Maklumat kewangan berjaya disimpan.','money'=>link_order_money_accounts($pdo,$state)]);
}

function require_order_todo_nizam(PDO $pdo): array
{
    $admin=current_admin($pdo);
    if (!$admin) json_response(['ok'=>false,'message'=>'Login diperlukan.'],401);
    if (($admin['access_scope'] ?? '') !== 'allocation') json_response(['ok'=>false,'message'=>'Page TO DO hanya untuk akaun Nizam.'],403);
    return $admin;
}

function read_order_todo_state(PDO $pdo): array
{
    $stmt=$pdo->prepare('SELECT setting_value FROM cl_settings WHERE setting_key="order_todo_nizam" LIMIT 1');
    $stmt->execute();
    $state=json_decode((string)($stmt->fetchColumn() ?: '{}'),true);
    if (!is_array($state)) $state=[];
    if (!is_array($state['tasks'] ?? null)) $state['tasks']=[];
    if (!is_array($state['checks'] ?? null)) $state['checks']=[];
    return $state;
}

function write_order_todo_state(PDO $pdo,array $state): void
{
    $json=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($json===false) json_response(['ok'=>false,'message'=>'Data TO DO gagal diproses.'],500);
    $stmt=$pdo->prepare('INSERT INTO cl_settings(setting_key,setting_value,updated_at) VALUES("order_todo_nizam",?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=CURRENT_TIMESTAMP');
    $stmt->execute([$json]);
}

function get_order_todo(PDO $pdo): void
{
    require_order_todo_nizam($pdo);
    $state=read_order_todo_state($pdo);
    json_response(['ok'=>true,'todo'=>$state,'server_now'=>date('Y-m-d H:i:s')]);
}

function save_order_todo_task(PDO $pdo): void
{
    $admin=require_order_todo_nizam($pdo);
    $state=read_order_todo_state($pdo);
    $id=clean_text($_POST['id'] ?? '',40);
    if ($id==='') $id='td_'.date('YmdHis').'_'.bin2hex(random_bytes(3));
    $title=clean_text($_POST['title'] ?? '',180);
    if ($title==='') json_response(['ok'=>false,'message'=>'Isi nama tugasan dahulu.'],422);
    $type=clean_text($_POST['schedule_type'] ?? 'inbox',20);
    if (!in_array($type,['once','daily','weekly','inbox'],true)) $type='inbox';
    $date=clean_text($_POST['due_date'] ?? '',10);
    if ($date!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) $date='';
    $time=clean_text($_POST['due_time'] ?? '',5);
    if ($time!=='' && !preg_match('/^\d{2}:\d{2}$/',$time)) $time='';
    $weekdays=array_values(array_unique(array_filter(array_map('intval',explode(',',(string)($_POST['weekdays'] ?? ''))),static fn($v)=>$v>=1&&$v<=7)));
    $existing=null;
    foreach ($state['tasks'] as $task) if (($task['id'] ?? '')===$id) {$existing=$task;break;}
    $newTask=[
        'id'=>$id,'title'=>$title,'notes'=>clean_text($_POST['notes'] ?? '',1000),
        'place'=>clean_text($_POST['place'] ?? '',180),
        'end_time'=>preg_match('/^\d{2}:\d{2}$/',(string)($_POST['end_time'] ?? ''))?(string)$_POST['end_time']:'',
        'kind'=>(($_POST['kind'] ?? '')==='event'?'event':'task'),
        'category'=>in_array((string)($_POST['category'] ?? ''),['important','training','learning','work','prayer','other'],true)?(string)$_POST['category']:'other',
        'schedule_type'=>$type,'due_date'=>$date,'due_time'=>$time,'weekdays'=>$weekdays,
        'active'=>true,'created_at'=>(string)($existing['created_at'] ?? date('Y-m-d H:i:s')),
        'updated_at'=>date('Y-m-d H:i:s'),'updated_by'=>(string)$admin['username'],
    ];
    $replaced=false;
    foreach ($state['tasks'] as $i=>$task) if (($task['id'] ?? '')===$id) {$state['tasks'][$i]=$newTask;$replaced=true;break;}
    if (!$replaced) array_unshift($state['tasks'],$newTask);
    write_order_todo_state($pdo,$state);
    json_response(['ok'=>true,'message'=>'Tugasan berjaya disimpan.','todo'=>$state,'server_now'=>date('Y-m-d H:i:s')]);
}

function toggle_order_todo(PDO $pdo): void
{
    require_order_todo_nizam($pdo);
    $state=read_order_todo_state($pdo);
    $taskId=clean_text($_POST['task_id'] ?? '',40);
    $date=clean_text($_POST['date'] ?? '',10);
    if ($taskId==='' || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) json_response(['ok'=>false,'message'=>'Rekod tugasan tidak sah.'],422);
    $key=$taskId.'|'.$date;
    if (!empty($_POST['done'])) $state['checks'][$key]=['status'=>'done','at'=>date('Y-m-d H:i:s')];
    else unset($state['checks'][$key]);
    write_order_todo_state($pdo,$state);
    json_response(['ok'=>true,'todo'=>$state,'server_now'=>date('Y-m-d H:i:s')]);
}

function delete_order_todo_task(PDO $pdo): void
{
    require_order_todo_nizam($pdo);
    $state=read_order_todo_state($pdo);
    $id=clean_text($_POST['id'] ?? '',40);
    foreach ($state['tasks'] as $i=>$task) if (($task['id'] ?? '')===$id) {
        $state['tasks'][$i]['active']=false;
        $state['tasks'][$i]['deleted_at']=date('Y-m-d H:i:s');
        break;
    }
    write_order_todo_state($pdo,$state);
    json_response(['ok'=>true,'todo'=>$state,'server_now'=>date('Y-m-d H:i:s')]);
}

function get_order_sheet_data(PDO $pdo): void
{
    $admin=current_admin($pdo);
    if (!$admin) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan.'], 401);
    }
    if (($admin['access_scope'] ?? 'admin') === 'stock') {
        json_response(['ok'=>false,'message'=>'Akses rekod order tidak dibenarkan untuk akaun stok.'],403);
    }

    $configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'order-record-sheet-config.php';
    $config = is_file($configPath) ? require $configPath : [];
    $publishedBase = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vSGOLsGL_RFkbGIyYbL5ec84eik9ptz7kf07QqbdqBy2tu90HFrTfkqq0gQvlXjuYsXgxp7K6cn8IFP/pub';
    $allUrl = trim((string) ($config['all_csv_url'] ?? ($publishedBase . '?gid=0&single=true&output=csv')));
    $dailyUrl = trim((string) ($config['daily_csv_url'] ?? ($publishedBase . '?gid=357721629&single=true&output=csv')));

    $readUrl = trim((string) ($config['read_url'] ?? ''));
    if ($readUrl !== '') {
        $json = fetch_order_sheet_json($readUrl);
        if (!empty($json['ok'])) {
            json_response($json['data']);
        }
    }

    $all = fetch_order_sheet_csv($allUrl);
    $daily = fetch_order_sheet_csv($dailyUrl);
    if (empty($all['ok']) && empty($daily['ok'])) {
        $reason = (string) ($all['message'] ?? $daily['message'] ?? 'Google Sheet tidak dapat dicapai.');
        json_response(['ok' => false, 'message' => $reason], 502);
    }

    json_response([
        'ok' => true,
        'all_rows' => !empty($all['ok']) ? $all['rows'] : [],
        'daily_rows' => !empty($daily['ok']) ? $daily['rows'] : [],
        'all_live' => !empty($all['ok']),
        'daily_live' => !empty($daily['ok']),
        'message' => !empty($all['ok']) && !empty($daily['ok'])
            ? 'Google Sheet live.'
            : 'Sebahagian paparan Google Sheet belum tersedia.',
    ]);
}

function fetch_order_sheet_json(string $url): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'PHP cURL belum aktif pada hosting.'];
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'GNEX-Order-Record/1.0',
    ]);
    $body = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($body === false || $error !== '' || $status < 200 || $status >= 300) {
        return ['ok' => false, 'message' => $error ?: 'Apps Script tidak dapat dicapai.'];
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data) || empty($data['ok'])) {
        return ['ok' => false, 'message' => (string) ($data['message'] ?? 'Respons Apps Script tidak sah.')];
    }
    return ['ok' => true, 'data' => $data];
}

function fetch_order_sheet_csv(string $url): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => 'PHP cURL belum aktif pada hosting.'];
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'GNEX-Order-Record/1.0',
    ]);
    $body = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($body === false || $error !== '') {
        return ['ok' => false, 'message' => $error ?: 'Google Sheet tidak dapat dicapai.'];
    }
    if ($status < 200 || $status >= 300) {
        return ['ok' => false, 'message' => 'Google Sheet memberi HTTP ' . $status . '.'];
    }

    $rows = [];
    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        return ['ok' => false, 'message' => 'CSV Google Sheet tidak dapat dibaca.'];
    }
    fwrite($stream, (string) $body);
    rewind($stream);
    while (($row = fgetcsv($stream)) !== false) {
        if (count(array_filter($row, static fn($value): bool => trim((string) $value) !== '')) > 0) {
            $rows[] = array_map(static fn($value): string => (string) $value, $row);
        }
    }
    fclose($stream);

    return ['ok' => true, 'rows' => $rows];
}

function create_order_record(PDO $pdo): void
{
    $admin=current_admin($pdo);
    if (!$admin) json_response(['ok'=>false,'message'=>'Login admin diperlukan.'],401);
    $message=trim((string)($_POST['message'] ?? $_POST['raw_message'] ?? ''));
    if ($message==='') json_response(['ok'=>false,'message'=>'Tulis mesej dahulu.'],422);
    if (mb_strlen($message)>1000) json_response(['ok'=>false,'message'=>'Mesej terlalu panjang.'],422);
    $stmt=$pdo->prepare('INSERT INTO cl_order_messages (message,status,created_by_admin_id) VALUES (?,"normal",?)');
    $stmt->execute([$message,(int)$admin['id']]);
    $messageId=(int)$pdo->lastInsertId();
    $stockUpdate=parse_stock_update_message($message);
    json_response(['ok'=>true,'message'=>$stockUpdate?'Mesej stok dihantar. Klik mesej dan tekan PROSES STOK.':'Mesej dihantar.','order_id'=>$messageId,'stock_update'=>(bool)$stockUpdate]);
}

function parse_stock_update_message(string $message): ?array
{
    $extractAmount=function (string $keyword) use ($message): float {
        $number='([0-9]+(?:\.[0-9]{1,2})?)';
        $word=preg_quote($keyword,'/');
        $patterns=[
            '/\brm\s*'.$number.'\s*'.$word.'\b/i',
            '/\b'.$word.'\s*\brm\s*'.$number.'\b/i',
            '/\brm\s*'.$word.'\s*'.$number.'\b/i',
            '/\b'.$word.'\s*'.$number.'\s*\brm\b/i',
            '/(?<![\d.])'.$number.'\s*\brm\s*'.$word.'\b/i',
            '/(?<![\d.])'.$number.'\s*'.$word.'\s*\brm\b/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern,$message,$match)) return round((float)$match[1],2);
        }
        return 0.0;
    };
    preg_match('/(?<!\d)(01\d(?:[\s-]?\d){7,9})(?!\d)/',$message,$phoneMatch);
    preg_match('/\bsim\s*([a-z0-9_-]+)/i',$message,$simMatch);
    $phone=preg_replace('/\D+/','',(string)($phoneMatch[0] ?? ''));
    $amount=$extractAmount('shell');
    $rggAmount=$extractAmount('rgg');
    if (($amount<=0 && $rggAmount<=0) || $amount>9999999.99 || $rggAmount>9999999.99) return null;
    return [
        'phone'=>$phone !== '' ? $phone : null,
        'sim_label'=>isset($simMatch[1]) ? 'SIM '.strtoupper($simMatch[1]) : null,
        'amount_rm'=>$amount,
        'rgg_amount_rm'=>$rggAmount,
        'change_sim'=>(bool)preg_match('/\btukar\s+sim\b/i',$message),
    ];
}

function process_stock_update_message(PDO $pdo, int $messageId, array $admin): void
{
    $stmt=$pdo->prepare('SELECT id,message,status,created_by_admin_id,created_at FROM cl_order_messages WHERE id=? LIMIT 1');
    $stmt->execute([$messageId]);
    $message=$stmt->fetch();
    if (!$message) json_response(['ok'=>false,'message'=>'Mesej stok tidak ditemui.'],404);
    if ((int)$message['created_by_admin_id'] !== (int)$admin['id']) {
        json_response(['ok'=>false,'message'=>'Izwan hanya boleh memproses mesej stok sendiri.'],403);
    }
    $stockUpdate=parse_stock_update_message((string)$message['message']);
    if (!$stockUpdate) json_response(['ok'=>false,'message'=>'Format stok tidak lengkap. Pastikan ada nombor telefon serta kod RM + nilai + SHELL atau RGG.'],422);
    if (empty($stockUpdate['phone'])) json_response(['ok'=>false,'message'=>'Nombor telefon SIM wajib dimasukkan sebelum proses stok.'],422);
    $shellAmount=round((float)$stockUpdate['amount_rm']*(1300/108),2);
    $simShellAmount=round((float)$stockUpdate['amount_rm'],2);
    $rggAmount=round((float)($stockUpdate['rgg_amount_rm'] ?? 0),2);
    $pdo->beginTransaction();
    try {
        $insert=$pdo->prepare('INSERT IGNORE INTO cl_stock_updates (source_message_id,phone,sim_label,amount_rm,shell_amount,change_sim,raw_message,created_by_admin_id) VALUES (?,?,?,?,?,?,?,?)');
        $insert->execute([$messageId,$stockUpdate['phone'],$stockUpdate['sim_label'],$stockUpdate['amount_rm'],$shellAmount,$stockUpdate['change_sim'] ? 1 : 0,(string)$message['message'],(int)$admin['id']]);
        if ($insert->rowCount()>0) {
            $accounts=load_order_sim_accounts_value($pdo,true);
            $rows=is_array($accounts['accounts'] ?? null) ? $accounts['accounts'] : $accounts;
            $matched=false;
            $requiresBalance=false;
            foreach ($rows as &$account) {
                if (!is_array($account)) continue;
                $savedPhone=preg_replace('/\D+/','',(string)($account['number'] ?? ''));
                if ($savedPhone !== (string)$stockUpdate['phone']) continue;
                $account['rgg']=round((float)($account['rgg'] ?? 0)+$rggAmount,2);
                // Nilai shell pada Senarai SIM mengikut nilai yang Izwan taip.
                // Conversion 1300/108 hanya digunakan untuk ledger stok Garena.
                $account['shell']=round((float)($account['shell'] ?? 0)+$simShellAmount,2);
                $account['last_used']=date('d/m/Y',strtotime((string)$message['created_at']));
                $requiresBalance=empty($account['stock_balance_initialized']);
                $matched=true;
                break;
            }
            unset($account);
            if (!$matched) throw new RuntimeException('Nombor '.$stockUpdate['phone'].' tidak ditemui dalam Senarai SIM.');
            if (is_array($accounts['accounts'] ?? null)) $accounts['accounts']=$rows; else $accounts=$rows;
            $json=json_encode($accounts,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if ($json===false) throw new RuntimeException('Data SIM gagal diproses.');
            $save=$pdo->prepare('INSERT INTO cl_settings (setting_key,setting_value,updated_at) VALUES ("order_sim_accounts",?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=CURRENT_TIMESTAMP');
            $save->execute([$json]);
        }
        $pdo->prepare('UPDATE cl_order_messages SET status="processed",updated_by_admin_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$admin['id'],$messageId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    json_response(['ok'=>true,'message'=>'Data stok berjaya diproses daripada satu mesej.','stock_update'=>true,'requires_sim_balance'=>$requiresBalance ?? false,'phone'=>$stockUpdate['phone'],'rgg_amount_rm'=>$rggAmount,'sim_shell_amount'=>$simShellAmount,'stock_shell_amount'=>$shellAmount,'already_processed'=>$insert->rowCount()===0]);
}

function set_order_record_status(PDO $pdo): void
{
    $admin=current_admin($pdo);
    if (!$admin) json_response(['ok'=>false,'message'=>'Login admin diperlukan.'],401);
    $id=(int)($_POST['order_id'] ?? 0);
    $status=clean_text($_POST['status'] ?? '',20);
    if ($id<=0 || !in_array($status,['normal','processed','pending','unrecorded'],true)) json_response(['ok'=>false,'message'=>'Status mesej tidak sah.'],422);
    if (($admin['access_scope'] ?? 'admin') === 'stock') {
        if ($status!=='processed') json_response(['ok'=>false,'message'=>'Akaun Izwan hanya boleh memproses mesej stok.'],403);
        process_stock_update_message($pdo,$id,$admin);
    }
    if ($status === 'unrecorded') {
        // Schema version lama mungkin masih dicache pada server. Pastikan nilai
        // baharu tersedia tepat sebelum butang TAK BOLEH BACA menyimpan status.
        $statusColumn=$pdo->query("SHOW COLUMNS FROM cl_order_messages LIKE 'status'")->fetch();
        $statusType=strtolower((string)($statusColumn['Type'] ?? ''));
        if (!$statusColumn || strpos($statusType,"'unrecorded'") === false) {
            $pdo->exec("ALTER TABLE cl_order_messages MODIFY status ENUM('normal','processed','pending','unrecorded') NOT NULL DEFAULT 'normal'");
        }
    }
    if ($status === 'processed') {
        process_order_message_to_sheet($pdo, $id, (int)$admin['id']);
    }

    $current=$pdo->prepare('SELECT sheet_sync_status FROM cl_order_messages WHERE id=? LIMIT 1');
    $current->execute([$id]);
    $row=$current->fetch();
    if (!$row) json_response(['ok'=>false,'message'=>'Mesej tidak ditemui.'],404);
    if ($status !== 'processed' && ($row['sheet_sync_status'] ?? 'none') === 'synced') {
        json_response(['ok'=>false,'message'=>'Order ini sudah masuk Google Sheet dan tidak boleh dibuang tandanya.'],409);
    }

    $stmt=$pdo->prepare('UPDATE cl_order_messages SET status=?,updated_by_admin_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
    $stmt->execute([$status,(int)$admin['id'],$id]);
    json_response(['ok'=>true,'message'=>$status==='processed'
        ? 'Order berjaya dihantar ke Google Sheet.'
        : ($status==='pending'
            ? 'Mesej ditanda pending.'
            : ($status==='unrecorded' ? 'Order manual ditanda belum masuk record.' : 'Status mesej dibuang.'))]);
}

function process_order_message_to_sheet(PDO $pdo, int $messageId, int $adminId): void
{
    $selected=$pdo->prepare('SELECT * FROM cl_order_messages WHERE id=? LIMIT 1');
    $selected->execute([$messageId]);
    $message=$selected->fetch();
    if (!$message) json_response(['ok'=>false,'message'=>'Mesej tidak ditemui.'],404);
    if (($message['sheet_sync_status'] ?? 'none') === 'synced') {
        json_response(['ok'=>true,'message'=>'Order ini memang sudah ada dalam Google Sheet.','already_synced'=>true]);
    }

    $idMessage=null;
    $orderMessage=null;
    $selectedText=trim((string)$message['message']);
    $selectedPlayerId=order_player_id($selectedText);
    $selectedCodes=order_process_codes($selectedText);
    $forceWebsiteFullWeb=false;

    // Order panjang daripada website dihantar dahulu, kemudian arahan ringkas
    // pada mesej tepat di bawahnya. "web" bermaksud seluruh order guna web.
    // Jika marker itu tiada, order panjang kekal menggunakan formula biasa.
    $previousStmt=$pdo->prepare('SELECT * FROM cl_order_messages WHERE id < ? ORDER BY id DESC LIMIT 1');
    $previousStmt->execute([$messageId]);
    $previous=$previousStmt->fetch() ?: null;
    $nextStmt=$pdo->prepare('SELECT * FROM cl_order_messages WHERE id > ? ORDER BY id ASC LIMIT 1');
    $nextStmt->execute([$messageId]);
    $next=$nextStmt->fetch() ?: null;

    if ($selectedPlayerId !== null && $selectedCodes !== [] && $next && order_full_web_marker((string)$next['message']) && ($next['sheet_sync_status'] ?? 'none') !== 'synced') {
        $idMessage=$message;
        $orderMessage=$next;
        $forceWebsiteFullWeb=true;
    } elseif (order_full_web_marker($selectedText) && $previous && ($previous['sheet_sync_status'] ?? 'none') !== 'synced') {
        $previousPlayerId=order_player_id((string)$previous['message']);
        $previousCodes=order_process_codes((string)$previous['message']);
        if ($previousPlayerId !== null && $previousCodes !== []) {
            $idMessage=$previous;
            $orderMessage=$message;
            $forceWebsiteFullWeb=true;
        }
    }

    // A complete website order contains its own Player ID, payment and price.
    if ($idMessage === null && $selectedPlayerId !== null && $selectedCodes !== []) {
        $idMessage=$message;
        $orderMessage=$message;
    } elseif ($idMessage === null) {
        // Short chat orders must be consecutive and in this exact direction:
        // Player ID first, then the payment/code message directly below it.
        if ($selectedPlayerId !== null && $next && ($next['sheet_sync_status'] ?? 'none') !== 'synced' && order_process_codes((string)$next['message']) !== []) {
            $idMessage=$message;
            $orderMessage=$next;
        } elseif ($selectedCodes !== [] && $previous && ($previous['sheet_sync_status'] ?? 'none') !== 'synced' && order_player_id((string)$previous['message']) !== null) {
            $idMessage=$previous;
            $orderMessage=$message;
        }
    }

    if ($idMessage === null || $orderMessage === null) {
        json_response(['ok'=>false,'message'=>'Order tidak lengkap. Untuk format ringkas, ID mesti berada tepat di atas mesej payment.'],422);
    }
    $normalizedPlayerId=order_player_id((string)$idMessage['message']);
    if ($normalizedPlayerId === null) json_response(['ok'=>false,'message'=>'Player ID tidak dapat dikenal pasti.'],422);
    $processCodes=$forceWebsiteFullWeb
        ? array_map('order_full_web_code',order_process_codes((string)$idMessage['message']))
        : order_process_codes((string)$orderMessage['message']);
    if ($processCodes === []) json_response(['ok'=>false,'message'=>'Code order tidak dapat dikenal pasti.'],422);
    $processCode=implode('+',$processCodes);

    $configPath=dirname(__DIR__).DIRECTORY_SEPARATOR.'order-record-sheet-config.php';
    $config=is_file($configPath) ? require $configPath : [];
    $webAppUrl=trim((string)($config['web_app_url'] ?? ''));
    $token=(string)($config['token'] ?? '');
    if ($webAppUrl === '' || $token === '') {
        json_response(['ok'=>false,'message'=>'Sambungan Google Sheet belum dikonfigurasi.'],503);
    }

    $sheetRows=[];
    foreach ($processCodes as $codeIndex=>$singleProcessCode) {
        $result=post_order_to_sheet($webAppUrl, [
            'action'=>'appendOrder',
            'token'=>$token,
            'id'=>$normalizedPlayerId,
            'code'=>$singleProcessCode,
        ]);
        if (!empty($result['ok'])) {
            if (isset($result['row'])) $sheetRows[]=(int)$result['row'];
            continue;
        }

        $reason=clean_text((string)($result['message'] ?? 'Google Sheet tidak memberi pengesahan.'),350);
        if ($codeIndex > 0) {
            $completed=implode(', ',array_slice($processCodes,0,$codeIndex));
            $reason='Sebahagian order sudah masuk ('.$completed.'). Order seterusnya gagal: '.$reason;
        }
        $failed=$pdo->prepare('UPDATE cl_order_messages SET sheet_sync_status="failed",sheet_error=?,updated_by_admin_id=?,updated_at=CURRENT_TIMESTAMP WHERE id IN (?,?)');
        $failed->execute([$reason,$adminId,(int)$idMessage['id'],(int)$orderMessage['id']]);
        json_response(['ok'=>false,'message'=>'Gagal hantar ke Google Sheet: '.$reason],502);
    }

    $sheetRow=$sheetRows[0] ?? null;
    $update=$pdo->prepare('UPDATE cl_order_messages SET status="processed",paired_message_id=?,process_code=?,sheet_sync_status="synced",sheet_row=?,sheet_synced_at=CURRENT_TIMESTAMP,sheet_error=NULL,updated_by_admin_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
    $update->execute([(int)$orderMessage['id'],$processCode,$sheetRow,$adminId,(int)$idMessage['id']]);
    if ((int)$orderMessage['id'] !== (int)$idMessage['id']) {
        $update->execute([(int)$idMessage['id'],$processCode,$sheetRow,$adminId,(int)$orderMessage['id']]);
    }
}

function order_full_web_marker(string $message): bool
{
    $message=mb_strtolower(trim(normalize_order_message_spacing($message)),'UTF-8');
    return (bool)preg_match('/^(?:web|full\s*web|guna\s*web)$/iu',$message);
}

function order_player_id(string $message): ?string
{
    $message=normalize_order_message_spacing($message);
    $candidate='';
    if (preg_match('/PLAYER\s*ID\s*:\s*([0-9][0-9 ()-]{4,40})/iu', $message, $labelled)) {
        $candidate=$labelled[1];
        if (preg_match('/SERVER\s*ID\s*:\s*(\d{1,12})/iu',$message,$serverLabelled)) {
            $candidate.=$serverLabelled[1];
        }
    } elseif (preg_match('/^\s*(\d{5,20}(?:\s*\(\s*\d{1,12}\s*\))?)(?=\s|$)/u', $message, $plain)) {
        $candidate=$plain[1];
    } else {
        return null;
    }
    $digits=preg_replace('/\D+/', '', $candidate) ?? '';
    return strlen($digits) >= 5 && strlen($digits) <= 32 ? $digits : null;
}

function normalize_order_message_spacing(string $message): string
{
    $normalized=preg_replace('/[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u', ' ', $message);
    return $normalized === null ? str_replace("\xC2\xA0", ' ', $message) : $normalized;
}

function order_message_quantity(string $message): array
{
    $quantity=1;
    if (preg_match('/(?<!\d)([1-5])\s*(?:X|×)(?![A-Z0-9])/iu',$message,$match)) {
        $quantity=(int)$match[1];
        $clean=preg_replace('/(?<!\d)[1-5]\s*(?:X|×)(?![A-Z0-9])/iu',' ',$message,1);
        if ($clean !== null) $message=$clean;
    }
    return [trim($message),$quantity];
}

function order_process_codes(string $message): array
{
    $message=normalize_order_message_spacing($message);
    global $pdo;
    if ($pdo instanceof PDO) {
        ensure_order_code_rules_table($pdo);
        $messageKey=mb_strtolower(trim((string)(preg_replace('/\s+/u',' ',$message) ?? $message)),'UTF-8');
        $rule=$pdo->prepare('SELECT process_code FROM cl_order_code_rules WHERE message_key=? LIMIT 1');
        $rule->execute([$messageKey]);
        $savedCode=trim((string)($rule->fetchColumn() ?: ''));
        if ($savedCode !== '') return array_values(array_filter(array_map('trim',explode('+',$savedCode))));
    }
    // WEB pada hujung mesej memaksa kaedah full-web dan menghasilkan code FW.
    // Simpan flag sebelum mesej dipecahkan supaya format quantity/gabungan juga konsisten.
    $forceFullWeb=(bool)preg_match('/\bWEB\s*$/iu',trim($message));
    if ($forceFullWeb) {
        $withoutWeb=preg_replace('/\bWEB\s*$/iu',' ',trim($message));
        if ($withoutWeb !== null) $message=trim($withoutWeb);
    }
    // "Cara isi: 57+57" menerangkan pecahan UC PUBG, bukannya dua order.
    // Buang baris metadata ini sebelum mentafsir tanda + sebagai pemisah order.
    $parseMessage=preg_replace('/^\s*CARA\s+ISI\s*:[^\r\n]*/imu',' ',$message);
    if ($parseMessage !== null) $message=$parseMessage;
    if (strpos($message,'+') === false) {
        [$singleMessage,$quantity]=order_message_quantity($message);
        $singleCode=order_process_code($singleMessage);
        if ($singleCode !== null && $forceFullWeb) $singleCode=order_full_web_code($singleCode);
        return $singleCode === null ? [] : array_fill(0,$quantity,$singleCode);
    }

    $parts=preg_split('/\s*\+\s*/u',$message);
    if (!is_array($parts) || count($parts)<2) return [];
    foreach ($parts as $part) {
        if (trim((string)$part) === '') return [];
    }

    $fullText=strtoupper($message);
    $context=[];
    if (preg_match('/\b(?:TNG|TOUCH\s*(?:N|AND|&)\s*GO|ONLINE\s+BANKING|T)\b/i',$fullText)) $context[]='T';
    elseif (preg_match('/\b(?:DIGI|DG|D)\b/i',$fullText)) $context[]='D';
    elseif (preg_match('/\b(?:CELCOM|C)\b/i',$fullText)) $context[]='C';

    if (preg_match('/\b(?:MOBILE\s+LEGENDS?|MLBB|ML|M)\b/i',$fullText)) $context[]='ML';
    elseif (preg_match('/\b(?:PUBG\s+MOBILE|PUBG|P)\b/i',$fullText)) $context[]='PUBG';
    elseif (preg_match('/\b(?:FREE\s+FIRE|FF|F)\b/i',$fullText)) $context[]='FF';

    $contextText=implode(' ',$context);
    $codes=[];
    foreach ($parts as $part) {
        [$cleanPart,$quantity]=order_message_quantity((string)$part);
        $candidate=trim($cleanPart.' '.$contextText);
        $code=order_process_code($candidate);
        if ($code === null) return [];
        if ($forceFullWeb) $code=order_full_web_code($code);
        for ($copy=0;$copy<$quantity;$copy++) $codes[]=$code;
    }
    return $codes;
}

function order_full_web_code(string $code): string
{
    $code=strtoupper(trim($code));
    if (str_ends_with($code,'FW')) return $code;
    // WB menandakan formula web/hybrid lama. Untuk full web, gantikan dengan FW.
    if (str_ends_with($code,'WB')) return substr($code,0,-2).'FW';
    return $code.'FW';
}

function save_order_code_rule(PDO $pdo): void
{
    $admin=current_admin($pdo);
    if (!$admin) json_response(['ok'=>false,'message'=>'Login admin diperlukan.'],401);
    if (!in_array((string)($admin['access_scope'] ?? 'admin'),['admin','allocation'],true)) json_response(['ok'=>false,'message'=>'Hanya GNEX dan Nizam boleh mengubah bacaan code.'],403);
    ensure_order_code_rules_table($pdo);
    $messageId=(int)($_POST['message_id'] ?? 0);
    $code=strtoupper(trim(clean_text($_POST['process_code'] ?? '',80)));
    if ($messageId<=0 || $code==='' || !preg_match('/^[A-Z0-9.]+(?:\+[A-Z0-9.]+)*$/',$code)) json_response(['ok'=>false,'message'=>'Code pembetulan tidak sah.'],422);
    $stmt=$pdo->prepare('SELECT message FROM cl_order_messages WHERE id=? LIMIT 1');
    $stmt->execute([$messageId]);
    $message=trim((string)($stmt->fetchColumn() ?: ''));
    if ($message==='') json_response(['ok'=>false,'message'=>'Mesej tidak ditemui.'],404);
    $messageKey=mb_strtolower(trim((string)(preg_replace('/\s+/u',' ',normalize_order_message_spacing($message)) ?? $message)),'UTF-8');
    $save=$pdo->prepare('INSERT INTO cl_order_code_rules (message_key,raw_example,process_code,created_by_admin_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE raw_example=VALUES(raw_example),process_code=VALUES(process_code),created_by_admin_id=VALUES(created_by_admin_id),updated_at=CURRENT_TIMESTAMP');
    $save->execute([$messageKey,$message,$code,(int)$admin['id']]);
    $pdo->prepare('UPDATE cl_order_messages SET process_code=?,updated_by_admin_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$code,(int)$admin['id'],$messageId]);
    json_response(['ok'=>true,'message'=>'Cara bacaan disimpan. Mesej sama selepas ini akan dibaca sebagai '.$code.'.','process_code'=>$code]);
}

function ensure_order_code_rules_table(PDO $pdo): void
{
    static $ready=false;
    if ($ready) return;
    $pdo->exec('CREATE TABLE IF NOT EXISTS cl_order_code_rules (id INT AUTO_INCREMENT PRIMARY KEY,message_key VARCHAR(500) NOT NULL,raw_example TEXT NOT NULL,process_code VARCHAR(80) NOT NULL,created_by_admin_id INT NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uniq_order_code_message (message_key),INDEX idx_order_code_admin (created_by_admin_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $ready=true;
}

function order_process_code(string $message): ?string
{
    $text=strtoupper(trim(normalize_order_message_spacing($message)));
    $compact=preg_replace('/\s+/u', '', $text) ?? '';

    // A compact message such as "mdf" is shorthand, not an already ordered
    // product code: M=monthly, D=Digi, F=Free Fire. Accept the three parts in
    // any order, then always emit the canonical game-payment-product order.
    $compactGames=[
        'F'=>['F','FF'],
        'M'=>['M','ML','MLBB'],
        'P'=>['P','PUBG'],
    ];
    $compactPayments=['T'=>['T'],'D'=>['D'],'C'=>['C']];
    $compactMemberships=['W'=>['W'],'WL'=>['WL'],'M'=>['M'],'CM'=>['CM']];
    foreach ($compactGames as $compactGameCode=>$gameAliases) {
        foreach ($compactPayments as $compactPaymentCode=>$paymentAliases) {
            foreach ($compactMemberships as $compactMembershipCode=>$membershipAliases) {
                foreach ($gameAliases as $gameAlias) {
                    foreach ($paymentAliases as $paymentAlias) {
                        foreach ($membershipAliases as $membershipAlias) {
                            $orders=[
                                $gameAlias.$paymentAlias.$membershipAlias,
                                $gameAlias.$membershipAlias.$paymentAlias,
                                $paymentAlias.$gameAlias.$membershipAlias,
                                $paymentAlias.$membershipAlias.$gameAlias,
                                $membershipAlias.$gameAlias.$paymentAlias,
                                $membershipAlias.$paymentAlias.$gameAlias,
                            ];
                            if (in_array($compact,$orders,true)) {
                                return $compactGameCode.$compactPaymentCode.$compactMembershipCode.($compactGameCode === 'M' ? 'WB' : '');
                            }
                        }
                    }
                }
            }
        }
    }
    if (preg_match('/^[FMP][TDC][A-Z0-9]{1,20}$/', $compact)) {
        return $compact;
    }

    $paymentCode=null;
    if (preg_match('/\b(?:TNG|TOUCH\s*(?:N|AND|&)\s*GO|ONLINE\s+BANKING|QR\s+TRANSFER|T)\b/i', $text)) $paymentCode='T';
    elseif (preg_match('/\b(?:DIGI|DG|D)\b/i', $text)) $paymentCode='D';
    elseif (preg_match('/\b(?:CELCOM|C)\b/i', $text)) $paymentCode='C';
    elseif (
        preg_match('/\bHELLO\s+GNEX\b/i',$text) &&
        preg_match('/\bPACKAGE\s*:/i',$text) &&
        preg_match('/\bPLAYER\s*ID\s*:/i',$text) &&
        preg_match('/\bPUBG\b/i',$text)
    ) $paymentCode='T';
    if ($paymentCode === null) return null;

    $amount=null;
    if (preg_match('/\bHARGA\s*:\s*(?:MYR|RM)\s*(\d+(?:\.\d{1,2})?)/i', $text, $priceMatch)) {
        $amount=(float)$priceMatch[1];
    } elseif (preg_match('/\b(?:MYR|RM)\s*(\d+(?:\.\d{1,2})?)/i', $text, $rmMatch)) {
        $amount=(float)$rmMatch[1];
    } elseif (preg_match('/\b(\d+(?:\.\d{1,2})?)\s*(?:TNG|TOUCH\s*(?:N|AND|&)\s*GO|ONLINE\s+BANKING|DIGI|DG|CELCOM|T|D|C)\b/i', $text, $numberMatch)) {
        $amount=(float)$numberMatch[1];
    }
    if ($amount !== null && $amount <= 0) return null;

    $membershipCode=null;
    if (preg_match('/\b(?:WEEKLY\s+LITE|WL|LITE)\b/i', $text)) $membershipCode='WL';
    elseif (preg_match('/\b(?:COMBO|CM)\b/i', $text)) $membershipCode='CM';
    elseif (preg_match('/\b(?:WEEKLY|W)\b/i', $text)) $membershipCode='W';
    elseif (preg_match('/\b(?:MONTHLY|MONTH)\b/i', $text)) $membershipCode='M';

    $gameCode='F';
    $hasExplicitMl=(bool)preg_match('/\b(?:MOBILE\s+LEGENDS?|MLBB|ML)\b/i', $text);
    if ($hasExplicitMl) $gameCode='M';
    elseif (preg_match('/\b(?:PUBG\s+MOBILE|PUBG|P)\b/i', $text)) $gameCode='P';
    elseif (preg_match('/\b(?:FREE\s+FIRE|FF|F)\b/i', $text)) $gameCode='F';

    $hasSingleM=(bool)preg_match('/\bM\b/i', $text);
    if (!$hasExplicitMl && $hasSingleM) {
        if ($membershipCode !== null || $amount !== null) {
            $gameCode='M';
        } else {
            $membershipCode='M';
        }
    }

    if ($membershipCode === null && $amount === null) return null;
    $productCode=$membershipCode;
    if ($productCode === null && $amount !== null) {
        $productCode=fmod($amount,1.0) === 0.0
            ? (string)(int)$amount
            : rtrim(rtrim(number_format($amount,2,'.',''),'0'),'.');
    }

    $needsWeb=false;
    if ($gameCode === 'M') {
        $needsWeb=true;
    } elseif ($gameCode === 'F' && $membershipCode === null && $amount !== null) {
        $usesDigiShell=$paymentCode === 'D' && $amount >= 5 && $amount <= 25;
        $usesTngShell=$paymentCode === 'T' && $amount >= 1 && $amount <= 19;
        $needsWeb=!$usesDigiShell && !$usesTngShell;
    }

    return $gameCode.$paymentCode.$productCode.($needsWeb ? 'WB' : '');
}

function post_order_to_sheet(string $url, array $payload): array
{
    if (!function_exists('curl_init')) return ['ok'=>false,'message'=>'PHP cURL belum aktif pada hosting.'];
    $curl=curl_init($url);
    curl_setopt_array($curl,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER=>['Content-Type: text/plain;charset=utf-8'],
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>30,
    ]);
    $body=curl_exec($curl);
    $error=curl_error($curl);
    $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($body === false || $error !== '') return ['ok'=>false,'message'=>$error ?: 'Google Sheet tidak dapat dicapai.'];
    $decoded=json_decode((string)$body,true);
    if (!is_array($decoded)) return ['ok'=>false,'message'=>'Respons Apps Script tidak sah (HTTP '.$status.').'];
    if ($status < 200 || $status >= 300) return ['ok'=>false,'message'=>(string)($decoded['message'] ?? 'HTTP '.$status)];
    return $decoded;
}

function admin_create_team(PDO $pdo): void
{
    if (!current_admin($pdo)) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan untuk tambah team.'], 401);
    }

    $teamName = to_upper_text(clean_text($_POST['team_name'] ?? '', 100));
    $phone = clean_text($_POST['phone'] ?? '', 40);
    $password = (string) ($_POST['team_password'] ?? '');
    if ($teamName === '' || $phone === '' || $password === '') {
        json_response(['ok' => false, 'message' => 'Nama team, nombor telefon dan password wajib diisi.'], 422);
    }
    if (!is_valid_team_name($teamName)) {
        json_response(['ok' => false, 'message' => 'Nama team tidak boleh mempunyai simbol.'], 422);
    }
    if (!is_valid_team_password($password)) {
        json_response(['ok' => false, 'message' => 'Password mesti mempunyai sekurang-kurangnya 1 huruf besar, 1 nombor dan 1 simbol.'], 422);
    }

    $duplicate = $pdo->prepare('SELECT id,team_name,phone FROM cl_teams WHERE status != "removed" AND (LOWER(team_name)=LOWER(?) OR phone=?) LIMIT 1');
    $duplicate->execute([$teamName, $phone]);
    $existing = $duplicate->fetch();
    if ($existing) {
        $message = strcasecmp((string)$existing['team_name'], $teamName) === 0
            ? 'Nama team ini sudah digunakan.'
            : 'Nombor telefon ini sudah digunakan oleh team lain.';
        json_response(['ok' => false, 'message' => $message], 409);
    }

    $pdo->beginTransaction();
    try {
        // Lock semua accepted team dahulu supaya slot baharu sentiasa unik dan
        // terus wujud walaupun proses membuat jadual selepas ini bermasalah.
        $pdo->query('SELECT id FROM cl_teams WHERE status="accepted" FOR UPDATE')->fetchAll();
        $slotNo = (int)$pdo->query('SELECT COALESCE(MAX(slot_no),0)+1 FROM cl_teams WHERE status="accepted" AND is_test_account=0')->fetchColumn();
        $insert = $pdo->prepare('INSERT INTO cl_teams (team_name,phone,password_hash,status,slot_no,admin_checked,admin_added,profile_update_required,updated_at) VALUES (?,?,?,"accepted",?,1,1,1,CURRENT_TIMESTAMP)');
        $insert->execute([$teamName, $phone, password_hash($password, PASSWORD_DEFAULT), $slotNo]);
        $teamId = (int)$pdo->lastInsertId();
        $room = $pdo->prepare('INSERT INTO cl_rooms (room_type,team_a_id,status,updated_at) VALUES ("admin",?,"open",CURRENT_TIMESTAMP)');
        $room->execute([$teamId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    $scheduleMessage = '';
    try {
        if (clean_text($_POST['schedule_context'] ?? '', 30) === 'group_stage') {
            sync_group_stage_matches($pdo);
        } else {
            sync_admin_added_team_schedule($pdo);
        }
    } catch (Throwable $scheduleError) {
        error_log('Clash League late-team schedule gagal untuk team '.$teamId.': '.$scheduleError->getMessage());
        $scheduleMessage = ' Team sudah masuk Slot '.$slotNo.', tetapi jadual belum berjaya dibuat dan boleh dicuba semula kemudian.';
    }

    json_response(get_state($pdo) + [
        'created_team_id' => $teamId,
        'created_team_slot' => $slotNo,
        'message' => 'Team berjaya ditambah sebagai Slot '.$slotNo.'.'.$scheduleMessage.' Selepas login, team wajib lengkapkan IGN dan ID untuk P1 hingga P4.',
    ]);
}

function sync_admin_added_team_schedule(PDO $pdo): void
{
    $pdo->beginTransaction();
    try {
        $waitingMatch = $pdo->query('
            SELECT id,team_a_id FROM cl_matches
            WHERE stage_code="late_q1" AND status IN ("up_next","live")
              AND team_a_id IS NOT NULL AND team_b_id IS NULL
            ORDER BY id ASC LIMIT 1 FOR UPDATE
        ')->fetch();
        $teams = $pdo->query('
            SELECT t.id FROM cl_teams t
            WHERE t.status="accepted" AND t.is_test_account=0 AND t.admin_added=1
              AND NOT EXISTS (SELECT 1 FROM cl_matches m WHERE m.team_a_id=t.id OR m.team_b_id=t.id)
            ORDER BY t.created_at ASC,t.id ASC FOR UPDATE
        ')->fetchAll(PDO::FETCH_COLUMN);
        if (!$teams) {
            $pdo->commit();
            return;
        }

        $updateWaiting = $pdo->prepare('UPDATE cl_matches SET team_b_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $updateRoom = $pdo->prepare('UPDATE cl_rooms SET team_b_id=?,status="open",updated_at=CURRENT_TIMESTAMP WHERE match_id=? AND room_type="match"');
        if ($waitingMatch) {
            $incoming = (int)array_shift($teams);
            $updateWaiting->execute([$incoming,(int)$waitingMatch['id']]);
            $updateRoom->execute([$incoming,(int)$waitingMatch['id']]);
        }

        $count = (int)$pdo->query('SELECT COUNT(*) FROM cl_matches WHERE stage_code="late_q1"')->fetchColumn();
        $insertMatch = $pdo->prepare('
            INSERT INTO cl_matches (team_a_id,team_b_id,match_name,stage_code,match_time,status,updated_at)
            VALUES (?, ?, ?, "late_q1", "2026-08-11 21:30:00", "up_next", CURRENT_TIMESTAMP)
        ');
        $insertRoom = $pdo->prepare('INSERT INTO cl_rooms (room_type,team_a_id,team_b_id,match_id,status,updated_at) VALUES ("match",?,?,?,"open",CURRENT_TIMESTAMP)');
        for ($i=0; $i<count($teams); $i+=2) {
            $teamA=(int)$teams[$i];
            $teamB=isset($teams[$i+1]) ? (int)$teams[$i+1] : null;
            $count++;
            $insertMatch->execute([$teamA,$teamB,'TEAM BARU / KELAYAKAN SLOT / BO3 '.$count]);
            $matchId=(int)$pdo->lastInsertId();
            $insertRoom->execute([$teamA,$teamB,$matchId]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function register_team(PDO $pdo, string $rootDir): void
{
    global $pushConfig;

    $registrationClosesAt = new DateTimeImmutable('2026-08-03 23:00:00', new DateTimeZone('Asia/Kuala_Lumpur'));
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur'));
    if ($now >= $registrationClosesAt) {
        json_response([
            'ok' => false,
            'message' => 'Pendaftaran telah ditutup pada 3 Ogos 2026, 11:00 PM.',
        ], 403);
    }

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
        SELECT t.id, t.team_name, t.logo_url, t.slot_no, t.status, t.phone, t.coach_name, t.manager_name, t.profile_update_required,
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

    $completeMainPlayers = 0;
    foreach ($players as $player) {
        $slot = (int) preg_replace('/\D+/', '', (string) $player['slot']);
        if ($slot >= 1 && $slot <= 4 && trim($player['ign']) !== '' && trim($player['id']) !== '') {
            $completeMainPlayers++;
        }
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
        'is_test_account' => !empty($team['is_test_account']),
        'has_schedule' => !empty($team['has_schedule']),
        'online' => !empty($team['last_seen_at']) && strtotime((string) $team['last_seen_at']) >= time() - 120,
        'last_seen_at' => (string) ($team['last_seen_at'] ?? ''),
        'notification' => [
            'devices' => (int) ($team['push_devices'] ?? 0),
            'tested_devices' => (int) ($team['push_tested_devices'] ?? 0),
            'healthy_devices' => (int) ($team['push_healthy_devices'] ?? 0),
            'ready' => (int) ($team['push_tested_devices'] ?? 0) > 0 && (int) ($team['push_healthy_devices'] ?? 0) > 0,
        ],
        'players' => $players,
        'profile_incomplete' => !empty($team['profile_update_required']) && $completeMainPlayers < 4,
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

function ensure_group_stage_fixtures(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS cl_group_stage_fixtures (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fixture_key VARCHAR(40) NOT NULL UNIQUE,
        group_code VARCHAR(4) NOT NULL,
        team_a_name VARCHAR(100) NOT NULL,
        team_b_name VARCHAR(100) NOT NULL,
        match_time DATETIME NOT NULL,
        match_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_cl_group_fixture_time (match_time),
        FOREIGN KEY (match_id) REFERENCES cl_matches(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $fixtures = [
        ['A01','A','GNEX ELITE','LEVEL PRIME','2026-08-21 21:00:00'],['A02','A','KWNPAIM','ANTI MACRO','2026-08-21 21:30:00'],
        ['B01','B','ZEQ N FRIEND','CUCUTOKABAH','2026-08-21 22:00:00'],['B02','B','SUPERZAIM','SQUAD SABAH','2026-08-21 22:30:00'],
        ['C01','C','MYD666','NINESTAR ASIX','2026-08-22 21:00:00'],['C02','C','ATLANTIS REALITY','HUNTRIX LADIES','2026-08-22 21:30:00'],
        ['D01','D','7GL X TEAMBOT','SECRET55','2026-08-22 22:00:00'],['D02','D','GNRS MY','TITIK','2026-08-22 22:30:00'],
        ['A03','A','GNEX ELITE','KWNPAIM','2026-08-23 21:00:00'],['A04','A','GNEX ELITE','ANTI MACRO','2026-08-23 21:30:00'],
        ['A05','A','LEVEL PRIME','KWNPAIM','2026-08-23 22:00:00'],['A06','A','LEVEL PRIME','ANTI MACRO','2026-08-23 22:30:00'],
        ['B03','B','ZEQ N FRIEND','SUPERZAIM','2026-08-24 21:00:00'],['B04','B','ZEQ N FRIEND','SQUAD SABAH','2026-08-24 21:30:00'],
        ['B05','B','CUCUTOKABAH','SUPERZAIM','2026-08-24 22:00:00'],['B06','B','CUCUTOKABAH','SQUAD SABAH','2026-08-24 22:30:00'],
        ['C03','C','MYD666','ATLANTIS REALITY','2026-08-25 21:00:00'],['C04','C','MYD666','HUNTRIX LADIES','2026-08-25 21:30:00'],
        ['C05','C','NINESTAR ASIX','ATLANTIS REALITY','2026-08-25 22:00:00'],['C06','C','NINESTAR ASIX','HUNTRIX LADIES','2026-08-25 22:30:00'],
        ['D03','D','7GL X TEAMBOT','GNRS MY','2026-08-26 21:00:00'],['D04','D','7GL X TEAMBOT','TITIK','2026-08-26 21:30:00'],
        ['D05','D','SECRET55','GNRS MY','2026-08-26 22:00:00'],['D06','D','SECRET55','TITIK','2026-08-26 22:30:00'],
    ];
    $insert = $pdo->prepare('INSERT IGNORE INTO cl_group_stage_fixtures (fixture_key,group_code,team_a_name,team_b_name,match_time) VALUES (?,?,?,?,?)');
    foreach ($fixtures as $fixture) $insert->execute($fixture);
}

function sync_group_stage_matches(PDO $pdo): void
{
    ensure_group_stage_fixtures($pdo);
    $teams = [];
    foreach ($pdo->query('SELECT id,UPPER(team_name) team_name FROM cl_teams WHERE status="accepted" AND is_test_account=0')->fetchAll() as $team) {
        $teams[(string)$team['team_name']] = (int)$team['id'];
    }
    $findMatch = $pdo->prepare('SELECT id FROM cl_matches WHERE id=? LIMIT 1');
    $insertMatch = $pdo->prepare('INSERT INTO cl_matches (team_a_id,team_b_id,match_name,stage_code,match_time,status,updated_at) VALUES (?,? ,?,"group_stage_2026",?,"up_next",CURRENT_TIMESTAMP)');
    $updateMatch = $pdo->prepare('UPDATE cl_matches SET team_a_id=?,team_b_id=?,match_name=?,stage_code="group_stage_2026",match_time=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
    $link = $pdo->prepare('UPDATE cl_group_stage_fixtures SET match_id=? WHERE id=?');
    foreach ($pdo->query('SELECT * FROM cl_group_stage_fixtures ORDER BY match_time,id')->fetchAll() as $fixture) {
        $teamA = $teams[mb_strtoupper((string)$fixture['team_a_name'])] ?? 0;
        $teamB = $teams[mb_strtoupper((string)$fixture['team_b_name'])] ?? 0;
        if (!$teamA || !$teamB || $teamA === $teamB) continue;
        $matchId = (int)($fixture['match_id'] ?? 0);
        if ($matchId) { $findMatch->execute([$matchId]); if (!$findMatch->fetchColumn()) $matchId=0; }
        $name = 'GROUP '.$fixture['group_code'].' / BO1 / 13 ROUND';
        if ($matchId) $updateMatch->execute([$teamA,$teamB,$name,$fixture['match_time'],$matchId]);
        else {
            try {
                $insertMatch->execute([$teamA,$teamB,$name,$fixture['match_time']]);
            } catch (PDOException $error) {
                // Sesetengah restore/import production mengekalkan ID lama tetapi
                // meninggalkan AUTO_INCREMENT di belakang MAX(id). Repair sekali
                // dan retry supaya sync fixture kekal idempotent.
                if ((string)$error->getCode() !== '23000' || stripos($error->getMessage(), 'PRIMARY') === false) throw $error;
                $nextMatchId=(int)$pdo->query('SELECT COALESCE(MAX(id),0)+1 FROM cl_matches')->fetchColumn();
                $pdo->exec('ALTER TABLE cl_matches AUTO_INCREMENT='.$nextMatchId);
                $insertMatch->execute([$teamA,$teamB,$name,$fixture['match_time']]);
            }
            $matchId=(int)$pdo->lastInsertId();
            $link->execute([$matchId,(int)$fixture['id']]);
        }
    }
}

function group_stage_admin_data(PDO $pdo): void
{
    if (!current_admin($pdo)) json_response(['ok'=>false,'message'=>'Login admin diperlukan.'],401);
    sync_group_stage_matches($pdo);
    $teams = $pdo->query('SELECT id,team_name,logo_url,status,slot_no,phone FROM cl_teams WHERE status!="removed" AND is_test_account=0 ORDER BY team_name')->fetchAll();
    $fixtures = $pdo->query('SELECT id,fixture_key,group_code,team_a_name,team_b_name,DATE_FORMAT(match_time,"%Y-%m-%dT%H:%i") match_time,match_id FROM cl_group_stage_fixtures ORDER BY match_time,id')->fetchAll();
    json_response(['ok'=>true,'teams'=>$teams,'fixtures'=>$fixtures]);
}

function save_group_stage_fixture(PDO $pdo): void
{
    if (!current_admin($pdo)) json_response(['ok'=>false,'message'=>'Login admin diperlukan.'],401);
    ensure_group_stage_fixtures($pdo);
    $id=(int)($_POST['fixture_id'] ?? 0);
    $teamA=to_upper_text(clean_text($_POST['team_a_name'] ?? '',100));
    $teamB=to_upper_text(clean_text($_POST['team_b_name'] ?? '',100));
    $time=normalize_match_time($_POST['match_time'] ?? '');
    if ($id<=0 || $teamA==='' || $teamB==='' || $teamA===$teamB || !$time) json_response(['ok'=>false,'message'=>'Peserta, tarikh atau masa tidak sah.'],422);
    $valid=$pdo->prepare('SELECT COUNT(*) FROM cl_teams WHERE status="accepted" AND is_test_account=0 AND team_name IN (?,?)');
    $valid->execute([$teamA,$teamB]);
    if ((int)$valid->fetchColumn()!==2) json_response(['ok'=>false,'message'=>'Kedua-dua team mesti sudah ada account aktif.'],422);
    $stmt=$pdo->prepare('UPDATE cl_group_stage_fixtures SET team_a_name=?,team_b_name=?,match_time=? WHERE id=?');
    $stmt->execute([$teamA,$teamB,$time,$id]);
    if (!$stmt->rowCount()) {
        $exists=$pdo->prepare('SELECT COUNT(*) FROM cl_group_stage_fixtures WHERE id=?');
        $exists->execute([$id]);
        if (!(int)$exists->fetchColumn()) json_response(['ok'=>false,'message'=>'Fixture tidak dijumpai.'],404);
    }
    sync_group_stage_matches($pdo);
    json_response(['ok'=>true,'message'=>'Jadual Group Stage berjaya dikemas kini.']);
}

function current_team(PDO $pdo): ?array
{
    restore_persistent_login($pdo);
    if (empty($_SESSION['cl_team_id'])) {
        return null;
    }

    $stmt = $pdo->prepare('
        SELECT id, team_name, logo_url, slot_no, status, phone, coach_name, manager_name, admin_note, is_test_account, profile_update_required, updated_at
        FROM cl_teams
        WHERE id = ? AND status != "removed"
    ');
    $stmt->execute([(int) $_SESSION['cl_team_id']]);
    $team = $stmt->fetch();
    return $team ? serialize_team($pdo, $team) : null;
}

function current_admin(PDO $pdo): ?array
{
    if (!empty($_SESSION['cl_admin_view_as_team'])) {
        return null;
    }
    return current_real_admin($pdo);
}

function current_real_admin(PDO $pdo): ?array
{
    restore_persistent_login($pdo);
    if (empty($_SESSION['cl_admin_id'])) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, username, access_scope FROM cl_admin_users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['cl_admin_id']]);
    $admin = $stmt->fetch();
    if ($admin) $_SESSION['cl_admin_access_scope']=(string)($admin['access_scope'] ?? 'admin');
    return $admin ? ['id' => (int) $admin['id'], 'username' => (string) $admin['username'], 'access_scope' => (string)($admin['access_scope'] ?? 'admin')] : null;
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

function get_info_room_id(PDO $pdo): int
{
    $roomId = (int) ($pdo->query('SELECT id FROM cl_rooms WHERE room_type = "info" AND status = "open" ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
    if ($roomId > 0) return $roomId;
    $pdo->exec('INSERT INTO cl_rooms (room_type, status, updated_at) VALUES ("info", "open", CURRENT_TIMESTAMP)');
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
    if (!empty($team['profile_incomplete'])) {
        json_response(['ok' => false, 'code' => 'profile_incomplete', 'message' => 'Lengkapkan IGN dan ID P1 hingga P4 di Profile sebelum menggunakan fungsi tournament.'], 403);
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
                   tb.team_name AS team_b_name, tb.logo_url AS team_b_logo,
                   tb.slot_no AS team_b_slot, tb.last_seen_at AS team_b_last_seen,
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
            'stage_code' => (string) ($match['stage_code'] ?? ''),
            'match_time' => (string) ($match['match_time'] ?? ''),
            'status' => (string) $match['status'],
            'team_a_name' => $teamARemoved ? 'TBD' : public_team_name((string) ($match['team_a_name'] ?? ($team['team_name'] ?? 'TBD'))),
            'team_a_logo' => $teamARemoved ? '' : (string) ($match['team_a_logo'] ?? ($team['logo_url'] ?? '')),
            'team_b_name' => $teamBRemoved ? 'TBD' : public_team_name((string) ($match['team_b_name'] ?? 'TBD')),
            'team_b_logo' => $teamBRemoved ? '' : (string) ($match['team_b_logo'] ?? ''),
            'team_a_point' => $match['team_a_point'],
            'team_b_point' => $match['team_b_point'],
            'my_result_submitted' => $team ? team_result_exists($pdo, (int) $match['id'], (int) $team['id']) : false,
        ];
    }

    return $matches;
}

function public_team_name(string $name): string
{
    return strcasecmp(trim($name), 'GNEX TEST TDB') === 0 ? 'TDB' : $name;
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
            'team_a_name' => public_team_name((string) ($result['team_a_name'] ?? 'Team A')),
            'team_a_logo' => (string) ($result['team_a_logo'] ?? ''),
            'team_b_name' => public_team_name((string) ($result['team_b_name'] ?? 'Team B')),
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
    $infoRoomId = get_info_room_id($pdo);
    if ($admin) {
        initialize_admin_room_reads($pdo, (int) $admin['id']);
    }
    $ownerReadSql = $admin
        ? '(SELECT rr.last_message_id FROM cl_room_reads rr WHERE rr.room_id = r.id AND rr.owner_type = "admin" AND rr.admin_id = ' . (int) $admin['id'] . ' LIMIT 1)'
        : ($team
            ? '(SELECT rr.last_message_id FROM cl_room_reads rr WHERE rr.room_id = r.id AND rr.owner_type = "team" AND rr.team_id = ' . (int) $team['id'] . ' LIMIT 1)'
            : '0');
    $incomingUnreadSql = $admin
        ? '(SELECT COUNT(*) FROM cl_messages unread_cm WHERE unread_cm.room_id = r.id AND unread_cm.id > COALESCE(' . $ownerReadSql . ', 0) AND unread_cm.sender_type = "team")'
        : ($team
            ? '(SELECT COUNT(*) FROM cl_messages unread_cm WHERE unread_cm.room_id = r.id AND unread_cm.id > COALESCE(' . $ownerReadSql . ', 0) AND (unread_cm.sender_type IN ("admin", "system") OR unread_cm.sender_team_id IS NULL OR unread_cm.sender_team_id != ' . (int) $team['id'] . '))'
            : '0');
    $selectSql = '
        SELECT r.id, r.room_type, r.team_a_id, r.team_b_id, r.status, r.admin_checked, r.report_match,
                   ta.team_name AS team_a_name, ta.logo_url AS team_a_logo,
                   ta.status AS team_a_status, ta.slot_no AS team_a_slot, ta.last_seen_at AS team_a_last_seen,
                   tb.team_name AS team_b_name, tb.logo_url AS team_b_logo,
                   tb.slot_no AS team_b_slot, tb.last_seen_at AS team_b_last_seen,
                   (SELECT message FROM cl_messages cm WHERE cm.room_id = r.id ORDER BY cm.id DESC LIMIT 1) AS last_message,
                   (SELECT id FROM cl_messages cm WHERE cm.room_id = r.id ORDER BY cm.id DESC LIMIT 1) AS last_message_id,
                   (SELECT sender_type FROM cl_messages cm WHERE cm.room_id = r.id ORDER BY cm.id DESC LIMIT 1) AS last_sender_type,
                   (SELECT MAX(id) FROM cl_messages cm WHERE cm.room_id = r.id AND cm.sender_type = "team") AS last_team_message_id,
                   (SELECT MAX(id) FROM cl_messages cm WHERE cm.room_id = r.id AND cm.sender_type = "admin") AS last_admin_message_id,
                   ' . $ownerReadSql . ' AS seen_message_id,
                   ' . $incomingUnreadSql . ' AS unread_count,
                   COALESCE(linked_match.match_name, (SELECT m.match_name FROM cl_matches m
                    WHERE (m.team_a_id = r.team_a_id AND m.team_b_id = r.team_b_id)
                       OR (m.team_a_id = r.team_b_id AND m.team_b_id = r.team_a_id)
                    ORDER BY m.id DESC LIMIT 1)) AS match_name,
                   COALESCE(linked_match.match_time, (SELECT m.match_time FROM cl_matches m
                    WHERE (m.team_a_id = r.team_a_id AND m.team_b_id = r.team_b_id)
                       OR (m.team_a_id = r.team_b_id AND m.team_b_id = r.team_a_id)
                    ORDER BY m.id DESC LIMIT 1)) AS match_time,
                    COALESCE(linked_match.id, (SELECT m.id FROM cl_matches m
                     WHERE (m.team_a_id = r.team_a_id AND m.team_b_id = r.team_b_id)
                        OR (m.team_a_id = r.team_b_id AND m.team_b_id = r.team_a_id)
                     ORDER BY m.id DESC LIMIT 1)) AS match_id,
                    COALESCE(linked_match.status, (SELECT m.status FROM cl_matches m
                     WHERE (m.team_a_id = r.team_a_id AND m.team_b_id = r.team_b_id)
                        OR (m.team_a_id = r.team_b_id AND m.team_b_id = r.team_a_id)
                     ORDER BY m.id DESC LIMIT 1)) AS match_status
            FROM cl_rooms r
            LEFT JOIN cl_matches linked_match ON linked_match.id = r.match_id
            LEFT JOIN cl_teams ta ON ta.id = r.team_a_id
            LEFT JOIN cl_teams tb ON tb.id = r.team_b_id
    ';
    if ($admin) {
        $stmt = $pdo->prepare($selectSql . '
            ORDER BY
                FIELD(r.status, "open", "closed"),
                FIELD(r.room_type, "info", "group", "admin", "deal", "match"),
                r.updated_at DESC,
                r.id DESC
        ');
        $stmt->execute();
    } elseif ($allowPersonal) {
        $teamId = (int) $team['id'];
        $stmt = $pdo->prepare($selectSql . '
            WHERE r.status = "open" AND (r.id IN (?, ?) OR r.team_a_id = ? OR r.team_b_id = ?)
            ORDER BY FIELD(r.room_type, "info", "group", "admin", "deal", "match"), r.updated_at DESC, r.id DESC
        ');
        $stmt->execute([$groupRoomId, $infoRoomId, $teamId, $teamId]);
    } else {
        $stmt = $pdo->prepare($selectSql . ' WHERE r.id IN (?, ?) AND r.status = "open" ORDER BY FIELD(r.room_type, "info", "group")');
        $stmt->execute([$groupRoomId, $infoRoomId]);
    }

    $rawRooms = $stmt->fetchAll();
    $matchIds = array_values(array_unique(array_filter(array_map(
        static fn(array $room): int => (int) ($room['match_id'] ?? 0),
        $rawRooms
    ))));
    $attendanceByMatch = [];
    $resultsByMatch = [];
    $latestTeamMessageByRoom = [];
    $roomIds = array_values(array_unique(array_filter(array_map(
        static fn(array $room): int => (int) ($room['id'] ?? 0),
        $rawRooms
    ))));
    if ($roomIds) {
        $roomPlaceholders = implode(',', array_fill(0, count($roomIds), '?'));
        $messageStmt = $pdo->prepare("
            SELECT room_id, sender_team_id, MAX(created_at) AS latest_message_at
            FROM cl_messages
            WHERE room_id IN ($roomPlaceholders)
              AND sender_type = 'team'
              AND sender_team_id IS NOT NULL
            GROUP BY room_id, sender_team_id
        ");
        $messageStmt->execute($roomIds);
        foreach ($messageStmt->fetchAll() as $messageRow) {
            $latestTeamMessageByRoom[(int) $messageRow['room_id']][(int) $messageRow['sender_team_id']]
                = (string) $messageRow['latest_message_at'];
        }
    }
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
        $resultStmt = $pdo->prepare("
            SELECT match_id, team_id, team_a_point, team_b_point, status
            FROM cl_match_results
            WHERE match_id IN ($attendancePlaceholders)
        ");
        $resultStmt->execute($matchIds);
        foreach ($resultStmt->fetchAll() as $result) {
            $resultsByMatch[(int) $result['match_id']][(int) $result['team_id']] = [
                'team_a_point' => (int) $result['team_a_point'],
                'team_b_point' => (int) $result['team_b_point'],
                'status' => (string) $result['status'],
            ];
        }
    }

    $rooms = [];
    foreach ($rawRooms as $room) {
        $isGroupRoom = $room['room_type'] === 'group';
        $isInfoRoom = $room['room_type'] === 'info';
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
        $roomResults = $resultsByMatch[$matchId] ?? [];
        $teamAId = (int) ($room['team_a_id'] ?? 0);
        $teamBId = (int) ($room['team_b_id'] ?? 0);
        $roomReadiness = [];
        foreach (array_filter([$teamAId, $teamBId]) as $participantId) {
            $confirmedAt = (string) ($roomAttendance[$participantId] ?? '');
            $latestMessageAt = (string) ($latestTeamMessageByRoom[(int) $room['id']][$participantId] ?? '');
            $roomReadiness[$participantId] = $confirmedAt !== ''
                && $latestMessageAt !== ''
                && strtotime($latestMessageAt) > strtotime($confirmedAt)
                ? $latestMessageAt
                : '';
        }
        $teamAResult = $roomResults[$teamAId] ?? null;
        $teamBResult = $roomResults[$teamBId] ?? null;
        $resultState = 'none';
        if ($teamAResult || $teamBResult) {
            $resultState = 'waiting';
        }
        if ($teamAResult && $teamBResult) {
            $resultState = ((int) $teamAResult['team_a_point'] === (int) $teamBResult['team_a_point']
                && (int) $teamAResult['team_b_point'] === (int) $teamBResult['team_b_point'])
                ? 'matched'
                : 'disputed';
        }
        if ((string) ($room['match_status'] ?? '') === 'completed') {
            $resultState = 'approved';
        }
        $title = $isInfoRoom ? 'INFO TOUR' : ($isGroupRoom ? 'PERTANYAAN / QUESTION' : ($isAdminRoom ? ($admin ? $teamDisplayName : 'ADMIN') : ($teamName . ' vs ' . (string) ($room['team_b_name'] ?? 'TBD'))));
        $avatar = $isInfoRoom ? 'INFO' : ($isGroupRoom ? 'Q' : ($isAdminRoom ? ($admin ? make_initials($teamName) : 'AD') : 'VS'));
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
            'team_a_slot' => (int) ($room['team_a_slot'] ?? 0),
            'team_b_slot' => (int) ($room['team_b_slot'] ?? 0),
            'team_a_online' => !empty($room['team_a_last_seen']) && strtotime((string) $room['team_a_last_seen']) >= time() - 120,
            'team_b_online' => !empty($room['team_b_last_seen']) && strtotime((string) $room['team_b_last_seen']) >= time() - 120,
            'team_a_last_seen' => (string) ($room['team_a_last_seen'] ?? ''),
            'team_b_last_seen' => (string) ($room['team_b_last_seen'] ?? ''),
            'title' => $title,
            'subtitle' => $isInfoRoom ? 'Pengumuman rasmi tournament' : ($isGroupRoom ? 'Pertanyaan awam · semua boleh chat' : ($isAdminRoom ? ($admin ? 'Chat ke admin' : $teamName) : 'Clash League Deal')),
            'avatar' => $avatar,
            'avatar_logo' => $isInfoRoom ? '/images/clash-league.png' : ($isGroupRoom ? '/images/question-chat-profile.png?v=20260726-2' : ($isAdminRoom && $admin ? $teamLogo : '')),
            'status' => (string) $room['status'],
            'admin_checked' => !empty($room['admin_checked']),
            'report_match' => !empty($room['report_match']),
            'last_message' => (string) ($room['last_message'] ?? 'Belum ada chat.'),
            'last_message_id' => (int) ($room['last_message_id'] ?? 0),
            'last_sender_type' => (string) ($room['last_sender_type'] ?? ''),
            'awaiting_admin_reply' => $admin
                && (int) ($room['last_team_message_id'] ?? 0) > (int) ($room['last_admin_message_id'] ?? 0),
            'seen_message_id' => (int) ($room['seen_message_id'] ?? 0),
            'unread_count' => (int) ($room['unread_count'] ?? 0),
            'match_name' => (string) ($room['match_name'] ?? ''),
            'match_id' => $matchId,
            'match_status' => (string) ($room['match_status'] ?? ''),
            'match_time' => $matchTime,
            'match_date' => $matchTime !== '' ? substr($matchTime, 0, 10) : '',
            'attendance' => $roomAttendance,
            'readiness_messages' => $roomReadiness,
            'team_a_result_submitted' => $teamAResult !== null,
            'team_b_result_submitted' => $teamBResult !== null,
            'result_state' => $resultState,
            'attendance_opens_at' => $attendanceOpensAt,
            'attendance_open' => $attendanceOpen,
            'attendance_closed' => $attendanceClosed,
            'my_attendance' => $team ? (string) ($roomAttendance[(int) $team['id']] ?? '') : '',
            'my_readiness_message' => $team ? (string) ($roomReadiness[(int) $team['id']] ?? '') : '',
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
        SELECT "admin", ?, r.id, 0, CURRENT_TIMESTAMP
        FROM cl_rooms r
    ');
    $stmt->execute([$adminId]);
}

function repair_shared_admin_unread_history_once(PDO $pdo): void
{
    $repairKey = 'shared_admin_unread_history_v1';
    $stmt = $pdo->prepare('SELECT setting_value FROM cl_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$repairKey]);
    if ((string) ($stmt->fetchColumn() ?: '') === 'complete') {
        return;
    }

    $pdo->beginTransaction();
    try {
        // Older initialization treated every existing chat as already read.
        // Restore those messages once for full admin accounts. Stock-only
        // accounts are intentionally excluded from tournament chat history.
        $pdo->exec('
            UPDATE cl_room_reads rr
            INNER JOIN cl_admin_users au ON au.id = rr.admin_id
            SET rr.last_message_id = 0, rr.updated_at = CURRENT_TIMESTAMP
            WHERE rr.owner_type = "admin"
              AND COALESCE(au.access_scope, "admin") = "admin"
        ');
        $marker = $pdo->prepare('
            INSERT INTO cl_settings (setting_key, setting_value, updated_at)
            VALUES (?, "complete", CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE setting_value = "complete", updated_at = CURRENT_TIMESTAMP
        ');
        $marker->execute([$repairKey]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function repair_shared_admin_unread_history_for_admin_once(PDO $pdo, int $adminId): void
{
    if ($adminId <= 0) return;
    $repairKey = 'shared_admin_unread_history_v2_admin_' . $adminId;
    $stmt = $pdo->prepare('SELECT setting_value FROM cl_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$repairKey]);
    if ((string) ($stmt->fetchColumn() ?: '') === 'complete') return;

    $pdo->beginTransaction();
    try {
        // Target the account that is actually logged in. Legacy databases may
        // contain a blank or non-standard access_scope, so role-based repair
        // can miss the real tournament admin account.
        $reset = $pdo->prepare('
            UPDATE cl_room_reads
            SET last_message_id = 0, updated_at = CURRENT_TIMESTAMP
            WHERE owner_type = "admin" AND admin_id = ?
        ');
        $reset->execute([$adminId]);
        $marker = $pdo->prepare('
            INSERT INTO cl_settings (setting_key, setting_value, updated_at)
            VALUES (?, "complete", CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE setting_value = "complete", updated_at = CURRENT_TIMESTAMP
        ');
        $marker->execute([$repairKey]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
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

function set_room_admin_check(PDO $pdo): void
{
    if (!current_admin($pdo)) {
        json_response(['ok' => false, 'message' => 'Admin login diperlukan.'], 401);
    }
    $roomId = (int) ($_POST['room_id'] ?? 0);
    $checked = filter_var($_POST['checked'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($roomId <= 0) {
        json_response(['ok' => false, 'message' => 'Room tidak valid.'], 422);
    }
    $stmt = $pdo->prepare('UPDATE cl_rooms SET admin_checked = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND room_type IN ("match", "deal")');
    $stmt->execute([$checked ? 1 : 0, $roomId]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare('SELECT COUNT(*) FROM cl_rooms WHERE id = ? AND room_type IN ("match", "deal")');
        $check->execute([$roomId]);
        if ((int) $check->fetchColumn() === 0) {
            json_response(['ok' => false, 'message' => 'Group Match tidak dijumpai.'], 404);
        }
    }
    json_response(get_state($pdo) + ['message' => $checked ? 'Group Match ditanda checked.' : 'Tanda checked dibuang.']);
}

function set_room_report_match(PDO $pdo): void
{
    if (!current_admin($pdo)) {
        json_response(['ok' => false, 'message' => 'Admin login diperlukan.'], 401);
    }
    $roomId = (int) ($_POST['room_id'] ?? 0);
    $marked = filter_var($_POST['marked'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($roomId <= 0) {
        json_response(['ok' => false, 'message' => 'Chat tidak valid.'], 422);
    }
    $stmt = $pdo->prepare('UPDATE cl_rooms SET report_match = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND room_type != "info"');
    $stmt->execute([$marked ? 1 : 0, $roomId]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare('SELECT COUNT(*) FROM cl_rooms WHERE id = ? AND room_type != "info"');
        $check->execute([$roomId]);
        if ((int) $check->fetchColumn() === 0) {
            json_response(['ok' => false, 'message' => 'Chat tidak dijumpai.'], 404);
        }
    }
    json_response(get_state($pdo) + ['message' => $marked
        ? 'Chat ditanda sebagai Report Match dan dimasukkan ke Checking.'
        : 'Tanda Report Match dibuang daripada chat.']);
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

function cancel_match_attendance(PDO $pdo): void
{
    $team = require_team($pdo);
    $matchId = (int) ($_POST['match_id'] ?? 0);
    $teamId = (int) ($team['id'] ?? 0);
    if (empty($team['is_test_account']) || (string) ($team['team_name'] ?? '') !== 'GNEX WEB TESTER') {
        json_response(['ok' => false, 'message' => 'Cancel hadir hanya tersedia untuk GNEX WEB TESTER.'], 403);
    }
    if ($matchId <= 0) {
        json_response(['ok' => false, 'message' => 'Match tidak valid.'], 422);
    }

    $participant = $pdo->prepare('SELECT COUNT(*) FROM cl_matches WHERE id = ? AND (team_a_id = ? OR team_b_id = ?)');
    $participant->execute([$matchId, $teamId, $teamId]);
    if ((int) $participant->fetchColumn() === 0) {
        json_response(['ok' => false, 'message' => 'Team tester bukan peserta match ini.'], 403);
    }

    $stmt = $pdo->prepare('DELETE FROM cl_match_attendance WHERE match_id = ? AND team_id = ?');
    $stmt->execute([$matchId, $teamId]);
    json_response(get_state($pdo) + ['message' => 'Kehadiran tester dibatalkan. Ulang semula tick hadir dan test mesej.']);
}

function get_messages(PDO $pdo, array $rooms, int $sinceMessageId = 0): array
{
    if (!$rooms) {
        return [];
    }
    $roomIds = array_map(static fn($room) => (int) $room['id'], $rooms);
    $placeholders = implode(',', array_fill(0, count($roomIds), '?'));
    // Group/INFO history is included during polling so edits to older messages
    // reach devices that already have a newer message id.
    $sinceSql = $sinceMessageId > 0 ? ' AND (cm.id > ? OR cr.room_type IN ("group", "info"))' : '';
    $stmt = $pdo->prepare("
        SELECT m.id, m.room_id, m.sender_type, m.sender_team_id, m.guest_name, m.reply_to_message_id, m.action_target, m.message, m.image_url, m.created_at, m.edited_at,
               t.team_name AS sender_team_name, t.status AS sender_team_status, t.slot_no AS sender_team_slot,
               rm.message AS reply_message, rm.sender_type AS reply_sender_type, rm.guest_name AS reply_guest_name,
               rt.team_name AS reply_team_name, rt.status AS reply_team_status, rt.slot_no AS reply_team_slot
        FROM (
            SELECT cm.*,
                   cr.room_type,
                   ROW_NUMBER() OVER (PARTITION BY cm.room_id ORDER BY cm.id DESC) AS message_rank
            FROM cl_messages cm
            INNER JOIN cl_rooms cr ON cr.id = cm.room_id
            WHERE cm.room_id IN ($placeholders)$sinceSql
        ) m
        LEFT JOIN cl_teams t ON t.id = m.sender_team_id
        LEFT JOIN cl_messages rm ON rm.id = m.reply_to_message_id AND rm.room_id = m.room_id
        LEFT JOIN cl_teams rt ON rt.id = rm.sender_team_id
        -- Keep login/state payload small enough for mobile. Messages remain in
        -- the database; only the initial history window is limited here.
        WHERE m.message_rank <= CASE WHEN m.room_type IN ('group','info') THEN 100 ELSE 10 END
        ORDER BY m.id ASC
    ");
    $messageParams = $roomIds;
    if ($sinceMessageId > 0) $messageParams[] = $sinceMessageId;
    $stmt->execute($messageParams);
    $messages = $stmt->fetchAll();
    if (!$messages) return [];
    $messageIds = array_map(static fn(array $message): int => (int) $message['id'], $messages);
    $reactionPlaceholders = implode(',', array_fill(0, count($messageIds), '?'));
    $reactionStmt = $pdo->prepare("SELECT message_id, emoji, COUNT(*) AS total FROM cl_message_reactions WHERE message_id IN ($reactionPlaceholders) GROUP BY message_id, emoji");
    $reactionStmt->execute($messageIds);
    $reactions = [];
    foreach ($reactionStmt->fetchAll() as $reaction) {
        $reactions[(int) $reaction['message_id']][] = ['emoji' => (string) $reaction['emoji'], 'count' => (int) $reaction['total']];
    }
    foreach ($messages as &$message) {
        $message['reactions'] = $reactions[(int) $message['id']] ?? [];
    }
    unset($message);
    return $messages;
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
    if (in_array($target, ['jadual', 'rules', 'profile', 'all-team', 'deal', 'cara-bermain', 'report-match'], true)) {
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
    return in_array($target, ['jadual', 'rules', 'profile', 'all-team', 'deal', 'cara-bermain', 'report-match'], true) ? $target : '';
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

function get_winner_pool(PDO $pdo, ?array $admin): array
{
    if (!$admin) {
        return ['count' => 0, 'teams' => []];
    }
    $stmt = $pdo->query('
        SELECT m.id AS source_match_id, m.match_name, m.team_a_point, m.team_b_point,
               CASE WHEN m.team_a_point > m.team_b_point THEN m.team_a_id ELSE m.team_b_id END AS winner_team_id,
               CASE WHEN m.team_a_point > m.team_b_point THEN ta.team_name ELSE tb.team_name END AS winner_team_name,
               CASE WHEN m.team_a_point > m.team_b_point THEN ta.logo_url ELSE tb.logo_url END AS winner_team_logo
        FROM cl_matches m
        LEFT JOIN cl_teams ta ON ta.id = m.team_a_id
        LEFT JOIN cl_teams tb ON tb.id = m.team_b_id
        LEFT JOIN cl_match_advancements a ON a.source_match_id = m.id
        LEFT JOIN cl_matches next_match ON next_match.id = a.next_match_id
        WHERE m.status = "completed"
          AND m.team_a_id IS NOT NULL AND m.team_b_id IS NOT NULL
          AND m.team_a_point IS NOT NULL AND m.team_b_point IS NOT NULL
          AND m.team_a_point <> m.team_b_point
          AND (
              a.id IS NULL
              OR next_match.id IS NULL
              OR next_match.status = "hidden"
              OR (
                  COALESCE(next_match.team_a_id, 0) <> (CASE WHEN m.team_a_point > m.team_b_point THEN m.team_a_id ELSE m.team_b_id END)
                  AND COALESCE(next_match.team_b_id, 0) <> (CASE WHEN m.team_a_point > m.team_b_point THEN m.team_a_id ELSE m.team_b_id END)
              )
          )
          AND NOT EXISTS (
              SELECT 1 FROM cl_matches active_match
              WHERE active_match.status IN ("up_next", "live")
                AND (
                    active_match.team_a_id = (CASE WHEN m.team_a_point > m.team_b_point THEN m.team_a_id ELSE m.team_b_id END)
                    OR active_match.team_b_id = (CASE WHEN m.team_a_point > m.team_b_point THEN m.team_a_id ELSE m.team_b_id END)
                )
          )
          AND NOT EXISTS (
              SELECT 1 FROM cl_matches lost_match
              WHERE lost_match.status = "completed"
                AND lost_match.team_a_point IS NOT NULL AND lost_match.team_b_point IS NOT NULL
                AND lost_match.team_a_point <> lost_match.team_b_point
                AND (
                    (lost_match.team_a_id = (CASE WHEN m.team_a_point > m.team_b_point THEN m.team_a_id ELSE m.team_b_id END)
                     AND lost_match.team_a_point < lost_match.team_b_point)
                    OR
                    (lost_match.team_b_id = (CASE WHEN m.team_a_point > m.team_b_point THEN m.team_a_id ELSE m.team_b_id END)
                     AND lost_match.team_b_point < lost_match.team_a_point)
                )
          )
        ORDER BY COALESCE(m.match_time, m.updated_at, m.created_at) DESC, m.id DESC
    ');
    $teamsById = [];
    foreach ($stmt->fetchAll() as $row) {
        $teamId = (int) $row['winner_team_id'];
        if ($teamId <= 0 || isset($teamsById[$teamId])) continue;
        $teamsById[$teamId] = [
            'source_match_id' => (int) $row['source_match_id'],
            'match_name' => (string) $row['match_name'],
            'team_id' => (int) $row['winner_team_id'],
            'team_name' => (string) ($row['winner_team_name'] ?? 'Team'),
            'team_logo' => (string) ($row['winner_team_logo'] ?? ''),
            'score' => (int) $row['team_a_point'] . ' - ' . (int) $row['team_b_point'],
        ];
    }
    $teams = array_values($teamsById);
    return ['count' => count($teams), 'teams' => $teams];
}

function get_qualifier_final_entries(PDO $pdo, ?array $admin): array
{
    if (!$admin) return ['brackets' => [], 'qualified_count' => 0];
    $stmt = $pdo->query('
        SELECT e.bracket_no, e.slot_no, e.team_id, e.points, e.placement,
               t.team_name, t.logo_url, t.slot_no AS registration_slot
        FROM cl_qualifier_final_entries e
        LEFT JOIN cl_teams t ON t.id = e.team_id
        ORDER BY e.bracket_no ASC, e.slot_no ASC
    ');
    $saved = [];
    foreach ($stmt->fetchAll() as $row) {
        $saved[(int) $row['bracket_no']][(int) $row['slot_no']] = [
            'team_id' => $row['team_id'] === null ? 0 : (int) $row['team_id'],
            'team_name' => (string) ($row['team_name'] ?? 'TBD'),
            'team_logo' => (string) ($row['logo_url'] ?? ''),
            'registration_slot' => $row['registration_slot'] === null ? 0 : (int) $row['registration_slot'],
            'points' => $row['points'] === null ? null : (int) $row['points'],
            'placement' => $row['placement'] === null ? null : (int) $row['placement'],
        ];
    }
    $brackets = [];
    $qualifiedCount = 0;
    for ($bracket = 1; $bracket <= 4; $bracket++) {
        $entries = [];
        for ($slot = 1; $slot <= 8; $slot++) {
            $entry = $saved[$bracket][$slot] ?? [
                'team_id' => 0, 'team_name' => 'TBD', 'team_logo' => '',
                'registration_slot' => 0, 'points' => null, 'placement' => null,
            ];
            $entry['slot_no'] = $slot;
            $entry['qualified'] = $entry['team_id'] > 0 && $entry['placement'] !== null && $entry['placement'] <= 3;
            if ($entry['qualified']) $qualifiedCount++;
            $entries[] = $entry;
        }
        $brackets[] = ['bracket_no' => $bracket, 'entries' => $entries];
    }
    return ['brackets' => $brackets, 'qualified_count' => $qualifiedCount];
}

function save_qualifier_final_entry(PDO $pdo): void
{
    if (!current_admin($pdo)) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan.'], 401);
    }
    $bracket = (int) ($_POST['bracket_no'] ?? 0);
    $slot = (int) ($_POST['slot_no'] ?? 0);
    $teamId = (int) ($_POST['team_id'] ?? 0);
    $pointsRaw = trim((string) ($_POST['points'] ?? ''));
    $placementRaw = trim((string) ($_POST['placement'] ?? ''));
    $points = $pointsRaw === '' ? null : (int) $pointsRaw;
    $placement = $placementRaw === '' ? null : (int) $placementRaw;
    if ($bracket < 1 || $bracket > 4 || $slot < 1 || $slot > 8) {
        json_response(['ok' => false, 'message' => 'Bracket atau slot tidak valid.'], 422);
    }
    if ($points !== null && ($points < 0 || $points > 9999)) {
        json_response(['ok' => false, 'message' => 'Point tidak valid.'], 422);
    }
    if ($placement !== null && ($placement < 1 || $placement > 8)) {
        json_response(['ok' => false, 'message' => 'Kedudukan mesti antara 1 hingga 8.'], 422);
    }
    if ($teamId > 0) {
        $check = $pdo->prepare('SELECT COUNT(*) FROM cl_teams WHERE id = ? AND status = "accepted"');
        $check->execute([$teamId]);
        if ((int) $check->fetchColumn() !== 1) {
            json_response(['ok' => false, 'message' => 'Team tidak aktif atau belum confirm.'], 422);
        }
    }
    if ($teamId > 0 && $placement !== null) {
        $rankCheck = $pdo->prepare('SELECT COUNT(*) FROM cl_qualifier_final_entries WHERE bracket_no = ? AND placement = ? AND slot_no != ?');
        $rankCheck->execute([$bracket, $placement, $slot]);
        if ((int) $rankCheck->fetchColumn() > 0) {
            json_response(['ok' => false, 'message' => 'Rank ' . $placement . ' sudah digunakan dalam bracket ini.'], 409);
        }
    }
    $pdo->beginTransaction();
    try {
        if ($teamId > 0) {
            $clear = $pdo->prepare('UPDATE cl_qualifier_final_entries SET team_id = NULL, points = NULL, placement = NULL, updated_at = CURRENT_TIMESTAMP WHERE team_id = ? AND NOT (bracket_no = ? AND slot_no = ?)');
            $clear->execute([$teamId, $bracket, $slot]);
        }
        $stmt = $pdo->prepare('
            INSERT INTO cl_qualifier_final_entries (bracket_no, slot_no, team_id, points, placement, updated_at)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), points = VALUES(points), placement = VALUES(placement), updated_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([$bracket, $slot, $teamId > 0 ? $teamId : null, $teamId > 0 ? $points : null, $teamId > 0 ? $placement : null]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        json_response(['ok' => false, 'message' => $error->getMessage()], 409);
    }
    json_response(get_state($pdo) + ['message' => 'Slot Qualifier Final berjaya disimpan.']);
}

function get_state(PDO $pdo): array
{
    global $pushConfig, $dbConfig;

    $team = current_team($pdo);
    $admin = current_admin($pdo);
    refresh_persistent_login($pdo);
    // Let an installed iOS Home Screen app backfill its own local device token
    // after authentication was restored from Safari's persistent cookie.
    $restoredDeviceLoginToken = ($team || $admin) ? device_login_token_from_request() : '';
    $chatTeam = $team ?: current_chat_team($pdo);
    $presenceTeam = $team ?: $chatTeam;

    // Release PHP's session-file lock before the heavier state queries. iOS
    // Safari can leave a polling request in flight while the user submits the
    // login form; without this, the login POST waits behind that GET and looks
    // stuck on "Logging in..." even though the credentials are correct.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // Everything below can be slow but no longer blocks another tab/device
    // from restoring the same saved login session.
    dispatch_due_match_day_notifications($pdo, $pushConfig, 4);
    if ($admin && (string) ($admin['username'] ?? '') !== 'izwan') {
        repair_shared_admin_unread_history_for_admin_once($pdo, (int) $admin['id']);
    }
    if ($admin) {
        repair_legacy_tbd_schedule($pdo);
    }

    if ($presenceTeam) {
        $presenceStmt = $pdo->prepare('
            UPDATE cl_teams SET last_seen_at = CURRENT_TIMESTAMP
            WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < CURRENT_TIMESTAMP - INTERVAL 60 SECOND)
        ');
        $presenceStmt->execute([(int) $presenceTeam['id']]);
    }
    if ($team && $team['status'] === 'accepted') {
        get_or_create_admin_room($pdo, (int) $team['id']);
    }

    // Match next round boleh lengkap selepas migration asal sudah selesai.
    // Pastikan room sentiasa wujud pada setiap state refresh.
    ensure_active_match_rooms($pdo);

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
        'viewing_as_team' => !empty($_SESSION['cl_admin_view_as_team']),
        'teams' => get_public_teams($pdo),
        'matches' => get_team_matches($pdo, $team, $admin),
        'results' => get_match_results($pdo, $team, $admin),
        'winner_pool' => get_winner_pool($pdo, $admin),
        'qualifier_final' => get_qualifier_final_entries($pdo, $admin),
        'password_requests' => get_password_change_requests($pdo, $admin, $dbConfig),
        'rooms' => $rooms,
        'messages' => get_messages($pdo, $rooms),
        'timeline' => get_timeline($pdo),
        'rules_text' => get_rules_text($pdo),
        'pinned_info' => get_pinned_info($pdo),
        'pinned_info_action_target' => get_pinned_action_target($pdo),
        'pinned_acknowledgement' => get_pinned_acknowledgement_state($pdo, $team, $chatTeam),
        'push_public_key' => $pushConfig['public_key'] ?? null,
        'notification_readiness' => $notificationReadiness,
        'device_login_token' => $restoredDeviceLoginToken !== '' ? $restoredDeviceLoginToken : null,
    ];
}

function get_chat_updates(PDO $pdo): array
{
    $team = current_team($pdo);
    $admin = current_admin($pdo);
    if ($admin && (string) ($admin['username'] ?? '') !== 'izwan') {
        repair_shared_admin_unread_history_for_admin_once($pdo, (int) $admin['id']);
    }
    refresh_persistent_login($pdo);
    $chatTeam = $team ?: current_chat_team($pdo);
    ensure_active_match_rooms($pdo);
    $deviceToken = ($team || $admin) ? device_login_token_from_request() : '';
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $rooms = get_deal_rooms($pdo, $chatTeam, $admin, $team !== null);
    $sinceMessageId = max(0, (int) ($_GET['since_message_id'] ?? 0));
    $messages = get_messages($pdo, $rooms, $sinceMessageId);

    return [
        'ok' => true,
        'chat_update' => true,
        'auth' => [
            'team_id' => (int) ($team['id'] ?? 0),
            'chat_team_id' => (int) ($chatTeam['id'] ?? 0),
            'admin_id' => (int) ($admin['id'] ?? 0),
        ],
        'rooms' => $rooms,
        // The Deal view polls this endpoint instead of full state. Include the
        // current fixtures so an admin opponent change reaches every team.
        'matches' => get_team_matches($pdo, $team, $admin),
        'messages' => $messages,
        'pinned_info' => get_pinned_info($pdo),
        'pinned_info_action_target' => get_pinned_action_target($pdo),
        'pinned_acknowledgement' => get_pinned_acknowledgement_state($pdo, $team, $chatTeam),
        'device_login_token' => $deviceToken !== '' ? $deviceToken : null,
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

        /* Legacy automatic TBD consolidation disabled.
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
        */
        // TBD pairing is now handled only by explicit admin actions. Running it
        // during a state read could mutate official fixtures and conflict with
        // the database anti-double-match guard.
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

    if (!$admin && !empty($loggedTeam['profile_incomplete'])) {
        $playersBySlot = [];
        foreach ($players as $player) $playersBySlot[(int)$player['slot']] = $player;
        for ($slot = 1; $slot <= 4; $slot++) {
            $player = $playersBySlot[$slot] ?? null;
            if (!$player || trim((string)$player['ign']) === '' || trim((string)$player['id']) === '') {
                json_response(['ok' => false, 'message' => 'Lengkapkan IGN dan ID untuk P1 hingga P4 sebelum teruskan.'], 422);
            }
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

        if (!$admin && !empty($loggedTeam['profile_incomplete'])) {
            $stmt = $pdo->prepare('UPDATE cl_teams SET profile_update_required=0, updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $stmt->execute([$teamId]);
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
    $teamPool = clean_text($_POST['team_pool'] ?? 'new', 20);
    if (!in_array($teamPool, ['new', 'winners'], true)) {
        json_response(['ok' => false, 'message' => 'Kumpulan team tidak valid.'], 422);
    }
    $prefix = clean_text($_POST['match_prefix'] ?? 'Qualifier Match', 120);
    if ($prefix === '') {
        $prefix = 'Qualifier Match';
    }
    $allowedMatchNames = [];
    $stageCodeByName = [];
    foreach (get_timeline($pdo) as $timelineItem) {
        $title = trim((string) ($timelineItem['title'] ?? ''));
        if ($title !== '') {
            $allowedMatchNames[$title] = true;
            $stageCodeByName[$title] = trim((string)($timelineItem['stage_code'] ?? ''));
        }
    }
    foreach (clash_timeline_match_slots() as $timelineSlot) {
        $allowedMatchNames[(string) $timelineSlot['name']] = true;
    }
    if (!isset($allowedMatchNames[$prefix])) {
        json_response(['ok' => false, 'message' => 'Nama match mesti dipilih daripada Timeline Jadual.'], 422);
    }
    $selectedStageCode = $stageCodeByName[$prefix] ?? '';

    $matchTimeSql = normalize_match_time($_POST['match_time'] ?? '');

    $pdo->beginTransaction();
    try {
        $sourceMatchByTeam = [];
        if ($teamPool === 'winners') {
            $stmt = $pdo->prepare('
                SELECT m.id AS source_match_id,
                       CASE WHEN m.team_a_point > m.team_b_point THEN m.team_a_id ELSE m.team_b_id END AS id
                FROM cl_matches m
                LEFT JOIN cl_match_advancements a ON a.source_match_id = m.id
                WHERE m.status = "completed"
                  AND m.team_a_id IS NOT NULL AND m.team_b_id IS NOT NULL
                  AND m.team_a_point IS NOT NULL AND m.team_b_point IS NOT NULL
                  AND m.team_a_point <> m.team_b_point AND a.id IS NULL
                  AND NOT EXISTS (
                      SELECT 1 FROM cl_matches lost_match
                      WHERE lost_match.status = "completed"
                        AND lost_match.team_a_point IS NOT NULL AND lost_match.team_b_point IS NOT NULL
                        AND lost_match.team_a_point <> lost_match.team_b_point
                        AND (
                            (lost_match.team_a_id = (CASE WHEN m.team_a_point > m.team_b_point THEN m.team_a_id ELSE m.team_b_id END)
                             AND lost_match.team_a_point < lost_match.team_b_point)
                            OR
                            (lost_match.team_b_id = (CASE WHEN m.team_a_point > m.team_b_point THEN m.team_a_id ELSE m.team_b_id END)
                             AND lost_match.team_b_point < lost_match.team_a_point)
                        )
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM cl_matches active_match
                      WHERE active_match.status IN ("up_next", "live")
                        AND (
                            active_match.team_a_id = (CASE WHEN m.team_a_point > m.team_b_point THEN m.team_a_id ELSE m.team_b_id END)
                            OR active_match.team_b_id = (CASE WHEN m.team_a_point > m.team_b_point THEN m.team_a_id ELSE m.team_b_id END)
                        )
                  )
                ORDER BY COALESCE(m.match_time, m.updated_at, m.created_at) DESC, m.id DESC
                FOR UPDATE
            ');
        } else {
            $stmt = $pdo->prepare('
                SELECT t.id, NULL AS source_match_id
                FROM cl_teams t
                WHERE t.status = "accepted" AND t.is_test_account = 0
                  AND NOT EXISTS (
                      SELECT 1 FROM cl_matches m WHERE m.team_a_id = t.id OR m.team_b_id = t.id
                  )
                ORDER BY COALESCE(t.slot_no, 999999), t.created_at ASC, t.id ASC
                LIMIT ? FOR UPDATE
            ');
        }
        if ($teamPool !== 'winners') {
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        $selectedRows = $stmt->fetchAll();
        $teamIds = [];
        foreach ($selectedRows as $row) {
            $teamId = (int) $row['id'];
            if ($teamId <= 0 || in_array($teamId, $teamIds, true)) continue;
            $teamIds[] = $teamId;
            if (!empty($row['source_match_id'])) $sourceMatchByTeam[$teamId] = (int) $row['source_match_id'];
            if (count($teamIds) >= $limit) break;
        }

        if (count($teamIds) < $limit) {
            $available = count($teamIds);
            $pdo->rollBack();
            json_response([
                'ok' => false,
                'message' => 'Batch belum cukup. Ada ' . $available . ($teamPool === 'winners' ? ' team layak' : ' team baharu') . ', perlukan ' . $limit . ' team untuk proses.',
            ], 422);
        }

        shuffle($teamIds);
        $processedCount = count($teamIds);
        $filledTbd = 0;

        while ($teamPool === 'new' && $teamIds) {
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
                    UPDATE cl_rooms SET team_a_id = ?, team_b_id = ?, match_id = ?, status = "open", updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ');
                $updateRoom->execute([$teamA, $teamB, $matchId, $roomId]);
            } else {
                $createRoom = $pdo->prepare('
                    INSERT INTO cl_rooms (room_type, team_a_id, team_b_id, match_id, status, updated_at)
                    VALUES ("match", ?, ?, ?, "open", CURRENT_TIMESTAMP)
                ');
                $createRoom->execute([$teamA, $teamB, $matchId]);
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
            INSERT INTO cl_matches (team_a_id, team_b_id, match_name, stage_code, match_time, status, updated_at)
            VALUES (?, ?, ?, ?, ?, "up_next", CURRENT_TIMESTAMP)
        ');
        $roomStmt = $pdo->prepare('
            INSERT INTO cl_rooms (room_type, team_a_id, team_b_id, match_id, status, updated_at)
            VALUES ("match", ?, ?, ?, "open", CURRENT_TIMESTAMP)
        ');
        $advanceStmt = $pdo->prepare('
            INSERT INTO cl_match_advancements (source_match_id, winner_team_id, next_match_id)
            VALUES (?, ?, ?)
        ');

        for ($index = 0; $index < count($teamIds); $index += 2) {
            $teamA = $teamIds[$index];
            $teamB = $teamIds[$index + 1] ?? null;
            $matchName = $prefix . ' ' . $matchNo;
            $matchStmt->execute([$teamA, $teamB, $matchName, $selectedStageCode ?: null, $matchTimeSql]);
            $newMatchId = (int) $pdo->lastInsertId();
            $roomStmt->execute([$teamA, $teamB, $newMatchId]);
            if ($teamPool === 'winners') {
                foreach (array_filter([$teamA, $teamB]) as $winnerTeamId) {
                    $sourceMatchId = (int) ($sourceMatchByTeam[(int) $winnerTeamId] ?? 0);
                    if ($sourceMatchId > 0) $advanceStmt->execute([$sourceMatchId, (int) $winnerTeamId, $newMatchId]);
                }
            }
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
            : 'Batch ' . $processedCount . ($teamPool === 'winners' ? ' team layak' : ' team baharu') . ' berjaya dibuat. Jika seorang sahaja, jadual ditetapkan sebagai vs TBD.',
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

function create_manual_match(PDO $pdo): void
{
    if (!current_admin($pdo)) json_response(['ok' => false, 'message' => 'Login admin diperlukan.'], 401);
    $teamAId = max(0, (int) ($_POST['team_a_id'] ?? 0));
    $teamBId = max(0, (int) ($_POST['team_b_id'] ?? 0));
    $matchName = clean_text($_POST['match_name'] ?? 'Qualifier / Fasa / BO3', 120);
    $matchTime = normalize_match_time($_POST['match_time'] ?? '');
    if (!$teamAId || !$teamBId || $teamAId === $teamBId || $matchName === '') {
        json_response(['ok' => false, 'message' => 'Pilih dua team berbeza dan isi nama jadual.'], 422);
    }
    $check = $pdo->prepare('SELECT COUNT(*) FROM cl_teams WHERE id IN (?, ?) AND status = "accepted"');
    $check->execute([$teamAId, $teamBId]);
    if ((int) $check->fetchColumn() !== 2) {
        json_response(['ok' => false, 'message' => 'Kedua-dua team mesti aktif dan sudah confirm.'], 422);
    }
    $stageCode = '';
    foreach (get_timeline($pdo) as $item) {
        if (strcasecmp(trim((string) ($item['title'] ?? '')), $matchName) === 0) {
            $stageCode = trim((string) ($item['stage_code'] ?? ''));
            break;
        }
    }
    $stmt = $pdo->prepare('INSERT INTO cl_matches (team_a_id,team_b_id,match_name,stage_code,match_time,status,updated_at) VALUES (?,?,?,?,?,"up_next",CURRENT_TIMESTAMP)');
    $stmt->execute([$teamAId, $teamBId, $matchName, $stageCode !== '' ? $stageCode : null, $matchTime]);
    json_response(get_state($pdo) + ['message' => 'Jadual manual berjaya ditambah.']);
}

function delete_match(PDO $pdo): void
{
    if (!current_admin($pdo)) json_response(['ok' => false, 'message' => 'Login admin diperlukan.'], 401);
    $matchId = (int) ($_POST['match_id'] ?? 0);
    if ($matchId <= 0) json_response(['ok' => false, 'message' => 'Match tidak valid.'], 422);
    $stmt = $pdo->prepare('SELECT team_a_id,team_b_id,match_name FROM cl_matches WHERE id=? FOR UPDATE');
    $pdo->beginTransaction();
    try {
        $stmt->execute([$matchId]);
        $match = $stmt->fetch();
        if (!$match) throw new RuntimeException('Jadual tidak dijumpai.');
        $pdo->prepare('DELETE FROM cl_rooms WHERE room_type IN ("match","deal") AND match_id=?')->execute([$matchId]);
        if (!empty($match['team_a_id']) && !empty($match['team_b_id'])) {
            $pdo->prepare('DELETE FROM cl_rooms WHERE room_type IN ("match","deal") AND match_id IS NULL AND ((team_a_id=? AND team_b_id=?) OR (team_a_id=? AND team_b_id=?))')
                ->execute([$match['team_a_id'], $match['team_b_id'], $match['team_b_id'], $match['team_a_id']]);
        }
        $pdo->prepare('UPDATE cl_match_advancements SET next_match_id=NULL WHERE next_match_id=?')->execute([$matchId]);
        $pdo->prepare('DELETE FROM cl_matches WHERE id=?')->execute([$matchId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['ok' => false, 'message' => $error->getMessage()], 409);
    }
    json_response(get_state($pdo) + ['message' => 'Jadual berjaya dipadam.']);
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
        route_qualifier_winner($pdo, $match, $teamAPoint, $teamBPoint);
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
        $stmt = $pdo->prepare('SELECT * FROM cl_matches WHERE id = ? FOR UPDATE');
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
        $isTestMatch = str_starts_with((string)($match['stage_code'] ?? ''), 'test_');
        if (!$isTestMatch && $now < $matchStartsAt) {
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
        route_qualifier_winner($pdo, $match, $teamAPoint, $teamBPoint);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        json_response(['ok' => false, 'message' => $error->getMessage()], 409);
    }

    json_response(get_state($pdo) + ['message' => 'Point kedua-dua team berjaya ditetapkan oleh admin.']);
}

function route_qualifier_winner(PDO $pdo, array $sourceMatch, int $teamAPoint, int $teamBPoint): void
{
    $sourceId = (int) ($sourceMatch['id'] ?? 0);
    $teamAId = (int) ($sourceMatch['team_a_id'] ?? 0);
    $teamBId = (int) ($sourceMatch['team_b_id'] ?? 0);
    $sourceName = trim((string) ($sourceMatch['match_name'] ?? ''));
    $sourceCode = trim((string) ($sourceMatch['stage_code'] ?? '')) ?: infer_stage_code($sourceName, (string)($sourceMatch['match_time'] ?? ''));
    if ($sourceId <= 0 || $teamAId <= 0 || $teamBId <= 0 || $teamAPoint === $teamBPoint) return;

    $winnerId = $teamAPoint > $teamBPoint ? $teamAId : $teamBId;

    // Tester progression is intentionally isolated from every official qualifier.
    // TDB is a real test-team row so admin can exercise the complete point flow.
    if (str_starts_with($sourceCode, 'test_')) {
        $testRoutes = [
            'test_q1' => ['target_code' => 'test_q2', 'timeline_code' => 'q2_f1'],
            'test_q2' => ['target_code' => 'test_q3', 'timeline_code' => 'q3_f1'],
        ];
        $testRoute = $testRoutes[$sourceCode] ?? null;
        if (!$testRoute) return;

        $targetItem = timeline_item_by_stage($pdo, $testRoute['timeline_code']);
        $targetTime = $targetItem ? timeline_item_datetime($targetItem) : null;
        if (!$targetItem || $targetTime === null) return;

        $existingAdvance = $pdo->prepare('SELECT next_match_id FROM cl_match_advancements WHERE source_match_id = ? LIMIT 1 FOR UPDATE');
        $existingAdvance->execute([$sourceId]);
        $existingTargetId = (int)($existingAdvance->fetchColumn() ?: 0);
        $opponentId = $winnerId === $teamAId ? $teamBId : $teamAId;

        $targetStmt = $pdo->prepare('SELECT id FROM cl_matches WHERE stage_code = ? LIMIT 1 FOR UPDATE');
        $targetStmt->execute([$testRoute['target_code']]);
        $targetId = (int)($targetStmt->fetchColumn() ?: 0);
        if ($targetId <= 0) {
            $insert = $pdo->prepare('INSERT INTO cl_matches (team_a_id, team_b_id, match_name, stage_code, match_time, status, updated_at) VALUES (?, ?, ?, ?, ?, "up_next", CURRENT_TIMESTAMP)');
            $insert->execute([$winnerId, $opponentId, (string)$targetItem['title'], $testRoute['target_code'], $targetTime]);
            $targetId = (int)$pdo->lastInsertId();
        } else {
            $update = $pdo->prepare('UPDATE cl_matches SET team_a_id=?, team_b_id=?, match_name=?, match_time=?, status="up_next", team_a_point=NULL, team_b_point=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $update->execute([$winnerId, $opponentId, (string)$targetItem['title'], $targetTime, $targetId]);
        }

        if ($existingTargetId <= 0) {
            $advance = $pdo->prepare('INSERT INTO cl_match_advancements (source_match_id, winner_team_id, next_match_id) VALUES (?, ?, ?)');
            $advance->execute([$sourceId, $winnerId, $targetId]);
        } else {
            $advance = $pdo->prepare('UPDATE cl_match_advancements SET winner_team_id=?, next_match_id=? WHERE source_match_id=?');
            $advance->execute([$winnerId, $targetId, $sourceId]);
        }
        return;
    }

    if ($sourceCode === 'late_q1') {
        route_late_entry_winner($pdo, $sourceId, $winnerId);
        return;
    }
    if (str_starts_with($sourceCode, 'late_playoff_')) {
        $challengedQ2Id = (int)substr($sourceCode, strlen('late_playoff_'));
        route_late_playoff_winner($pdo, $sourceId, $challengedQ2Id, $winnerId);
        return;
    }

    $routes = [
        'q1_g1'=>['target_code'=>'q2_f1','side'=>'a'], 'q1_g2'=>['target_code'=>'q2_f1','side'=>'b'],
        'q1_g3'=>['target_code'=>'q2_f2','side'=>'a'], 'q1_g4'=>['target_code'=>'q2_f2','side'=>'b'],
        'q2_f1'=>['target_code'=>'q3_f1','side'=>'a'], 'q2_f2'=>['target_code'=>'q3_f1','side'=>'b'],
    ];
    $route = $routes[$sourceCode] ?? null;
    if (!$route) return;
    $targetItem = timeline_item_by_stage($pdo, $route['target_code']);
    if (!$targetItem) return;
    $route['name'] = (string)$targetItem['title'];
    $route['time'] = timeline_item_datetime($targetItem);
    if ($route['time'] === null) return;

    $existingAdvance = $pdo->prepare('SELECT next_match_id FROM cl_match_advancements WHERE source_match_id = ? LIMIT 1 FOR UPDATE');
    $existingAdvance->execute([$sourceId]);
    if ($existingAdvance->fetchColumn()) return;

    $ordinalStmt = $pdo->prepare('SELECT COUNT(*) FROM cl_matches WHERE stage_code=? AND id <= ?');
    $ordinalStmt->execute([$sourceCode, $sourceId]);
    $ordinal = max(1, (int) $ordinalStmt->fetchColumn());
    $targetName = $route['name'] . ' ' . $ordinal;

    $targetStmt = $pdo->prepare('SELECT * FROM cl_matches WHERE stage_code=? AND match_name LIKE ? LIMIT 1 FOR UPDATE');
    $targetStmt->execute([$route['target_code'], '% '.$ordinal]);
    $target = $targetStmt->fetch();
    if (!$target) {
        $insert = $pdo->prepare('INSERT INTO cl_matches (team_a_id, team_b_id, match_name, stage_code, match_time, status, updated_at) VALUES (?, ?, ?, ?, ?, "up_next", CURRENT_TIMESTAMP)');
        $insert->execute([
            $route['side'] === 'a' ? $winnerId : null,
            $route['side'] === 'b' ? $winnerId : null,
            $targetName, $route['target_code'],
            $route['time'],
        ]);
        $targetId = (int) $pdo->lastInsertId();
        $targetA = $route['side'] === 'a' ? $winnerId : null;
        $targetB = $route['side'] === 'b' ? $winnerId : null;
    } else {
        $targetId = (int) $target['id'];
        $targetA = $target['team_a_id'] === null ? null : (int) $target['team_a_id'];
        $targetB = $target['team_b_id'] === null ? null : (int) $target['team_b_id'];
        if ($route['side'] === 'a') $targetA = $winnerId;
        else $targetB = $winnerId;
        $update = $pdo->prepare('UPDATE cl_matches SET team_a_id = ?, team_b_id = ?, status = "up_next", updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $update->execute([$targetA, $targetB, $targetId]);
    }

    $advance = $pdo->prepare('INSERT INTO cl_match_advancements (source_match_id, winner_team_id, next_match_id) VALUES (?, ?, ?)');
    $advance->execute([$sourceId, $winnerId, $targetId]);
    if ($targetA && $targetB) {
        $roomExists = $pdo->prepare('SELECT id FROM cl_rooms WHERE room_type = "match" AND match_id = ? LIMIT 1');
        $roomExists->execute([$targetId]);
        $targetRoomId = (int)($roomExists->fetchColumn() ?: 0);
        if ($targetRoomId <= 0) {
            $createRoom = $pdo->prepare('INSERT INTO cl_rooms (room_type, team_a_id, team_b_id, match_id, status, updated_at) VALUES ("match", ?, ?, ?, "open", CURRENT_TIMESTAMP)');
            $createRoom->execute([$targetA, $targetB, $targetId]);
        } else {
            $openRoom = $pdo->prepare('UPDATE cl_rooms SET team_a_id=?,team_b_id=?,status="open",updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $openRoom->execute([$targetA,$targetB,$targetRoomId]);
        }
    }
    if (in_array($sourceCode, ['q2_f1','q2_f2'], true)) {
        $waiting=$pdo->query('
            SELECT m.id,
              CASE WHEN m.team_a_point>m.team_b_point THEN m.team_a_id ELSE m.team_b_id END winner_id
            FROM cl_matches m
            LEFT JOIN cl_match_advancements a ON a.source_match_id=m.id
            WHERE m.stage_code="late_q1" AND m.status="completed" AND a.id IS NULL
              AND m.team_a_point IS NOT NULL AND m.team_b_point IS NOT NULL AND m.team_a_point<>m.team_b_point
            ORDER BY m.match_time ASC,m.id ASC FOR UPDATE
        ')->fetchAll();
        foreach ($waiting as $lateMatch) {
            route_late_entry_winner($pdo,(int)$lateMatch['id'],(int)$lateMatch['winner_id']);
        }
    }
}

function ensure_match_room(PDO $pdo, int $matchId, ?int $teamA, ?int $teamB): void
{
    if (!$teamA || !$teamB) return;
    $find=$pdo->prepare('SELECT id FROM cl_rooms WHERE room_type="match" AND match_id=? LIMIT 1');
    $find->execute([$matchId]);
    $roomId=(int)($find->fetchColumn() ?: 0);
    if ($roomId>0) {
        $stmt=$pdo->prepare('UPDATE cl_rooms SET team_a_id=?,team_b_id=?,status="open",updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $stmt->execute([$teamA,$teamB,$roomId]);
    } else {
        $stmt=$pdo->prepare('INSERT INTO cl_rooms (room_type,team_a_id,team_b_id,match_id,status,updated_at) VALUES ("match",?,?,?,"open",CURRENT_TIMESTAMP)');
        $stmt->execute([$teamA,$teamB,$matchId]);
    }
}

function route_late_entry_winner(PDO $pdo, int $sourceId, int $winnerId): void
{
    $existing=$pdo->prepare('SELECT next_match_id FROM cl_match_advancements WHERE source_match_id=? LIMIT 1 FOR UPDATE');
    $existing->execute([$sourceId]);
    if ($existing->fetchColumn()) return;

    // A late-entry result may be approved after the same winner has already
    // received an official active match through another qualifier route. Link
    // that result to the existing match instead of trying to schedule the team
    // twice (which would correctly be rejected by the database uniqueness guard).
    $activeMatch=$pdo->prepare('
        SELECT id FROM cl_matches
        WHERE status IN ("up_next","live") AND (team_a_id=? OR team_b_id=?)
        ORDER BY match_time ASC,id ASC LIMIT 1 FOR UPDATE
    ');
    $activeMatch->execute([$winnerId,$winnerId]);
    $activeMatchId=(int)($activeMatch->fetchColumn() ?: 0);
    if ($activeMatchId>0) {
        $link=$pdo->prepare('INSERT INTO cl_match_advancements(source_match_id,winner_team_id,next_match_id) VALUES(?,?,?)');
        $link->execute([$sourceId,$winnerId,$activeMatchId]);
        return;
    }

    $vacancy=$pdo->query('
        SELECT id,team_a_id,team_b_id FROM cl_matches
        WHERE stage_code IN ("q2_f1","q2_f2") AND status IN ("up_next","live")
          AND (team_a_id IS NULL OR team_b_id IS NULL)
        ORDER BY match_time ASC,id ASC LIMIT 1 FOR UPDATE
    ')->fetch();
    if ($vacancy) {
        $targetId=(int)$vacancy['id'];
        if ($vacancy['team_a_id']===null) {
            $teamA=$winnerId;
            $teamB=$vacancy['team_b_id']===null ? null : (int)$vacancy['team_b_id'];
        } else {
            $teamA=(int)$vacancy['team_a_id'];
            $teamB=$winnerId;
        }
        $update=$pdo->prepare('UPDATE cl_matches SET team_a_id=?,team_b_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $update->execute([$teamA,$teamB,$targetId]);
        $advance=$pdo->prepare('INSERT INTO cl_match_advancements (source_match_id,winner_team_id,next_match_id) VALUES (?,?,?)');
        $advance->execute([$sourceId,$winnerId,$targetId]);
        ensure_match_room($pdo,$targetId,$teamA,$teamB);
        return;
    }

    $candidate=$pdo->query('
        SELECT m.id,
          CASE WHEN m.team_a_point>m.team_b_point THEN m.team_a_id ELSE m.team_b_id END winner_id
        FROM cl_matches m
        WHERE m.stage_code IN ("q2_f1","q2_f2") AND m.status="completed"
          AND m.team_a_point IS NOT NULL AND m.team_b_point IS NOT NULL AND m.team_a_point<>m.team_b_point
          AND NOT EXISTS (SELECT 1 FROM cl_matches p WHERE p.stage_code=CONCAT("late_playoff_",m.id))
        ORDER BY m.match_time ASC,m.id ASC LIMIT 1 FOR UPDATE
    ')->fetch();
    if (!$candidate || (int)$candidate['winner_id']<=0) return;

    $q2Id=(int)$candidate['id'];
    $q2Winner=(int)$candidate['winner_id'];
    $insert=$pdo->prepare('
        INSERT INTO cl_matches (team_a_id,team_b_id,match_name,stage_code,match_time,status,updated_at)
        VALUES (?, ?, "REBUT SLOT QUALIFIER 2 / BO3", ?, "2026-08-12 21:30:00", "up_next", CURRENT_TIMESTAMP)
    ');
    $insert->execute([$winnerId,$q2Winner,'late_playoff_'.$q2Id]);
    $playoffId=(int)$pdo->lastInsertId();
    ensure_match_room($pdo,$playoffId,$winnerId,$q2Winner);
    $advance=$pdo->prepare('INSERT INTO cl_match_advancements (source_match_id,winner_team_id,next_match_id) VALUES (?,?,?)');
    $advance->execute([$sourceId,$winnerId,$playoffId]);
}

function route_late_playoff_winner(PDO $pdo, int $sourceId, int $challengedQ2Id, int $winnerId): void
{
    if ($challengedQ2Id<=0) return;
    $existing=$pdo->prepare('SELECT next_match_id FROM cl_match_advancements WHERE source_match_id=? LIMIT 1 FOR UPDATE');
    $existing->execute([$sourceId]);
    if ($existing->fetchColumn()) return;

    $q2Advance=$pdo->prepare('SELECT winner_team_id,next_match_id FROM cl_match_advancements WHERE source_match_id=? LIMIT 1 FOR UPDATE');
    $q2Advance->execute([$challengedQ2Id]);
    $route=$q2Advance->fetch();
    if (!$route || (int)($route['next_match_id'] ?? 0)<=0) return;
    $oldWinner=(int)$route['winner_team_id'];
    $targetId=(int)$route['next_match_id'];
    $target=$pdo->prepare('SELECT team_a_id,team_b_id FROM cl_matches WHERE id=? LIMIT 1 FOR UPDATE');
    $target->execute([$targetId]);
    $row=$target->fetch();
    if (!$row) return;
    $teamA=(int)($row['team_a_id'] ?? 0);
    $teamB=(int)($row['team_b_id'] ?? 0);
    if ($teamA===$oldWinner) $teamA=$winnerId;
    elseif ($teamB===$oldWinner) $teamB=$winnerId;
    else return;
    $update=$pdo->prepare('UPDATE cl_matches SET team_a_id=?,team_b_id=?,team_a_point=NULL,team_b_point=NULL,status="up_next",updated_at=CURRENT_TIMESTAMP WHERE id=?');
    $update->execute([$teamA ?: null,$teamB ?: null,$targetId]);
    $updateAdvance=$pdo->prepare('UPDATE cl_match_advancements SET winner_team_id=? WHERE source_match_id=?');
    $updateAdvance->execute([$winnerId,$challengedQ2Id]);
    $insert=$pdo->prepare('INSERT INTO cl_match_advancements (source_match_id,winner_team_id,next_match_id) VALUES (?,?,?)');
    $insert->execute([$sourceId,$winnerId,$targetId]);
    ensure_match_room($pdo,$targetId,$teamA ?: null,$teamB ?: null);
}

function timeline_item_by_stage(PDO $pdo, string $stageCode): ?array
{
    foreach (get_timeline($pdo) as $item) if (($item['stage_code'] ?? '') === $stageCode) return $item;
    return null;
}

function timeline_match_datetime(PDO $pdo, string $title): ?string
{
    foreach (get_timeline($pdo) as $item) {
        if (strcasecmp(trim((string) ($item['title'] ?? '')), trim($title)) !== 0) continue;
        return timeline_item_datetime($item);
    }
    return null;
}

function timeline_item_datetime(array $item): ?string
{
    $date = str_ireplace(['Ogos', 'September'], ['August', 'September'], trim((string) ($item['date'] ?? '')));
    $note = trim((string) ($item['note'] ?? ''));
    if (!preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*(AM|PM)\b/i', $note, $timeMatch)) return null;
    $minute = $timeMatch[2] ?? '00';
    $value = $date . ' ' . $timeMatch[1] . ':' . $minute . ' ' . strtoupper($timeMatch[3]);
    $parsed = DateTimeImmutable::createFromFormat('!j F Y g:i A', $value, new DateTimeZone('Asia/Kuala_Lumpur'));
    return $parsed ? $parsed->format('Y-m-d H:i:s') : null;
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
    $newMatchId = (int) $pdo->lastInsertId();

    $roomStmt = $pdo->prepare('
        INSERT INTO cl_rooms (room_type, team_a_id, team_b_id, match_id, status, updated_at)
        VALUES ("match", ?, ?, ?, "open", CURRENT_TIMESTAMP)
    ');
    $roomStmt->execute([$teamA, $teamB, $newMatchId]);
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
        ['name'=>'Qualifier 1 / Fasa 1 / Group 1 / BO3','time'=>'2026-08-05 21:30:00'],
        ['name'=>'Qualifier 1 / Fasa 1 / Group 2 / BO3','time'=>'2026-08-07 21:30:00'],
        ['name'=>'Qualifier 1 / Fasa 1 / Group 3 / BO3','time'=>'2026-08-09 21:30:00'],
        ['name'=>'Qualifier 1 / Fasa 1 / Group 4 / BO3','time'=>'2026-08-11 21:30:00'],
        ...array_map(static fn(array $item): array => [
            'name'=>(string)$item['title'],
            'time'=>timeline_item_datetime($item),
        ], stable_tournament_schedule()),
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
        unset($_SESSION['cl_team_id'], $_SESSION['cl_admin_view_as_team']);
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
    unset($_SESSION['cl_admin_id'], $_SESSION['cl_admin_view_as_team']);
    $deviceLoginToken = issue_persistent_login($pdo, 'team', (int) $team['id'], null);
    get_or_create_admin_room($pdo, (int) $team['id']);
    json_response(get_state($pdo) + [
        'message' => 'Login team berjaya.',
        'chat_token' => $chatToken,
        'device_login_token' => $deviceLoginToken,
    ]);
}

function admin_view_as_team(PDO $pdo): void
{
    $admin = current_real_admin($pdo);
    if (!$admin) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan.'], 401);
    }

    $teamId = (int) ($_POST['team_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT id, status FROM cl_teams WHERE id = ? AND status != "removed" LIMIT 1');
    $stmt->execute([$teamId]);
    $team = $stmt->fetch();
    if (!$team) {
        json_response(['ok' => false, 'message' => 'Team tidak dijumpai.'], 404);
    }

    $_SESSION['cl_team_id'] = (int) $team['id'];
    $_SESSION['cl_admin_view_as_team'] = 1;
    get_or_create_admin_room($pdo, (int) $team['id']);
    json_response(get_state($pdo) + [
        'message' => 'Paparan team dibuka. Logout untuk kembali ke akaun admin.',
        'viewing_as_team' => true,
    ]);
}

function login_order_record_admin(PDO $pdo): void
{
    $secret = (string) ($_POST['secret'] ?? '');
    if ($secret === '') {
        json_response(['ok' => false, 'message' => 'Masukkan password admin.'], 422);
    }

    $matchedAdminId = 0;
    if (hash_equals('GnexStok186700371877',$secret)) {
        seed_stock_worker($pdo);
        $workerStmt=$pdo->prepare('SELECT id FROM cl_admin_users WHERE username="izwan" LIMIT 1');
        $workerStmt->execute();
        $matchedAdminId=(int)($workerStmt->fetchColumn() ?: 0);
    } else {
        $stmt=$pdo->query('SELECT id,password_hash FROM cl_admin_users ORDER BY id ASC');
        foreach ($stmt->fetchAll() as $admin) {
            if (password_verify($secret, (string) ($admin['password_hash'] ?? ''))) {
                $matchedAdminId = (int) ($admin['id'] ?? 0);
                break;
            }
        }
    }

    if ($matchedAdminId <= 0) {
        json_response(['ok' => false, 'message' => 'Password admin salah.'], 401);
    }

    session_regenerate_id(true);
    $_SESSION['cl_admin_id'] = $matchedAdminId;
    $scopeStmt=$pdo->prepare('SELECT access_scope FROM cl_admin_users WHERE id=? LIMIT 1');
    $scopeStmt->execute([$matchedAdminId]);
    $_SESSION['cl_admin_access_scope']=(string)($scopeStmt->fetchColumn() ?: 'admin');
    unset($_SESSION['cl_team_id']);
    $deviceLoginToken = issue_persistent_login($pdo, 'admin', null, $matchedAdminId);
    json_response([
        'ok' => true,
        'message' => 'Admin login berjaya.',
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
    $stableSecret = trim((string) ($dbConfig['password_request_secret'] ?? ''));
    if ($stableSecret !== '') {
        return hash('sha256', 'gnex-clash-password-request-secret|' . $stableSecret, true);
    }
    return hash(
        'sha256',
        'gnex-clash-password-request|' . (string) ($dbConfig['database'] ?? '') . '|' . (string) ($dbConfig['password'] ?? ''),
        true
    );
}

function password_request_decryption_keys(array $dbConfig): array
{
    $keys = [password_request_key($dbConfig)];
    foreach ((array) ($dbConfig['password_request_legacy_key_hex'] ?? []) as $legacyHex) {
        $legacyHex = trim((string) $legacyHex);
        if (preg_match('/^[a-f0-9]{64}$/i', $legacyHex) !== 1) {
            continue;
        }
        $legacyKey = hex2bin($legacyHex);
        if ($legacyKey !== false) {
            $keys[] = $legacyKey;
        }
    }
    return array_values(array_unique($keys, SORT_STRING));
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
    foreach (password_request_decryption_keys($dbConfig) as $key) {
        $password = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($password !== false) {
            return $password;
        }
    }
    return '';
}

function request_password_change(PDO $pdo, array $dbConfig): void
{
    global $pushConfig;

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
        $requestId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    $pushSummary = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'statuses' => []];
    $adminStmt = $pdo->query('SELECT id FROM cl_admin_users ORDER BY id ASC');
    foreach ($adminStmt->fetchAll() as $adminRow) {
        $adminId = (int) ($adminRow['id'] ?? 0);
        if ($adminId <= 0) continue;
        $eventId = queue_push_event(
            $pdo,
            'admin',
            null,
            $adminId,
            'Request Tukar Password',
            (string) $team['team_name'] . ' menghantar request tukar password.',
            'clash-league.html#confirm-slot',
            'clash-password-request-' . $requestId
        );
        $pushSummary = merge_push_summary(
            $pushSummary,
            send_push_to_owner($pdo, $pushConfig, 'admin', null, $adminId, $eventId)
        );
    }

    json_response([
        'ok' => true,
        'message' => 'Berjaya menghantar request, sila tunggu admin sahkan pertukaran pass team anda.',
        'push_summary' => $pushSummary,
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

function upload_chat_image(string $rootDir, array $file): string
{
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Gambar chat gagal dimuat naik.');
    }
    if ((int) ($file['size'] ?? 0) > 3 * 1024 * 1024) {
        throw new RuntimeException('Gambar chat maksimum 3MB selepas compression.');
    }
    $tmpName = (string) ($file['tmp_name'] ?? '');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName) ?: '';
    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Format gambar mesti PNG, JPG, WEBP atau GIF.');
    }
    $uploadDir = $rootDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'clash-league' . DIRECTORY_SEPARATOR . 'chat';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Folder gambar chat gagal dibuat.');
    }
    $fileName = 'chat-' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($tmpName, $uploadDir . DIRECTORY_SEPARATOR . $fileName)) {
        throw new RuntimeException('Gambar chat gagal disimpan.');
    }
    return public_upload_url('uploads/clash-league/chat/' . $fileName);
}

function react_message(PDO $pdo): void
{
    $team = require_team($pdo);
    $messageId = (int) ($_POST['message_id'] ?? 0);
    $emoji = trim((string) ($_POST['emoji'] ?? ''));
    if ($messageId <= 0 || !in_array($emoji, ['👍', '❤️', '🔥', '👏', '😮'], true)) {
        json_response(['ok' => false, 'message' => 'Reaction tidak sah.'], 422);
    }
    $check = $pdo->prepare('SELECT m.id FROM cl_messages m INNER JOIN cl_rooms r ON r.id = m.room_id WHERE m.id = ? AND r.room_type = "info" LIMIT 1');
    $check->execute([$messageId]);
    if (!$check->fetchColumn()) {
        json_response(['ok' => false, 'message' => 'Reaction hanya tersedia dalam INFO TOUR.'], 403);
    }
    $existing = $pdo->prepare('SELECT emoji FROM cl_message_reactions WHERE message_id = ? AND team_id = ? LIMIT 1');
    $existing->execute([$messageId, (int) $team['id']]);
    $current = (string) ($existing->fetchColumn() ?: '');
    if ($current === $emoji) {
        $delete = $pdo->prepare('DELETE FROM cl_message_reactions WHERE message_id = ? AND team_id = ?');
        $delete->execute([$messageId, (int) $team['id']]);
    } else {
        $save = $pdo->prepare('INSERT INTO cl_message_reactions (message_id, team_id, emoji) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), created_at = CURRENT_TIMESTAMP');
        $save->execute([$messageId, (int) $team['id'], $emoji]);
    }
    json_response(get_state($pdo));
}

function delete_chat_message(PDO $pdo): void
{
    if (!current_admin($pdo)) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan.'], 401);
    }
    $messageId = (int) ($_POST['message_id'] ?? 0);
    if ($messageId <= 0) {
        json_response(['ok' => false, 'message' => 'Mesej tidak valid.'], 422);
    }
    $check = $pdo->prepare('SELECT id FROM cl_messages WHERE id = ? LIMIT 1');
    $check->execute([$messageId]);
    if (!$check->fetch()) {
        json_response(['ok' => false, 'message' => 'Mesej tidak dijumpai.'], 404);
    }
    $stmt = $pdo->prepare('DELETE FROM cl_messages WHERE id = ?');
    $stmt->execute([$messageId]);
    json_response(get_state($pdo) + ['message' => 'Mesej berjaya dipadam.']);
}

function edit_chat_message(PDO $pdo): void
{
    if (!current_admin($pdo)) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan.'], 401);
    }
    $messageId = (int) ($_POST['message_id'] ?? 0);
    $message = clean_multiline_text($_POST['message'] ?? '', 700);
    if ($messageId <= 0 || $message === '') {
        json_response(['ok' => false, 'message' => 'Mesej yang hendak diedit wajib diisi.'], 422);
    }
    $check = $pdo->prepare('
        SELECT m.id
        FROM cl_messages m
        INNER JOIN cl_rooms r ON r.id = m.room_id
        WHERE m.id = ? AND m.sender_type = "admin" AND r.room_type = "info"
        LIMIT 1
    ');
    $check->execute([$messageId]);
    if (!$check->fetchColumn()) {
        json_response(['ok' => false, 'message' => 'Hanya mesej admin dalam INFO TOUR boleh diedit.'], 403);
    }
    $stmt = $pdo->prepare('UPDATE cl_messages SET message = ?, edited_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$message, $messageId]);
    json_response(get_state($pdo) + ['message' => 'Mesej INFO TOUR berjaya diedit.']);
}

function send_message(PDO $pdo): void
{
    global $pushConfig, $rootDir;

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
    if (!in_array($actionTarget, ['', 'jadual', 'rules', 'profile', 'all-team', 'deal', 'cara-bermain', 'report-match'], true)) {
        json_response(['ok' => false, 'message' => 'Tag admin tidak valid.'], 422);
    }
    $message = clean_multiline_text($_POST['message'] ?? '', 700);
    $imageUrl = '';
    if (($admin || $loggedTeam) && isset($_FILES['message_image']) && (int) ($_FILES['message_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $imageUrl = upload_chat_image($rootDir, $_FILES['message_image']);
    }
    if ($roomId <= 0 || ($message === '' && $imageUrl === '')) {
        json_response(['ok' => false, 'message' => 'Room serta mesej atau gambar wajib diisi.'], 422);
    }
    if (!$admin && contains_external_contact($message)) {
        json_response([
            'ok' => false,
            'message' => 'Nombor telefon atau link WhatsApp tidak dibenarkan. Semua urusan wajib dibuat dalam chat website GNEX.',
        ], 422);
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
    if ((string) $room['room_type'] === 'info' && !$admin) {
        json_response(['ok' => false, 'message' => 'INFO TOUR adalah read-only. Hanya admin boleh hantar pengumuman.'], 403);
    }
    if ($admin && (string) $room['room_type'] === 'info') {
        // Every INFO TOUR post is important; always notify subscribed teams.
        $adminPushMode = 'all';
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
        INSERT INTO cl_messages (room_id, sender_type, sender_team_id, guest_name, reply_to_message_id, action_target, message, image_url)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$roomId, $senderType, $senderTeamId, $isGuest ? $guestName : null, $replyToMessageId ?: null, $actionTarget ?: null, $message, $imageUrl ?: null]);
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
    require_team($pdo);
    json_response(['ok' => false, 'message' => 'Update point hanya boleh dilakukan oleh admin.'], 403);

    /* Legacy team submission flow is intentionally disabled. */
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

    $submissionClosesAt = $matchStartsAt->modify('+3 hours');
    if ($now > $submissionClosesAt) {
        json_response([
            'ok' => false,
            'message' => 'Tempoh Update Point telah tamat. Hubungi admin untuk semakan manual.',
        ], 403);
    }

    $attendanceStmt = $pdo->prepare('
        SELECT COUNT(DISTINCT team_id)
        FROM cl_match_attendance
        WHERE match_id = ? AND team_id IN (?, ?)
    ');
    $attendanceStmt->execute([$matchId, (int) $match['team_a_id'], (int) $match['team_b_id']]);
    if ((int) $attendanceStmt->fetchColumn() !== 2) {
        json_response(['ok' => false, 'message' => 'Kedua-dua team mesti sahkan kehadiran sebelum Update Point dibuka.'], 403);
    }

    $existingStmt = $pdo->prepare('SELECT id FROM cl_match_results WHERE match_id = ? AND team_id = ? LIMIT 1');
    $existingStmt->execute([$matchId, (int) $team['id']]);
    if ((int) ($existingStmt->fetchColumn() ?: 0) > 0) {
        json_response(['ok' => false, 'message' => 'Result team anda sudah dihantar dan telah dikunci. Hubungi admin jika tersalah isi.'], 409);
    }

    [$screenshotPath, $screenshotUrl] = save_result_screenshot($_FILES['result_screenshot'] ?? [], (string) $team['team_name'], $matchId, $rootDir);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            INSERT INTO cl_match_results (match_id, team_id, team_a_point, team_b_point, screenshot_url, screenshot_path, status, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, "pending", CURRENT_TIMESTAMP)
        ');
        $stmt->execute([$matchId, $team['id'], $teamAPoint, $teamBPoint, $screenshotUrl, $screenshotPath]);

        $compareStmt = $pdo->prepare('
            SELECT team_id, team_a_point, team_b_point
            FROM cl_match_results
            WHERE match_id = ? AND team_id IN (?, ?)
            FOR UPDATE
        ');
        $compareStmt->execute([$matchId, (int) $match['team_a_id'], (int) $match['team_b_id']]);
        $submissions = $compareStmt->fetchAll();
        $message = 'Result dikunci. Menunggu pihak lawan menghantar laporan.';
        if (count($submissions) === 2) {
            $first = $submissions[0];
            $second = $submissions[1];
            $scoresMatch = (int) $first['team_a_point'] === (int) $second['team_a_point']
                && (int) $first['team_b_point'] === (int) $second['team_b_point'];
            if ($scoresMatch && (int) $first['team_a_point'] !== (int) $first['team_b_point']) {
                $pdo->prepare('
                    UPDATE cl_matches
                    SET status = "completed", team_a_point = ?, team_b_point = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ')->execute([(int) $first['team_a_point'], (int) $first['team_b_point'], $matchId]);
                $pdo->prepare('
                    UPDATE cl_match_results
                    SET status = "approved", admin_note = "Disahkan automatik: kedua-dua laporan sepadan", updated_at = CURRENT_TIMESTAMP
                    WHERE match_id = ?
                ')->execute([$matchId]);
                $message = 'Kedua-dua laporan sepadan. Keputusan telah disahkan automatik.';
            } else {
                $message = 'Laporan tidak sepadan. Status PERTIKAIAN dan admin akan menyemak screenshot.';
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    json_response(get_state($pdo) + ['message' => $message]);
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

function get_timeline(PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT setting_value FROM cl_settings WHERE setting_key = "timeline_json" LIMIT 1');
    $stmt->execute();
    $raw = $stmt->fetchColumn();
    if ($raw === false || trim((string) $raw) === '') {
        return [];
    }
    $items = json_decode((string) $raw, true);
    return is_array($items) ? array_values($items) : [];
}

function save_timeline(PDO $pdo): void
{
    if (!current_admin($pdo)) {
        json_response(['ok' => false, 'message' => 'Login admin diperlukan untuk edit timeline.'], 401);
    }
    $items = json_decode((string) ($_POST['timeline'] ?? ''), true);
    if (!is_array($items) || !$items || count($items) > 30) {
        json_response(['ok' => false, 'message' => 'Data timeline tidak valid.'], 422);
    }
    $cleanItems = [];
    foreach ($items as $item) {
        $date = clean_text($item['date'] ?? '', 80);
        $title = clean_text($item['title'] ?? '', 120);
        $note = clean_text($item['note'] ?? '', 300);
        if ($date === '' || $title === '') {
            json_response(['ok' => false, 'message' => 'Tarikh dan tajuk timeline wajib diisi.'], 422);
        }
        $oldItem = get_timeline($pdo)[count($cleanItems)] ?? [];
        $stageCode = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($oldItem['stage_code'] ?? $item['stage_code'] ?? '')));
        $cleanItems[] = [
            'date' => $date,
            'title' => $title,
            'note' => $note,
            'final' => !empty($item['final']),
            'stage_code' => $stageCode,
        ];
    }
    $oldItems = get_timeline($pdo);
    $encoded = json_encode($cleanItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            INSERT INTO cl_settings (setting_key, setting_value, updated_at)
            VALUES ("timeline_json", ?, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([$encoded]);
        sync_matches_with_timeline($pdo, $oldItems, $cleanItems);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        json_response(['ok' => false, 'message' => 'Timeline gagal disimpan: ' . $error->getMessage()], 409);
    }
    json_response(get_state($pdo) + ['message' => 'Timeline dan semua jadual match berjaya update.']);
}

function sync_matches_with_timeline(PDO $pdo, array $oldItems, array $newItems): void
{
    $count = min(count($oldItems), count($newItems));
    $update = $pdo->prepare('
        UPDATE cl_matches
        SET match_name = CONCAT(?, SUBSTRING(match_name, CHAR_LENGTH(?) + 1)),
            match_time = COALESCE(?, match_time), updated_at = CURRENT_TIMESTAMP
        WHERE match_name = ? OR match_name LIKE ?
    ');
    for ($index = 0; $index < $count; $index++) {
        $oldTitle = trim((string) ($oldItems[$index]['title'] ?? ''));
        $newTitle = trim((string) ($newItems[$index]['title'] ?? ''));
        if ($oldTitle === '' || $newTitle === '') continue;
        $newTime = timeline_item_datetime($newItems[$index]);
        $stageCode = trim((string)($oldItems[$index]['stage_code'] ?? $newItems[$index]['stage_code'] ?? ''));
        if ($stageCode !== '') {
            $byStage = $pdo->prepare('UPDATE cl_matches SET match_name=CONCAT(?, IF(LOCATE(" / M",match_name)>0,SUBSTRING(match_name,LOCATE(" / M",match_name)),"")), match_time=COALESCE(?,match_time), updated_at=CURRENT_TIMESTAMP WHERE stage_code=?');
            $byStage->execute([$newTitle,$newTime,$stageCode]);
        } else {
            $update->execute([$newTitle, $oldTitle, $newTime, $oldTitle, $oldTitle . ' %']);
        }
    }
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
    $actionTarget = in_array((string) ($message['action_target'] ?? ''), ['jadual', 'rules', 'profile', 'all-team', 'deal', 'cara-bermain', 'report-match'], true)
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
