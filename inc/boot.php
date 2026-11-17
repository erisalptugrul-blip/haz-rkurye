<?php
// Ortak yardımcılar: veritabanı, ayarlar, oturum, biçimlendirme.
// PHP 7.4 ve üzeri ile çalışır.
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
date_default_timezone_set('Europe/Istanbul');
mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');

const STATUSES = [
    'yeni'         => 'Yeni',
    'hazirlaniyor' => 'Hazırlanıyor',
    'yolda'        => 'Yolda',
    'teslim'       => 'Teslim edildi',
    'iptal'        => 'İptal',
];

// Para birimi her yerde kuruş (tam sayı) olarak tutulur: 185 TL = 18500.
const SETTING_DEFAULTS = [
    'shop_name'      => 'İtiraf Et Coffee',
    'tagline'        => 'Taze kavrulmuş kahve, kapınıza kadar',
    'phone'          => '',
    'min_order'      => '20000',
    'delivery_fee'   => '0',
    'is_open'        => '1',
    'eta_text'       => '30-45 dk',
    'delivery_note'  => '',
    'closed_message' => 'Şu an sipariş almıyoruz. Kısa süre sonra tekrar deneyin.',
    'admin_hash'     => '',
];

/* ---------- Veritabanı ---------- */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dir = APP_ROOT . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    // Dosya adına rastgele ek konur; sunucu .htaccess'i okumasa bile tahmin edilemez.
    $found = glob($dir . '/siparis-*.sqlite') ?: [];
    $fresh = count($found) === 0;
    $path  = $fresh ? $dir . '/siparis-' . bin2hex(random_bytes(8)) . '.sqlite' : $found[0];

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    migrate($pdo, $fresh);
    return $pdo;
}

function migrate(PDO $pdo, bool $fresh): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        sort INTEGER NOT NULL DEFAULT 0,
        is_sample INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
        name TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        price INTEGER NOT NULL,
        image TEXT NOT NULL DEFAULT '',
        available INTEGER NOT NULL DEFAULT 1,
        sort INTEGER NOT NULL DEFAULT 0,
        is_sample INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'yeni',
        name TEXT NOT NULL,
        phone TEXT NOT NULL,
        address TEXT NOT NULL,
        note TEXT NOT NULL DEFAULT '',
        payment TEXT NOT NULL,
        change_for INTEGER,
        subtotal INTEGER NOT NULL,
        delivery_fee INTEGER NOT NULL,
        total INTEGER NOT NULL,
        ip TEXT NOT NULL DEFAULT '')");
    $pdo->exec('CREATE INDEX IF NOT EXISTS orders_status ON orders(status, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS orders_phone ON orders(phone, created_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
        product_id INTEGER,
        name TEXT NOT NULL,
        unit_price INTEGER NOT NULL,
        qty INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (ip TEXT NOT NULL, ts INTEGER NOT NULL)');

    if ($fresh) {
        seed($pdo);
    }
}

// İlk açılışta menü boş görünmesin diye örnek ürünler eklenir.
// Yönetim panelinden tek tıkla silinebilirler (is_sample = 1).
function seed(PDO $pdo): void
{
    $menu = [
        'Sıcak Kahveler' => [
            ['Türk Kahvesi', 'Közde, lokum ile', 8500],
            ['Latte', 'Çift shot espresso, buharlı süt', 12000],
            ['Cappuccino', 'Yoğun köpüklü, dengeli', 12000],
            ['Filtre Kahve', 'Günün çekirdeği', 9500],
            ['Flat White', 'Kadifemsi, yoğun kahve tadı', 12500],
        ],
        'Soğuk Kahveler' => [
            ['Iced Latte', 'Buzlu, sütlü', 13000],
            ['Cold Brew', '12 saat demlenmiş', 13500],
            ['Iced Americano', '', 11000],
        ],
        'Tatlılar' => [
            ['San Sebastian Cheesecake', '', 16000],
            ['Tiramisu', '', 15500],
            ['Brownie', 'Sıcak çikolata sos ile', 12000],
        ],
    ];
    $pdo->beginTransaction();
    $cs = $pdo->prepare('INSERT INTO categories (name, sort, is_sample) VALUES (?, ?, 1)');
    $ps = $pdo->prepare('INSERT INTO products (category_id, name, description, price, sort, is_sample) VALUES (?, ?, ?, ?, ?, 1)');
    $ci = 0;
    foreach ($menu as $cat => $items) {
        $cs->execute([$cat, ++$ci]);
        $cid = (int)$pdo->lastInsertId();
        $pi = 0;
        foreach ($items as $it) {
            $ps->execute([$cid, $it[0], $it[1], $it[2], ++$pi]);
        }
    }
    $pdo->commit();
}

/* ---------- Ayarlar ---------- */

function settings(bool $fresh = false): array
{
    static $cache = null;
    if ($cache === null || $fresh) {
        $cache = SETTING_DEFAULTS;
        foreach (db()->query('SELECT k, v FROM settings') as $r) {
            $cache[$r['k']] = $r['v'];
        }
    }
    return $cache;
}

function save_settings(array $kv): void
{
    $st = db()->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)');
    foreach ($kv as $k => $v) {
        $st->execute([$k, (string)$v]);
    }
    settings(true);
}

// Müşteriye gösterilebilecek ayarlar (şifre özeti asla dahil edilmez).
function public_settings(): array
{
    $s = settings();
    return [
        'shop_name'      => $s['shop_name'],
        'tagline'        => $s['tagline'],
        'phone'          => $s['phone'],
        'min_order'      => (int)$s['min_order'],
        'delivery_fee'   => (int)$s['delivery_fee'],
        'is_open'        => $s['is_open'] === '1',
        'eta_text'       => $s['eta_text'],
        'delivery_note'  => $s['delivery_note'],
        'closed_message' => $s['closed_message'],
    ];
}

/* ---------- Menü ---------- */

function menu_data(bool $onlyFilled = true, string $imgPrefix = 'uploads/'): array
{
    $pdo   = db();
    $cats  = $pdo->query('SELECT id, name FROM categories ORDER BY sort, id')->fetchAll();
    $prods = $pdo->query('SELECT id, category_id, name, description, price, image, available FROM products ORDER BY sort, id')->fetchAll();
    $out = [];
    foreach ($cats as $c) {
        $id = (int)$c['id'];
        $out[$id] = ['id' => $id, 'name' => $c['name'], 'products' => []];
    }
    foreach ($prods as $p) {
        $cid = (int)$p['category_id'];
        if (!isset($out[$cid])) {
            continue;
        }
        $out[$cid]['products'][] = [
            'id'          => (int)$p['id'],
            'name'        => $p['name'],
            'description' => $p['description'],
            'price'       => (int)$p['price'],
            'image'       => $p['image'] !== '' ? $imgPrefix . $p['image'] : '',
            'available'   => (int)$p['available'] === 1,
        ];
    }
    $list = array_values($out);
    if ($onlyFilled) {
        $list = array_values(array_filter($list, function ($c) {
            return count($c['products']) > 0;
        }));
    }
    return $list;
}

/* ---------- Biçimlendirme ve doğrulama ---------- */

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(int $kurus): string
{
    $tl = $kurus / 100;
    return ($kurus % 100 === 0 ? number_format($tl, 0, ',', '.') : number_format($tl, 2, ',', '.')) . ' ₺';
}

// "185", "185,50", "1.250,5", "1.500" gibi girişleri kuruşa çevirir.
function parse_tl($v): ?int
{
    $v = trim((string)$v);
    if ($v === '') {
        return null;
    }
    $v = preg_replace('/[\s₺]|TL/iu', '', $v);
    if (strpos($v, ',') !== false) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
        $v = str_replace('.', '', $v);
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $v)) {
        return null;
    }
    return (int)round(((float)$v) * 100);
}

function normalize_phone(string $raw): ?string
{
    $d = preg_replace('/\D+/', '', $raw);
    if (strlen($d) === 12 && strpos($d, '90') === 0) {
        $d = '0' . substr($d, 2);
    } elseif (strlen($d) === 10 && $d[0] === '5') {
        $d = '0' . $d;
    }
    return preg_match('/^05\d{9}$/', $d) ? $d : null;
}

function format_phone(string $p): string
{
    if (preg_match('/^0(\d{3})(\d{3})(\d{2})(\d{2})$/', $p, $m)) {
        return "0{$m[1]} {$m[2]} {$m[3]} {$m[4]}";
    }
    return $p;
}

// Uluslararası biçim (905321234567); tel: ve WhatsApp bağlantıları için.
function intl_digits(string $raw): string
{
    $d = preg_replace('/\D+/', '', $raw);
    if ($d === '') {
        return '';
    }
    if (strpos($d, '90') === 0 && strlen($d) >= 12) {
        return $d;
    }
    if ($d[0] === '0') {
        return '90' . substr($d, 1);
    }
    return strlen($d) === 10 ? '90' . $d : $d;
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}

function new_order_code(): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // 0/O, 1/I/L karışmasın
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $status = 422, array $extra = []): void
{
    json_out(array_merge(['ok' => false, 'error' => $message], $extra), $status);
}

// Sayfa içine gömülecek JSON; </script> kaçışına karşı güvenli.
function json_for_html(array $data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function asset_v(string $rel): int
{
    $f = APP_ROOT . '/' . $rel;
    return is_file($f) ? (int)filemtime($f) : 1;
}

/* ---------- Yönetici oturumu ---------- */

function start_admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $dir = APP_ROOT . '/data/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $life = 60 * 60 * 24 * 14;
    ini_set('session.gc_maxlifetime', (string)$life);
    session_name('siparis_yonetim');
    session_set_cookie_params([
        'lifetime' => $life,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_ok($t): bool
{
    return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

function is_admin(): bool
{
    return !empty($_SESSION['admin']);
}

function login_blocked(): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND ts > ?');
    $st->execute([client_ip(), time() - 900]);
    return (int)$st->fetchColumn() >= 8;
}

function login_failed(): void
{
    db()->prepare('INSERT INTO login_attempts (ip, ts) VALUES (?, ?)')->execute([client_ip(), time()]);
    db()->prepare('DELETE FROM login_attempts WHERE ts < ?')->execute([time() - 86400]);
}

/** Sayfanın tam adresi (paylaşım önizlemesi için mutlak adres gerekir). */
function base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host  = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $dir   = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    if (substr($dir, -8) === '/yonetim') { $dir = substr($dir, 0, -8); }
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}
