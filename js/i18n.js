// Tarayıcıda oluşturulan metinlerin (randevu sihirbazı, görüşme odası) çevirisi.
// Sözlük sayfaya window.I18N olarak gömülür; Türkçe sayfalarda bu dosya hiç yüklenmez.
(() => {
  const D = window.I18N || {};
  const norm = s => String(s).replace(/\s+/g, ' ').trim();
  const SLOT = { n: '(\\d[\\d., ]*(?:\\s?B)?)', a: '(\\d+)', b: '(\\d+)', p: '(₺?\\s?\\d[\\d., ]*)' };
  const exact = new Map(), patterns = [];
  for (const [k, v] of Object.entries(D)) {
    if (!v || k.startsWith('//')) continue;
    const key = norm(k);
    if (/\{\w+\}/.test(key)) {
      const names = [];
      const re = new RegExp('^' + key.split(/(\{\w+\})/).map(p => { const m = p.match(/^\{(\w+)\}$/); if (m) { names.push(m[1]); return SLOT[m[1]] || '(.+?)'; } return p.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }).join('') + '$');
      patterns.push({ re, names, v, len: key.length });
    } else exact.set(key, v);
  }
  patterns.sort((a, b) => b.len - a.len);
  const T = s => {
    const k = norm(s);
    if (!k) return s;
    if (exact.has(k)) return exact.get(k);
    for (const p of patterns) { const m = k.match(p.re); if (m) return p.names.reduce((o, n, i) => o.split(`{${n}}`).join(T(m[i + 1])), p.v); }
    return s;
  };
  window.T = T;

  const ATTRS = ['placeholder', 'aria-label', 'title'];
  const fix = node => {
    if (node.nodeType === 3) {
      const out = T(node.nodeValue);
      if (out !== node.nodeValue) node.nodeValue = node.nodeValue.replace(norm(node.nodeValue), out);
      return;
    }
    if (node.nodeType !== 1) return;
    for (const a of ATTRS) if (node.hasAttribute(a)) { const v = node.getAttribute(a), o = T(v); if (o !== v) node.setAttribute(a, o); }
    node.childNodes.forEach(fix);
  };
  const watch = el => {
    fix(el);
    new MutationObserver(list => list.forEach(m => {
      if (m.type === 'characterData') fix(m.target);
      m.addedNodes.forEach(fix);
    })).observe(el, { childList: true, subtree: true, characterData: true });
  };
  document.querySelectorAll('[data-i18n-live]').forEach(watch);
})();
