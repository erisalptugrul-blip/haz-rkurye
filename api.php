<?php
// Müşteri tarafı API: sipariş verme ve sipariş durumu sorgulama.
declare(strict_types=1);
require __DIR__ . '/inc/boot.php';

header('X-Content-Type-Options: nosniff');

try {
    $action = $_GET['action'] ?? '';
    if ($action === 'order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        place_order();
    } elseif ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        order_status();
    } else {
        fail('Bulunamadı.', 404);
    }
} catch (Throwable $e) {
    error_log('[siparis] ' . $e->getMessage());
    fail('Bir sorun oluştu. Lütfen biraz sonra tekrar deneyin.', 500);
}

function place_order(): void
{
    $raw = file_get_contents('php://input', false, null, 0, 30001);
    $in  = json_decode((string)$raw, true);
    if (!is_array($in)) {
        fail('İstek okunamadı.', 400);
    }

    $s = settings();
    if ($s['is_open'] !== '1') {
        fail($s['closed_message'], 403, ['closed' => true]);
    }

    // Bot tuzağı: gizli alan doluysa sessizce "başarılı" dön, kayıt oluşturma.
    if (!empty($in['website'])) {
        json_out(['ok' => true, 'code' => 'XXXXXXXX', 'total' => 0]);
    }

    $name    = trim((string)preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
    $address = trim((string)($in['address'] ?? ''));
    $note    = trim((string)($in['note'] ?? ''));
    $payment = (string)($in['payment'] ?? '');
    $phone   = normalize_phone((string)($in['phone'] ?? ''));

    if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
        fail('Lütfen adınızı ve soyadınızı yazın.', 422, ['field' => 'name']);
    }
    if ($phone === null) {
        fail('Telefon numarası geçersiz. Örnek: 0532 123 45 67', 422, ['field' => 'phone']);
    }
    if (mb_strlen($address) < 10 || mb_strlen($address) > 300) {
        fail('Adres en az 10 karakter olmalı. Mahalle, sokak ve kapı numarasını yazın.', 422, ['field' => 'address']);
    }
    if (mb_strlen($note) > 200) {
        fail('Sipariş notu en fazla 200 karakter olabilir.', 422, ['field' => 'note']);
    }
    if ($payment !== 'nakit' && $payment !== 'kart') {
        fail('Lütfen ödeme yöntemini seçin.', 422, ['field' => 'payment']);
    }

    // Sepet: fiyatlar istemciden değil veritabanından okunur.
    $want = [];
    foreach ((array)($in['items'] ?? []) as $it) {
        if (!is_array($it)) {
            continue;
        }
        $id = (int)($it['id'] ?? 0);
        $q  = (int)($it['qty'] ?? 0);
        if ($id < 1 || $q < 1) {
            continue;
        }
        $want[$id] = min(20, ($want[$id] ?? 0) + $q);
    }
    if (!$want || count($want) > 40) {
        fail('Sepetiniz boş.');
    }
    $ids  = implode(',', array_map('intval', array_keys($want)));
    $rows = db()->query("SELECT id, name, price, available FROM products WHERE id IN ($ids)")->fetchAll();
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int)$r['id']] = $r;
    }
    $gone = [];
    $goneNames = [];
    foreach ($want as $id => $q) {
        if (!isset($byId[$id]) || (int)$byId[$id]['available'] !== 1) {
            $gone[] = $id;
            if (isset($byId[$id])) {
                $goneNames[] = $byId[$id]['name'];
            }
        }
    }
    if ($gone) {
        $msg = 'Bazı ürünler şu an satışta değil' . ($goneNames ? ': ' . implode(', ', $goneNames) : '') . '. Sepetinizden çıkarıldı, lütfen kontrol edin.';
        fail($msg, 409, ['unavailable' => $gone]);
    }

    $subtotal = 0;
    foreach ($want as $id => $q) {
        $subtotal += (int)$byId[$id]['price'] * $q;
    }
    $min = (int)$s['min_order'];
    if ($subtotal < $min) {
        fail('Minimum sipariş tutarı ' . money($min) . '. Sepetiniz ' . money($subtotal) . '.', 422, ['min' => $min]);
    }
    $fee   = (int)$s['delivery_fee'];
    $total = $subtotal + $fee;

    $changeFor = null;
    if ($payment === 'nakit' && isset($in['change_for']) && trim((string)$in['change_for']) !== '') {
        $changeFor = parse_tl($in['change_for']);
        if ($changeFor === null || $changeFor > 5000000) {
            fail('Para üstü tutarı anlaşılamadı. Örnek: 500', 422, ['field' => 'change_for']);
        }
        if ($changeFor < $total) {
            fail('Yazdığınız tutar sipariş toplamı olan ' . money($total) . ' tutarından az olamaz.', 422, ['field' => 'change_for']);
        }
    }

    // Aşırı sipariş denemelerine karşı sınır.
    $pdo = db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE phone = ? AND created_at > ?');
    $st->execute([$phone, time() - 1800]);
    if ((int)$st->fetchColumn() >= 4) {
        fail('Kısa sürede çok fazla sipariş verildi. Lütfen bizi arayın.', 429);
    }
    $ip = client_ip();
    $st = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE ip = ? AND created_at > ?');
    $st->execute([$ip, time() - 1800]);
    if ((int)$st->fetchColumn() >= 30) {
        fail('Çok fazla istek geldi. Lütfen biraz sonra tekrar deneyin.', 429);
    }

    $now = time();
    $pdo->beginTransaction();
    try {
        $code = '';
        for ($try = 0; $try < 5; $try++) {
            $code = new_order_code();
            $chk = $pdo->prepare('SELECT 1 FROM orders WHERE code = ?');
            $chk->execute([$code]);
            if (!$chk->fetchColumn()) {
                break;
            }
        }
        $pdo->prepare('INSERT INTO orders (code, created_at, updated_at, status, name, phone, address, note, payment, change_for, subtotal, delivery_fee, total, ip)
                       VALUES (?, ?, ?, \'yeni\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$code, $now, $now, $name, $phone, $address, $note, $payment, $changeFor, $subtotal, $fee, $total, $ip]);
        $orderId = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO order_items (order_id, product_id, name, unit_price, qty) VALUES (?, ?, ?, ?, ?)');
        foreach ($want as $id => $q) {
            $ins->execute([$orderId, $id, $byId[$id]['name'], (int)$byId[$id]['price'], $q]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    json_out(['ok' => true, 'code' => $code, 'total' => $total]);
}

function order_status(): void
{
    $k = strtoupper((string)($_GET['k'] ?? ''));
    if (!preg_match('/^[A-Z0-9]{8}$/', $k)) {
        fail('Sipariş bulunamadı.', 404);
    }
    $st = db()->prepare('SELECT status, updated_at FROM orders WHERE code = ?');
    $st->execute([$k]);
    $o = $st->fetch();
    if (!$o) {
        fail('Sipariş bulunamadı.', 404);
    }
    json_out([
        'ok'         => true,
        'status'     => $o['status'],
        'label'      => STATUSES[$o['status']] ?? $o['status'],
        'updated_at' => (int)$o['updated_at'],
    ]);
}
