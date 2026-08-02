<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array {
    $data = json_decode((string) file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

try {
    $config = require dirname(__DIR__) . '/scrim-db-config.php';
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4",
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS cashier_products (
            barcode VARCHAR(64) PRIMARY KEY,
            name VARCHAR(160) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            stock INT NOT NULL DEFAULT 0,
            category VARCHAR(80) NOT NULL DEFAULT 'Lain-lain',
            unit VARCHAR(40) NOT NULL DEFAULT 'unit',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_cashier_product_name (name),
            INDEX idx_cashier_product_active (active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Throwable $error) {
    respond(['ok' => false, 'message' => 'Database tidak dapat disambungkan.'], 500);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? 'list');

if ($method === 'GET' && $action === 'list') {
    $includeInactive = ($_GET['all'] ?? '') === '1';
    $sql = 'SELECT barcode, name, price, stock, category, unit, active, updated_at FROM cashier_products';
    if (!$includeInactive) $sql .= ' WHERE active = 1';
    $sql .= ' ORDER BY name ASC';
    $rows = $pdo->query($sql)->fetchAll();
    foreach ($rows as &$row) {
        $row['price'] = (float) $row['price'];
        $row['stock'] = (int) $row['stock'];
        $row['active'] = (bool) $row['active'];
    }
    respond(['ok' => true, 'products' => $rows]);
}

if ($method === 'GET' && $action === 'get') {
    $barcode = trim((string) ($_GET['barcode'] ?? ''));
    $stmt = $pdo->prepare('SELECT barcode, name, price, stock, category, unit, active, updated_at FROM cashier_products WHERE barcode = ?');
    $stmt->execute([$barcode]);
    $product = $stmt->fetch();
    if (!$product) respond(['ok' => false, 'message' => 'Barcode belum didaftarkan.'], 404);
    $product['price'] = (float) $product['price'];
    $product['stock'] = (int) $product['stock'];
    $product['active'] = (bool) $product['active'];
    respond(['ok' => true, 'product' => $product]);
}

if ($method !== 'POST') respond(['ok' => false, 'message' => 'Permintaan tidak disokong.'], 405);

$input = body();
$adminPassword = (string) ($config['admin_password'] ?? '');
if ($adminPassword === '' || !hash_equals($adminPassword, (string) ($input['admin_password'] ?? ''))) {
    respond(['ok' => false, 'message' => 'Kata laluan pentadbir tidak betul.'], 401);
}

if ($action === 'save') {
    $barcode = trim((string) ($input['barcode'] ?? ''));
    $name = trim((string) ($input['name'] ?? ''));
    $price = filter_var($input['price'] ?? null, FILTER_VALIDATE_FLOAT);
    $stock = filter_var($input['stock'] ?? null, FILTER_VALIDATE_INT);
    $category = trim((string) ($input['category'] ?? 'Lain-lain'));
    $unit = trim((string) ($input['unit'] ?? 'unit'));
    if ($barcode === '' || strlen($barcode) > 64 || !preg_match('/^[A-Za-z0-9._-]+$/', $barcode)) {
        respond(['ok' => false, 'message' => 'Barcode tidak sah.'], 422);
    }
    if ($name === '' || mb_strlen($name) > 160) respond(['ok' => false, 'message' => 'Nama produk diperlukan.'], 422);
    if ($price === false || $price < 0) respond(['ok' => false, 'message' => 'Harga tidak sah.'], 422);
    if ($stock === false || $stock < 0) respond(['ok' => false, 'message' => 'Stok tidak sah.'], 422);
    $stmt = $pdo->prepare(
        'INSERT INTO cashier_products (barcode, name, price, stock, category, unit, active)
         VALUES (?, ?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE name=VALUES(name), price=VALUES(price), stock=VALUES(stock),
         category=VALUES(category), unit=VALUES(unit), active=1'
    );
    $stmt->execute([$barcode, $name, $price, $stock, $category ?: 'Lain-lain', $unit ?: 'unit']);
    respond(['ok' => true, 'message' => 'Produk berjaya disimpan.']);
}

if ($action === 'delete') {
    $barcode = trim((string) ($input['barcode'] ?? ''));
    $stmt = $pdo->prepare('UPDATE cashier_products SET active = 0 WHERE barcode = ?');
    $stmt->execute([$barcode]);
    respond(['ok' => true, 'message' => 'Produk dinyahaktifkan.']);
}

respond(['ok' => false, 'message' => 'Tindakan tidak dijumpai.'], 404);
