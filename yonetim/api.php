<?php
// Satıcı paneli API'si. Her istek oturum ister; değiştiren istekler CSRF belirteci ister.
declare(strict_types=1);
require __DIR__ . '/../inc/boot.php';

start_admin_session();
header('X-Content-Type-Options: nosniff');

if (!is_admin()) {
    fail('Oturum gerekli. Sayfayı yenileyip giriş yapın.', 401, ['auth' => false]);
}

$action  = (string)($_GET['action'] ?? '');
$isPost  = $_SERVER['REQUEST_METHOD'] === 'POST';
$readers = ['orders', 'catalog'];

if (!in_array($action, $readers, true)) {
    if (!$isPost) {
        fail('Yöntem geçersiz.', 405);
    }
    if (!csrf_ok($_SERVER['HTTP_X_CSRF'] ?? null)) {
        fail('Oturum doğrulanamadı. Sayfayı yenileyin.', 403);
    }
}

$in = [];
if ($isPost && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === 0) {
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 20001), true);
    if (!is_array($in)) {
        fail('İstek okunamadı.', 400);
    }
}

try {
    switch ($action) {
        case 'orders':          act_orders(); break;
        case 'catalog':         act_catalog(); break;
        case 'order_status':    act_order_status($in); break;
        case 'toggle_open':     act_toggle_open($in); break;
        case 'product_save':    act_product_save(); break;
        case 'product_toggle':  act_product_toggle($in); break;
        case 'product_delete':  act_product_delete($in); break;
        case 'product_move':    act_move('products', $in); break;
        case 'category_save':   act_category_save($in); break;
        case 'category_delete': act_category_delete($in); break;
        case 'category_move':   act_move('categories', $in); break;
        case 'sample_delete':   act_sample_delete(); break;
        case 'settings_save':   act_settings_save($in); break;
        case 'password_change': act_password_change($in); break;
        default: fail('Bulunamadı.', 404);
    }
} catch (RuntimeException $e) {
    fail($e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('[siparis-yonetim] ' . $e->getMessage());
    fail('Bir sorun oluştu. Lütfen tekrar deneyin.', 500);
}

/* ---------- Siparişler ---------- */

function act_orders(): void
{
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM orders WHERE created_at > ? OR status IN ('yeni','hazirlaniyor','yolda') ORDER BY id DESC LIMIT 200");
    $st->execute([time() - 3 * 86400]);
    $rows = $st->fetchAll();

    $items = [];
    if ($rows) {
        $ids = implode(',', array_map(function ($r) { return (int)$r['id']; }, $rows));
        foreach ($pdo->query("SELECT order_id, name, unit_price, qty FROM order_items WHERE order_id IN ($ids) ORDER BY id") as $i) {
            $items[(int)$i['order_id']][] = ['name' => $i['name'], 'unit_price' => (int)$i['unit_price'], 'qty' => (int)$i['qty']];
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'           => (int)$r['id'],
            'code'         => $r['code'],
            'created_at'   => (int)$r['created_at'],
            'status'       => $r['status'],
            'name'         => $r['name'],
            'phone'        => $r['phone'],
            'phone_fmt'    => format_phone($r['phone']),
            'wa'           => intl_digits($r['phone']),
            'address'      => $r['address'],
            'note'         => $r['note'],
            'payment'      => $r['payment'],
            'change_for'   => $r['change_for'] === null ? null : (int)$r['change_for'],
            'subtotal'     => (int)$r['subtotal'],
            'delivery_fee' => (int)$r['delivery_fee'],
            'total'        => (int)$r['total'],
            'items'        => $items[(int)$r['id']] ?? [],
        ];
    }
    json_out(['ok' => true, 'now' => time(), 'orders' => $out]);
}

function act_order_status(array $in): void
{
    $id     = (int)($in['id'] ?? 0);
    $status = (string)($in['status'] ?? '');
    if ($id < 1 || !isset(STATUSES[$status])) {
        fail('Geçersiz istek.');
    }
    $st = db()->prepare('UPDATE orders SET status = ?, updated_at = ? WHERE id = ?');
    $st->execute([$status, time(), $id]);
    if ($st->rowCount() === 0) {
        fail('Sipariş bulunamadı.', 404);
    }
    json_out(['ok' => true]);
}

function act_toggle_open(array $in): void
{
    $open = !empty($in['open']);
    save_settings(['is_open' => $open ? '1' : '0']);
    json_out(['ok' => true, 'open' => $open]);
}

/* ---------- Menü ---------- */

function act_catalog(): void
{
    $pdo   = db();
    $cats  = $pdo->query('SELECT id, name, sort, is_sample FROM categories ORDER BY sort, id')->fetchAll();
    $prods = $pdo->query('SELECT id, category_id, name, description, price, image, available, sort, is_sample FROM products ORDER BY sort, id')->fetchAll();
    $hasSample = false;
    foreach ($cats as &$c) {
        $c['id'] = (int)$c['id'];
        $c['sort'] = (int)$c['sort'];
        unset($c['is_sample']);
    }
    unset($c);
    foreach ($prods as &$p) {
        $p['id']          = (int)$p['id'];
        $p['category_id'] = (int)$p['category_id'];
        $p['price']       = (int)$p['price'];
        $p['available']   = (int)$p['available'] === 1;
        $p['image_url']   = $p['image'] !== '' ? '../uploads/' . $p['image'] : '';
        if ((int)$p['is_sample'] === 1) {
            $hasSample = true;
        }
        unset($p['is_sample'], $p['sort']);
    }
    unset($p);

    $s = settings();
    json_out([
        'ok'         => true,
        'categories' => $cats,
        'products'   => $prods,
        'has_sample' => $hasSample,
        'settings'   => [
            'shop_name'      => $s['shop_name'],
            'tagline'        => $s['tagline'],
            'phone'          => $s['phone'],
            'min_order'      => (int)$s['min_order'],
            'delivery_fee'   => (int)$s['delivery_fee'],
            'is_open'        => $s['is_open'] === '1',
            'eta_text'       => $s['eta_text'],
            'delivery_note'  => $s['delivery_note'],
            'closed_message' => $s['closed_message'],
        ],
    ]);
}

function remove_image_file(string $file): void
{
    if (preg_match('/^[a-f0-9]{16}\.(jpg|png|webp)$/', $file)) {
        @unlink(APP_ROOT . '/uploads/' . $file);
    }
}

// Yüklenen fotoğrafı doğrular, küçültür ve JPEG olarak yeniden kaydeder.
function save_product_image(array $f): string
{
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('Fotoğraf çok büyük. En fazla 4 MB olmalı.');
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        throw new RuntimeException('Fotoğraf yüklenemedi. Tekrar deneyin.');
    }
    if ((int)$f['size'] > 4 * 1024 * 1024) {
        throw new RuntimeException('Fotoğraf çok büyük. En fazla 4 MB olmalı.');
    }
    $info = @getimagesize($f['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) {
        throw new RuntimeException('Yalnızca JPG, PNG veya WebP fotoğraf yükleyebilirsiniz.');
    }
    if ($info[0] * $info[1] > 40000000) {
        throw new RuntimeException('Fotoğrafın çözünürlüğü çok yüksek. Daha küçük bir fotoğraf seçin.');
    }
    $dir = APP_ROOT . '/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $base = bin2hex(random_bytes(8));

    if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        $src = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
        if (!$src) {
            throw new RuntimeException('Fotoğraf okunamadı. Başka bir dosya deneyin.');
        }
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $ex    = @exif_read_data($f['tmp_name']);
            $angle = [3 => 180, 6 => -90, 8 => 90][(int)($ex['Orientation'] ?? 1)] ?? 0;
            if ($angle) {
                $rot = imagerotate($src, $angle, 0);
                if ($rot) {
                    imagedestroy($src);
                    $src = $rot;
                }
            }
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, 900 / max($w, $h));
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $file = $base . '.jpg';
        $ok = imagejpeg($dst, $dir . '/' . $file, 82);
        imagedestroy($src);
        imagedestroy($dst);
        if (!$ok) {
            throw new RuntimeException('Fotoğraf kaydedilemedi. uploads klasörünün yazılabilir olduğundan emin olun.');
        }
        return $file;
    }

    $file = $base . '.' . $types[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $file)) {
        throw new RuntimeException('Fotoğraf kaydedilemedi. uploads klasörünün yazılabilir olduğundan emin olun.');
    }
    return $file;
}

function act_product_save(): void
{
    $pdo  = db();
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    $cat  = (int)($_POST['category_id'] ?? 0);
    $price = parse_tl($_POST['price'] ?? '');
    $avail = (string)($_POST['available'] ?? '1') === '1' ? 1 : 0;

    if ($name === '' || mb_strlen($name) > 80) {
        fail('Ürün adı 1 ile 80 karakter arasında olmalı.');
    }
    if (mb_strlen($desc) > 200) {
        fail('Açıklama en fazla 200 karakter olabilir.');
    }
    if ($price === null || $price < 1 || $price > 10000000) {
        fail('Fiyat geçersiz. Örnek: 185 veya 185,50');
    }
    $chk = $pdo->prepare('SELECT 1 FROM categories WHERE id = ?');
    $chk->execute([$cat]);
    if (!$chk->fetchColumn()) {
        fail('Lütfen bir kategori seçin.');
    }

    $old = null;
    if ($id) {
        $q = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $q->execute([$id]);
        $old = $q->fetch();
        if (!$old) {
            fail('Ürün bulunamadı.', 404);
        }
    }

    $image = $old ? $old['image'] : '';
    $hasUpload = isset($_FILES['image']) && (int)$_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE;
    if ($hasUpload) {
        $new = save_product_image($_FILES['image']);
        if ($image !== '') {
            remove_image_file($image);
        }
        $image = $new;
    } elseif ($old && (string)($_POST['remove_image'] ?? '') === '1') {
        remove_image_file($image);
        $image = '';
    }

    if ($old) {
        $sort = (int)$old['sort'];
        if ((int)$old['category_id'] !== $cat) {
            $m = $pdo->prepare('SELECT COALESCE(MAX(sort), 0) + 1 FROM products WHERE category_id = ?');
            $m->execute([$cat]);
            $sort = (int)$m->fetchColumn();
        }
        $pdo->prepare('UPDATE products SET category_id = ?, name = ?, description = ?, price = ?, image = ?, available = ?, sort = ? WHERE id = ?')
            ->execute([$cat, $name, $desc, $price, $image, $avail, $sort, $id]);
    } else {
        $m = $pdo->prepare('SELECT COALESCE(MAX(sort), 0) + 1 FROM products WHERE category_id = ?');
        $m->execute([$cat]);
        $pdo->prepare('INSERT INTO products (category_id, name, description, price, image, available, sort) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$cat, $name, $desc, $price, $image, $avail, (int)$m->fetchColumn()]);
        $id = (int)$pdo->lastInsertId();
    }
    json_out(['ok' => true, 'id' => $id]);
}

function act_product_toggle(array $in): void
{
    $st = db()->prepare('UPDATE products SET available = ? WHERE id = ?');
    $st->execute([!empty($in['available']) ? 1 : 0, (int)($in['id'] ?? 0)]);
    json_out(['ok' => true]);
}

function act_product_delete(array $in): void
{
    $pdo = db();
    $id  = (int)($in['id'] ?? 0);
    $q = $pdo->prepare('SELECT image FROM products WHERE id = ?');
    $q->execute([$id]);
    $img = $q->fetchColumn();
    if ($img === false) {
        fail('Ürün bulunamadı.', 404);
    }
    if ($img !== '') {
        remove_image_file((string)$img);
    }
    $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    json_out(['ok' => true]);
}

// Aynı grup içinde bir adım yukarı (-1) veya aşağı (+1) taşır.
function act_move(string $table, array $in): void
{
    $pdo = db();
    $id  = (int)($in['id'] ?? 0);
    $dir = ((int)($in['dir'] ?? 0)) < 0 ? -1 : 1;

    if ($table === 'products') {
        $q = $pdo->prepare('SELECT category_id FROM products WHERE id = ?');
        $q->execute([$id]);
        $cid = $q->fetchColumn();
        if ($cid === false) {
            fail('Ürün bulunamadı.', 404);
        }
        $s = $pdo->prepare('SELECT id FROM products WHERE category_id = ? ORDER BY sort, id');
        $s->execute([(int)$cid]);
    } else {
        $s = $pdo->query('SELECT id FROM categories ORDER BY sort, id');
    }
    $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    $pos = array_search($id, $ids, true);
    if ($pos === false) {
        fail('Kayıt bulunamadı.', 404);
    }
    $to = $pos + $dir;
    if ($to >= 0 && $to < count($ids)) {
        $tmp = $ids[$pos];
        $ids[$pos] = $ids[$to];
        $ids[$to] = $tmp;
    }
    $up = $pdo->prepare("UPDATE $table SET sort = ? WHERE id = ?");
    foreach ($ids as $i => $rid) {
        $up->execute([$i + 1, $rid]);
    }
    json_out(['ok' => true]);
}

function act_category_save(array $in): void
{
    $pdo  = db();
    $id   = (int)($in['id'] ?? 0);
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 40) {
        fail('Kategori adı 1 ile 40 karakter arasında olmalı.');
    }
    if ($id) {
        $pdo->prepare('UPDATE categories SET name = ? WHERE id = ?')->execute([$name, $id]);
    } else {
        $max = (int)$pdo->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM categories')->fetchColumn();
        $pdo->prepare('INSERT INTO categories (name, sort) VALUES (?, ?)')->execute([$name, $max]);
        $id = (int)$pdo->lastInsertId();
    }
    json_out(['ok' => true, 'id' => $id]);
}

function act_category_delete(array $in): void
{
    $pdo = db();
    $id  = (int)($in['id'] ?? 0);
    $q = $pdo->prepare('SELECT image FROM products WHERE category_id = ?');
    $q->execute([$id]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $img) {
        if ($img !== '') {
            remove_image_file((string)$img);
        }
    }
    $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]); // ürünler zincirleme silinir
    json_out(['ok' => true]);
}

function act_sample_delete(): void
{
    $pdo = db();
    $pdo->exec('DELETE FROM products WHERE is_sample = 1');
    $pdo->exec('DELETE FROM categories WHERE is_sample = 1 AND id NOT IN (SELECT DISTINCT category_id FROM products)');
    json_out(['ok' => true]);
}

/* ---------- Ayarlar ---------- */

function act_settings_save(array $in): void
{
    $shop = trim((string)($in['shop_name'] ?? ''));
    if ($shop === '' || mb_strlen($shop) > 60) {
        fail('İşletme adı 1 ile 60 karakter arasında olmalı.');
    }
    $tagline = trim((string)($in['tagline'] ?? ''));
    $eta     = trim((string)($in['eta_text'] ?? ''));
    $note    = trim((string)($in['delivery_note'] ?? ''));
    $closed  = trim((string)($in['closed_message'] ?? ''));
    $phone   = trim((string)($in['phone'] ?? ''));
    if (mb_strlen($tagline) > 100 || mb_strlen($eta) > 30 || mb_strlen($note) > 200 || mb_strlen($closed) > 200) {
        fail('Bir alan çok uzun. Metinleri kısaltın.');
    }
    if (!preg_match('/^[0-9+\s()\-]{0,20}$/', $phone)) {
        fail('Telefon yalnızca rakam, boşluk, + ve - içerebilir.');
    }
    $min = parse_tl($in['min_order'] ?? '');
    $fee = parse_tl($in['delivery_fee'] ?? '0');
    if ($min === null || $min > 100000000 || $fee === null || $fee > 10000000) {
        fail('Tutarlar geçersiz. Örnek: 200 veya 12,50');
    }
    if ($closed === '') {
        $closed = SETTING_DEFAULTS['closed_message'];
    }
    save_settings([
        'shop_name'      => $shop,
        'tagline'        => $tagline,
        'phone'          => $phone,
        'min_order'      => $min,
        'delivery_fee'   => $fee,
        'eta_text'       => $eta,
        'delivery_note'  => $note,
        'closed_message' => $closed,
    ]);
    json_out(['ok' => true]);
}

function act_password_change(array $in): void
{
    $cur = (string)($in['current'] ?? '');
    $new = (string)($in['new'] ?? '');
    if (!password_verify($cur, settings()['admin_hash'])) {
        fail('Mevcut şifre hatalı.');
    }
    if (mb_strlen($new) < 8) {
        fail('Yeni şifre en az 8 karakter olmalı.');
    }
    save_settings(['admin_hash' => password_hash($new, PASSWORD_DEFAULT)]);
    json_out(['ok' => true]);
}
