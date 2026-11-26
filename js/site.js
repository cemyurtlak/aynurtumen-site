(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Üst menü: kaydırınca koyulaşır; koyu başlığı olmayan sayfalarda hep koyu
  const top = document.querySelector('[data-topbar]');
  if (!document.querySelector('.hero, .page-hero, .room-sec')) document.body.classList.add('is-light');
  const onScroll = () => top && top.classList.toggle('scrolled', scrollY > 30);
  addEventListener('scroll', onScroll, { passive: true }); onScroll();

  // Mobil menü
  const burger = document.querySelector('.burger'), nav = document.getElementById('nav');
  burger?.addEventListener('click', () => {
    const open = nav.classList.toggle('open');
    burger.setAttribute('aria-expanded', open);
    document.body.style.overflow = open ? 'hidden' : '';
    top?.classList.toggle('menu-open', open);
  });
  nav?.addEventListener('click', e => { if (e.target.closest('a')) { nav.classList.remove('open'); burger.setAttribute('aria-expanded', false); document.body.style.overflow = ''; top?.classList.remove('menu-open'); } });

  // Dil seçici
  const lang = document.querySelector('[data-lang]');
  if (lang) {
    const btn = lang.querySelector('.lang-btn');
    btn.addEventListener('click', e => { e.stopPropagation(); const o = lang.classList.toggle('open'); btn.setAttribute('aria-expanded', o); });
    document.addEventListener('click', e => { if (!lang.contains(e.target)) { lang.classList.remove('open'); btn.setAttribute('aria-expanded', false); } });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { lang.classList.remove('open'); btn.setAttribute('aria-expanded', false); } });
  }

  // Kaydırınca beliren öğeler
  const io = 'IntersectionObserver' in window && !reduce ? new IntersectionObserver(entries => {
    entries.forEach(en => { if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); } });
  }, { rootMargin: '0px 0px -8% 0px', threshold: .08 }) : null;
  document.querySelectorAll('.reveal').forEach(el => io ? io.observe(el) : el.classList.add('in'));

  // Sayaçlar
  const counters = document.querySelectorAll('[data-count]');
  const countIO = 'IntersectionObserver' in window && !reduce ? new IntersectionObserver(entries => {
    entries.forEach(en => {
      if (!en.isIntersecting) return;
      const el = en.target, end = Number(el.dataset.count), startVal = end > 1900 && end < 2100 ? 1990 : 0, t0 = performance.now();
      const step = t => { const p = Math.min(1, (t - t0) / 1400), v = Math.round(startVal + (end - startVal) * (1 - Math.pow(1 - p, 3))); el.textContent = v.toLocaleString('tr-TR').replace(/\./g, end > 1900 && end < 2100 ? '' : '.'); if (p < 1) requestAnimationFrame(step); };
      requestAnimationFrame(step); countIO.unobserve(el);
    });
  }, { threshold: .6 }) : null;
  counters.forEach(el => countIO && countIO.observe(el));

  // Yıldızlı gökyüzü
  const cv = document.querySelector('canvas.stars');
  if (cv && !reduce) {
    const ctx = cv.getContext('2d'); let stars = [], w, h, dpr = Math.min(2, devicePixelRatio || 1);
    const size = () => { w = cv.clientWidth; h = cv.clientHeight; cv.width = w * dpr; cv.height = h * dpr; ctx.scale(dpr, dpr);
      stars = Array.from({ length: Math.round(w * h / 9000) }, () => ({ x: Math.random() * w, y: Math.random() * h, r: Math.random() * 1.2 + .2, p: Math.random() * Math.PI * 2, s: .4 + Math.random() * 1.2 })); };
    size(); addEventListener('resize', () => { ctx.setTransform(1, 0, 0, 1, 0, 0); size(); });
    let visible = true; new IntersectionObserver(([e]) => visible = e.isIntersecting).observe(cv);
    const draw = t => {
      if (visible) { ctx.clearRect(0, 0, w, h);
        for (const s of stars) { const a = .35 + .65 * (0.5 + 0.5 * Math.sin(t / 1000 * s.s + s.p)); ctx.globalAlpha = a; ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.arc(s.x, s.y, s.r, 0, 6.283); ctx.fill(); } }
      requestAnimationFrame(draw);
    };
    requestAnimationFrame(draw);
  }

  // Konu sekmeleri
  const tabs = [...document.querySelectorAll('.topic-tabs [role=tab]')];
  const select = t => {
    tabs.forEach(x => { const on = x === t; x.setAttribute('aria-selected', on); x.tabIndex = on ? 0 : -1; document.getElementById(x.getAttribute('aria-controls')).hidden = !on; });
  };
  tabs.forEach((t, i) => {
    t.addEventListener('click', () => select(t));
    t.addEventListener('keydown', e => { const d = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0; if (d) { const n = tabs[(i + d + tabs.length) % tabs.length]; n.focus(); select(n); } });
  });

  // Filtreler (etkinlik türü, yazı kategorisi)
  document.querySelectorAll('.filters').forEach(bar => bar.addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    bar.querySelectorAll('button').forEach(x => x.setAttribute('aria-pressed', x === b));
    const f = b.dataset.filter;
    bar.parentElement.querySelectorAll('[data-type]').forEach(el => { el.hidden = !(f === 'all' || el.dataset.type === f); });
  }));

  // Yazılarda arama + kategori (ikisi birlikte çalışır), kaç yazı gösterildiğini yazar
  const ps = document.querySelector('[data-post-search]');
  if (ps) {
    const T = window.T || (x => x);
    const sec = ps.closest('section');
    const cards = [...sec.querySelectorAll('article[data-slug]')];
    const countEl = sec.querySelector('[data-post-count]'), emptyEl = sec.querySelector('[data-post-empty]');
    let hits = null, cat = 'all', timer = 0, seq = 0;
    const init = new URLSearchParams(location.search).get('q');
    if (init && init.trim()) hits = new Set(cards.filter(c => !c.hidden).map(c => c.dataset.slug));
    const apply = () => {
      let n = 0;
      cards.forEach(c => { const ok = (!hits || hits.has(c.dataset.slug)) && (cat === 'all' || c.dataset.type === cat); c.hidden = !ok; if (ok) n++; });
      countEl.textContent = T(n + ' yazı');
      emptyEl.hidden = n > 0;
    };
    const run = () => {
      const q = ps.value.trim(), my = ++seq;
      const u = new URL(location.href); if (q) u.searchParams.set('q', q); else u.searchParams.delete('q'); history.replaceState(null, '', u);
      if (!q) { hits = null; apply(); return; }
      fetch('/api/yazi-ara?lang=' + (document.documentElement.lang || 'tr') + '&q=' + encodeURIComponent(q)).then(r => r.json()).then(d => { if (my !== seq) return; hits = new Set(d.slugs || []); apply(); }).catch(() => {});
    };
    ps.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 250); });
    ps.closest('form').addEventListener('submit', e => { e.preventDefault(); clearTimeout(timer); run(); ps.blur(); });
    sec.querySelector('.filters')?.addEventListener('click', e => { const b = e.target.closest('button'); if (!b) return; cat = b.dataset.filter; apply(); });
  }

  // Çerez tercihi
  const cookie = document.querySelector('[data-cookie]');
  let choice = null; try { choice = localStorage.getItem('cerez'); } catch {}
  if (cookie && !choice) setTimeout(() => cookie.hidden = false, 1200);
  document.querySelectorAll('[data-cookie-ok]').forEach(b => b.addEventListener('click', () => { try { localStorage.setItem('cerez', b.dataset.cookieOk); } catch {} cookie.hidden = true; }));

  // Büyütülerek açılan görseller (basın kupürleri, galeri)
  const dlg = document.querySelector('[data-lightbox-dialog]');
  if (dlg) {
    const img = dlg.querySelector('img');
    const items = () => [...document.querySelectorAll('[data-lightbox]')].filter(a => !a.hidden);
    let idx = 0;
    const show = i => { const list = items(); idx = (i + list.length) % list.length; img.src = list[idx].href; };
    document.querySelectorAll('[data-lightbox]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); show(items().indexOf(a)); dlg.showModal(); }));
    dlg.querySelector('.lb-prev')?.addEventListener('click', e => { e.stopPropagation(); show(idx - 1); });
    dlg.querySelector('.lb-next')?.addEventListener('click', e => { e.stopPropagation(); show(idx + 1); });
    dlg.addEventListener('keydown', e => { if (e.key === 'ArrowRight') show(idx + 1); if (e.key === 'ArrowLeft') show(idx - 1); });
    dlg.addEventListener('click', e => { if (e.target === dlg || e.target.classList.contains('lightbox-close')) dlg.close(); });
  }
})();
