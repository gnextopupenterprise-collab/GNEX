<?php
declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Kuala_Lumpur');

$topupSessionPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'sessions';
if (!is_dir($topupSessionPath)) @mkdir($topupSessionPath, 0700, true);
if (is_dir($topupSessionPath)) ini_set('session.save_path', $topupSessionPath);

const GT_SESSION_DAYS = 365;
$topupSessionLifetime = GT_SESSION_DAYS * 86400;
ini_set('session.gc_maxlifetime', (string) $topupSessionLifetime);
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '100');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.lazy_write', '0');

session_set_cookie_params([
    'lifetime' => $topupSessionLifetime,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Sliding expiry: every valid visit renews the login cookie for another year.
if (session_id() !== '') {
    setcookie(session_name(), session_id(), [
        'expires' => time() + $topupSessionLifetime,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

const GT_DEVICE_COOKIE = 'gnex_topup_device';
const GT_DEVICE_DAYS = 730;
const GT_ADMIN_REMEMBER_COOKIE = 'gnex_admin_remember';
const GT_ADMIN_REMEMBER_DAYS = 365;

function get_bot_enabled(): bool {
    $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'bot-status.json';

    if (!file_exists($file)) {
        return true;
    }

    $data = json_decode((string) file_get_contents($file), true);

    return (bool) ($data['enabled'] ?? true);
}


function set_bot_enabled(bool $enabled): void
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';

    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Folder data tidak dapat dibuat.');
    }

    $file = $dir . DIRECTORY_SEPARATOR . 'bot-status.json';
    $json = json_encode(['enabled' => $enabled], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($json === false || file_put_contents($file, $json, LOCK_EX) === false) {
        throw new RuntimeException('Status bot tidak dapat disimpan.');
    }
}

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

function normalize_phone(mixed $value): string
{
    $digits = preg_replace('/\D+/', '', clean($value, 40));
    if (str_starts_with($digits, '60')) $digits = '0' . substr($digits, 2);
    return $digits;
}

function auth_limit_check(string $key, int $limit, int $windowSeconds): void
{
    $now = time();
    $attempts = array_values(array_filter((array)($_SESSION['gt_auth_attempts'][$key] ?? []), static fn($stamp) => is_int($stamp) && $stamp > $now - $windowSeconds));
    $_SESSION['gt_auth_attempts'][$key] = $attempts;
    if (count($attempts) >= $limit) respond(['ok'=>false,'message'=>'Terlalu banyak percubaan. Tunggu sebentar dan cuba semula.'],429);
}

function auth_limit_fail(string $key): void
{
    $_SESSION['gt_auth_attempts'][$key][] = time();
}

function auth_limit_clear(string $key): void
{
    unset($_SESSION['gt_auth_attempts'][$key]);
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
    $stmt = $pdo->prepare('SELECT id,name,login_id,status,created_at FROM gt_customers WHERE id=? AND status="active" LIMIT 1');
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

function set_admin_remember_cookie(string $value, int $expires): void
{
    setcookie(GT_ADMIN_REMEMBER_COOKIE, $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if ($value === '') unset($_COOKIE[GT_ADMIN_REMEMBER_COOKIE]);
    else $_COOKIE[GT_ADMIN_REMEMBER_COOKIE] = $value;
}

function admin_remember_request_token(): string
{
    $headerToken = (string) ($_SERVER['HTTP_X_GNEX_REMEMBER'] ?? '');
    return $headerToken !== '' ? $headerToken : (string) ($_COOKIE[GT_ADMIN_REMEMBER_COOKIE] ?? '');
}

function clear_admin_remember_token(PDO $pdo): void
{
    $cookie = admin_remember_request_token();
    if (preg_match('/^([a-f0-9]{24})\.[a-f0-9]{64}$/', $cookie, $match)) {
        $pdo->prepare('DELETE FROM gt_admin_remember_tokens WHERE selector=?')->execute([$match[1]]);
    }
    set_admin_remember_cookie('', time() - 42000);
}

function issue_admin_remember_token(PDO $pdo, int $adminId): string
{
    clear_admin_remember_token($pdo);
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expires = time() + GT_ADMIN_REMEMBER_DAYS * 86400;
    $stmt = $pdo->prepare('INSERT INTO gt_admin_remember_tokens(selector,admin_id,token_hash,expires_at,last_used_at) VALUES(?,?,?,?,NOW())');
    $stmt->execute([$selector,$adminId,hash('sha256',$validator),date('Y-m-d H:i:s',$expires)]);
    $token = $selector.'.'.$validator;
    set_admin_remember_cookie($token, $expires);
    return $token;
}

function restore_admin_from_remember(PDO $pdo): void
{
    if (!empty($_SESSION['cl_admin_id'])) return;
    $cookie = admin_remember_request_token();
    if (!preg_match('/^([a-f0-9]{24})\.([a-f0-9]{64})$/', $cookie, $match)) {
        if ($cookie !== '') set_admin_remember_cookie('', time() - 42000);
        return;
    }
    $stmt = $pdo->prepare('SELECT t.admin_id,t.token_hash,t.expires_at,a.access_scope FROM gt_admin_remember_tokens t INNER JOIN cl_admin_users a ON a.id=t.admin_id WHERE t.selector=? AND t.expires_at>NOW() LIMIT 1');
    $stmt->execute([$match[1]]);
    $row = $stmt->fetch();
    if (!$row || !hash_equals((string)$row['token_hash'], hash('sha256',$match[2]))) {
        clear_admin_remember_token($pdo);
        return;
    }
    session_regenerate_id(true);
    $_SESSION['cl_admin_id'] = (int) $row['admin_id'];
    $_SESSION['cl_admin_access_scope'] = (string) ($row['access_scope'] ?? 'admin');
    $pdo->prepare('UPDATE gt_admin_remember_tokens SET last_used_at=NOW() WHERE selector=?')->execute([$match[1]]);
    set_admin_remember_cookie($cookie, max(time() + 86400, strtotime((string)$row['expires_at'])));
}

function touch_admin_presence(PDO $pdo, int $adminId): void
{
    $stmt = $pdo->prepare('INSERT INTO gt_admin_presence(session_hash,admin_id,last_seen_at,expires_at) VALUES(?,?,NOW(),DATE_ADD(NOW(),INTERVAL 45 SECOND)) ON DUPLICATE KEY UPDATE admin_id=VALUES(admin_id),last_seen_at=NOW(),expires_at=DATE_ADD(NOW(),INTERVAL 45 SECOND)');
    $stmt->execute([hash('sha256', session_id()),$adminId]);
}

function admin_presence(PDO $pdo): array
{
    $row = $pdo->query('SELECT MAX(last_seen_at) last_seen_at,MAX(expires_at>NOW()) is_online FROM gt_admin_presence')->fetch() ?: [];
    return ['online'=>(bool)($row['is_online'] ?? false),'last_seen_at'=>$row['last_seen_at'] ?? null];
}

function user_unread_count(PDO $pdo, int $deviceId): int
{
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM gt_messages m INNER JOIN gt_conversations c ON c.id=m.conversation_id WHERE c.device_id=? AND c.status="open" AND m.sender_type IN ("admin","system") AND m.id>c.user_last_read_message_id');
    $stmt->execute([$deviceId]);
    return (int)$stmt->fetchColumn();
}

function push_public_key(): string
{
    $path=dirname(__DIR__).DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'push-config.php';
    if (!is_file($path)) return '';
    $config=require $path;
    return (string)($config['public_key'] ?? '');
}

function admin_unread_count(PDO $pdo): int
{
    return (int)$pdo->query('SELECT COUNT(*) FROM gt_conversations c WHERE c.status="open" AND EXISTS(SELECT 1 FROM gt_messages m WHERE m.conversation_id=c.id AND m.id>c.admin_last_read_message_id AND m.sender_type IN ("guest","customer"))')->fetchColumn();
}

function active_worker_alert(PDO $pdo, ?array $admin): ?array
{
    if (!$admin || (string)($admin['access_scope'] ?? '') !== 'order') return null;
    $stmt=$pdo->prepare('SELECT id,alert_type,message,created_at FROM gt_worker_alerts WHERE target_admin_id=? AND status="active" ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int)$admin['id']]);
    $row=$stmt->fetch();
    return $row ?: null;
}

function send_web_push(PDO $pdo, string $where, array $params, array $payload): void
{
    $autoload=dirname(__DIR__).DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
    $configPath=dirname(__DIR__).DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'push-config.php';
    if (!is_file($autoload) || !is_file($configPath)) return;
    try {
        require_once $autoload;
        $config=require $configPath;
        $webPush=new Minishlink\WebPush\WebPush(['VAPID'=>[
            'subject'=>$config['subject'],
            'publicKey'=>$config['public_key'],
            'privateKey'=>$config['private_key'],
        ]],['TTL'=>86400,'urgency'=>'high']);
        $stmt=$pdo->prepare('SELECT endpoint,p256dh,auth_token FROM gt_push_subscriptions WHERE enabled=1 AND '.$where);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $subscription) {
            $webPush->queueNotification(Minishlink\WebPush\Subscription::create([
                'endpoint'=>$subscription['endpoint'],
                'keys'=>['p256dh'=>$subscription['p256dh'],'auth'=>$subscription['auth_token']],
            ]),json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        }
        foreach ($webPush->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                $pdo->prepare('DELETE FROM gt_push_subscriptions WHERE endpoint=?')->execute([$report->getEndpoint()]);
            } elseif (!$report->isSuccess()) {
                error_log('GNEX push delivery failed: '.$report->getReason().' · '.substr($report->getEndpoint(),0,80));
            }
        }
    } catch (Throwable $error) {
        error_log('GNEX push: '.$error->getMessage());
    }
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
        'CREATE TABLE IF NOT EXISTS gt_admin_presence (
            session_hash CHAR(64) PRIMARY KEY,
            admin_id INT NOT NULL,
            last_seen_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            FOREIGN KEY (admin_id) REFERENCES cl_admin_users(id) ON DELETE CASCADE,
            INDEX idx_gt_admin_presence_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_customers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            login_id VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            status ENUM("pending","active","rejected","suspended") NOT NULL DEFAULT "pending",
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
            department ENUM("topup","tour","report") NOT NULL DEFAULT "topup",
            last_message_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (device_id) REFERENCES gt_devices(id) ON DELETE CASCADE,
            FOREIGN KEY (customer_id) REFERENCES gt_customers(id) ON DELETE SET NULL,
            INDEX idx_gt_conversations_device (device_id,status),
            INDEX idx_gt_conversations_department (department,last_message_at),
            INDEX idx_gt_conversations_customer (customer_id,last_message_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id BIGINT UNSIGNED NOT NULL,
            sender_type ENUM("guest","customer","admin","system") NOT NULL,
            sender_admin_id INT NULL,
            body VARCHAR(2000) NOT NULL,
            media_url VARCHAR(500) NULL,
            message_kind VARCHAR(30) NULL,
            order_status VARCHAR(20) NULL,
            reply_to_message_id BIGINT UNSIGNED NULL,
            updated_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (conversation_id) REFERENCES gt_conversations(id) ON DELETE CASCADE,
            FOREIGN KEY (sender_admin_id) REFERENCES cl_admin_users(id) ON DELETE SET NULL,
            INDEX idx_gt_messages_conversation (conversation_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_push_subscriptions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            endpoint VARCHAR(1000) NOT NULL,
            endpoint_hash CHAR(64) NOT NULL UNIQUE,
            p256dh VARCHAR(255) NOT NULL,
            auth_token VARCHAR(255) NOT NULL,
            role ENUM("user","admin") NOT NULL,
            device_id BIGINT UNSIGNED NULL,
            admin_id INT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            FOREIGN KEY(device_id) REFERENCES gt_devices(id) ON DELETE CASCADE,
            FOREIGN KEY(admin_id) REFERENCES cl_admin_users(id) ON DELETE CASCADE,
            INDEX idx_gt_push_role(role,enabled),
            INDEX idx_gt_push_device(device_id,enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_admin_conversation_reads (
            admin_id INT NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL,
            last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NULL,
            PRIMARY KEY(admin_id,conversation_id),
            FOREIGN KEY(admin_id) REFERENCES cl_admin_users(id) ON DELETE CASCADE,
            FOREIGN KEY(conversation_id) REFERENCES gt_conversations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_admin_remember_tokens (
            selector CHAR(24) PRIMARY KEY,
            admin_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            FOREIGN KEY(admin_id) REFERENCES cl_admin_users(id) ON DELETE CASCADE,
            INDEX idx_gt_admin_remember_expiry(expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_admin_reminder_dispatch (
            admin_id INT NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL,
            reminder_type ENUM("order","chat") NOT NULL,
            last_sent_at DATETIME NOT NULL,
            PRIMARY KEY(admin_id,conversation_id,reminder_type),
            FOREIGN KEY(admin_id) REFERENCES cl_admin_users(id) ON DELETE CASCADE,
            FOREIGN KEY(conversation_id) REFERENCES gt_conversations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_worker_alerts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            alert_type VARCHAR(40) NOT NULL,
            message VARCHAR(255) NOT NULL,
            target_admin_id INT NOT NULL,
            requested_by_admin_id INT NOT NULL,
            status ENUM("active","acknowledged") NOT NULL DEFAULT "active",
            last_sent_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            acknowledged_at DATETIME NULL,
            acknowledged_by_admin_id INT NULL,
            FOREIGN KEY(target_admin_id) REFERENCES cl_admin_users(id) ON DELETE CASCADE,
            FOREIGN KEY(requested_by_admin_id) REFERENCES cl_admin_users(id) ON DELETE CASCADE,
            FOREIGN KEY(acknowledged_by_admin_id) REFERENCES cl_admin_users(id) ON DELETE SET NULL,
            INDEX idx_gt_worker_alert_due(target_admin_id,status,last_sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_chat_labels (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            created_by_admin_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(created_by_admin_id) REFERENCES cl_admin_users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_system_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value VARCHAR(500) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_communities (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            channel VARCHAR(40) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(500) NULL,
            image_url VARCHAR(500) NULL,
            admin_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (admin_id) REFERENCES cl_admin_users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_community_posts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            channel ENUM("ff","ml","pubg") NOT NULL,
            admin_id INT NOT NULL,
            body VARCHAR(3000) NOT NULL,
            media_url VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (admin_id) REFERENCES cl_admin_users(id) ON DELETE CASCADE,
            INDEX idx_gt_community_channel (channel,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_groups (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(500) NULL,
            image_url VARCHAR(500) NULL,
            is_internal TINYINT(1) NOT NULL DEFAULT 0,
            pinned TINYINT(1) NOT NULL DEFAULT 0,
            embed_url VARCHAR(500) NULL,
            admin_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(admin_id) REFERENCES cl_admin_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_group_members (
            group_id BIGINT UNSIGNED NOT NULL,
            device_id BIGINT UNSIGNED NOT NULL,
            muted TINYINT(1) NOT NULL DEFAULT 0,
            joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(group_id,device_id),
            FOREIGN KEY(group_id) REFERENCES gt_groups(id) ON DELETE CASCADE,
            FOREIGN KEY(device_id) REFERENCES gt_devices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_group_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_id BIGINT UNSIGNED NOT NULL,
            device_id BIGINT UNSIGNED NULL,
            admin_id INT NULL,
            body VARCHAR(2000) NOT NULL,
            media_url VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(group_id) REFERENCES gt_groups(id) ON DELETE CASCADE,
            FOREIGN KEY(device_id) REFERENCES gt_devices(id) ON DELETE SET NULL,
            FOREIGN KEY(admin_id) REFERENCES cl_admin_users(id) ON DELETE SET NULL,
            INDEX idx_gt_group_messages(group_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS gt_community_reactions (
            post_id BIGINT UNSIGNED NOT NULL,
            device_id BIGINT UNSIGNED NOT NULL,
            emoji VARCHAR(12) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(post_id,device_id,emoji),
            FOREIGN KEY (post_id) REFERENCES gt_community_posts(id) ON DELETE CASCADE,
            FOREIGN KEY (device_id) REFERENCES gt_devices(id) ON DELETE CASCADE
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
    $statusColumn = $pdo->query('SHOW COLUMNS FROM gt_customers LIKE "status"')->fetch();
    if (!$statusColumn || !str_contains((string) ($statusColumn['Type'] ?? ''), "'pending'")) {
        $pdo->exec('ALTER TABLE gt_customers MODIFY status ENUM("pending","active","rejected","suspended") NOT NULL DEFAULT "pending"');
    }

    $departmentColumn = $pdo->query('SHOW COLUMNS FROM gt_conversations LIKE "department"')->fetch();
    if (!$departmentColumn) {
        $pdo->exec('ALTER TABLE gt_conversations ADD COLUMN department ENUM("topup","tour","report") NOT NULL DEFAULT "topup" AFTER status');
        $pdo->exec('ALTER TABLE gt_conversations ADD INDEX idx_gt_conversations_department (department,last_message_at)');
    }
    if (!$pdo->query('SHOW COLUMNS FROM gt_conversations LIKE "admin_last_read_message_id"')->fetch()) {
        $pdo->exec('ALTER TABLE gt_conversations ADD COLUMN admin_last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER last_message_at');
    }
    if (!$pdo->query('SHOW COLUMNS FROM gt_conversations LIKE "user_last_read_message_id"')->fetch()) {
        $pdo->exec('ALTER TABLE gt_conversations ADD COLUMN user_last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER admin_last_read_message_id');
    }
    if (!$pdo->query('SHOW COLUMNS FROM gt_conversations LIKE "admin_label"')->fetch()) {
        $pdo->exec('ALTER TABLE gt_conversations ADD COLUMN admin_label VARCHAR(100) NULL AFTER admin_last_read_message_id');
    }
    if (!$pdo->query('SHOW COLUMNS FROM gt_devices LIKE "banned_at"')->fetch()) {
        $pdo->exec('ALTER TABLE gt_devices ADD COLUMN banned_at DATETIME NULL, ADD COLUMN ban_reason VARCHAR(255) NULL');
    }
    if (!$pdo->query('SHOW COLUMNS FROM gt_messages LIKE "media_url"')->fetch()) {
        $pdo->exec('ALTER TABLE gt_messages ADD COLUMN media_url VARCHAR(500) NULL AFTER body');
    }
    if (!$pdo->query('SHOW COLUMNS FROM gt_messages LIKE "message_kind"')->fetch()) {
        $pdo->exec('ALTER TABLE gt_messages ADD COLUMN message_kind VARCHAR(30) NULL AFTER media_url');
    }
    if (!$pdo->query('SHOW COLUMNS FROM gt_messages LIKE "order_status"')->fetch()) {
        $pdo->exec('ALTER TABLE gt_messages ADD COLUMN order_status VARCHAR(20) NULL AFTER message_kind');
    }
    if (!$pdo->query('SHOW COLUMNS FROM gt_messages LIKE "updated_at"')->fetch()) {
        $pdo->exec('ALTER TABLE gt_messages ADD COLUMN updated_at DATETIME NULL AFTER order_status');
    }
    if (!$pdo->query('SHOW COLUMNS FROM gt_messages LIKE "reply_to_message_id"')->fetch()) {
        $pdo->exec('ALTER TABLE gt_messages ADD COLUMN reply_to_message_id BIGINT UNSIGNED NULL AFTER order_status, ADD INDEX idx_gt_messages_reply (reply_to_message_id)');
    }
    if (!$pdo->query('SHOW COLUMNS FROM gt_community_posts LIKE "media_url"')->fetch()) {
        $pdo->exec('ALTER TABLE gt_community_posts ADD COLUMN media_url VARCHAR(500) NULL AFTER body');
    }
    if (!$pdo->query('SHOW COLUMNS FROM gt_groups LIKE "image_url"')->fetch()) {
        $pdo->exec('ALTER TABLE gt_groups ADD COLUMN image_url VARCHAR(500) NULL AFTER description');
    }
    if (!$pdo->query('SHOW COLUMNS FROM gt_groups LIKE "is_internal"')->fetch()) {
        $pdo->exec('ALTER TABLE gt_groups ADD COLUMN is_internal TINYINT(1) NOT NULL DEFAULT 0 AFTER image_url, ADD COLUMN pinned TINYINT(1) NOT NULL DEFAULT 0 AFTER is_internal, ADD COLUMN embed_url VARCHAR(500) NULL AFTER pinned');
    }
    $communityChannelColumn=$pdo->query('SHOW COLUMNS FROM gt_community_posts LIKE "channel"')->fetch();
    if ($communityChannelColumn && str_contains(strtolower((string)($communityChannelColumn['Type']??'')),'enum(')) {
        $pdo->exec('ALTER TABLE gt_community_posts MODIFY channel VARCHAR(40) NOT NULL');
    }
    $pdo->exec("INSERT IGNORE INTO gt_communities(channel,name,description,image_url) VALUES
      ('ff','FREE FIRE','Komuniti tournament Free Fire','images/ff-logo.webp'),
      ('ml','MOBILE LEGENDS','Komuniti tournament Mobile Legends','images/logo-ml.webp'),
      ('pubg','PUBG MOBILE','Komuniti tournament PUBG Mobile','images/pubg-logo.webp')");
    $pdo->exec('INSERT IGNORE INTO gt_chat_labels(name) SELECT DISTINCT admin_label FROM gt_conversations WHERE admin_label IS NOT NULL AND admin_label<>""');
}

function current_device(PDO $pdo): array
{
    $hash = hash('sha256', device_token());
    $customerId = !empty($_SESSION['gt_customer_id']) ? (int) $_SESSION['gt_customer_id'] : null;
    $stmt = $pdo->prepare('SELECT id,customer_id,banned_at,ban_reason FROM gt_devices WHERE token_hash=? LIMIT 1');
    $stmt->execute([$hash]);
    $device = $stmt->fetch();
    if (!$device) {
        $stmt = $pdo->prepare('INSERT INTO gt_devices(token_hash,customer_id,user_agent,last_seen_at) VALUES(?,?,?,NOW())');
        $stmt->execute([$hash, $customerId, clean($_SERVER['HTTP_USER_AGENT'] ?? '', 255)]);
        return ['id' => (int) $pdo->lastInsertId(), 'customer_id' => $customerId];
    }
    $stmt = $pdo->prepare('UPDATE gt_devices SET customer_id=COALESCE(?,customer_id),last_seen_at=NOW() WHERE id=?');
    $stmt->execute([$customerId, (int) $device['id']]);
    return ['id' => (int) $device['id'], 'customer_id' => $customerId ?: ($device['customer_id'] ? (int) $device['customer_id'] : null), 'banned_at'=>$device['banned_at'] ?? null, 'ban_reason'=>$device['ban_reason'] ?? null];
}

function normalize_department(mixed $value): string
{
    $department = strtolower(clean($value ?? 'topup', 20));
    return in_array($department, ['topup','tour','report'], true) ? $department : 'topup';
}

function conversation_id(PDO $pdo, array $device, ?array $user, string $department = 'topup'): int
{
    $department = normalize_department($department);

    $stmt = $pdo->prepare('SELECT id FROM gt_conversations WHERE device_id=? AND department=? AND status="open" ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int) $device['id'], $department]);
    $id = (int) ($stmt->fetchColumn() ?: 0);

    if ($id) {
        if ($user) {
            $pdo->prepare('UPDATE gt_conversations SET customer_id=? WHERE id=?')
                ->execute([(int) $user['id'], $id]);
        }
        return $id;
    }

    $stmt = $pdo->prepare('INSERT INTO gt_conversations(device_id,customer_id,status,department,last_message_at) VALUES(?,?,"open",?,NOW())');
    $stmt->execute([
        (int) $device['id'],
        $user ? (int) $user['id'] : null,
        $department
    ]);

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
restore_admin_from_remember($pdo);
$user = customer($pdo);
$adminUser = admin($pdo);
$hasRememberCookie = !empty($_COOKIE[GT_ADMIN_REMEMBER_COOKIE]);
if ($adminUser && !$hasRememberCookie) {
    issue_admin_remember_token($pdo, (int)$adminUser['id']);
}
$device = current_device($pdo);
if ($adminUser) touch_admin_presence($pdo,(int)$adminUser['id']);

if ($method === 'POST' && $action === 'uploadImage') {
    require_csrf($_POST);
    if (!empty($device['banned_at']) && !$adminUser) respond(['ok'=>false,'message'=>'Akaun/peranti ini telah diban.'],403);
    if (!isset($_FILES['image']) || !is_array($_FILES['image'])) respond(['ok'=>false,'message'=>'Fail gambar tidak sampai ke server. Sila pilih semula gambar.'],422);
    $file = $_FILES['image'];
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $uploadMessages = [
        UPLOAD_ERR_INI_SIZE=>'Gambar melebihi had upload server.',
        UPLOAD_ERR_FORM_SIZE=>'Gambar terlalu besar.',
        UPLOAD_ERR_PARTIAL=>'Upload gambar tidak lengkap. Cuba semula.',
        UPLOAD_ERR_NO_FILE=>'Tiada gambar dipilih.',
        UPLOAD_ERR_NO_TMP_DIR=>'Folder sementara server tidak tersedia.',
        UPLOAD_ERR_CANT_WRITE=>'Server gagal menulis fail gambar.',
        UPLOAD_ERR_EXTENSION=>'Upload gambar dihentikan oleh server.'
    ];
    if ($uploadError !== UPLOAD_ERR_OK) respond(['ok'=>false,'message'=>$uploadMessages[$uploadError] ?? 'Upload gambar gagal.'],422);
    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !file_exists($tmpName)) respond(['ok'=>false,'message'=>'Fail sementara gambar tidak dijumpai. Cuba semula.'],422);
    if ((int)$file['size'] > 5 * 1024 * 1024) respond(['ok'=>false,'message'=>'Saiz gambar maksimum 5MB.'],422);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);
    $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    if (!isset($extensions[$mime])) respond(['ok'=>false,'message'=>'Format gambar tidak disokong.'],422);
    $relativeDir = 'uploads/chat/' . date('Y-m');
    $absoluteDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/',DIRECTORY_SEPARATOR,$relativeDir);
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir,0755,true) && !is_dir($absoluteDir)) respond(['ok'=>false,'message'=>'Folder upload tidak tersedia.'],500);
    $filename = bin2hex(random_bytes(18)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($tmpName,$absoluteDir.DIRECTORY_SEPARATOR.$filename)) respond(['ok'=>false,'message'=>'Gambar diterima tetapi gagal disimpan.'],500);
    respond(['ok'=>true,'url'=>$relativeDir.'/'.$filename,'csrf'=>csrf_token()]);
}

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
    $pendingAccount = null;
    if (!$user && !empty($_SESSION['gt_pending_customer_id'])) {
        $stmt = $pdo->prepare('SELECT id,name,login_id,status,created_at FROM gt_customers WHERE id=? LIMIT 1');
        $stmt->execute([(int) $_SESSION['gt_pending_customer_id']]);
        $pendingAccount = $stmt->fetch() ?: null;
    }
    respond(['ok' => true, 'csrf' => csrf_token(), 'customer' => $user, 'pending_customer' => $pendingAccount, 'admin' => $adminUser, 'worker_alert' => active_worker_alert($pdo,$adminUser), 'remember_token' => $adminUser ? admin_remember_request_token() : null, 'admin_presence' => admin_presence($pdo), 'push_public_key'=>push_public_key(), 'totals' => $totals, 'game_accounts' => $games]);
}

if ($method === 'GET' && $action === 'chatHome') {
    $departments = [
        'topup' => ['title' => 'Admin Topup', 'subtitle' => 'Pembelian, harga & order topup'],
        'tour' => ['title' => 'Admin Tour', 'subtitle' => 'Tournament, jadual & pendaftaran'],
        'report' => ['title' => 'Admin Report', 'subtitle' => 'Masalah, aduan & bantuan akaun'],
    ];

    $stmt = $pdo->prepare(
        'SELECT c.id,c.department,c.last_message_at,
            (SELECT body FROM gt_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_message
            ,(SELECT COUNT(*) FROM gt_messages m WHERE m.conversation_id=c.id AND m.sender_type IN ("admin","system") AND m.id>c.user_last_read_message_id) AS unread_count
         FROM gt_conversations c
         WHERE c.device_id=? AND c.status="open"
         ORDER BY c.id DESC'
    );
    $stmt->execute([(int) $device['id']]);

    $latest = [];
    foreach ($stmt->fetchAll() as $row) {
        $dep = normalize_department($row['department'] ?? 'topup');
        if (!isset($latest[$dep])) $latest[$dep] = $row;
    }

    $items = [];
    foreach ($departments as $key => $meta) {
        $row = $latest[$key] ?? null;
        $items[] = [
            'key' => $key,
            'title' => $meta['title'],
            'subtitle' => $meta['subtitle'],
            'conversation_id' => $row ? (int) $row['id'] : 0,
            'last_message' => $row['last_message'] ?? '',
            'last_message_at' => $row['last_message_at'] ?? null,
            'unread_count' => (int)($row['unread_count'] ?? 0),
        ];
    }

    respond([
        'ok' => true,
        'csrf' => csrf_token(),
        'departments' => $items,
        'customer' => $user,
        'admin_presence' => admin_presence($pdo),
        'unread_count' => user_unread_count($pdo,(int)$device['id']),
    ]);
}

if ($method === 'GET' && $action === 'messages') {
    $requestedConversation = max(0, (int) ($_GET['conversation_id'] ?? 0));
    $department = normalize_department($_GET['department'] ?? 'topup');

    if ($requestedConversation > 0) {
        require_admin($pdo);
        $stmt = $pdo->prepare('SELECT id,department FROM gt_conversations WHERE id=? LIMIT 1');
        $stmt->execute([$requestedConversation]);
        $conversationRow = $stmt->fetch();
        if (!$conversationRow) respond(['ok' => false, 'message' => 'Chat tidak dijumpai.'], 404);
        $conversation = (int) $conversationRow['id'];
        $department = normalize_department($conversationRow['department'] ?? 'topup');
    } else {
        $conversation = conversation_id($pdo, $device, $user, $department);
    }

    if (!$adminUser) {
        $pdo->prepare('UPDATE gt_conversations SET user_last_read_message_id=COALESCE((SELECT MAX(m.id) FROM gt_messages m WHERE m.conversation_id=?),0) WHERE id=? AND device_id=?')->execute([$conversation,$conversation,(int)$device['id']]);
    }

    $after = max(0, (int) ($_GET['after'] ?? 0));
    $stmt = $pdo->prepare('SELECT m.id,m.sender_type,m.body,m.media_url,m.message_kind,m.order_status,m.reply_to_message_id,m.updated_at,m.created_at,r.body AS reply_body,r.sender_type AS reply_sender_type FROM gt_messages m LEFT JOIN gt_messages r ON r.id=m.reply_to_message_id AND r.conversation_id=m.conversation_id WHERE m.conversation_id=? AND (m.id>? OR (m.message_kind="order_status" AND m.id=(SELECT MAX(s.id) FROM gt_messages s WHERE s.conversation_id=? AND s.message_kind="order_status"))) ORDER BY m.id ASC LIMIT 100');
    $stmt->execute([$conversation, $after, $conversation]);

    respond([
        'ok' => true,
        'conversation_id' => $conversation,
        'department' => $department,
        'messages' => $stmt->fetchAll(),
        'admin_presence' => admin_presence($pdo),
        'unread_count' => user_unread_count($pdo,(int)$device['id']),
        'csrf' => csrf_token()
    ]);
}

if ($method === 'GET' && $action === 'adminInbox') {
    require_admin($pdo);

    $department = normalize_department($_GET['department'] ?? 'topup');

    $stmt = $pdo->prepare(
        'SELECT c.id,c.device_id,c.status,c.department,c.last_message_at,c.created_at,c.admin_label,c.admin_last_read_message_id,d.last_seen_at,d.banned_at,d.ban_reason,
            COALESCE(NULLIF(cu.name,""),CONCAT("Guest #",d.id)) AS display_name,
            cu.login_id AS phone,cu.status AS account_status,
            (SELECT body FROM gt_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
            (SELECT id FROM gt_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_message_id,
            (SELECT sender_type FROM gt_messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_sender,
            (SELECT COUNT(*) FROM gt_messages m WHERE m.conversation_id=c.id AND m.id>c.admin_last_read_message_id AND m.sender_type IN ("guest","customer")) AS unread_count
         FROM gt_conversations c
         INNER JOIN gt_devices d ON d.id=c.device_id
         LEFT JOIN gt_customers cu ON cu.id=c.customer_id
         WHERE c.department=?
         ORDER BY c.last_message_at DESC,c.id DESC
         LIMIT 250'
    );
    $stmt->execute([$department]);
    $rows = $stmt->fetchAll();

    $counts = ['topup' => 0, 'tour' => 0, 'report' => 0];
    $countRows = $pdo->query('SELECT c.department,SUM((SELECT COUNT(*) FROM gt_messages m WHERE m.conversation_id=c.id AND m.id>c.admin_last_read_message_id AND m.sender_type IN ("guest","customer"))) AS total
        FROM gt_conversations c
        WHERE c.status="open" GROUP BY c.department')->fetchAll();
    foreach ($countRows as $countRow) {
        $key = normalize_department($countRow['department'] ?? 'topup');
        $counts[$key] = (int) $countRow['total'];
    }

    $pending = $pdo->query(
        'SELECT c.id,c.name,c.login_id,c.status,c.created_at,
            ga.game_code,ga.game_id,ga.server_id
         FROM gt_customers c
         LEFT JOIN gt_game_accounts ga ON ga.customer_id=c.id
         WHERE c.status="pending"
         ORDER BY c.created_at ASC'
    )->fetchAll();
    $labels=$pdo->query('SELECT id,name FROM gt_chat_labels ORDER BY name ASC')->fetchAll();

    respond([
        'ok' => true,
        'csrf' => csrf_token(),
        'admin' => $adminUser,
        'department' => $department,
        'counts' => $counts,
        'conversations' => $rows,
        'pending_registrations' => $pending
        ,'labels' => $labels
        ,'worker_alert' => active_worker_alert($pdo,$adminUser)
    ]);
}

if ($method === 'GET' && $action === 'botStatus') {
    require_admin($pdo);

    respond([
        'ok' => true,
        'enabled' => get_bot_enabled(),
        'csrf' => csrf_token(),
    ]);
}

if ($method === 'GET' && $action === 'runOrderReminders') {
    $secretFile=dirname(__DIR__).DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'reminder-secret.php';
    $expected=is_file($secretFile)?(string)(require $secretFile):'';
    $provided=(string)($_SERVER['HTTP_X_GNEX_CRON'] ?? '');
    if($expected==='' || !hash_equals($expected,$provided)) respond(['ok'=>false,'message'=>'Akses cron tidak sah.'],403);
    $workerStmt=$pdo->prepare('SELECT id FROM cl_admin_users WHERE username=? AND access_scope="order" LIMIT 1');
    $workerStmt->execute(['GNEX ORDER']);$workerId=(int)($workerStmt->fetchColumn() ?: 0);
    if(!$workerId)respond(['ok'=>true,'sent'=>0]);
    $alertStmt=$pdo->prepare('SELECT id,message FROM gt_worker_alerts WHERE target_admin_id=? AND status="active" AND (last_sent_at IS NULL OR last_sent_at<=DATE_SUB(NOW(),INTERVAL 30 SECOND)) ORDER BY id ASC');
    $alertStmt->execute([$workerId]);$sent=0;
    foreach($alertStmt->fetchAll() as $alert){
        send_web_push($pdo,'role="admin" AND admin_id=?',[$workerId],[
          'title'=>'GNEX · PERINGATAN','body'=>(string)$alert['message'],
          'url'=>'topup-admin.html?worker_alert='.(int)$alert['id'],
          'tag'=>'gnex-diamond-reminder-'.(int)$alert['id'].'-'.time(),
          'alert_id'=>(int)$alert['id'],'alert_type'=>'fill_diamond','badge_count'=>1,
        ]);
        $pdo->prepare('UPDATE gt_worker_alerts SET last_sent_at=NOW() WHERE id=? AND status="active"')->execute([(int)$alert['id']]);
        $sent++;
    }
    $stmt=$pdo->prepare('SELECT c.id,c.department,COUNT(m.id) unread_count,MAX(m.id) latest_id,MAX(m.created_at) latest_created,
      MAX(CASE WHEN m.message_kind LIKE "pin_order%" THEN 1 ELSE 0 END) has_pin_order,
      SUBSTRING_INDEX(GROUP_CONCAT(m.body ORDER BY m.id DESC SEPARATOR "\n"),"\n",1) latest_body
      FROM gt_conversations c
      JOIN gt_messages m ON m.conversation_id=c.id AND m.sender_type IN ("guest","customer")
      LEFT JOIN gt_admin_conversation_reads r ON r.admin_id=? AND r.conversation_id=c.id
      WHERE c.status="open" AND c.department="topup" AND m.id>COALESCE(r.last_read_message_id,0)
      GROUP BY c.id,c.department ORDER BY latest_id DESC LIMIT 30');
    $stmt->execute([$workerId]);
    foreach($stmt->fetchAll() as $row){
        $type=(int)$row['has_pin_order']===1?'order':'chat';$interval=$type==='order'?30:120;
        $lastStmt=$pdo->prepare('SELECT last_sent_at FROM gt_admin_reminder_dispatch WHERE admin_id=? AND conversation_id=? AND reminder_type=?');
        $lastStmt->execute([$workerId,(int)$row['id'],$type]);$lastSent=(string)($lastStmt->fetchColumn() ?: $row['latest_created']);
        if(strtotime($lastSent)>time()-$interval)continue;
        send_web_push($pdo,'role="admin" AND admin_id=?',[$workerId],[
          'title'=>$type==='order'?'GNEX ORDER · Order belum dibuka':'GNEX ORDER · Mesej belum dibuka',
          'body'=>(int)$row['unread_count'].' mesej · '.clean($row['latest_body'] ?? '',120),
          'url'=>'topup-admin.html?conversation_id='.(int)$row['id'],
          'tag'=>'gnex-order-reminder-'.(int)$row['id'].'-'.time(),
          'conversation_id'=>(int)$row['id'],
          'badge_count'=>(int)$row['unread_count'],
        ]);
        $pdo->prepare('INSERT INTO gt_admin_reminder_dispatch(admin_id,conversation_id,reminder_type,last_sent_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE last_sent_at=NOW()')->execute([$workerId,(int)$row['id'],$type]);
        $sent++;
    }
    respond(['ok'=>true,'sent'=>$sent]);
}

$input = input_data();
if ($method === 'POST' && $action !== 'recoverAdminInstallation') require_csrf($input);

if ($method === 'POST' && $action === 'recoverAdminInstallation') {
    $endpoint=clean($input['endpoint'] ?? '',1000);
    $p256dh=clean($input['keys']['p256dh'] ?? '',255);
    $authToken=clean($input['keys']['auth'] ?? '',255);
    if($endpoint===''||$p256dh===''||$authToken==='')respond(['ok'=>false,'message'=>'Pemasangan Android tidak lengkap.'],401);
    $stmt=$pdo->prepare('SELECT s.admin_id,s.p256dh,s.auth_token,a.username,a.access_scope FROM gt_push_subscriptions s INNER JOIN cl_admin_users a ON a.id=s.admin_id WHERE s.endpoint_hash=? AND s.role="admin" AND s.enabled=1 LIMIT 1');
    $stmt->execute([hash('sha256',$endpoint)]);$trusted=$stmt->fetch();
    if(!$trusted||!hash_equals((string)$trusted['p256dh'],$p256dh)||!hash_equals((string)$trusted['auth_token'],$authToken)||strcasecmp((string)$trusted['username'],'GNEX ORDER')!==0)respond(['ok'=>false,'message'=>'Peranti ini belum dipercayai. Login sekali untuk aktifkan semula.'],401);
    session_regenerate_id(true);
    $_SESSION['cl_admin_id']=(int)$trusted['admin_id'];
    $_SESSION['cl_admin_access_scope']=(string)$trusted['access_scope'];
    $rememberToken=issue_admin_remember_token($pdo,(int)$trusted['admin_id']);
    touch_admin_presence($pdo,(int)$trusted['admin_id']);
    respond(['ok'=>true,'admin'=>admin($pdo),'remember_token'=>$rememberToken,'csrf'=>csrf_token()]);
}

if ($method === 'POST' && $action === 'subscribePush') {
    $endpoint=clean($input['endpoint'] ?? '',1000);
    $p256dh=clean($input['keys']['p256dh'] ?? '',255);
    $authToken=clean($input['keys']['auth'] ?? '',255);
    if ($endpoint==='' || $p256dh==='' || $authToken==='') respond(['ok'=>false,'message'=>'Push subscription tidak sah.'],422);
    if (!str_starts_with($endpoint,'https://')) respond(['ok'=>false,'message'=>'Push endpoint tidak selamat.'],422);
    $role=$adminUser?'admin':'user';
    $stmt=$pdo->prepare('INSERT INTO gt_push_subscriptions(endpoint,endpoint_hash,p256dh,auth_token,role,device_id,admin_id,enabled,updated_at) VALUES(?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE p256dh=VALUES(p256dh),auth_token=VALUES(auth_token),role=VALUES(role),device_id=VALUES(device_id),admin_id=VALUES(admin_id),enabled=1,updated_at=NOW()');
    $stmt->execute([$endpoint,hash('sha256',$endpoint),$p256dh,$authToken,$role,$role==='user'?(int)$device['id']:null,$role==='admin'?(int)$adminUser['id']:null,1]);
    respond(['ok'=>true,'message'=>'Push notification aktif.','csrf'=>csrf_token()]);
}

if ($method === 'POST' && $action === 'triggerWorkerAlert') {
    $requestingAdmin=require_admin($pdo);
    if (strcasecmp((string)($requestingAdmin['username'] ?? ''),'GNEX') !== 0 || (string)($requestingAdmin['access_scope'] ?? '') === 'order') respond(['ok'=>false,'message'=>'Butang ini hanya untuk akaun GNEX.'],403);
    $workerStmt=$pdo->prepare('SELECT id FROM cl_admin_users WHERE username=? AND access_scope="order" LIMIT 1');
    $workerStmt->execute(['GNEX ORDER']);$workerId=(int)($workerStmt->fetchColumn() ?: 0);
    if(!$workerId)respond(['ok'=>false,'message'=>'Akaun GNEX ORDER tidak dijumpai.'],404);
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE gt_worker_alerts SET status="acknowledged",acknowledged_at=NOW(),acknowledged_by_admin_id=? WHERE target_admin_id=? AND alert_type="fill_diamond" AND status="active"')->execute([(int)$requestingAdmin['id'],$workerId]);
    $pdo->prepare('INSERT INTO gt_worker_alerts(alert_type,message,target_admin_id,requested_by_admin_id,status,last_sent_at) VALUES("fill_diamond","Sila isi diamond",?,?,"active",NOW())')->execute([$workerId,(int)$requestingAdmin['id']]);
    $alertId=(int)$pdo->lastInsertId();$pdo->commit();
    send_web_push($pdo,'role="admin" AND admin_id=?',[$workerId],[
      'title'=>'GNEX · PERINGATAN','body'=>'Sila isi diamond','url'=>'topup-admin.html?worker_alert='.$alertId,
      'tag'=>'gnex-diamond-reminder-'.$alertId.'-'.time(),'alert_id'=>$alertId,'alert_type'=>'fill_diamond','badge_count'=>1,
    ]);
    respond(['ok'=>true,'message'=>'Peringatan dihantar kepada GNEX ORDER dan akan diulang setiap 30 saat.','alert_id'=>$alertId,'csrf'=>csrf_token()]);
}

if ($method === 'POST' && $action === 'ackWorkerAlert') {
    $ackAdmin=require_admin($pdo);$alertId=max(0,(int)($input['alert_id'] ?? 0));
    if((string)($ackAdmin['access_scope'] ?? '')!=='order' || !$alertId)respond(['ok'=>false,'message'=>'Peringatan tidak sah.'],403);
    $stmt=$pdo->prepare('UPDATE gt_worker_alerts SET status="acknowledged",acknowledged_at=NOW(),acknowledged_by_admin_id=? WHERE id=? AND target_admin_id=? AND status="active"');
    $stmt->execute([(int)$ackAdmin['id'],$alertId,(int)$ackAdmin['id']]);
    respond(['ok'=>true,'acknowledged'=>(bool)$stmt->rowCount(),'csrf'=>csrf_token()]);
}

if ($method === 'POST' && $action === 'markConversationRead') {
    $readingAdmin=require_admin($pdo);
    $conversationId = max(0, (int) ($input['conversation_id'] ?? 0));
    if (!$conversationId) respond(['ok'=>false,'message'=>'Chat tidak sah.'], 422);
    $pdo->prepare('UPDATE gt_conversations SET admin_last_read_message_id=COALESCE((SELECT MAX(m.id) FROM gt_messages m WHERE m.conversation_id=?),0) WHERE id=?')
        ->execute([$conversationId,$conversationId]);
    $pdo->prepare('INSERT INTO gt_admin_conversation_reads(admin_id,conversation_id,last_read_message_id,updated_at) SELECT ?,?,COALESCE(MAX(id),0),NOW() FROM gt_messages WHERE conversation_id=? ON DUPLICATE KEY UPDATE last_read_message_id=VALUES(last_read_message_id),updated_at=NOW()')
        ->execute([(int)$readingAdmin['id'],$conversationId,$conversationId]);
    respond(['ok'=>true,'csrf'=>csrf_token()]);
}

if ($method === 'POST' && $action === 'markAllAdminRead') {
    require_admin($pdo);
    $stmt=$pdo->prepare('INSERT IGNORE INTO gt_system_settings(setting_key,setting_value) VALUES("admin_unread_reset_20260819","done")');
    $stmt->execute();
    if($stmt->rowCount())$pdo->exec('UPDATE gt_conversations c SET admin_last_read_message_id=COALESCE((SELECT MAX(m.id) FROM gt_messages m WHERE m.conversation_id=c.id),0)');
    respond(['ok'=>true,'reset'=>(bool)$stmt->rowCount(),'message'=>'Semua chat ditanda sudah dibaca.','csrf'=>csrf_token()]);
}

if ($method === 'GET' && $action === 'communityPosts') {
    $channel = clean($_GET['channel'] ?? 'ff', 40);
    $communityStmt=$pdo->prepare('SELECT channel,name,description,image_url FROM gt_communities WHERE channel=? LIMIT 1');
    $communityStmt->execute([$channel]);
    $community=$communityStmt->fetch();
    if(!$community) respond(['ok'=>false,'message'=>'Komuniti tidak dijumpai.'],404);
    $stmt = $pdo->prepare('SELECT p.id,p.channel,p.body,p.media_url,p.created_at,a.username AS admin_name,
        (SELECT COUNT(*) FROM gt_community_reactions r WHERE r.post_id=p.id AND r.emoji="👍") AS likes,
        (SELECT COUNT(*) FROM gt_community_reactions r WHERE r.post_id=p.id AND r.emoji="❤️") AS hearts,
        EXISTS(SELECT 1 FROM gt_community_reactions r WHERE r.post_id=p.id AND r.device_id=? AND r.emoji="👍") AS liked,
        EXISTS(SELECT 1 FROM gt_community_reactions r WHERE r.post_id=p.id AND r.device_id=? AND r.emoji="❤️") AS hearted
        FROM gt_community_posts p JOIN cl_admin_users a ON a.id=p.admin_id
        WHERE p.channel=? ORDER BY p.id DESC LIMIT 100');
    $stmt->execute([(int)$device['id'],(int)$device['id'],$channel]);
    respond(['ok'=>true,'posts'=>$stmt->fetchAll(),'channel'=>$channel,'community'=>$community]);
}

if ($method === 'GET' && $action === 'communities') {
    $rows=$pdo->query('SELECT c.id,c.channel,c.name,c.description,c.image_url,c.created_at,
      (SELECT COUNT(*) FROM gt_community_posts p WHERE p.channel=c.channel) post_count,
      (SELECT body FROM gt_community_posts p WHERE p.channel=c.channel ORDER BY p.id DESC LIMIT 1) last_post
      FROM gt_communities c ORDER BY c.id ASC')->fetchAll();
    respond(['ok'=>true,'communities'=>$rows]);
}

if ($method === 'GET' && $action === 'groups') {
    $stmt = $pdo->prepare('SELECT g.id,g.name,g.description,g.image_url,g.is_internal,g.pinned,g.embed_url,g.created_at,
        EXISTS(SELECT 1 FROM gt_group_members gm WHERE gm.group_id=g.id AND gm.device_id=?) AS joined,
        COALESCE((SELECT muted FROM gt_group_members gm WHERE gm.group_id=g.id AND gm.device_id=?),0) AS muted,
        (SELECT COUNT(*) FROM gt_group_members gm WHERE gm.group_id=g.id) AS members,
        (SELECT id FROM gt_group_messages x WHERE x.group_id=g.id ORDER BY x.id DESC LIMIT 1) AS last_message_id,
        (SELECT body FROM gt_group_messages x WHERE x.group_id=g.id ORDER BY x.id DESC LIMIT 1) AS last_message
        FROM gt_groups g WHERE (?=1 OR g.is_internal=0) ORDER BY g.pinned DESC,g.id DESC');
    $stmt->execute([(int)$device['id'],(int)$device['id'],$adminUser?1:0]);
    respond(['ok'=>true,'groups'=>$stmt->fetchAll()]);
}

if ($method === 'GET' && $action === 'groupMessages') {
    $groupId=max(0,(int)($_GET['group_id']??0));
    $after=max(0,(int)($_GET['after']??0));
    $isMember=$pdo->prepare('SELECT 1 FROM gt_group_members WHERE group_id=? AND device_id=?');
    $isMember->execute([$groupId,(int)$device['id']]);
    if(!$adminUser&&!$isMember->fetchColumn()) respond(['ok'=>false,'message'=>'Join group dahulu.'],403);
    $stmt=$pdo->prepare('SELECT m.id,m.body,m.media_url,m.created_at,m.device_id,m.admin_id,
      CASE WHEN ?=1 THEN (m.admin_id=?) ELSE (m.device_id=?) END AS is_mine,
      COALESCE(a.username,cu.name,CONCAT("Guest #",m.device_id)) sender_name
      FROM gt_group_messages m LEFT JOIN cl_admin_users a ON a.id=m.admin_id
      LEFT JOIN gt_devices d ON d.id=m.device_id LEFT JOIN gt_customers cu ON cu.id=d.customer_id
      WHERE m.group_id=? AND m.id>? ORDER BY m.id ASC LIMIT 150');
    $stmt->execute([$adminUser?1:0,$adminUser?(int)$adminUser['id']:0,(int)$device['id'],$groupId,$after]);
    respond(['ok'=>true,'messages'=>$stmt->fetchAll()]);
}

if ($method === 'POST' && $action === 'setConversationLabel') {
    $currentAdmin=require_admin($pdo);
    $conversationId = max(0, (int) ($input['conversation_id'] ?? 0));
    $label = clean($input['label'] ?? '', 100);
    if (!$conversationId) respond(['ok'=>false,'message'=>'Chat tidak sah.'], 422);
    if ($label!=='') $pdo->prepare('INSERT IGNORE INTO gt_chat_labels(name,created_by_admin_id) VALUES(?,?)')->execute([$label,(int)$currentAdmin['id']]);
    $pdo->prepare('UPDATE gt_conversations SET admin_label=? WHERE id=?')->execute([$label !== '' ? $label : null,$conversationId]);
    respond(['ok'=>true,'label'=>$label,'csrf'=>csrf_token()]);
}

if ($method === 'POST' && $action === 'createChatLabel') {
    $currentAdmin=require_admin($pdo);
    $label=clean($input['label'] ?? '',100);
    if($label==='')respond(['ok'=>false,'message'=>'Nama label diperlukan.'],422);
    $pdo->prepare('INSERT IGNORE INTO gt_chat_labels(name,created_by_admin_id) VALUES(?,?)')->execute([$label,(int)$currentAdmin['id']]);
    respond(['ok'=>true,'label'=>$label,'labels'=>$pdo->query('SELECT id,name FROM gt_chat_labels ORDER BY name ASC')->fetchAll(),'csrf'=>csrf_token()]);
}

if ($method === 'POST' && $action === 'banConversationDevice') {
    require_admin($pdo);
    $conversationId = max(0,(int)($input['conversation_id'] ?? 0));
    $banned = filter_var($input['banned'] ?? true,FILTER_VALIDATE_BOOLEAN);
    $reason = clean($input['reason'] ?? 'Spam mesej',255);
    $stmt = $pdo->prepare('SELECT device_id FROM gt_conversations WHERE id=?');
    $stmt->execute([$conversationId]);
    $deviceId = (int)($stmt->fetchColumn() ?: 0);
    if(!$deviceId) respond(['ok'=>false,'message'=>'Chat tidak sah.'],422);
    $pdo->prepare('UPDATE gt_devices SET banned_at=?,ban_reason=? WHERE id=?')->execute([$banned?date('Y-m-d H:i:s'):null,$banned?$reason:null,$deviceId]);
    respond(['ok'=>true,'banned'=>$banned,'message'=>$banned?'User telah diban.':'Ban telah dibuang.']);
}

if ($method === 'POST' && $action === 'createCommunityPost') {
    $currentAdmin = require_admin($pdo);
    $channel = clean($input['channel'] ?? '',40);
    $body = clean($input['body'] ?? '',3000);
    $media = clean($input['media_url'] ?? '',500);
    if ($media !== '' && !preg_match('#^uploads/chat/[0-9]{4}-[0-9]{2}/[a-f0-9]{36}\.(jpg|png|webp|gif)$#',$media)) respond(['ok'=>false,'message'=>'Fail gambar tidak sah.'],422);
    $exists=$pdo->prepare('SELECT 1 FROM gt_communities WHERE channel=?');$exists->execute([$channel]);
    if (!$exists->fetchColumn() || ($body === ''&&$media==='')) respond(['ok'=>false,'message'=>'Channel atau update tidak sah.'],422);
    $stmt = $pdo->prepare('INSERT INTO gt_community_posts(channel,admin_id,body,media_url) VALUES(?,?,?,?)');
    $stmt->execute([$channel,(int)$currentAdmin['id'],$body,$media?:null]);
    respond(['ok'=>true,'message'=>'Update komuniti diterbitkan.','post_id'=>(int)$pdo->lastInsertId()]);
}

if ($method === 'POST' && $action === 'createCommunity') {
    $currentAdmin=require_admin($pdo);
    $name=clean($input['name']??'',120);$description=clean($input['description']??'',500);
    if(mb_strlen($name)<2) respond(['ok'=>false,'message'=>'Nama komuniti diperlukan.'],422);
    $base=strtolower(preg_replace('/[^a-z0-9]+/i','-',iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name)?:$name));$base=trim($base,'-');
    if($base==='')$base='community';$channel=substr($base,0,30);$candidate=$channel;$suffix=1;
    $check=$pdo->prepare('SELECT 1 FROM gt_communities WHERE channel=?');
    do{$check->execute([$candidate]);if(!$check->fetchColumn())break;$candidate=substr($channel,0,26).'-'.(++$suffix);}while($suffix<100);
    $stmt=$pdo->prepare('INSERT INTO gt_communities(channel,name,description,admin_id) VALUES(?,?,?,?)');$stmt->execute([$candidate,$name,$description?:null,(int)$currentAdmin['id']]);
    respond(['ok'=>true,'community_id'=>(int)$pdo->lastInsertId(),'channel'=>$candidate,'message'=>'Komuniti berjaya dibuat.']);
}

if ($method === 'POST' && $action === 'updateCommunityImage') {
    require_admin($pdo);$channel=clean($input['channel']??'',40);$imageUrl=clean($input['image_url']??'',500);
    if($channel===''||!preg_match('#^uploads/chat/[0-9]{4}-[0-9]{2}/[a-f0-9]{36}\.(jpg|png|webp|gif)$#',$imageUrl))respond(['ok'=>false,'message'=>'Gambar komuniti tidak sah.'],422);
    $stmt=$pdo->prepare('UPDATE gt_communities SET image_url=? WHERE channel=?');$stmt->execute([$imageUrl,$channel]);
    if(!$stmt->rowCount())respond(['ok'=>false,'message'=>'Komuniti tidak dijumpai atau gambar tidak berubah.'],404);
    respond(['ok'=>true,'image_url'=>$imageUrl,'message'=>'Gambar komuniti berjaya ditukar.']);
}

if ($method === 'POST' && $action === 'reactCommunityPost') {
    $postId = max(0,(int)($input['post_id'] ?? 0));
    $emoji = (string)($input['emoji'] ?? '');
    if (!$postId || !in_array($emoji,['👍','❤️'],true)) respond(['ok'=>false,'message'=>'Reaksi tidak sah.'],422);
    $stmt = $pdo->prepare('SELECT 1 FROM gt_community_reactions WHERE post_id=? AND device_id=? AND emoji=?');
    $stmt->execute([$postId,(int)$device['id'],$emoji]);
    if ($stmt->fetchColumn()) $pdo->prepare('DELETE FROM gt_community_reactions WHERE post_id=? AND device_id=? AND emoji=?')->execute([$postId,(int)$device['id'],$emoji]);
    else $pdo->prepare('INSERT INTO gt_community_reactions(post_id,device_id,emoji) VALUES(?,?,?)')->execute([$postId,(int)$device['id'],$emoji]);
    respond(['ok'=>true,'csrf'=>csrf_token()]);
}

if ($method === 'POST' && $action === 'createGroup') {
    $currentAdmin=require_admin($pdo);
    $name=clean($input['name']??'',120);$description=clean($input['description']??'',500);
    if($name==='') respond(['ok'=>false,'message'=>'Nama group diperlukan.'],422);
    $stmt=$pdo->prepare('INSERT INTO gt_groups(name,description,admin_id) VALUES(?,?,?)');$stmt->execute([$name,$description?:null,(int)$currentAdmin['id']]);
    respond(['ok'=>true,'group_id'=>(int)$pdo->lastInsertId()]);
}

if ($method === 'POST' && $action === 'updateGroupImage') {
    require_admin($pdo);
    $groupId=max(0,(int)($input['group_id']??0));
    $imageUrl=clean($input['image_url']??'',500);
    if(!$groupId || !preg_match('#^uploads/chat/[0-9]{4}-[0-9]{2}/[a-f0-9]{36}\.(jpg|png|webp|gif)$#',$imageUrl)) respond(['ok'=>false,'message'=>'Gambar group tidak sah.'],422);
    $stmt=$pdo->prepare('UPDATE gt_groups SET image_url=? WHERE id=?');
    $stmt->execute([$imageUrl,$groupId]);
    if(!$stmt->rowCount()) respond(['ok'=>false,'message'=>'Group tidak dijumpai atau gambar tidak berubah.'],404);
    respond(['ok'=>true,'image_url'=>$imageUrl,'message'=>'Gambar group berjaya ditukar.']);
}

if ($method === 'POST' && $action === 'deleteGroup') {
    require_admin($pdo);
    $groupId=max(0,(int)($input['group_id']??0));
    if(!$groupId) respond(['ok'=>false,'message'=>'Group tidak sah.'],422);
    $stmt=$pdo->prepare('DELETE FROM gt_groups WHERE id=? AND is_internal=0');
    $stmt->execute([$groupId]);
    if(!$stmt->rowCount()) respond(['ok'=>false,'message'=>'Group tidak dijumpai.'],404);
    respond(['ok'=>true,'message'=>'Group dan semua chatnya telah dipadam.']);
}

if ($method === 'POST' && $action === 'joinGroup') {
    $groupId=max(0,(int)($input['group_id']??0));
    $pdo->prepare('INSERT IGNORE INTO gt_group_members(group_id,device_id) VALUES(?,?)')->execute([$groupId,(int)$device['id']]);
    respond(['ok'=>true]);
}

if ($method === 'POST' && $action === 'muteGroup') {
    $groupId=max(0,(int)($input['group_id']??0));$muted=filter_var($input['muted']??true,FILTER_VALIDATE_BOOLEAN);
    $pdo->prepare('UPDATE gt_group_members SET muted=? WHERE group_id=? AND device_id=?')->execute([$muted?1:0,$groupId,(int)$device['id']]);
    respond(['ok'=>true,'muted'=>$muted]);
}

if ($method === 'POST' && $action === 'sendGroupMessage') {
    $groupId=max(0,(int)($input['group_id']??0));$body=clean($input['body']??'',2000);$media=clean($input['media_url']??'',500);
    if ($media !== '' && !preg_match('#^uploads/chat/[0-9]{4}-[0-9]{2}/[a-f0-9]{36}\.(jpg|png|webp|gif)$#',$media)) respond(['ok'=>false,'message'=>'Fail gambar tidak sah.'],422);
    if($body===''&&$media==='') respond(['ok'=>false,'message'=>'Mesej kosong.'],422);
    if(!$adminUser){
      if(!empty($device['banned_at'])) respond(['ok'=>false,'message'=>'Anda telah diban.'],403);
      $stmt=$pdo->prepare('SELECT 1 FROM gt_group_members WHERE group_id=? AND device_id=?');$stmt->execute([$groupId,(int)$device['id']]);
      if(!$stmt->fetchColumn()) respond(['ok'=>false,'message'=>'Join group dahulu.'],403);
    }
    $pdo->prepare('INSERT INTO gt_group_messages(group_id,device_id,admin_id,body,media_url) VALUES(?,?,?,?,?)')->execute([$groupId,$adminUser?null:(int)$device['id'],$adminUser?(int)$adminUser['id']:null,$body,$media?:null]);
    respond(['ok'=>true]);
}

if ($method === 'POST' && $action === 'setBotStatus') {
    require_admin($pdo);

    $enabled = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

    try {
        set_bot_enabled($enabled);
    } catch (Throwable $error) {
        error_log($error->__toString());
        respond(['ok' => false, 'message' => 'Status bot gagal disimpan.'], 500);
    }

    respond([
        'ok' => true,
        'enabled' => $enabled,
        'message' => $enabled ? 'Bot diaktifkan.' : 'Bot dimatikan.',
        'csrf' => csrf_token(),
    ]);
}

if ($method === 'POST' && $action === 'register') {
    $name = clean($input['name'] ?? '', 100);
    $loginId = normalize_phone($input['login_id'] ?? '');
    $password = (string) ($input['password'] ?? '');
    $game = clean($input['game_code'] ?? '', 10);
    $gameId = clean($input['game_id'] ?? '', 120);
    $serverId = clean($input['server_id'] ?? '', 120);
    auth_limit_check('register', 4, 3600);
    if (mb_strlen($name) < 2 || !preg_match('/^01\d{8,9}$/', $loginId) || strlen($password) < 8 || strlen($password) > 128 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password) || !in_array($game, ['ff','ml','pubg'], true) || $gameId === '') respond(['ok' => false, 'message' => 'Semak nama, nombor Malaysia dan password (minimum 8 aksara dengan huruf serta nombor).'], 422);
    auth_limit_fail('register');
    $internationalPhone = '60' . substr($loginId, 1);
    $duplicate = $pdo->prepare('SELECT 1 FROM gt_customers WHERE login_id IN (?,?,?) LIMIT 1');
    $duplicate->execute([$loginId,$internationalPhone,'+'.$internationalPhone]);
    if ($duplicate->fetchColumn()) respond(['ok'=>false,'message'=>'Nombor telefon ini sudah didaftarkan. Cuba login atau hubungi admin.'],409);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO gt_customers(name,login_id,password_hash,status) VALUES(?,?,?,"pending")');
        $stmt->execute([$name, $loginId, password_hash($password, PASSWORD_DEFAULT)]);
        $customerId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO gt_game_accounts(customer_id,game_code,game_id,server_id,label) VALUES(?,?,?,?,?)');
        $stmt->execute([$customerId, $game, $gameId, $serverId ?: null, $name]);
        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((string) $error->getCode() === '23000') respond(['ok' => false, 'message' => 'Email atau nombor ini sudah didaftarkan.'], 409);
        throw $error;
    }
    $_SESSION['gt_pending_customer_id'] = $customerId;
    $pdo->prepare('UPDATE gt_devices SET customer_id=? WHERE id=?')->execute([$customerId, (int) $device['id']]);
    $conversation = conversation_id($pdo, $device, null, 'topup');
    $pdo->prepare('UPDATE gt_conversations SET customer_id=? WHERE id=?')->execute([$customerId, $conversation]);
    $notice = sprintf('Permohonan akaun: %s · %s · %s ID %s%s', $name, $loginId, strtoupper($game), $gameId, $serverId ? ' (Server '.$serverId.')' : '');
    $pdo->prepare('INSERT INTO gt_messages(conversation_id,sender_type,body) VALUES(?,"system",?)')->execute([$conversation, $notice]);
    respond(['ok' => true, 'message' => 'Permohonan dihantar. Tunggu admin sahkan akaun.', 'pending_customer' => ['id'=>$customerId,'name'=>$name,'login_id'=>$loginId,'status'=>'pending'], 'csrf' => csrf_token()]);
}

if ($method === 'POST' && $action === 'login') {
    $rawLoginId = clean($input['login_id'] ?? '', 190);
    $password = (string) ($input['password'] ?? '');
    $loginLimitKey = 'login_' . hash('sha256', strtolower($rawLoginId));
    auth_limit_check($loginLimitKey, 6, 900);
    if ($rawLoginId === '' || $password === '' || strlen($password) > 128) respond(['ok'=>false,'message'=>'Masukkan nombor telefon/username dan password yang sah.'],422);

    // The customer and admin share one login form. Admin usernames must be
    // checked before phone normalization, otherwise values such as "GNEX"
    // become an empty string and can never match cl_admin_users.
    $adminStmt = $pdo->prepare('SELECT id,password_hash FROM cl_admin_users WHERE LOWER(username)=LOWER(?) LIMIT 1');
    $adminStmt->execute([$rawLoginId]);
    $adminAccount = $adminStmt->fetch();
    if ($adminAccount && password_verify($password, (string) $adminAccount['password_hash'])) {
        auth_limit_clear($loginLimitKey);
        session_regenerate_id(true);
        $_SESSION['cl_admin_id'] = (int) $adminAccount['id'];
        $_SESSION['cl_admin_access_scope'] = (string) (admin($pdo)['access_scope'] ?? 'admin');
        $rememberToken = issue_admin_remember_token($pdo, (int)$adminAccount['id']);
        touch_admin_presence($pdo,(int)$adminAccount['id']);
        unset($_SESSION['gt_customer_id'], $_SESSION['gt_pending_customer_id']);
        respond([
            'ok' => true,
            'message' => 'Login admin berjaya.',
            'admin' => admin($pdo),
            'remember_token' => $rememberToken,
            'redirect' => 'topup-admin.html',
            'csrf' => csrf_token(),
        ]);
    }

    $loginId = normalize_phone($rawLoginId);
    $legacyLoginId = preg_replace('/[^0-9+]/', '', $rawLoginId);
    $internationalPhone = str_starts_with($loginId,'0') ? '60'.substr($loginId,1) : $loginId;
    $stmt = $pdo->prepare('SELECT id,password_hash,status FROM gt_customers WHERE login_id IN (?,?,?,?) LIMIT 1');
    $stmt->execute([$loginId,$legacyLoginId,$internationalPhone,'+'.$internationalPhone]);
    $account = $stmt->fetch();
    if (!$account || !password_verify($password, (string) $account['password_hash'])) {
        auth_limit_fail($loginLimitKey);
        respond(['ok' => false, 'message' => 'Nombor telefon atau password salah.'], 401);
    }
    if ($account['status'] === 'pending') respond(['ok' => false, 'message' => 'Akaun masih menunggu pengesahan admin.'], 403);
    if ($account['status'] !== 'active') respond(['ok' => false, 'message' => 'Akaun tidak aktif. Hubungi admin melalui chat.'], 403);
    auth_limit_clear($loginLimitKey);
    session_regenerate_id(true);
    $_SESSION['gt_customer_id'] = (int) $account['id'];
    clear_admin_remember_token($pdo);
    unset($_SESSION['cl_admin_id'], $_SESSION['cl_admin_access_scope'], $_SESSION['gt_pending_customer_id']);
    if (password_needs_rehash((string)$account['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE gt_customers SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),(int)$account['id']]);
    }
    $pdo->prepare('UPDATE gt_devices SET customer_id=? WHERE id=?')->execute([(int) $account['id'], (int) $device['id']]);
    respond(['ok' => true, 'message' => 'Login berjaya.', 'customer' => customer($pdo), 'csrf' => csrf_token()]);
}

if ($method === 'POST' && $action === 'reviewRegistration') {
    $currentAdmin = require_admin($pdo);
    $customerId = max(0, (int) ($input['customer_id'] ?? 0));
    $decision = clean($input['decision'] ?? '', 20);
    if (!$customerId || !in_array($decision, ['approve','reject'], true)) respond(['ok'=>false,'message'=>'Permohonan tidak sah.'], 422);
    $status = $decision === 'approve' ? 'active' : 'rejected';
    $stmt = $pdo->prepare('UPDATE gt_customers SET status=?,updated_at=NOW() WHERE id=? AND status="pending"');
    $stmt->execute([$status,$customerId]);
    if (!$stmt->rowCount()) respond(['ok'=>false,'message'=>'Permohonan sudah diproses.'], 409);
    $stmt = $pdo->prepare('SELECT id FROM gt_conversations WHERE customer_id=? AND department="topup" ORDER BY id DESC LIMIT 1');
    $stmt->execute([$customerId]);
    $conversation = (int) ($stmt->fetchColumn() ?: 0);
    if ($conversation) {
        $body = $decision === 'approve' ? 'Akaun anda telah disahkan. Anda kini boleh login.' : 'Permohonan akaun tidak diluluskan. Sila chat admin untuk bantuan.';
        $pdo->prepare('INSERT INTO gt_messages(conversation_id,sender_type,sender_admin_id,body) VALUES(?,"admin",?,?)')->execute([$conversation,(int)$currentAdmin['id'],$body]);
        $pdo->prepare('UPDATE gt_conversations SET last_message_at=NOW() WHERE id=?')->execute([$conversation]);
    }
    respond(['ok'=>true,'message'=>$decision === 'approve' ? 'Akaun customer diluluskan.' : 'Permohonan ditolak.']);
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
    $_SESSION['cl_admin_access_scope'] = (string) (admin($pdo)['access_scope'] ?? 'admin');
    $rememberToken = issue_admin_remember_token($pdo, (int)$account['id']);
    touch_admin_presence($pdo,(int)$account['id']);
    respond(['ok' => true, 'message' => 'Admin login berjaya.', 'admin' => admin($pdo), 'remember_token' => $rememberToken, 'csrf' => csrf_token()]);
}

if ($method === 'POST' && $action === 'setOrderStatus') {
    require_csrf($input);
    $currentAdmin = require_admin($pdo);
    $conversationId = max(0,(int)($input['conversation_id'] ?? 0));
    $orderStatus = clean($input['order_status'] ?? '',20);
    if (!$conversationId || !in_array($orderStatus,['processing','completed'],true)) {
        respond(['ok'=>false,'message'=>'Status order tidak sah.'],422);
    }

    $conversationStmt=$pdo->prepare('SELECT id,device_id,department FROM gt_conversations WHERE id=? LIMIT 1');
    $conversationStmt->execute([$conversationId]);
    $conversationRow=$conversationStmt->fetch();
    if (!$conversationRow) respond(['ok'=>false,'message'=>'Chat tidak dijumpai.'],404);
    if (($conversationRow['department'] ?? '') !== 'topup') respond(['ok'=>false,'message'=>'Status order hanya untuk chat topup.'],422);

    $processingStmt=$pdo->prepare('SELECT id FROM gt_messages WHERE conversation_id=? AND message_kind="order_status" AND order_status="processing" ORDER BY id DESC LIMIT 1');
    $processingStmt->execute([$conversationId]);
    $statusMessageId=(int)($processingStmt->fetchColumn() ?: 0);

    if ($orderStatus === 'processing') {
        if (!$statusMessageId) {
            $insert=$pdo->prepare('INSERT INTO gt_messages(conversation_id,sender_type,sender_admin_id,body,message_kind,order_status,updated_at) VALUES(?,"admin",?,?,"order_status","processing",NOW())');
            $insert->execute([$conversationId,(int)$currentAdmin['id'],'Order sedang diproses']);
            $statusMessageId=(int)$pdo->lastInsertId();
        }
        $body='Order sedang diproses';
        $pushTitle='GNEX · Order sedang diproses';
    } else {
        if (!$statusMessageId) respond(['ok'=>false,'message'=>'Tekan PROSES dahulu sebelum DONE.'],409);
        $body='Order complete';
        $pdo->prepare('UPDATE gt_messages SET body=?,order_status="completed",updated_at=NOW() WHERE id=? AND conversation_id=?')->execute([$body,$statusMessageId,$conversationId]);
        $pushTitle='GNEX · Order complete';
    }

    $pdo->prepare('UPDATE gt_conversations SET last_message_at=NOW() WHERE id=?')->execute([$conversationId]);
    $targetDevice=(int)$conversationRow['device_id'];
    if ($targetDevice) send_web_push($pdo,'role="user" AND device_id=?',[$targetDevice],[
        'title'=>$pushTitle,
        'body'=>$body,
        'url'=>'index.html?chat=guest',
        'tag'=>'gnex-user-order-'.$statusMessageId,
        'badge_count'=>user_unread_count($pdo,$targetDevice),
    ]);

    respond(['ok'=>true,'message_id'=>$statusMessageId,'order_status'=>$orderStatus,'message'=>$body]);
}

if ($method === 'POST' && $action === 'submitPinTopup') {
    if($adminUser)respond(['ok'=>false,'message'=>'Form ini untuk customer.'],403);
    if(!empty($device['banned_at']))respond(['ok'=>false,'message'=>'Anda telah diban daripada menghantar order.'],403);
    $gameCode=clean($input['game_code'] ?? '',10);
    $gameNames=['ff'=>'FREE FIRE','ml'=>'MOBILE LEGENDS','pubg'=>'PUBG MOBILE'];
    $gameId=clean($input['game_id'] ?? '',120);
    $rawPins=is_array($input['pins'] ?? null)?$input['pins']:[];$pins=[];
    foreach(array_slice($rawPins,0,10) as $pin){$pin=clean($pin,160);if($pin!=='')$pins[]=$pin;}
    if(!isset($gameNames[$gameCode])||$gameId==='')respond(['ok'=>false,'message'=>'Lengkapkan pilihan game dan ID game.'],422);
    $conversation=conversation_id($pdo,$device,$user,'topup');
    $senderType=$user?'customer':'guest';
    $messages=array_merge([
      ['ORDER TOPUP · '.$gameNames[$gameCode],'pin_order'],
      [$gameId,'pin_order_id'],
    ],array_map(static fn($pin)=>[$pin,'pin_order_pin'],$pins));
    $pdo->beginTransaction();
    try{
      $stmt=$pdo->prepare('INSERT INTO gt_messages(conversation_id,sender_type,body,message_kind) VALUES(?,?,?,?)');
      foreach($messages as [$message,$kind])$stmt->execute([$conversation,$senderType,$message,$kind]);
      $pdo->prepare('UPDATE gt_conversations SET last_message_at=NOW(),customer_id=COALESCE(?,customer_id) WHERE id=?')->execute([$user?(int)$user['id']:null,$conversation]);
      $pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    $workerStmt=$pdo->prepare('SELECT id FROM cl_admin_users WHERE username=? AND access_scope="order" LIMIT 1');
    $workerStmt->execute(['GNEX ORDER']);$workerId=(int)($workerStmt->fetchColumn() ?: 0);
    if($workerId)send_web_push($pdo,'role="admin" AND admin_id=?',[$workerId],[
      'title'=>'GNEX ORDER · Order topup baharu',
      'body'=>$gameNames[$gameCode].' · ID '.$gameId.($pins?' · '.count($pins).' PIN':' · Tanpa PIN'),
      'url'=>'topup-admin.html?conversation_id='.$conversation,
      'tag'=>'gnex-pin-order-'.$conversation.'-'.time(),
      'conversation_id'=>$conversation,
      'badge_count'=>count($messages),
    ]);
    respond(['ok'=>true,'conversation_id'=>$conversation,'message_count'=>count($messages),'message'=>'Order dihantar.']);
}

if ($method === 'POST' && $action === 'sendMessage') {
    $body = clean($input['body'] ?? '', 2000);
    $mediaUrl = clean($input['media_url'] ?? '', 500);
    if ($mediaUrl !== '' && !preg_match('#^uploads/chat/[0-9]{4}-[0-9]{2}/[a-f0-9]{36}\.(jpg|png|webp|gif)$#',$mediaUrl)) respond(['ok'=>false,'message'=>'Fail gambar tidak sah.'],422);

    if ($body === '' && $mediaUrl === '') {
        respond([
            'ok' => false,
            'message' => 'Mesej kosong.'
        ], 422);
    }

    $requestedConversation = max(
        0,
        (int) ($input['conversation_id'] ?? 0)
    );

    $department = normalize_department($input['department'] ?? 'topup');

    if ($adminUser && $requestedConversation) {
        $stmt = $pdo->prepare('SELECT id,department FROM gt_conversations WHERE id=? LIMIT 1');
        $stmt->execute([$requestedConversation]);
        $conversationRow = $stmt->fetch();
        if (!$conversationRow) respond(['ok' => false, 'message' => 'Chat tidak dijumpai.'], 404);

        $conversation = (int) $conversationRow['id'];
        $department = normalize_department($conversationRow['department'] ?? 'topup');
    } else {
        if (!empty($device['banned_at'])) respond(['ok'=>false,'message'=>'Anda telah diban daripada menghantar mesej.'],403);
        $conversation = conversation_id($pdo, $device, $user, $department);
    }

    $replyToMessageId=max(0,(int)($input['reply_to_message_id'] ?? 0));
    if($replyToMessageId){
        $replyCheck=$pdo->prepare('SELECT id FROM gt_messages WHERE id=? AND conversation_id=? LIMIT 1');
        $replyCheck->execute([$replyToMessageId,$conversation]);
        if(!$replyCheck->fetchColumn())respond(['ok'=>false,'message'=>'Mesej reply tidak sah.'],422);
    }

    $senderType = $adminUser
        ? 'admin'
        : ($user ? 'customer' : 'guest');

    /*
     * SIMPAN MESEJ CUSTOMER / ADMIN
     */
    $stmt = $pdo->prepare(
        'INSERT INTO gt_messages
        (conversation_id, sender_type, sender_admin_id, body, media_url, reply_to_message_id)
        VALUES (?, ?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        $conversation,
        $senderType,
        $adminUser ? (int) $adminUser['id'] : null,
        $body,
        $mediaUrl !== '' ? $mediaUrl : null,
        $replyToMessageId ?: null
    ]);

    // Simpan ID mesej asal sebelum bot insert mesej lain
    $messageId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'UPDATE gt_conversations
         SET last_message_at = NOW(),
             customer_id = COALESCE(?, customer_id)
         WHERE id = ?'
    )->execute([
        $user ? (int) $user['id'] : null,
        $conversation
    ]);

    /*
     * AUTO REPLY BOT
     * Hanya untuk customer / guest
     */
    if (!$adminUser && $department === 'topup' && clean($input['source'] ?? '',30) !== 'web_checkout' && get_bot_enabled()) {
        $messageLower = mb_strtolower(trim($body));

        

        $autoReplies = [
            [
                'keywords' => [
                    'nak tengok list',
                    'tengok list',
                    'list game',
                    'senarai game',
                    'list',
                    'list dm',
                    'ada list'
                ],
                'reply' => 'nak game apa ya'
            ],


            [
                'keywords' => [
                    'free fire',
                    'ff'
                ],
                'reply' => 'nak topup berapa dm tu?'
            ],

            [
                'keywords' => [
                    '100',
                    '100 dm',
                    '100 diamond'
                ],
                'reply' => 'okay nak bayar guna apa digi/celcom atau tng/bank'
            ],


            [
                'keywords' => [
                    'tng',
                    'bank'
                ],
                'reply' => 'okay kalau tng rm4'
            ],

            

        ];

        $botReply = null;

        foreach ($autoReplies as $rule) {
            foreach ($rule['keywords'] as $keyword) {
                if (
                    str_contains(
                        $messageLower,
                        mb_strtolower($keyword)
                    )
                ) {
                    $botReply = $rule['reply'];
                    break 2;
                }
            }
        }

        /*
         * SIMPAN AUTO REPLY
         */
        if ($botReply !== null) {
            $botStmt = $pdo->prepare(
                'INSERT INTO gt_messages
                (conversation_id, sender_type, sender_admin_id, body)
                VALUES (?, ?, ?, ?)'
            );

            $botStmt->execute([
                $conversation,
                'system',
                null,
                $botReply
            ]);

            $pdo->prepare(
                'UPDATE gt_conversations
                 SET last_message_at = NOW()
                 WHERE id = ?'
            )->execute([
                $conversation
            ]);
        }
    }

    if ($adminUser) {
        $targetStmt=$pdo->prepare('SELECT device_id FROM gt_conversations WHERE id=?');
        $targetStmt->execute([$conversation]);
        $targetDevice=(int)$targetStmt->fetchColumn();
        if ($targetDevice) send_web_push($pdo,'role="user" AND device_id=?',[$targetDevice],[
            'title'=>'GNEX · Balasan admin',
            'body'=>$body!==''?$body:'Admin menghantar gambar.',
            'url'=>'index.html?chat=guest',
            'tag'=>'gnex-user-chat-'.$messageId,
            'badge_count'=>user_unread_count($pdo,$targetDevice),
        ]);
    } else {
        send_web_push($pdo,'role="admin"',[],[
            'title'=>'GNEX Admin · Chat baharu',
            'body'=>$body!==''?$body:'User menghantar gambar.',
            'url'=>'topup-admin.html',
            'tag'=>'gnex-admin-chat-'.$conversation,
            'badge_count'=>admin_unread_count($pdo),
        ]);
    }

    respond([
        'ok' => true,
        'id' => $messageId,
        'department' => $department,
        'message' => 'Mesej dihantar.'
    ]);
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

if ($method === 'POST' && $action === 'logout') {
    if ($adminUser) {
        $pdo->prepare('DELETE FROM gt_admin_presence WHERE session_hash=?')->execute([hash('sha256',session_id())]);
    }
    clear_admin_remember_token($pdo);
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    respond([
        'ok' => true,
        'message' => 'Logout berjaya.'
    ]);
}


respond(['ok' => false, 'message' => 'Action tidak dijumpai.'], 404);
