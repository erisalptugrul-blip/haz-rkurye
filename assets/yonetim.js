// Satıcı paneli: sipariş takibi, menü yönetimi, ayarlar.
(function () {
  'use strict';

  const APP = document.getElementById('app');
  const CSRF = APP.dataset.csrf;
  const $ = function (s, r) { return (r || document).querySelector(s); };
  const $$ = function (s, r) { return Array.from((r || document).querySelectorAll(s)); };
  const nf0 = new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 0 });
  const nf2 = new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const money = function (k) { return (k % 100 === 0 ? nf0.format(k / 100) : nf2.format(k / 100)) + ' ₺'; };
  const tlInput = function (k) { return k % 100 === 0 ? String(k / 100) : (k / 100).toFixed(2).replace('.', ','); };
  const esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };

  const ACTIVE = ['yeni', 'hazirlaniyor', 'yolda'];
  const RANK = { yeni: 0, hazirlaniyor: 1, yolda: 2 };
  const LABEL = { yeni: 'Yeni', hazirlaniyor: 'Hazırlanıyor', yolda: 'Yolda', teslim: 'Teslim edildi', iptal: 'İptal' };
  const NEXT = {
    yeni: ['hazirlaniyor', 'Hazırlamaya başla'],
    hazirlaniyor: ['yolda', 'Yola çıktı'],
    yolda: ['teslim', 'Teslim edildi'],
  };

  const state = {
    tab: 'orders',
    filter: 'active',
    orders: [],
    sig: '',
    maxId: null,
    fresh: new Set(),
    skew: 0,
    lastBeep: 0,
    cats: [],
    prods: [],
    settings: null,
    hasSample: false,
  };

  /* ---------- İstek yardımcıları ---------- */
  function request(action, opts) {
    return fetch('api.php?action=' + action, Object.assign({ credentials: 'same-origin', cache: 'no-store' }, opts))
      .then(function (res) {
        return res.json().catch(function () { return null; }).then(function (j) { return { res: res, j: j }; });
      })
      .then(function (r) {
        if (r.res.status === 401) { window.location.reload(); throw new Error('Oturum bitti'); }
        if (!r.res.ok || !r.j || !r.j.ok) { throw new Error((r.j && r.j.error) || 'İşlem tamamlanamadı.'); }
        return r.j;
      });
  }
  const get = function (a) { return request(a); };
  const post = function (a, body) {
    return request(a, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': CSRF }, body: JSON.stringify(body || {}) });
  };
  const postForm = function (a, fd) {
    return request(a, { method: 'POST', headers: { 'X-CSRF': CSRF }, body: fd });
  };

  let toastTimer = 0;
  function toast(msg, isErr) {
    const t = $('#toast');
    t.textContent = msg;
    t.classList.toggle('is-err', !!isErr);
    t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.hidden = true; }, isErr ? 5000 : 2600);
  }
  const fail = function (e) { if (e && e.message !== 'Oturum bitti') { toast(e.message, true); } };

  /* ---------- Sekmeler ---------- */
  function showTab(t) {
    state.tab = t;
    $$('.tabs button').forEach(function (b) { b.setAttribute('aria-selected', b.dataset.tab === t ? 'true' : 'false'); });
    ['orders', 'menu', 'settings'].forEach(function (n) { $('#tab-' + n).hidden = n !== t; });
    if (t !== 'orders') { loadCatalog(); }
  }
  $$('.tabs button').forEach(function (b) { b.addEventListener('click', function () { showTab(b.dataset.tab); }); });

  /* ---------- Sesli uyarı ---------- */
  let audio = null;
  const soundPref = function () { try { return localStorage.getItem('yonetim_ses') === '1'; } catch (e) { return false; } };
  const setSoundPref = function (on) { try { localStorage.setItem('yonetim_ses', on ? '1' : '0'); } catch (e) { /* yok say */ } };

  function unlockAudio() {
    if (!audio) {
      const AC = window.AudioContext || window.webkitAudioContext;
      if (AC) { audio = new AC(); }
    }
    if (audio && audio.state === 'suspended') { audio.resume().then(updateSoundLabel); }
    updateSoundLabel();
  }
  function updateSoundLabel() {
    const b = $('#sound-toggle');
    const on = soundPref();
    const running = audio && audio.state === 'running';
    b.textContent = !on ? 'Sesli uyarı: Kapalı' : (running ? 'Sesli uyarı: Açık' : 'Sesi başlatmak için dokunun');
    b.classList.toggle('is-on', on && running);
  }
  function beep() {
    if (!soundPref() || !audio || audio.state !== 'running') { return; }
    const t0 = audio.currentTime;
    [0, 0.22, 0.44].forEach(function (d, i) {
      const o = audio.createOscillator();
      const g = audio.createGain();
      o.type = 'sine';
      o.frequency.value = i === 2 ? 1175 : 880;
      g.gain.setValueAtTime(0.0001, t0 + d);
      g.gain.exponentialRampToValueAtTime(0.4, t0 + d + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + d + 0.18);
      o.connect(g);
      g.connect(audio.destination);
      o.start(t0 + d);
      o.stop(t0 + d + 0.2);
    });
  }
  $('#sound-toggle').addEventListener('click', function () {
    if (soundPref() && audio && audio.state === 'running') {
      setSoundPref(false);
    } else {
      setSoundPref(true);
      unlockAudio();
      setTimeout(beep, 80);
    }
    updateSoundLabel();
  });
  document.addEventListener('pointerdown', function () { if (soundPref()) { unlockAudio(); } }, { once: true });
  updateSoundLabel();

  /* ---------- Sipariş alıyor / kapalı ---------- */
  $('#open-toggle').addEventListener('click', function () {
    const btn = this;
    const open = btn.getAttribute('aria-checked') !== 'true';
    post('toggle_open', { open: open }).then(function () {
      btn.setAttribute('aria-checked', open ? 'true' : 'false');
      $('#open-label').textContent = open ? 'Sipariş alıyor' : 'Siparişe kapalı';
      toast(open ? 'Müşteriler sipariş verebilir.' : 'Sipariş alımı kapatıldı.');
    }).catch(fail);
  });

  /* ---------- Siparişler ---------- */
  const nowSec = function () { return Date.now() / 1000 + state.skew; };
  function ago(ts) {
    const d = Math.max(0, Math.round(nowSec() - ts));
    if (d < 60) { return 'şimdi'; }
    if (d < 3600) { return Math.floor(d / 60) + ' dk önce'; }
    if (d < 86400) { return Math.floor(d / 3600) + ' sa önce'; }
    return Math.floor(d / 86400) + ' gün önce';
  }
  function clock(ts) {
    const d = new Date(ts * 1000);
    const hm = d.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' });
    return d.toDateString() === new Date().toDateString() ? hm : d.toLocaleDateString('tr-TR', { day: 'numeric', month: 'short' }) + ' ' + hm;
  }
  function payText(o) {
    if (o.payment !== 'nakit') { return 'Kapıda kredi kartı'; }
    if (o.change_for) { return 'Kapıda nakit · ' + money(o.change_for) + ' verecek, para üstü ' + money(o.change_for - o.total); }
    return 'Kapıda nakit';
  }

  function orderCard(o) {
    const items = o.items.map(function (i) {
      return '<li><span>' + i.qty + '× ' + esc(i.name) + '</span><span>' + money(i.unit_price * i.qty) + '</span></li>';
    }).join('') + (o.delivery_fee > 0 ? '<li><span>Teslimat</span><span>' + money(o.delivery_fee) + '</span></li>' : '');
    const next = NEXT[o.status];
    const opts = Object.keys(LABEL).map(function (k) {
      return '<option value="' + k + '"' + (k === o.status ? ' selected' : '') + '>' + LABEL[k] + '</option>';
    }).join('');
    const map = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(o.address);
    const wa = 'https://wa.me/' + o.wa + '?text=' + encodeURIComponent('Merhaba, ' + o.code + ' numaralı siparişiniz hakkında yazıyorum.');
    const cls = 'order' + (o.status === 'yeni' ? ' is-new' : '') + (o.status === 'yeni' && state.fresh.has(o.id) ? ' is-fresh' : '');
    return '<article class="' + cls + '" data-id="' + o.id + '">' +
      '<header class="o-head"><div><span class="o-code">' + esc(o.code) + '</span>' +
      '<span class="o-time"><span>' + esc(clock(o.created_at)) + '</span> · <span data-ts="' + o.created_at + '">' + ago(o.created_at) + '</span></span></div>' +
      '<span class="tag tag--' + o.status + '">' + LABEL[o.status] + '</span></header>' +
      '<div class="o-who"><strong>' + esc(o.name) + '</strong>' +
      '<a href="tel:+' + esc(o.wa) + '">' + esc(o.phone_fmt) + '</a>' +
      '<a class="lnk" href="' + esc(wa) + '" target="_blank" rel="noopener">WhatsApp</a></div>' +
      '<p class="o-addr">' + esc(o.address) + '<br><a href="' + esc(map) + '" target="_blank" rel="noopener">Haritada aç</a></p>' +
      (o.note ? '<p class="o-note"><strong>Not:</strong> ' + esc(o.note) + '</p>' : '') +
      '<ul class="o-items">' + items + '</ul>' +
      '<div class="o-pay"><span>' + esc(payText(o)) + '</span><strong>' + money(o.total) + '</strong></div>' +
      '<div class="o-act">' +
      (next ? '<button type="button" class="btn btn--primary" data-next="' + o.id + '">' + next[1] + '</button>' : '') +
      '<select data-status="' + o.id + '" aria-label="Durumu değiştir">' + opts + '</select>' +
      '<button type="button" class="btn btn--ghost btn--sm" data-print="' + o.id + '">Fiş yazdır</button>' +
      '</div></article>';
  }

  function renderOrders(force) {
    const act = state.orders.filter(function (o) { return ACTIVE.indexOf(o.status) >= 0; });
    const done = state.orders.filter(function (o) { return ACTIVE.indexOf(o.status) < 0; });
    $('#cnt-active').textContent = act.length;
    $('#cnt-done').textContent = done.length;

    const sig = state.filter + '|' + state.orders.map(function (o) { return o.id + o.status; }).join(',');
    const box = $('#orders');
    if (!force && sig === state.sig) {
      $$('[data-ts]', box).forEach(function (el) { el.textContent = ago(+el.dataset.ts); });
      return;
    }
    state.sig = sig;

    let list;
    if (state.filter === 'active') {
      list = act.slice().sort(function (a, b) { return (RANK[a.status] - RANK[b.status]) || (a.id - b.id); });
    } else {
      list = done.slice().sort(function (a, b) { return b.id - a.id; });
    }
    box.innerHTML = list.length
      ? list.map(orderCard).join('')
      : '<p class="empty">' + (state.filter === 'active' ? 'Şu an aktif sipariş yok. Yeni siparişler burada görünür.' : 'Son 3 günde tamamlanan sipariş yok.') + '</p>';
  }

  $$('[data-filter]').forEach(function (b) {
    b.addEventListener('click', function () {
      state.filter = b.dataset.filter;
      $$('[data-filter]').forEach(function (x) { x.classList.toggle('is-on', x === b); });
      renderOrders(true);
    });
  });

  function setStatus(id, status) {
    const o = state.orders.find(function (x) { return x.id === id; });
    if (!o || o.status === status) { return; }
    const prev = o.status;
    o.status = status;
    state.fresh.delete(id);
    renderOrders(true);
    updateBadge();
    post('order_status', { id: id, status: status }).catch(function (e) {
      o.status = prev;
      renderOrders(true);
      updateBadge();
      fail(e);
    });
  }

  $('#orders').addEventListener('click', function (e) {
    const b = e.target.closest('button');
    if (!b) { return; }
    if (b.dataset.next) {
      const o = state.orders.find(function (x) { return x.id === +b.dataset.next; });
      if (o && NEXT[o.status]) { setStatus(o.id, NEXT[o.status][0]); }
    } else if (b.dataset.print) {
      const o = state.orders.find(function (x) { return x.id === +b.dataset.print; });
      if (o) { printOrder(o); }
    }
  });
  $('#orders').addEventListener('change', function (e) {
    const sel = e.target.closest('select[data-status]');
    if (!sel) { return; }
    const id = +sel.dataset.status;
    if (sel.value === 'iptal' && !window.confirm('Bu siparişi iptal etmek istiyor musunuz? Müşteri iptal durumunu görür.')) {
      renderOrders(true);
      return;
    }
    setStatus(id, sel.value);
  });

  function printOrder(o) {
    const row = function (a, b, cls) { return '<div class="tr ' + (cls || '') + '"><span>' + a + '</span><span>' + b + '</span></div>'; };
    let h = '<h2>' + esc($('#shop-title').textContent) + '</h2>';
    h += '<p>Sipariş: <b>' + esc(o.code) + '</b></p><p>' + esc(new Date(o.created_at * 1000).toLocaleString('tr-TR')) + '</p><hr>';
    h += '<p class="big">' + esc(o.name) + '</p><p>' + esc(o.phone_fmt) + '</p><p>' + esc(o.address) + '</p>';
    if (o.note) { h += '<p>Not: ' + esc(o.note) + '</p>'; }
    h += '<hr>' + o.items.map(function (i) { return row(i.qty + 'x ' + esc(i.name), money(i.unit_price * i.qty)); }).join('') + '<hr>';
    if (o.delivery_fee > 0) { h += row('Teslimat', money(o.delivery_fee)); }
    h += row('TOPLAM', money(o.total), 'big') + '<p>' + esc(payText(o)) + '</p>';
    $('#ticket').innerHTML = h;
    window.print();
  }

  function updateBadge() {
    const n = state.orders.filter(function (o) { return o.status === 'yeni'; }).length;
    const b = $('#badge-new');
    b.textContent = n;
    b.hidden = n === 0;
    const shop = $('#shop-title').textContent;
    document.title = (n ? '(' + n + ') Yeni sipariş · ' : 'Yönetim · ') + shop;
  }

  let netFails = 0;
  function poll() {
    return get('orders').then(function (j) {
      netFails = 0;
      $('#netwarn').hidden = true;
      state.skew = j.now - Date.now() / 1000;
      const first = state.maxId === null;
      const arrivals = first ? [] : j.orders.filter(function (o) { return o.id > state.maxId; });
      const top = j.orders.reduce(function (m, o) { return Math.max(m, o.id); }, 0);
      state.maxId = Math.max(state.maxId || 0, top);
      state.orders = j.orders;
      arrivals.forEach(function (o) { state.fresh.add(o.id); });
      renderOrders(false);
      updateBadge();
      const waiting = j.orders.filter(function (o) { return o.status === 'yeni'; }).length;
      if (arrivals.length) {
        beep();
        state.lastBeep = Date.now();
        toast('Yeni sipariş: ' + arrivals[0].name + ' · ' + money(arrivals[0].total));
      } else if (waiting && Date.now() - state.lastBeep > 60000) {
        beep(); // cevaplanmayan sipariş için hatırlatma
        state.lastBeep = Date.now();
      }
    }).catch(function (e) {
      if (e && e.message === 'Oturum bitti') { return; }
      netFails += 1;
      if (netFails >= 2) { $('#netwarn').hidden = false; }
    });
  }

  /* ---------- Menü ve ayarlar ---------- */
  function loadCatalog() {
    return get('catalog').then(function (j) {
      state.cats = j.categories;
      state.prods = j.products;
      state.settings = j.settings;
      state.hasSample = j.has_sample;
      $('#shop-title').textContent = j.settings.shop_name;
      if (state.tab === 'menu') { renderMenu(); }
      if (state.tab === 'settings') { renderSettings(); }
    }).catch(fail);
  }

  function renderMenu() {
    const el = $('#tab-menu');
    const by = {};
    state.prods.forEach(function (p) { (by[p.category_id] = by[p.category_id] || []).push(p); });
    let h = '<div class="menu-top"><form id="cat-form"><input id="cat-name" type="text" maxlength="40" placeholder="Yeni kategori adı" aria-label="Yeni kategori adı" required>' +
      '<button class="btn btn--sm" type="submit">Kategori ekle</button></form></div>';
    if (state.hasSample) {
      h += '<div class="banner"><span>Menüdeki ürünler örnektir. Kendi ürünlerinizi ekledikten sonra örnekleri silin.</span>' +
        '<button type="button" class="btn btn--sm btn--ghost" data-act="sample-del">Örnekleri sil</button></div>';
    }
    if (!state.cats.length) {
      h += '<p class="empty">Henüz kategori yok. Yukarıdan bir kategori ekleyin, örneğin Ana Yemekler.</p>';
    }
    h += '<div class="mcats">' + state.cats.map(function (c) {
      const list = by[c.id] || [];
      return '<section class="mcat"><header class="mcat-head"><h3>' + esc(c.name) + '</h3><div class="row-btns">' +
        '<button type="button" class="ibtn" data-act="cat-up" data-id="' + c.id + '" aria-label="Kategoriyi yukarı taşı">↑</button>' +
        '<button type="button" class="ibtn" data-act="cat-down" data-id="' + c.id + '" aria-label="Kategoriyi aşağı taşı">↓</button>' +
        '<button type="button" class="ibtn" data-act="cat-rename" data-id="' + c.id + '">Adı değiştir</button>' +
        '<button type="button" class="ibtn ibtn--danger" data-act="cat-del" data-id="' + c.id + '">Sil</button></div></header>' +
        '<ul class="mlist">' + (list.length ? list.map(prodRow).join('') : '<li class="mprod"><span></span><span class="muted">Bu kategoride ürün yok.</span></li>') + '</ul>' +
        '<div class="mcat-foot"><button type="button" class="btn btn--ghost btn--sm" data-act="prod-new" data-cat="' + c.id + '">+ Ürün ekle</button></div></section>';
    }).join('') + '</div>';
    el.innerHTML = h;
  }

  function prodRow(p) {
    return '<li class="mprod' + (p.available ? '' : ' is-off') + '">' +
      (p.image_url ? '<img class="mthumb" src="' + esc(p.image_url) + '" alt="" loading="lazy">' : '<span class="mthumb mthumb--empty"></span>') +
      '<div><div class="mname">' + esc(p.name) + '</div><div class="mprice">' + money(p.price) + '</div></div>' +
      '<button type="button" class="switch switch--sm" role="switch" aria-checked="' + (p.available ? 'true' : 'false') + '" data-act="prod-toggle" data-id="' + p.id + '"><span>' + (p.available ? 'Satışta' : 'Tükendi') + '</span><i aria-hidden="true"></i></button>' +
      '<div class="mprod-act"><button type="button" class="ibtn" data-act="prod-edit" data-id="' + p.id + '">Düzenle</button>' +
      '<button type="button" class="ibtn" data-act="prod-up" data-id="' + p.id + '" aria-label="Yukarı taşı">↑</button>' +
      '<button type="button" class="ibtn" data-act="prod-down" data-id="' + p.id + '" aria-label="Aşağı taşı">↓</button></div></li>';
  }

  function act(promise, okMsg) {
    return promise.then(function () { if (okMsg) { toast(okMsg); } return loadCatalog(); }).catch(fail);
  }

  $('#tab-menu').addEventListener('click', function (e) {
    const b = e.target.closest('[data-act]');
    if (!b) { return; }
    const id = +b.dataset.id;
    const cat = state.cats.find(function (c) { return c.id === id; });
    const prod = state.prods.find(function (p) { return p.id === id; });
    switch (b.dataset.act) {
      case 'cat-up': act(post('category_move', { id: id, dir: -1 })); break;
      case 'cat-down': act(post('category_move', { id: id, dir: 1 })); break;
      case 'cat-rename': {
        const n = window.prompt('Kategori adı', cat ? cat.name : '');
        if (n && n.trim()) { act(post('category_save', { id: id, name: n.trim() }), 'Kategori güncellendi.'); }
        break;
      }
      case 'cat-del': {
        const n = state.prods.filter(function (p) { return p.category_id === id; }).length;
        if (window.confirm('"' + (cat ? cat.name : '') + '" kategorisi ve içindeki ' + n + ' ürün silinecek. Emin misiniz?')) {
          act(post('category_delete', { id: id }), 'Kategori silindi.');
        }
        break;
      }
      case 'prod-new': openProduct(null, +b.dataset.cat); break;
      case 'prod-edit': openProduct(id, null); break;
      case 'prod-up': act(post('product_move', { id: id, dir: -1 })); break;
      case 'prod-down': act(post('product_move', { id: id, dir: 1 })); break;
      case 'prod-toggle':
        if (prod) { act(post('product_toggle', { id: id, available: !prod.available }), prod.available ? 'Tükendi olarak işaretlendi.' : 'Tekrar satışta.'); }
        break;
      case 'sample-del':
        if (window.confirm('Örnek ürünlerin hepsi silinecek. Kendi eklediğiniz ürünlere dokunulmaz. Devam edilsin mi?')) {
          act(post('sample_delete'), 'Örnek ürünler silindi.');
        }
        break;
      default: break;
    }
  });
  $('#tab-menu').addEventListener('submit', function (e) {
    if (e.target.id !== 'cat-form') { return; }
    e.preventDefault();
    const name = $('#cat-name').value.trim();
    if (name) { act(post('category_save', { name: name }), 'Kategori eklendi.'); }
  });

  /* ---------- Ürün penceresi ---------- */
  const dlg = $('#product-dialog');
  function closeDlg() {
    if (typeof dlg.close === 'function') { dlg.close(); } else { dlg.removeAttribute('open'); }
  }
  function openProduct(id, catId) {
    const p = id ? state.prods.find(function (x) { return x.id === id; }) : null;
    const cid = p ? p.category_id : catId;
    const catOpts = state.cats.map(function (c) {
      return '<option value="' + c.id + '"' + (c.id === cid ? ' selected' : '') + '>' + esc(c.name) + '</option>';
    }).join('');
    dlg.innerHTML =
      '<div class="dlg-head"><h2 id="pd-title">' + (p ? 'Ürünü düzenle' : 'Yeni ürün') + '</h2><button type="button" class="ibtn" data-dclose>Kapat</button></div>' +
      '<form id="prod-form" novalidate><p class="err" id="pd-err" role="alert" hidden></p>' +
      '<div class="field"><label for="pd-name">Ürün adı</label><input id="pd-name" name="name" type="text" maxlength="80" required value="' + esc(p ? p.name : '') + '"></div>' +
      '<div class="field"><label for="pd-cat">Kategori</label><select id="pd-cat" name="category_id">' + catOpts + '</select></div>' +
      '<div class="two"><div class="field"><label for="pd-price">Fiyat (₺)</label><input id="pd-price" name="price" type="text" inputmode="decimal" required placeholder="185" value="' + esc(p ? tlInput(p.price) : '') + '"></div>' +
      '<div class="field"><span class="lbl">Satış durumu</span><label class="check" style="min-height:46px"><input type="checkbox" name="available" value="1"' + (!p || p.available ? ' checked' : '') + '> Satışta</label></div></div>' +
      '<div class="field"><label for="pd-desc">Açıklama <span class="opt">isteğe bağlı</span></label><textarea id="pd-desc" name="description" maxlength="200" placeholder="Örn. Yoğurt ve sos ile servis edilir">' + esc(p ? p.description : '') + '</textarea></div>' +
      '<div class="field"><label for="pd-img">Fotoğraf <span class="opt">isteğe bağlı, JPG/PNG/WebP, en fazla 4 MB</span></label>' +
      '<div class="pimg">' + (p && p.image_url ? '<img id="pd-prev" src="' + esc(p.image_url) + '" alt="">' : '<img id="pd-prev" alt="" hidden>') +
      '<input id="pd-img" name="image" type="file" accept="image/jpeg,image/png,image/webp"></div>' +
      (p && p.image_url ? '<label class="check" style="font-weight:500;margin-top:6px"><input type="checkbox" name="remove_image" value="1"> Mevcut fotoğrafı kaldır</label>' : '') + '</div>' +
      '<div class="dlg-foot">' + (p ? '<button type="button" class="btn btn--danger" data-pdel="' + p.id + '">Ürünü sil</button>' : '<span></span>') +
      '<button type="submit" class="btn btn--primary" id="pd-save">Kaydet</button></div></form>';
    dlg.dataset.id = p ? String(p.id) : '';
    if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
    $('#pd-name').focus();
  }

  dlg.addEventListener('click', function (e) {
    if (e.target === dlg || e.target.closest('[data-dclose]')) { closeDlg(); return; }
    const del = e.target.closest('[data-pdel]');
    if (del && window.confirm('Bu ürün menüden silinecek. Geçmiş siparişler etkilenmez. Emin misiniz?')) {
      post('product_delete', { id: +del.dataset.pdel }).then(function () {
        closeDlg();
        toast('Ürün silindi.');
        return loadCatalog();
      }).catch(fail);
    }
  });
  dlg.addEventListener('change', function (e) {
    if (e.target.id !== 'pd-img') { return; }
    const f = e.target.files && e.target.files[0];
    const prev = $('#pd-prev');
    if (f && prev) { prev.src = URL.createObjectURL(f); prev.hidden = false; }
  });
  dlg.addEventListener('submit', function (e) {
    if (e.target.id !== 'prod-form') { return; }
    e.preventDefault();
    const form = e.target;
    const err = $('#pd-err');
    err.hidden = true;
    const fd = new FormData(form);
    if (!fd.get('available')) { fd.set('available', '0'); }
    if (dlg.dataset.id) { fd.set('id', dlg.dataset.id); }
    const save = $('#pd-save');
    save.disabled = true;
    save.textContent = 'Kaydediliyor…';
    postForm('product_save', fd).then(function () {
      closeDlg();
      toast('Ürün kaydedildi.');
      return loadCatalog();
    }).catch(function (e2) {
      err.textContent = e2.message;
      err.hidden = false;
      save.disabled = false;
      save.textContent = 'Kaydet';
    });
  });

  /* ---------- Ayarlar ---------- */
  function renderSettings() {
    const s = state.settings;
    const link = new URL('../', window.location.href).href;
    $('#tab-settings').innerHTML =
      '<div class="settings">' +
      '<div class="card"><h2>Müşteri linki</h2><p class="muted" style="margin-top:0">Bu bağlantıyı Instagram profilinizdeki web sitesi alanına yapıştırın.</p>' +
      '<div class="linkbox"><input id="share-link" type="text" readonly value="' + esc(link) + '" aria-label="Müşteri linki"><button type="button" class="btn btn--sm" id="copy-link">Kopyala</button></div>' +
      '<p class="muted" style="margin-bottom:0"><a href="' + esc(link) + '" target="_blank" rel="noopener">Müşteri sayfasını yeni sekmede aç</a></p></div>' +

      '<form class="card" id="settings-form" novalidate><h2>İşletme bilgileri</h2><p class="err" id="st-err" role="alert" hidden></p>' +
      '<div class="field"><label for="s-name">İşletme adı</label><input id="s-name" name="shop_name" type="text" maxlength="60" required value="' + esc(s.shop_name) + '"></div>' +
      '<div class="field"><label for="s-tag">Alt başlık <span class="opt">isteğe bağlı</span></label><input id="s-tag" name="tagline" type="text" maxlength="100" value="' + esc(s.tagline) + '"></div>' +
      '<div class="field"><label for="s-phone">Telefon <span class="opt">müşteri sizi buradan arar ve WhatsApp\'tan yazar</span></label><input id="s-phone" name="phone" type="tel" maxlength="20" placeholder="0532 123 45 67" value="' + esc(s.phone) + '"></div>' +
      '<div class="two"><div class="field"><label for="s-min">Minimum sipariş (₺)</label><input id="s-min" name="min_order" type="text" inputmode="decimal" required value="' + esc(tlInput(s.min_order)) + '"></div>' +
      '<div class="field"><label for="s-fee">Teslimat ücreti (₺)</label><input id="s-fee" name="delivery_fee" type="text" inputmode="decimal" value="' + esc(tlInput(s.delivery_fee)) + '"><small>Ücretsiz için 0 yazın.</small></div></div>' +
      '<div class="field"><label for="s-eta">Hazırlık süresi</label><input id="s-eta" name="eta_text" type="text" maxlength="30" placeholder="30-45 dk" value="' + esc(s.eta_text) + '"></div>' +
      '<div class="field"><label for="s-note">Teslimat bölgesi notu <span class="opt">isteğe bağlı</span></label><input id="s-note" name="delivery_note" type="text" maxlength="200" placeholder="Örn. Yalnızca Merkez ve Yıldız mahallelerine teslimat" value="' + esc(s.delivery_note) + '"></div>' +
      '<div class="field"><label for="s-closed">Kapalıyken gösterilen mesaj</label><textarea id="s-closed" name="closed_message" maxlength="200">' + esc(s.closed_message) + '</textarea></div>' +
      '<button type="submit" class="btn btn--primary btn--block">Ayarları kaydet</button></form>' +

      '<form class="card" id="pw-form" novalidate><h2>Şifre değiştir</h2><p class="err" id="pw-err" role="alert" hidden></p>' +
      '<div class="field"><label for="pw-cur">Mevcut şifre</label><input id="pw-cur" name="current" type="password" autocomplete="current-password" required></div>' +
      '<div class="field"><label for="pw-new">Yeni şifre <span class="opt">en az 8 karakter</span></label><input id="pw-new" name="new" type="password" autocomplete="new-password" required></div>' +
      '<button type="submit" class="btn btn--block">Şifreyi değiştir</button></form></div>';
  }

  $('#tab-settings').addEventListener('click', function (e) {
    if (e.target.id !== 'copy-link') { return; }
    const input = $('#share-link');
    const done = function () { toast('Bağlantı kopyalandı.'); };
    const manual = function () { input.select(); toast('Bağlantı seçildi, kopyalayabilirsiniz.'); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(input.value).then(done).catch(manual); } else { manual(); }
  });
  $('#tab-settings').addEventListener('submit', function (e) {
    e.preventDefault();
    const f = e.target;
    const data = {};
    new FormData(f).forEach(function (v, k) { data[k] = v; });
    if (f.id === 'settings-form') {
      const err = $('#st-err');
      err.hidden = true;
      post('settings_save', data).then(function () {
        toast('Ayarlar kaydedildi.');
        return loadCatalog();
      }).catch(function (e2) { err.textContent = e2.message; err.hidden = false; });
    } else if (f.id === 'pw-form') {
      const err = $('#pw-err');
      err.hidden = true;
      post('password_change', data).then(function () {
        f.reset();
        toast('Şifre değiştirildi.');
      }).catch(function (e2) { err.textContent = e2.message; err.hidden = false; });
    }
  });

  /* ---------- Başlangıç ---------- */
  poll();
  setInterval(poll, 8000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
})();
