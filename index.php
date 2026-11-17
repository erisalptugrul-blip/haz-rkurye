<?php
// Müşteri sayfası: Instagram biyografisindeki link buraya açılır.
declare(strict_types=1);
require __DIR__ . '/inc/boot.php';

$s    = public_settings();
$menu = menu_data();
$open = $s['is_open'];

$flat = [];
foreach ($menu as $c) {
    foreach ($c['products'] as $p) {
        $flat[] = ['id' => $p['id'], 'name' => $p['name'], 'price' => $p['price'], 'available' => $p['available'], 'cat' => $c['id']];
    }
}
$data = ['settings' => $s, 'products' => $flat];
$tel  = intl_digits($s['phone']);
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($s['shop_name']) ?> · Sipariş</title>
<meta name="description" content="<?= h($s['shop_name']) ?> menüsü ve online sipariş. Minimum sipariş <?= h(money($s['min_order'])) ?>, kapıda nakit veya kart.">
<meta name="theme-color" content="#C42A12">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= h($s['shop_name']) ?> · Sipariş">
<meta property="og:description" content="<?= h($s['tagline']) ?>. Minimum sipariş <?= h(money($s['min_order'])) ?>, kapıda nakit veya kart.">
<meta property="og:image" content="<?= h(base_url()) ?>assets/og.jpg">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" type="image/png" href="assets/favicon.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700;12..96,800&amp;family=Figtree:wght@400;500;600;700&amp;family=IBM+Plex+Mono:wght@500;600&amp;display=swap">
<link rel="stylesheet" href="assets/musteri.css?v=<?= asset_v('assets/musteri.css') ?>">
</head>
<body>

<header class="top">
  <div class="wrap">
    <div class="brand">
      <img class="logo" src="assets/logo.webp" alt="" width="96" height="96">
      <div>
        <h1><?= h($s['shop_name']) ?></h1>
        <?php if ($s['tagline'] !== ''): ?><p class="tagline"><?= h($s['tagline']) ?></p><?php endif; ?>
        <span class="pill <?= $open ? 'pill--open' : 'pill--closed' ?>"><?= $open ? 'Açık' : 'Kapalı' ?></span>
      </div>
    </div>

    <dl class="facts">
      <div><dt>Min. sipariş</dt><dd><?= h(money($s['min_order'])) ?></dd></div>
      <div><dt>Hazırlık</dt><dd><?= h($s['eta_text']) ?></dd></div>
      <div><dt>Teslimat</dt><dd><?= $s['delivery_fee'] > 0 ? h(money($s['delivery_fee'])) : 'Ücretsiz' ?></dd></div>
      <div><dt>Ödeme</dt><dd>Kapıda</dd></div>
    </dl>

    <?php if ($s['delivery_note'] !== ''): ?><p class="note"><?= h($s['delivery_note']) ?></p><?php endif; ?>
    <?php if (!$open): ?><p class="closed" role="status"><?= h($s['closed_message']) ?></p><?php endif; ?>
    <p class="last-order" id="last-order" hidden><a href="#" id="last-order-link">Son siparişini takip et</a></p>
  </div>
</header>

<?php if ($menu): ?>
<nav class="cats" aria-label="Kategoriler">
  <div class="wrap cats-row">
    <?php foreach ($menu as $i => $c): ?>
      <a href="#c<?= $c['id'] ?>" data-cat="c<?= $c['id'] ?>"<?= $i === 0 ? ' class="is-active"' : '' ?>><?= h($c['name']) ?></a>
    <?php endforeach; ?>
  </div>
</nav>
<?php endif; ?>

<main class="wrap" id="menu">
  <noscript><p class="closed">Sipariş verebilmek için tarayıcınızda JavaScript açık olmalı.<?= $tel ? ' Bizi arayabilirsiniz: ' . h($s['phone']) : '' ?></p></noscript>

  <?php if (!$menu): ?>
    <p class="empty">Menü henüz hazırlanıyor.</p>
  <?php endif; ?>

  <?php foreach ($menu as $c): ?>
  <section class="cat" id="c<?= $c['id'] ?>" aria-labelledby="h<?= $c['id'] ?>">
    <h2 id="h<?= $c['id'] ?>"><?= h($c['name']) ?></h2>
    <?php foreach ($c['products'] as $p): ?>
    <article class="item<?= $p['image'] !== '' ? ' has-img' : '' ?><?= $p['available'] ? '' : ' is-out' ?>" id="p<?= $p['id'] ?>">
      <div class="ti">
        <h3><?= h($p['name']) ?></h3>
        <span class="dots" aria-hidden="true"></span>
        <span class="price"><?= h(money($p['price'])) ?></span>
      </div>
      <?php if ($p['description'] !== ''): ?><p class="desc"><?= h($p['description']) ?></p><?php endif; ?>
      <div class="ctl" data-ctl="<?= $p['id'] ?>">
        <?php if ($p['available'] && $open): ?>
          <button type="button" class="add" data-add="<?= $p['id'] ?>"><span aria-hidden="true">+</span> Ekle<span class="sr"> <?= h($p['name']) ?></span></button>
        <?php elseif (!$p['available']): ?>
          <span class="out-tag">Tükendi</span>
        <?php endif; ?>
      </div>
      <?php if ($p['image'] !== ''): ?>
        <img class="thumb" src="<?= h($p['image']) ?>" alt="" loading="lazy" width="96" height="96">
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </section>
  <?php endforeach; ?>

  <footer class="foot">
    <?php if ($tel): ?><p>Sipariş hakkında: <a href="tel:+<?= h($tel) ?>"><?= h($s['phone']) ?></a></p><?php endif; ?>
  </footer>
</main>

<!-- Alt sepet çubuğu -->
<div class="bar" id="bar" hidden>
  <button type="button" class="bar-btn" data-open-cart>
    <span class="bar-row"><strong id="bar-count">0 ürün</strong><span class="bar-sum" id="bar-sum">0 ₺</span></span>
    <span class="meter" id="bar-meter" aria-hidden="true"><i></i></span>
    <span class="bar-row bar-sub"><span id="bar-note"></span><span class="bar-go">Sepeti aç</span></span>
  </button>
</div>

<!-- Sepet ve sipariş formu -->
<dialog class="sheet" id="sheet" aria-labelledby="sheet-title">
  <div class="sheet-head">
    <h2 id="sheet-title">Sepetim</h2>
    <button type="button" class="x" data-close aria-label="Kapat">Kapat</button>
  </div>

  <div class="sheet-body">
    <div class="slip" id="slip"></div>

    <form id="order-form" novalidate>
      <div class="field">
        <label for="f-name">Ad soyad</label>
        <input id="f-name" name="name" type="text" autocomplete="name" maxlength="60" required>
      </div>
      <div class="field">
        <label for="f-phone">Telefon</label>
        <input id="f-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="05xx xxx xx xx" maxlength="20" required>
      </div>
      <div class="field">
        <label for="f-address">Adres</label>
        <textarea id="f-address" name="address" rows="3" autocomplete="street-address" maxlength="300" placeholder="Mahalle, sokak, bina ve daire no, varsa tarif" required></textarea>
      </div>
      <div class="field">
        <label for="f-note">Sipariş notu <span class="opt">isteğe bağlı</span></label>
        <input id="f-note" name="note" type="text" maxlength="200" placeholder="Örn. soğansız, zili çalmayın">
      </div>

      <fieldset class="pay">
        <legend>Ödeme, kapıda</legend>
        <label><input type="radio" name="payment" value="nakit" checked><span>Nakit</span></label>
        <label><input type="radio" name="payment" value="kart"><span>Kredi kartı</span></label>
      </fieldset>
      <div class="field" id="change-field">
        <label for="f-change">Kaç liralık vereceksiniz? <span class="opt">para üstü için, isteğe bağlı</span></label>
        <input id="f-change" name="change_for" type="text" inputmode="decimal" placeholder="Örn. 500" maxlength="9">
      </div>

      <div class="hp" aria-hidden="true"><label>Web sitesi<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

      <p class="err" id="form-error" role="alert" hidden></p>
      <button type="submit" class="go" id="go">Siparişi gönder</button>
      <p class="fine">Siparişiniz doğrudan işletmeye iletilir. Ödemeyi teslimatta yaparsınız.</p>
    </form>
  </div>
</dialog>

<script id="menu-data" type="application/json"><?= json_for_html($data) ?></script>
<script src="assets/musteri.js?v=<?= asset_v('assets/musteri.js') ?>" defer></script>
</body>
</html>
