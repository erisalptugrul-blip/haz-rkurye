<?php
// Satıcı paneli: ilk kurulumda şifre belirlenir, sonrasında giriş yapılır.
declare(strict_types=1);
require __DIR__ . '/../inc/boot.php';

start_admin_session();
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');

$s         = settings();
$needSetup = $s['admin_hash'] === '';
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string)($_POST['do'] ?? '');
    $pw = (string)($_POST['password'] ?? '');
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'Oturum süresi doldu. Sayfayı yenileyip tekrar deneyin.';
    } elseif ($do === 'setup' && $needSetup) {
        if (mb_strlen($pw) < 8) {
            $error = 'Şifre en az 8 karakter olmalı.';
        } elseif ($pw !== (string)($_POST['password2'] ?? '')) {
            $error = 'İki şifre birbirinin aynısı değil.';
        } else {
            save_settings(['admin_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            header('Location: ./');
            exit;
        }
    } elseif ($do === 'login' && !$needSetup) {
        if (login_blocked()) {
            $error = 'Çok fazla hatalı deneme yapıldı. 15 dakika sonra tekrar deneyin.';
        } elseif (password_verify($pw, $s['admin_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            header('Location: ./');
            exit;
        } else {
            login_failed();
            $error = 'Şifre hatalı.';
        }
    } elseif ($do === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: ./');
        exit;
    }
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" href="../assets/favicon.png">
<link rel="apple-touch-icon" href="../assets/apple-touch-icon.png">
<title>Yönetim · <?= h($s['shop_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700;12..96,800&amp;family=Figtree:wght@400;500;600;700&amp;family=IBM+Plex+Mono:wght@500;600&amp;display=swap">
<link rel="stylesheet" href="../assets/yonetim.css?v=<?= asset_v('assets/yonetim.css') ?>">
</head>
<body>

<?php if (!is_admin()): ?>
<main class="auth">
  <form method="post" class="auth-card" autocomplete="on">
    <img class="auth-logo" src="../assets/logo.webp" alt="" width="88" height="88">
    <h1><?= $needSetup ? 'İlk kurulum' : 'Yönetim girişi' ?></h1>
    <p class="muted"><?= h($s['shop_name']) ?></p>
    <?php if ($needSetup): ?>
      <p class="hint">Panel için bir şifre belirleyin. Bu sayfayı ilk açan kişi şifreyi belirler; kurulumu yüklemeden hemen sonra yapın.</p>
    <?php endif; ?>
    <?php if ($error): ?><p class="err" role="alert"><?= h($error) ?></p><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="do" value="<?= $needSetup ? 'setup' : 'login' ?>">
    <div class="field">
      <label for="pw"><?= $needSetup ? 'Yeni şifre (en az 8 karakter)' : 'Şifre' ?></label>
      <input id="pw" name="password" type="password" required autofocus autocomplete="<?= $needSetup ? 'new-password' : 'current-password' ?>">
    </div>
    <?php if ($needSetup): ?>
    <div class="field">
      <label for="pw2">Şifre tekrar</label>
      <input id="pw2" name="password2" type="password" required autocomplete="new-password">
    </div>
    <?php endif; ?>
    <button class="btn btn--primary btn--block" type="submit"><?= $needSetup ? 'Şifreyi kaydet ve gir' : 'Giriş yap' ?></button>
  </form>
</main>
<?php else: ?>

<header class="appbar">
  <div class="appbar-in">
    <div class="ab-brand">
      <img class="ab-logo" src="../assets/logo.webp" alt="" width="46" height="46">
      <div class="ab-title">
        <strong id="shop-title"><?= h($s['shop_name']) ?></strong>
        <span class="muted">Yönetim paneli</span>
      </div>
    </div>
    <div class="ab-actions">
      <button type="button" class="switch" id="open-toggle" role="switch" aria-checked="<?= $s['is_open'] === '1' ? 'true' : 'false' ?>">
        <span id="open-label"><?= $s['is_open'] === '1' ? 'Sipariş alıyor' : 'Siparişe kapalı' ?></span><i aria-hidden="true"></i>
      </button>
      <button type="button" class="chip" id="sound-toggle">Sesli uyarı: Kapalı</button>
      <form method="post" class="inline">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="do" value="logout">
        <button type="submit" class="chip">Çıkış</button>
      </form>
    </div>
  </div>
  <nav class="tabs" role="tablist" aria-label="Bölümler">
    <button type="button" role="tab" data-tab="orders" aria-selected="true">Siparişler <span class="badge" id="badge-new" hidden>0</span></button>
    <button type="button" role="tab" data-tab="menu" aria-selected="false">Menü</button>
    <button type="button" role="tab" data-tab="settings" aria-selected="false">Ayarlar</button>
  </nav>
</header>

<div class="netwarn" id="netwarn" role="status" hidden>Bağlantı kesildi. Yeni siparişler bağlantı gelene kadar görünmez.</div>

<main class="page" id="app" data-csrf="<?= h($csrf) ?>">
  <section id="tab-orders" role="tabpanel">
    <div class="filters" role="group" aria-label="Sipariş filtresi">
      <button type="button" class="chip is-on" data-filter="active">Aktif <span id="cnt-active">0</span></button>
      <button type="button" class="chip" data-filter="done">Tamamlanan <span id="cnt-done">0</span></button>
    </div>
    <div class="orders" id="orders"><p class="muted">Siparişler yükleniyor…</p></div>
  </section>

  <section id="tab-menu" role="tabpanel" hidden></section>
  <section id="tab-settings" role="tabpanel" hidden></section>
</main>

<dialog id="product-dialog" class="dlg" aria-labelledby="pd-title"></dialog>
<div id="ticket" class="ticket" aria-hidden="true"></div>
<div id="toast" class="toast" role="status" aria-live="polite" hidden></div>

<script src="../assets/yonetim.js?v=<?= asset_v('assets/yonetim.js') ?>" defer></script>
<?php endif; ?>
</body>
</html>
