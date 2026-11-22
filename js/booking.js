// Randevu sihirbazı
(() => {
  const root = document.getElementById('wizard');
  if (!root) return;
  const services = JSON.parse(root.dataset.services);
  const events = JSON.parse(root.dataset.events);
  const body = root.querySelector('.wizard-body');
  const bar = [...root.querySelectorAll('.steps-bar li')];
  const back = root.querySelector('[data-back]'), next = root.querySelector('[data-next]');
  const LANG = document.documentElement.lang || 'tr';
  const LOC = { tr: 'tr-TR', en: 'en-GB', ru: 'ru-RU', de: 'de-DE' }[LANG] || 'tr-TR';
  const T = window.T || (s => s);
  const P = u => LANG === 'tr' || !u.startsWith('/') ? u : `/${LANG}${u}`; // dil önekini korur
  const tl = n => n ? '₺' + Number(n).toLocaleString(LOC) : 'Ücretsiz';
  const U = { tr: ['gün', 'saat', 'sa', 'dk'], en: ['days', 'hours', 'h', 'min'], ru: ['дн.', 'ч', 'ч', 'мин'], de: ['Tage', 'Std.', 'Std.', 'Min.'] }[LANG] || ['gün', 'saat', 'sa', 'dk'];
  const dur = m => m >= 1440 ? `${Math.round(m / 1440)} ${U[0]}` : m >= 60 ? (m % 60 ? `${Math.floor(m / 60)} ${U[2]} ${m % 60} ${U[3]}` : `${m / 60} ${U[1]}`) : `${m} ${U[3]}`;
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const cap = s => s.charAt(0).toLocaleUpperCase(LOC) + s.slice(1);
  // Ay ve gün adları seçili dilde (Rusçada "23 ноября", takvim başlığında "Ноябрь")
  const MONTHS = [...Array(12)].map((_, i) => new Date(2026, i, 15).toLocaleDateString(LOC, { day: 'numeric', month: 'long' }).replace(/[\d.\s]/g, ' ').trim());
  const MONTHS_TITLE = [...Array(12)].map((_, i) => cap(new Date(2026, i, 15).toLocaleDateString(LOC, { month: 'long' })));
  const DAYS = [...Array(7)].map((_, i) => cap(new Date(2026, 0, 4 + i).toLocaleDateString(LOC, { weekday: 'long' })));
  const DOW = LANG === 'tr' ? ['Pt', 'Sa', 'Ça', 'Pe', 'Cu', 'Ct', 'Pz'] : [...Array(7)].map((_, i) => cap(new Date(2026, 0, 5 + i).toLocaleDateString(LOC, { weekday: 'short' })).replace('.', ''));

  const st = { step: 0, kind: null, item: null, mode: null, date: null, time: null, month: null, days: {}, form: {} };
  const preS = root.dataset.preService, preE = root.dataset.preEvent;
  if (preE && events.find(e => e.id === preE)) { st.kind = 'event'; st.item = events.find(e => e.id === preE); }
  else if (preS && services.find(s => s.id === preS)) { st.kind = 'service'; st.item = services.find(s => s.id === preS); }

  const isEvent = () => st.kind === 'event';
  const PAY = root.dataset.pay === 'online'; // sitede ödeme yoksa bilgiler WhatsApp mesajı olarak açılır
  const goLabel = () => !PAY ? 'WhatsApp’tan gönder' : st.item.price ? `${tl(st.item.price)} öde` : 'Kaydı tamamla';
  const needMode = () => !isEvent() && st.item && st.item.modes.length > 1;
  const steps = () => [0, needMode() ? 1 : null, isEvent() ? null : 2, 3, 4].filter(x => x !== null);
  const whenText = () => isEvent() ? `${st.item.when.long}, ${st.item.when.hm}` : st.date ? `${fmtDay(st.date)}, ${st.time}${away && st.time ? ` (${T('Sizin saatinizle')}: ${localHM(st.date, st.time)})` : ''}` : '';
  // Türkiye dışındaki ziyaretçiye saati kendi saat diliminde de göster
  const TZ = (() => { try { return Intl.DateTimeFormat().resolvedOptions().timeZone; } catch { return 'Europe/Istanbul'; } })();
  const away = !['Europe/Istanbul', 'Asia/Istanbul'].includes(TZ) && new Date().getTimezoneOffset() !== -180;
  const localHM = (key, hm) => new Date(`${key}T${hm}:00+03:00`).toLocaleString(LOC, { hour: '2-digit', minute: '2-digit', day: 'numeric', month: 'short' });
  function fmtDay(key) { return cap(new Date(key + 'T12:00:00').toLocaleDateString(LOC, { day: 'numeric', month: 'long', weekday: 'long' })); }

  function setNext(label) { // WhatsApp düğmesinde simge de olsun
    next.innerHTML = (!PAY && label === goLabel() && st.step === 4 ? root.dataset.waIcon + ' ' : '') + esc(label);
  }
  function render() {
    const s = st.step;
    bar.forEach((li, i) => { li.className = i < s ? 'done' : i === s ? 'current' : ''; li.hidden = !steps().includes(i); });
    back.style.visibility = s === 0 ? 'hidden' : 'visible';
    body.innerHTML = '';
    const view = document.createElement('div');
    if (s === 0) view.innerHTML = stepItem();
    if (s === 1) view.innerHTML = stepMode();
    if (s === 2) { view.innerHTML = stepDate(); }
    if (s === 3) view.innerHTML = stepInfo();
    if (s === 4) view.innerHTML = stepSummary();
    body.appendChild(view);
    if (s === 2) initCalendar();
    if (s === 3) bindForm();
    setNext(s === 4 ? goLabel() : 'Devam et');
    next.className = s === 4 ? (PAY ? 'btn btn-gold btn-lg' : 'btn btn-wa btn-lg') : 'btn btn-violet';
    validate();
  }

  function stepItem() {
    const modeTxt = m => m === 'online' ? 'Online' : 'Yüz yüze';
    const svc = services.map(x => `<div class="choice choice-row"><input type="radio" name="item" id="s-${x.id}" value="s:${x.id}" ${st.kind === 'service' && st.item.id === x.id ? 'checked' : ''}><label for="s-${x.id}"><span class="tick" aria-hidden="true"></span><span class="c-main"><b>${esc(x.name)}</b><small>${esc(x.short)}</small><span class="chips"><span>${dur(x.duration)}</span>${x.modes.map(m => `<span>${modeTxt(m)}</span>`).join('')}</span></span></label></div>`).join('');
    const ev = events.map(x => `<div class="choice choice-row choice-ev"><input type="radio" name="item" id="e-${x.id}" value="e:${x.id}" ${st.kind === 'event' && st.item.id === x.id ? 'checked' : ''}><label for="e-${x.id}"><span class="date-badge" aria-hidden="true"><strong>${x.when.day}</strong>${x.when.month.slice(0, 3)}</span><span class="c-main"><b>${esc(x.title)}</b><small>${x.when.dow}, ${x.when.hm} · ${esc(x.location)}</small></span><span class="chips chips-end">${x.price ? '' : '<span class="chip-free">Ücretsiz</span>'}<span>${x.seats} yer kaldı</span></span><span class="tick" aria-hidden="true"></span></label></div>`).join('');
    return `<h2>Hangi çalışma için kayıt oluyorsun?</h2>
      <p class="group-label">Bireysel seanslar</p><div class="choice-grid cg-2">${svc}</div>
      ${events.length ? `<p class="group-label">Yaklaşan etkinlikler</p><div class="choice-list">${ev}</div>` : ''}`;
  }
  function stepMode() {
    const opt = (v, t, d) => `<div class="choice"><input type="radio" name="mode" id="m-${v}" value="${v}" ${st.mode === v ? 'checked' : ''}><label for="m-${v}"><b>${t}</b><small>${d}</small></label></div>`;
    return `<h2>Nasıl görüşmek istersin?</h2><div class="choice-grid">
      ${st.item.modes.includes('online') ? opt('online', 'Online', 'Sitedeki görüşme odasından; uygulama indirmeden bilgisayar, tablet veya telefonla.') : ''}
      ${st.item.modes.includes('yuzyuze') ? opt('yuzyuze', 'Yüz yüze', 'Sarıyer, İstanbul. Adres onay mesajında paylaşılır.') : ''}</div>`;
  }
  function stepDate() {
    return `<h2>Sana uygun gün ve saati seç</h2><p class="muted">Saatler Türkiye saatine göredir. Dolu saatler gösterilmez.</p>
      <div class="cal-wrap"><div><div class="cal-head"><button type="button" data-m="-1" aria-label="Önceki ay">‹</button><b data-title></b><button type="button" data-m="1" aria-label="Sonraki ay">›</button></div><div class="cal" data-cal></div></div>
      <div><p class="slot-title" data-slot-title>Önce bir gün seç</p><p class="muted small" data-tz-note hidden>Küçük rakamlar sizin saatinizdir.</p><div class="slots" data-slots></div></div></div>`;
  }
  function stepInfo() {
    const f = st.form;
    return `<h2>Bilgilerin</h2><form class="form" data-form novalidate>
      <div class="row"><label>Ad soyad<input name="name" autocomplete="name" value="${esc(f.name)}" required></label><label>Telefon (WhatsApp)<input name="phone" type="tel" autocomplete="tel" placeholder="05xx xxx xx xx" value="${esc(f.phone)}" required></label></div>
      <label>E-posta<input name="email" type="email" autocomplete="email" value="${esc(f.email)}" required></label>
      <label>Konunu kısaca yazabilirsin <span class="muted">(isteğe bağlı)</span><textarea name="note" rows="3" placeholder="Örn. annemle ilişkim ve tekrar eden ilişki döngülerim">${esc(f.note)}</textarea></label>
      <label class="check"><input type="checkbox" name="kvkk" ${f.kvkk ? 'checked' : ''}><span>Kişisel verilerimin kaydın yürütülmesi için işlenmesini onaylıyorum. <a href="${P('/yasal')}#kvkk" target="_blank">KVKK aydınlatma metni</a></span></label>
      <label class="check"><input type="checkbox" name="marketing" ${f.marketing ? 'checked' : ''}><span>Yeni etkinlik ve içeriklerden e-posta ve WhatsApp ile haberdar olmak istiyorum.</span></label>
      <p class="form-error" data-err hidden></p></form>`;
  }
  function stepSummary() {
    const modeText = isEvent() ? (st.item.type === 'online' ? 'Online' : st.item.location) : st.mode === 'online' ? 'Online · görüşme odası' : 'Yüz yüze · Sarıyer';
    return `<h2>Özet</h2><div class="split"><div class="sum-box"><dl class="summary">
      <dt>Çalışma</dt><dd>${esc(st.item.name || st.item.title)}</dd>
      <dt>Zaman</dt><dd>${whenText()}</dd><dt>Süre</dt><dd>${dur(st.item.duration)}</dd>
      <dt>Şekil</dt><dd>${esc(modeText)}</dd><dt>Ad soyad</dt><dd>${esc(st.form.name)}</dd>
      ${PAY ? `<dt>Tutar</dt><dd class="total">${st.item.priceLabel ? st.item.priceLabel + ' ' : ''}${tl(st.item.price)}</dd>` : ''}</dl></div>
      <div>${PAY ? `<label class="check"><input type="checkbox" data-agree><span>Okudum ve kabul ediyorum: <a href="${P('/yasal')}#onbilgi" target="_blank">Ön bilgilendirme formu</a>, <a href="${P('/yasal')}#mesafeli" target="_blank">mesafeli satış sözleşmesi</a>, <a href="${P('/yasal')}#iade" target="_blank">iptal-iade koşulları</a>.</span></label>` : `<p class="wa-note">Gönder’e bastığında WhatsApp açılır ve bilgilerin mesaja hazır yazılmış olur. Mesajı göndermen yeterli; onay için sana döneceğiz.</p>`}
      ${PAY ? `<div class="trust"><span>3D Secure</span><span>256-bit SSL</span><span>Visa · Mastercard · Troy</span><span>Taksit seçenekleri</span></div>
      <p class="small" style="margin-top:1rem">Ödemeyi onayladığında güvenli ödeme sayfasına yönlendirileceksin. Kart bilgilerin bu sitede saklanmaz.</p>` : ''}
      <p class="form-error" data-err hidden></p></div></div>`;
  }

  async function initCalendar() {
    const cal = body.querySelector('[data-cal]'), title = body.querySelector('[data-title]');
    const today = new Date(); if (!st.month) st.month = new Date(today.getFullYear(), today.getMonth(), 1);
    if (!st.days[st.item.id]) st.days[st.item.id] = (await (await fetch(`/api/days?service=${st.item.id}`)).json()).days;
    const avail = st.days[st.item.id];
    const draw = () => {
      title.textContent = `${MONTHS_TITLE[st.month.getMonth()]} ${st.month.getFullYear()}`;
      const first = (st.month.getDay() + 6) % 7, n = new Date(st.month.getFullYear(), st.month.getMonth() + 1, 0).getDate();
      let h = DOW.map(d => `<span class="dow">${d}</span>`).join('') + '<span></span>'.repeat(first);
      for (let d = 1; d <= n; d++) {
        const key = `${st.month.getFullYear()}-${String(st.month.getMonth() + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        h += `<button type="button" data-d="${key}" ${avail[key] ? '' : 'disabled'} class="${st.date === key ? 'sel' : ''}">${d}</button>`;
      }
      cal.innerHTML = h;
    };
    draw();
    body.querySelectorAll('[data-m]').forEach(b => b.onclick = () => { st.month = new Date(st.month.getFullYear(), st.month.getMonth() + Number(b.dataset.m), 1); draw(); });
    cal.onclick = e => { const b = e.target.closest('button[data-d]'); if (!b || b.disabled) return; st.date = b.dataset.d; st.time = null; draw(); loadSlots(); validate(); };
    if (!st.date) { const firstFree = Object.keys(avail).find(k => avail[k]); if (firstFree) { st.date = firstFree; st.month = new Date(+firstFree.slice(0, 4), +firstFree.slice(5, 7) - 1, 1); draw(); } }
    if (st.date) loadSlots();
  }
  async function loadSlots() {
    const box = body.querySelector('[data-slots]'), t = body.querySelector('[data-slot-title]');
    const d = new Date(st.date + 'T12:00:00');
    t.textContent = fmtDay(st.date);
    box.innerHTML = '<span class="muted">Saatler yükleniyor…</span>';
    const { slots } = await (await fetch(`/api/slots?service=${st.item.id}&date=${st.date}`)).json();
    const free = slots.filter(x => x.free);
    box.innerHTML = free.length ? free.map(x => `<button type="button" data-t="${x.time}" class="${st.time === x.time ? 'sel' : ''}"${away ? ` title="${esc(localHM(st.date, x.time))}"` : ''}>${x.time}${away ? `<small class="local-t">${new Date(`${st.date}T${x.time}:00+03:00`).toLocaleTimeString(LOC, { hour: '2-digit', minute: '2-digit' })}</small>` : ''}</button>`).join('') : '<span class="muted">Bu gün için boş saat kalmadı.</span>';
    const note = body.querySelector('[data-tz-note]');
    if (note) note.hidden = !away;
    box.onclick = e => { const b = e.target.closest('button[data-t]'); if (!b) return; st.time = b.dataset.t; box.querySelectorAll('button').forEach(x => x.classList.toggle('sel', x === b)); validate(); };
  }
  function bindForm() {
    const f = body.querySelector('[data-form]');
    const sync = () => { const fd = new FormData(f); st.form = { name: fd.get('name'), phone: fd.get('phone'), email: fd.get('email'), note: fd.get('note'), kvkk: !!fd.get('kvkk'), marketing: !!fd.get('marketing') }; validate(); };
    f.addEventListener('input', sync); f.addEventListener('change', sync); sync();
  }

  function valid() {
    const s = st.step, f = st.form;
    if (s === 0) return !!st.item;
    if (s === 1) return !!st.mode;
    if (s === 2) return !!(st.date && st.time);
    if (s === 3) return f.name?.trim().length > 2 && (f.phone || '').replace(/\D/g, '').length >= 10 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email || '') && f.kvkk;
    if (s === 4) return !PAY || !!body.querySelector('[data-agree]')?.checked;
    return true;
  }
  function validate() { next.disabled = !valid(); }
  body.addEventListener('change', e => {
    if (e.target.name === 'item') { const [k, id] = e.target.value.split(':'); st.kind = k === 'e' ? 'event' : 'service'; st.item = (k === 'e' ? events : services).find(x => x.id === id); st.mode = null; st.date = null; st.time = null; }
    if (e.target.name === 'mode') st.mode = e.target.value;
    validate();
  });

  function go(dir) {
    const list = steps(); let i = list.indexOf(st.step) + dir;
    i = Math.max(0, Math.min(list.length - 1, i));
    st.step = list[i];
    if (st.step >= 2 && !needMode() && !isEvent() && !st.mode) st.mode = st.item.modes[0];
    render(); root.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  back.onclick = () => go(-1);
  next.onclick = async () => {
    if (!valid()) return;
    if (st.step !== 4) return go(1);
    next.disabled = true; next.textContent = PAY ? 'Yönlendiriliyor…' : 'WhatsApp açılıyor…';
    const payload = { ...st.form, lang: LANG, ...(isEvent() ? { eventId: st.item.id } : { serviceId: st.item.id, mode: st.mode, date: st.date, time: st.time }) };
    try {
      const r = await fetch('/api/bookings', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
      const j = await r.json();
      if (j.wa) { location.href = j.wa; next.disabled = false; setNext(goLabel()); return; } // WhatsApp mesajı hazır açılır
      if (j.redirect) return location.href = P(j.redirect);
      const err = body.querySelector('[data-err]'); err.hidden = false; err.textContent = Object.values(j.errors || {}).map(T).join(' ') || T('Kayıt oluşturulamadı.');
      if (j.errors?.time) { st.time = null; st.days = {}; }
    } catch { const err = body.querySelector('[data-err]'); err.hidden = false; err.textContent = 'Bağlantı kurulamadı. İnternet bağlantını kontrol edip tekrar dene.'; }
    next.disabled = false; setNext(goLabel());
  };

  st.step = st.item ? (needMode() ? 1 : isEvent() ? 3 : 2) : 0;
  if (st.item && !needMode() && !isEvent()) st.mode = st.item.modes[0];
  render();
})();
