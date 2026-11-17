// Müşteri sayfası: sepet, minimum tutar kontrolü ve sipariş gönderme.
// Fiyatlar burada yalnızca gösterim içindir; asıl hesabı sunucu yeniden yapar.
(function () {
  'use strict';

  const D = JSON.parse(document.getElementById('menu-data').textContent);
  const S = D.settings;
  const P = {};
  D.products.forEach(function (p) { P[p.id] = p; });

  const $ = function (s, r) { return (r || document).querySelector(s); };
  const $$ = function (s, r) { return Array.from((r || document).querySelectorAll(s)); };
  const nf0 = new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 0 });
  const nf2 = new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const money = function (k) { return (k % 100 === 0 ? nf0.format(k / 100) : nf2.format(k / 100)) + ' ₺'; };
  const esc = function (s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };

  // Tarayıcı depolaması kapalı olabilir; hata vermeden devam eder.
  const store = {
    get: function (k) { try { return JSON.parse(localStorage.getItem(k)); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* yok say */ } },
  };

  /* ---------- Sepet ---------- */
  let cart = {};
  (function loadCart() {
    const saved = store.get('siparis_sepet_v1') || {};
    Object.keys(saved).forEach(function (id) {
      const p = P[id];
      const q = parseInt(saved[id], 10);
      if (p && p.available && q > 0) { cart[id] = Math.min(20, q); }
    });
  })();
  const saveCart = function () { store.set('siparis_sepet_v1', cart); };
  const count = function () { return Object.keys(cart).reduce(function (a, id) { return a + cart[id]; }, 0); };
  const subtotal = function () { return Object.keys(cart).reduce(function (a, id) { return a + P[id].price * cart[id]; }, 0); };

  function setQty(id, q) {
    q = Math.max(0, Math.min(20, q));
    if (q === 0) { delete cart[id]; } else { cart[id] = q; }
    saveCart();
    renderCtl(id);
    renderBar();
    if (sheet.open) { renderSlip(); }
  }

  function stepper(id, q, small) {
    return '<div class="stepper' + (small ? ' stepper--sm' : '') + '" role="group" aria-label="' + esc(P[id].name) + ' adedi">' +
      '<button type="button" data-dec="' + id + '" aria-label="Azalt">−</button>' +
      '<output>' + q + '</output>' +
      '<button type="button" data-inc="' + id + '" aria-label="Artır">+</button></div>';
  }

  function renderCtl(id) {
    const box = $('[data-ctl="' + id + '"]');
    const p = P[id];
    if (!box || !p || !p.available || !S.is_open) { return; }
    const q = cart[id] || 0;
    box.innerHTML = q
      ? stepper(id, q, false)
      : '<button type="button" class="add" data-add="' + id + '"><span aria-hidden="true">+</span> Ekle<span class="sr"> ' + esc(p.name) + '</span></button>';
  }

  function renderBar() {
    const n = count();
    const bar = $('#bar');
    const show = n > 0 && S.is_open;
    bar.hidden = !show;
    document.body.classList.toggle('has-cart', show);
    if (!show) { return; }
    const sub = subtotal();
    const min = S.min_order;
    const ok = sub >= min;
    $('#bar-count').textContent = n + ' ürün';
    $('#bar-sum').textContent = money(sub);
    $('#bar-meter').classList.toggle('is-ok', ok);
    $('#bar-meter i').style.width = Math.min(100, min ? (sub / min) * 100 : 100) + '%';
    const note = $('#bar-note');
    note.classList.toggle('is-ok', ok);
    note.textContent = ok ? 'Minimum tutara ulaştınız' : money(min) + ' için ' + money(min - sub) + ' daha ekleyin';
    $('.bar-go').textContent = ok ? 'Sepeti onayla' : 'Sepeti aç';
  }

  /* ---------- Sepet sayfası (adisyon) ---------- */
  const sheet = $('#sheet');
  const form = $('#order-form');
  let sending = false;

  function renderSlip() {
    const slip = $('#slip');
    const ids = D.products.map(function (p) { return String(p.id); }).filter(function (id) { return cart[id]; });
    if (!ids.length) {
      slip.innerHTML = '<p class="slip-title">Sipariş özeti</p><p class="slip-empty">Sepetiniz boş. Menüden ürün ekleyin.</p>';
      updateSubmit();
      return;
    }
    let html = '<p class="slip-title">' + esc(S.shop_name) + ' · Sipariş özeti</p>';
    ids.forEach(function (id) {
      const p = P[id];
      const q = cart[id];
      html += '<div class="ln"><span class="ln-name">' + esc(p.name) + '</span>' +
        '<span class="ln-total">' + money(p.price * q) + '</span>' +
        stepper(id, q, true) +
        '<span class="ln-unit">' + q + ' × ' + money(p.price) + '</span></div>';
    });
    const sub = subtotal();
    const fee = S.delivery_fee;
    html += '<div class="sum"><span>Ara toplam</span><span class="dots"></span><span>' + money(sub) + '</span></div>';
    html += '<div class="sum"><span>Teslimat</span><span class="dots"></span><span>' + (fee ? money(fee) : 'Ücretsiz') + '</span></div>';
    html += '<div class="sum sum--grand"><span>Toplam</span><span class="dots"></span><strong>' + money(sub + fee) + '</strong></div>';
    slip.innerHTML = html;
    updateSubmit();
  }

  function updateSubmit() {
    const go = $('#go');
    if (sending) { return; }
    const sub = subtotal();
    const min = S.min_order;
    if (!count()) {
      go.disabled = true;
      go.textContent = 'Sepet boş';
    } else if (sub < min) {
      go.disabled = true;
      go.textContent = money(min - sub) + ' daha ekleyin (min. ' + money(min) + ')';
    } else {
      go.disabled = false;
      go.textContent = 'Siparişi gönder · ' + money(sub + S.delivery_fee);
    }
  }

  function openSheet() {
    renderSlip();
    if (typeof sheet.showModal === 'function') { sheet.showModal(); } else { sheet.setAttribute('open', ''); }
  }
  function closeSheet() {
    if (typeof sheet.close === 'function') { sheet.close(); } else { sheet.removeAttribute('open'); }
  }

  /* ---------- Tıklamalar ---------- */
  function refocus(id, inSlip) {
    const el = inSlip
      ? $('#slip [data-inc="' + id + '"]')
      : $('[data-ctl="' + id + '"] ' + (cart[id] ? '[data-inc]' : '[data-add]'));
    if (el) { el.focus(); }
  }

  document.addEventListener('click', function (e) {
    const t = e.target.closest('button');
    if (!t) { return; }
    const inSlip = !!t.closest('#slip');
    let id = t.dataset.add || t.dataset.inc || t.dataset.dec;
    if (t.dataset.add) { setQty(id, 1); refocus(id, inSlip); return; }
    if (t.dataset.inc) { setQty(id, (cart[id] || 0) + 1); refocus(id, inSlip); return; }
    if (t.dataset.dec) { setQty(id, (cart[id] || 0) - 1); refocus(id, inSlip); return; }
    if (t.hasAttribute('data-open-cart')) { openSheet(); return; }
    if (t.hasAttribute('data-close')) { closeSheet(); }
  });
  sheet.addEventListener('click', function (e) { if (e.target === sheet) { closeSheet(); } });

  /* ---------- Kategori çubuğu ---------- */
  const links = $$('.cats a');
  const row = $('.cats-row');
  function setActive(id) {
    let active = null;
    links.forEach(function (a) {
      const on = a.dataset.cat === id;
      a.classList.toggle('is-active', on);
      if (on) { active = a; }
    });
    if (active && row) {
      row.scrollTo({ left: active.offsetLeft - row.clientWidth / 2 + active.clientWidth / 2, behavior: 'smooth' });
    }
  }
  if ('IntersectionObserver' in window && links.length) {
    const io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { setActive(en.target.id); } });
    }, { rootMargin: '-80px 0px -65% 0px' });
    links.forEach(function (a) { const s = document.getElementById(a.dataset.cat); if (s) { io.observe(s); } });
  }

  /* ---------- Form ---------- */
  const info = store.get('siparis_bilgi_v1') || {};
  ['name', 'phone', 'address'].forEach(function (k) {
    if (info[k]) { form.elements[k].value = info[k]; }
  });

  const changeField = $('#change-field');
  function syncPayment() {
    changeField.hidden = form.elements.payment.value !== 'nakit';
  }
  form.addEventListener('change', function (e) { if (e.target.name === 'payment') { syncPayment(); } });
  syncPayment();

  const errBox = $('#form-error');
  function clearErrors() {
    errBox.hidden = true;
    errBox.textContent = '';
    $$('.is-bad', form).forEach(function (el) { el.classList.remove('is-bad'); });
  }
  function showError(msg, field) {
    errBox.textContent = msg;
    errBox.hidden = false;
    const el = field ? form.elements[field] : null;
    if (el && el.classList) { el.classList.add('is-bad'); el.focus(); } else { errBox.scrollIntoView({ block: 'nearest' }); }
  }

  function markGone(ids) {
    ids.forEach(function (id) {
      if (P[id]) { P[id].available = false; }
      delete cart[id];
      const box = $('[data-ctl="' + id + '"]');
      if (box) { box.innerHTML = '<span class="out-tag">Tükendi</span>'; }
      const item = document.getElementById('p' + id);
      if (item) { item.classList.add('is-out'); }
    });
    saveCart();
    renderBar();
    renderSlip();
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (sending) { return; }
    clearErrors();

    const fd = new FormData(form);
    const body = {
      name: String(fd.get('name') || '').trim(),
      phone: String(fd.get('phone') || '').trim(),
      address: String(fd.get('address') || '').trim(),
      note: String(fd.get('note') || '').trim(),
      payment: String(fd.get('payment') || ''),
      change_for: String(fd.get('change_for') || '').trim(),
      website: String(fd.get('website') || ''),
      items: Object.keys(cart).map(function (id) { return { id: +id, qty: cart[id] }; }),
    };

    if (subtotal() < S.min_order) { showError('Minimum sipariş tutarı ' + money(S.min_order) + '.'); return; }
    if (body.name.length < 2) { showError('Lütfen adınızı ve soyadınızı yazın.', 'name'); return; }
    if (body.phone.replace(/\D/g, '').length < 10) { showError('Telefon numarası eksik. Örnek: 0532 123 45 67', 'phone'); return; }
    if (body.address.length < 10) { showError('Adres en az 10 karakter olmalı. Mahalle, sokak ve kapı numarasını yazın.', 'address'); return; }

    sending = true;
    const go = $('#go');
    go.disabled = true;
    go.textContent = 'Gönderiliyor…';

    const ctl = new AbortController();
    const timer = setTimeout(function () { ctl.abort(); }, 20000);
    fetch('api.php?action=order', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      signal: ctl.signal,
    })
      .then(function (res) { return res.json().catch(function () { return null; }).then(function (j) { return { res: res, j: j }; }); })
      .then(function (r) {
        clearTimeout(timer);
        const j = r.j;
        if (r.res.ok && j && j.ok) {
          store.set('siparis_bilgi_v1', { name: body.name, phone: body.phone, address: body.address });
          store.set('siparis_son_v1', { code: j.code, ts: Date.now() });
          cart = {};
          saveCart();
          window.location.href = 'siparis.php?k=' + encodeURIComponent(j.code);
          return;
        }
        sending = false;
        const msg = (j && j.error) || 'Sipariş gönderilemedi. Lütfen tekrar deneyin.';
        if (j && j.unavailable) { markGone(j.unavailable); }
        if (j && j.closed) { setTimeout(function () { window.location.reload(); }, 2500); }
        showError(msg, j && j.field);
        updateSubmit();
      })
      .catch(function () {
        clearTimeout(timer);
        sending = false;
        showError('Bağlantı kurulamadı. İnternetinizi kontrol edip tekrar deneyin.');
        updateSubmit();
      });
  });

  /* ---------- Son sipariş bağlantısı ---------- */
  const last = store.get('siparis_son_v1');
  if (last && last.code && Date.now() - last.ts < 6 * 3600 * 1000) {
    $('#last-order-link').href = 'siparis.php?k=' + encodeURIComponent(last.code);
    $('#last-order').hidden = false;
  }

  /* ---------- Başlangıç ---------- */
  Object.keys(cart).forEach(renderCtl);
  renderBar();
})();
