<?php
// Sipariş takip sayfası: müşteri verdiği siparişin durumunu buradan izler.
declare(strict_types=1);
require __DIR__ . '/inc/boot.php';

header('Cache-Control: no-store');
$s = public_settings();
$k = strtoupper((string)($_GET['k'] ?? ''));

$order = null;
$items = [];
if (preg_match('/^[A-Z0-9]{8}$/', $k)) {
    $st = db()->prepare('SELECT * FROM orders WHERE code = ?');
    $st->execute([$k]);
    $order = $st->fetch() ?: null;
    if ($order) {
        $it = db()->prepare('SELECT name, unit_price, qty FROM order_items WHERE order_id = ? ORDER BY id');
        $it->execute([$order['id']]);
        $items = $it->fetchAll();
    }
}
if (!$order) {
    http_response_code(404);
}

$heads = [
    'yeni'         => ['Siparişiniz alındı', 'İşletme siparişinizi görüp hazırlamaya başlayacak. Bu sayfa kendiliğinden güncellenir.'],
    'hazirlaniyor' => ['Siparişiniz hazırlanıyor', 'Yemekleriniz hazırlanıyor.'],
    'yolda'        => ['Siparişiniz yolda', 'Kurye siparişinizle yola çıktı.'],
    'teslim'       => ['Teslim edildi', 'Afiyet olsun. Bizi tercih ettiğiniz için teşekkürler.'],
    'iptal'        => ['Sipariş iptal edildi', 'Ayrıntı için işletmeyi arayabilirsiniz.'],
];
$flow = ['yeni' => 'Sipariş alındı', 'hazirlaniyor' => 'Hazırlanıyor', 'yolda' => 'Yolda', 'teslim' => 'Teslim edildi'];
$tel  = intl_digits($s['phone']);
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<link rel="icon" type="image/png" href="assets/favicon.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<title>Sipariş durumu · <?= h($s['shop_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700;12..96,800&amp;family=Figtree:wght@400;500;600;700&amp;family=IBM+Plex+Mono:wght@500;600&amp;display=swap">
<link rel="stylesheet" href="assets/musteri.css?v=<?= asset_v('assets/musteri.css') ?>">
</head>
<body>
<main class="wrap status-page">
  <p class="back"><img class="logo-sm" src="assets/logo.webp" alt="" width="44" height="44"><a href="./"><?= h($s['shop_name']) ?></a></p>

<?php if (!$order): ?>
  <h1 class="st-title">Sipariş bulunamadı</h1>
  <p class="desc">Bağlantıyı kontrol edin. Yeni sipariş vermek için <a href="./">menüye dönün</a>.</p>
<?php else: $st = $order['status']; ?>
  <p class="slip-title">Sipariş no</p>
  <p class="code"><?= h($order['code']) ?></p>
  <h1 class="st-title" id="st-title"><?= h($heads[$st][0] ?? 'Sipariş') ?></h1>
  <p class="desc" id="st-desc"><?= h($heads[$st][1] ?? '') ?></p>

  <?php if ($st === 'iptal'): ?>
    <p class="closed" role="status">Bu sipariş iptal edildi.</p>
  <?php else: ?>
    <?php $reached = true; ?>
    <ol class="steps" aria-label="Sipariş aşamaları">
      <?php foreach ($flow as $key => $label):
          $cls = $reached ? ($key === $st ? 'is-now' : 'is-done') : '';
          if ($key === $st) { $reached = false; }
      ?>
        <li class="<?= $cls ?>"<?= $key === $st ? ' aria-current="step"' : '' ?>><?= h($label) ?></li>
      <?php endforeach; ?>
    </ol>
    <?php if ($st === 'yeni' || $st === 'hazirlaniyor'): ?>
      <p class="note">Tahmini hazırlık süresi: <?= h($s['eta_text']) ?></p>
    <?php endif; ?>
  <?php endif; ?>

  <div class="slip">
    <p class="slip-title"><?= h($s['shop_name']) ?> · Sipariş özeti</p>
    <?php foreach ($items as $i): ?>
      <div class="ln">
        <span class="ln-name"><?= h($i['name']) ?></span>
        <span class="ln-total"><?= h(money((int)$i['unit_price'] * (int)$i['qty'])) ?></span>
        <span class="ln-unit" style="text-align:left"><?= (int)$i['qty'] ?> × <?= h(money((int)$i['unit_price'])) ?></span>
      </div>
    <?php endforeach; ?>
    <div class="sum"><span>Ara toplam</span><span class="dots"></span><span><?= h(money((int)$order['subtotal'])) ?></span></div>
    <div class="sum"><span>Teslimat</span><span class="dots"></span><span><?= (int)$order['delivery_fee'] > 0 ? h(money((int)$order['delivery_fee'])) : 'Ücretsiz' ?></span></div>
    <div class="sum sum--grand"><span>Toplam</span><span class="dots"></span><strong><?= h(money((int)$order['total'])) ?></strong></div>
    <div class="sum"><span>Ödeme</span><span class="dots"></span><span><?= $order['payment'] === 'nakit' ? 'Kapıda nakit' : 'Kapıda kart' ?></span></div>
    <?php if ($order['payment'] === 'nakit' && $order['change_for'] !== null): ?>
      <div class="sum"><span>Verilecek tutar</span><span class="dots"></span><span><?= h(money((int)$order['change_for'])) ?></span></div>
    <?php endif; ?>
  </div>

  <?php if ($tel): ?>
  <div class="actions">
    <a class="btn" href="tel:+<?= h($tel) ?>">İşletmeyi ara</a>
    <a class="btn btn--ghost" href="https://wa.me/<?= h($tel) ?>?text=<?= rawurlencode('Merhaba, ' . $order['code'] . ' numaralı siparişim hakkında yazıyorum.') ?>" target="_blank" rel="noopener">WhatsApp</a>
  </div>
  <?php endif; ?>
  <p class="fine"><a href="./">Menüye dön</a></p>

  <?php if ($st !== 'teslim' && $st !== 'iptal'): ?>
  <script>
  (function () {
    var current = <?= json_encode($st) ?>;
    var url = 'api.php?action=status&k=<?= h($order['code']) ?>';
    setInterval(function () {
      if (document.hidden) { return; }
      fetch(url, { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok && j.status !== current) { window.location.reload(); } })
        .catch(function () {});
    }, 10000);
  })();
  </script>
  <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
