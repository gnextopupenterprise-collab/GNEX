<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Kuala_Lumpur');

$topupSessionPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'sessions';
if (!is_dir($topupSessionPath)) @mkdir($topupSessionPath, 0700, true);
if (is_dir($topupSessionPath)) ini_set('session.save_path', $topupSessionPath);

session_set_cookie_params([
    'lifetime' => 365 * 86400,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

const GT_DEVICE_COOKIE = 'gnex_topup_device';
const GT_DEVICE_DAYS = 730;

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function input_data(): array
{
    $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($type, 'application/json')) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

function clean(mixed $value, int $max = 255): string
{
    return mb_substr(trim((string) $value), 0, $max);
}

function csrf_token(): string
{
    if (empty($_SESSION['gt_csrf'])) {
        $_SESSION['gt_csrf'] = bin2hex(random_bytes(24));
    }
    return (string) $_SESSION['gt_csrf'];
}

function require_csrf(array $input): void
{
    $provided = clean($input['csrf'] ?? '', 100);
    if ($provided === '' || !hash_equals(csrf_token(), $provided)) {
        respond(['ok' => false, 'message' => 'Session tidak sah. Refresh dan cuba lagi.'], 419);
    }
}

function device_token(): string
{
    $token = clean($_COOKIE[GT_DEVICE_COOKIE] ?? '', 128);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        $token = bin2hex(random_bytes(32));
        setcookie(GT_DEVICE_COOKIE, $token, [
            'expires' => time() + GT_DEVICE_DAYS * 86400,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[GT_DEVICE_COOKIE] = $token;
    }
    return $token;
}

function customer(PDO $pdo): ?array
{
    if (empty($_SESSION['gt_customer_id'])) return null;
    $stmt = $pdo->prepare('SELECT id,name,login_id,created_at FROM gt_customers WHERE id=? AND status="active" LIMIT 1');
    $stmt->execute([(int) $_SESSION['gt_customer_id']]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function admin(PDO $pdo): ?array
{
    if (empty($_SESSION['cl_admin_id'])) return null;
    $stmt = $pdo->prepare('SELECT id,username,access_scope FROM cl_admin_users WHERE id=? LIMIT 1');
    $stmt->execute([(int) $_SESSION['cl_admin_id']]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function require_customer(PDO $pdo): array
{
    $user = customer($pdo);
    if (!$user) respond(['ok' => false, 'message' => 'Login diperlukan.'], 401);
    return $user;
}

function require_admin(PDO $pdo): array
{
    $user = admin($pdo);
    if (!$user) respond(['ok' => false, 'message' => 'Login admin diperlukan.'], 401);
    return $user;
}

function ensure_schema(PDO $pdo): void
{
    $queries = [
        'CREATE TABLE IF NOT EXISTS cl_admin_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(80) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            access_scope VARCHAR(30) NOT NULL DEFAULT "admin",
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_customers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            login_id VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            status ENUM("active","suspended") NOT NULL DEFAULT "active",
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_devices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) NOT NULL UNIQUE,
            customer_id BIGINT UNSIGNED NULL,
            user_agent VARCHAR(255) NULL,
            last_seen_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES gt_customers(id) ON DELETE SET NULL,
            INDEX idx_gt_devices_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_conversations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NULL,
            status ENUM("open","resolved") NOT NULL DEFAULT "open",
            last_message_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (device_id) REFERENCES gt_devices(id) ON DELETE CASCADE,
            FOREIGN KEY (customer_id) REFERENCES gt_customers(id) ON DELETE SET NULL,
            INDEX idx_gt_conversations_device (device_id,status),
            INDEX idx_gt_conversations_customer (customer_id,last_message_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id BIGINT UNSIGNED NOT NULL,
            sender_type ENUM("guest","customer","admin","system") NOT NULL,
            sender_admin_id INT NULL,
            body VARCHAR(2000) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (conversation_id) REFERENCES gt_conversations(id) ON DELETE CASCADE,
            FOREIGN KEY (sender_admin_id) REFERENCES cl_admin_users(id) ON DELETE SET NULL,
            INDEX idx_gt_messages_conversation (conversation_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_game_accounts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id BIGINT UNSIGNED NOT NULL,
            game_code ENUM("ff","ml","pubg") NOT NULL,
            game_id VARCHAR(120) NOT NULL,
            server_id VARCHAR(120) NULL,
            label VARCHAR(100) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            FOREIGN KEY (customer_id) REFERENCES gt_customers(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_gt_game_account (customer_id,game_code,game_id,server_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_purchases (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id BIGINT UNSIGNED NULL,
            device_id BIGINT UNSIGNED NULL,
            game_code ENUM("ff","ml","pubg") NOT NULL,
            game_id VARCHAR(120) NULL,
            server_id VARCHAR(120) NULL,
            package_name VARCHAR(150) NOT NULL,
            amount_rm DECIMAL(10,2) NOT NULL DEFAULT 0,
            status ENUM("pending","paid","processing","completed","cancelled") NOT NULL DEFAULT "pending",
            created_by_admin_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            FOREIGN KEY (customer_id) REFERENCES gt_customers(id) ON DELETE SET NULL,
            FOREIGN KEY (device_id) REFERENCES gt_devices(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by_admin_id) REFERENCES cl_admin_users(id) ON DELETE SET NULL,
            INDEX idx_gt_purchases_customer (customer_id,status,created_at),
            INDEX idx_gt_purchases_game (game_code,status,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    ];
    foreach ($queries as $query) $pdo->exec($query);
}

function current_device(PDO $pdo): array
{
    $hash = hash('sha256', device_token());
    $customerId = !empty($_SESSION['gt_customer_id']) ? (int) $_SESSION['gt_customer_id'] : null;
    $stmt = $pdo->prepare('SELECT id,customer_id FROM gt_devices WHERE token_hash=? LIMIT 1');
    $stmt->execute([$hash]);
    $device = $stmt->fetch();
    if (!$device) {
        $stmt = $pdo->prepare('INSERT INTO gt_devices(token_hash,customer_id,user_agent,last_seen_at) VALUES(?,?,?,NOW())');
        $stmt->execute([$hash, $customerId, clean($_SERVER['HTTP_USER_AGENT'] ?? '', 255)]);
        return ['id' => (int) $pdo->lastInsertId(), 'customer_id' => $customerId];
    }
    $stmt = $pdo->prepare('UPDATE gt_devices SET customer_id=COALESCE(?,customer_id),last_seen_at=NOW() WHERE id=?');
    $stmt->execute([$customerId, (int) $device['id']]);
    return ['id' => (int) $device['id'], 'customer_id' => $customerId ?: ($device['customer_id'] ? (int) $device['customer_id'] : null)];
}

function conversation_id(PDO $pdo, array $device, ?array $user): int
{
    $stmt = $pdo->prepare('SELECT id FROM gt_conversations WHERE device_id=? AND status="open" ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int) $device['id']]);
    $id = (int) ($stmt->fetchColumn() ?: 0);
    if ($id) {
        if ($user) $pdo->prepare('UPDATE gt_conversations SET customer_id=? WHERE id=?')->execute([(int) $user['id'], $id]);
        return $id;
    }
    $stmt = $pdo->prepare('INSERT INTO gt_conversations(device_id,customer_id,last_message_at) VALUES(?,?,NOW())');
    $stmt->execute([(int) $device['id'], $user ? (int) $user['id'] : null]);
    return (int) $pdo->lastInsertId();
}

$root = dirname(__DIR__);
$configPath = $root . DIRECTORY_SEPARATOR . 'scrim-db-config.php';
if (!is_file($configPath)) respond(['ok' => false, 'message' => 'Database config belum tersedia.'], 500);
$config = require $configPath;

try {
    $pdo = new PDO(
        'mysql:host=' . $config['host'] . ';dbname=' . $config['database'] . ';charset=utf8mb4',
        $config['username'],
        $config['password'],
        [PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo->exec("SET time_zone = '+08:00'");
    ensure_schema($pdo);
} catch (Throwable $error) {
    error_log($error->__toString());
    respond(['ok' => false, 'message' => 'Topup database belum tersedia.'], 500);
}

$action = clean($_GET['action'] ?? $_POST['action'] ?? '', 40);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$user = customer($pdo);
$adminUser = admin($pdo);
$device = current_device($pdo);

if ($method === 'GET' && $action === 'state') {
    $totals = ['total_rm' => 0, 'orders' => 0];
    $games = [];
    if ($user) {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount_rm),0) total_rm,COUNT(*) orders FROM gt_purchases WHERE customer_id=? AND status="completed"');
        $stmt->execute([(int) $user['id']]);
        $totals = $stmt->fetch() ?: $totals;
        $stmt = $pdo->prepare('SELECT id,game_code,game_id,server_id,label FROM gt_game_accounts WHERE customer_id=? ORDER BY id DESC');
        $stmt->execute([(int) $user['id']]);
        $games = $stmt->fetchAll();
    }
    respond(['ok' => true, 'csrf' => csrf_token(), 'customer' => $user, 'admin' => $adminUser, 'totals' => $totals, 'game_accounts' => $games]);
}

if ($method === 'GET' && $action === 'messages') {
    $conversation = conversation_id($pdo, $device, $user);
    $after = max(0, (int) ($_GET['after'] ?? 0));
    $stmt = $pdo->prepare('SELECT id,sender_type,body,created_at FROM gt_messages WHERE conversation_id=? AND id>? ORDER BY id ASC LIMIT 100');
    $stmt->execute([$conversation, $after]);
    respond(['ok' => true, 'conversation_id' => $conversation, 'messages' => $stmt->fetchAll(), 'csrf' => csrf_token()]);
}

$input = input_data();
if ($method === 'POST') require_csrf($input);

if ($method === 'POST' && $action === 'register') {
    $name = clean($input['name'] ?? '', 100);
    $loginId = strtolower(clean($input['login_id'] ?? '', 190));
    $password = (string) ($input['password'] ?? '');
    if ($name === '' || $loginId === '' || strlen($password) < 8) respond(['ok' => false, 'message' => 'Isi nama, email/nombor dan password minimum 8 aksara.'], 422);
    try {
        $stmt = $pdo->prepare('INSERT INTO gt_customers(name,login_id,password_hash) VALUES(?,?,?)');
        $stmt->execute([$name, $loginId, password_hash($password, PASSWORD_DEFAULT)]);
    } catch (PDOException $error) {
        if ((string) $error->getCode() === '23000') respond(['ok' => false, 'message' => 'Email atau nombor ini sudah didaftarkan.'], 409);
        throw $error;
    }
    session_regenerate_id(true);
    $_SESSION['gt_customer_id'] = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE gt_devices SET customer_id=? WHERE id=?')->execute([$_SESSION['gt_customer_id'], (int) $device['id']]);
    respond(['ok' => true, 'message' => 'Akaun berjaya didaftarkan.', 'customer' => customer($pdo), 'csrf' => csrf_token()]);
}

if ($method === 'POST' && $action === 'login') {
    $loginId = strtolower(clean($input['login_id'] ?? '', 190));
    $password = (string) ($input['password'] ?? '');
    $stmt = $pdo->prepare('SELECT id,password_hash,status FROM gt_customers WHERE login_id=? LIMIT 1');
    $stmt->execute([$loginId]);
    $account = $stmt->fetch();
    if (!$account || $account['status'] !== 'active' || !password_verify($password, (string) $account['password_hash'])) respond(['ok' => false, 'message' => 'Login tidak sah.'], 401);
    session_regenerate_id(true);
    $_SESSION['gt_customer_id'] = (int) $account['id'];
    $pdo->prepare('UPDATE gt_devices SET customer_id=? WHERE id=?')->execute([(int) $account['id'], (int) $device['id']]);
    respond(['ok' => true, 'message' => 'Login berjaya.', 'customer' => customer($pdo), 'csrf' => csrf_token()]);
}

if ($method === 'POST' && $action === 'adminLogin') {
    $name = clean($input['username'] ?? '', 80);
    $password = (string) ($input['password'] ?? '');
    $stmt = $pdo->prepare('SELECT id,password_hash FROM cl_admin_users WHERE username=? LIMIT 1');
    $stmt->execute([$name]);
    $account = $stmt->fetch();
    if (!$account || !password_verify($password, (string) $account['password_hash'])) respond(['ok' => false, 'message' => 'Login admin salah.'], 401);
    session_regenerate_id(true);
    $_SESSION['cl_admin_id'] = (int) $account['id'];
    respond(['ok' => true, 'message' => 'Admin login berjaya.', 'admin' => admin($pdo), 'csrf' => csrf_token()]);
}

if ($method === 'POST' && $action === 'logout') {
    unset($_SESSION['gt_customer_id']);
    respond(['ok' => true, 'message' => 'Logout berjaya.', 'csrf' => csrf_token()]);
}

if ($method === 'POST' && $action === 'sendMessage') {
    $body = clean($input['body'] ?? '', 2000);
    if ($body === '') respond(['ok' => false, 'message' => 'Mesej kosong.'], 422);
    $conversation = conversation_id($pdo, $device, $user);
    $senderType = $adminUser ? 'admin' : ($user ? 'customer' : 'guest');
    $stmt = $pdo->prepare('INSERT INTO gt_messages(conversation_id,sender_type,sender_admin_id,body) VALUES(?,?,?,?)');
    $stmt->execute([$conversation, $senderType, $adminUser ? (int) $adminUser['id'] : null, $body]);
    $pdo->prepare('UPDATE gt_conversations SET last_message_at=NOW(),customer_id=COALESCE(?,customer_id) WHERE id=?')->execute([$user ? (int) $user['id'] : null, $conversation]);
    respond(['ok' => true, 'id' => (int) $pdo->lastInsertId(), 'message' => 'Mesej dihantar.']);
}

if ($method === 'POST' && $action === 'saveGameAccount') {
    $current = require_customer($pdo);
    $game = clean($input['game_code'] ?? '', 10);
    $gameId = clean($input['game_id'] ?? '', 120);
    $serverId = clean($input['server_id'] ?? '', 120);
    if (!in_array($game, ['ff','ml','pubg'], true) || $gameId === '') respond(['ok' => false, 'message' => 'Maklumat game tidak sah.'], 422);
    $stmt = $pdo->prepare('INSERT INTO gt_game_accounts(customer_id,game_code,game_id,server_id,label) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),updated_at=NOW()');
    $stmt->execute([(int) $current['id'], $game, $gameId, $serverId ?: null, clean($input['label'] ?? '', 100) ?: null]);
    respond(['ok' => true, 'message' => 'ID game disimpan.']);
}

if ($method === 'POST' && $action === 'recordPurchase') {
    $currentAdmin = require_admin($pdo);
    $customerId = max(0, (int) ($input['customer_id'] ?? 0));
    $game = clean($input['game_code'] ?? '', 10);
    $amount = round((float) ($input['amount_rm'] ?? 0), 2);
    if (!in_array($game, ['ff','ml','pubg'], true) || $amount <= 0) respond(['ok' => false, 'message' => 'Data pembelian tidak sah.'], 422);
    $stmt = $pdo->prepare('INSERT INTO gt_purchases(customer_id,device_id,game_code,game_id,server_id,package_name,amount_rm,status,created_by_admin_id) VALUES(NULLIF(?,0),?,?,?,?,?,?,?,?)');
    $stmt->execute([$customerId, (int) $device['id'], $game, clean($input['game_id'] ?? '', 120) ?: null, clean($input['server_id'] ?? '', 120) ?: null, clean($input['package_name'] ?? '', 150), $amount, clean($input['status'] ?? 'completed', 20), (int) $currentAdmin['id']]);
    respond(['ok' => true, 'message' => 'Pembelian direkodkan.', 'purchase_id' => (int) $pdo->lastInsertId()]);
}

respond(['ok' => false, 'message' => 'Action tidak dijumpai.'], 404);
