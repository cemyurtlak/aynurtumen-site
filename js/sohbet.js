// Sitedeki sohbet asistanı: sağ alttaki "Soru sorun" düğmesi. Konuşma sekme kapanana kadar sayfalar arasında korunur.
(() => {
  const root = document.querySelector('[data-chat]');
  if (!root) return;
  const panel = root.querySelector('[data-chat-panel]');
  const open = root.querySelector('[data-chat-open]');
  const log = root.querySelector('[data-chat-log]');
  const form = root.querySelector('[data-chat-form]');
  const input = form.querySelector('textarea');
  const errText = root.querySelector('[data-chat-err]').textContent;
  const lang = document.documentElement.lang || 'tr';
  const store = (k, v) => { try { if (v === undefined) return sessionStorage.getItem(k); sessionStorage.setItem(k, v); } catch (e) { return null; } };
  let sid = store('sohbet-sid');
  if (!sid) { sid = Array.from(crypto.getRandomValues(new Uint8Array(12)), b => b.toString(16).padStart(2, '0')).join(''); store('sohbet-sid', sid); }
  let history = []; try { history = JSON.parse(store('sohbet-log') || '[]'); } catch (e) {}

  const esc = s => s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const linkify = s => esc(s).replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)])/g, '<a href="$1" target="_blank" rel="noopener">$1</a>').replace(/\n/g, '<br>');
  const add = (who, text, save = true) => {
    const p = document.createElement('p');
    p.className = 'msg ' + who;
    p.innerHTML = who === 'bot' ? linkify(text) : esc(text).replace(/\n/g, '<br>');
    log.appendChild(p);
    log.scrollTop = log.scrollHeight;
    if (save) { history.push([who, text]); store('sohbet-log', JSON.stringify(history.slice(-40))); }
    return p;
  };
  history.forEach(([w, t]) => add(w, t, false));

  const setOpen = on => {
    panel.hidden = !on; open.setAttribute('aria-expanded', on ? 'true' : 'false');
    root.classList.toggle('is-open', on);
    store('sohbet-acik', on ? '1' : '0');
    if (on) { log.scrollTop = log.scrollHeight; setTimeout(() => input.focus(), 50); }
  };
  open.addEventListener('click', () => setOpen(panel.hidden));
  root.querySelector('[data-chat-close]').addEventListener('click', () => { setOpen(false); open.focus(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !panel.hidden) { setOpen(false); open.focus(); } });
  if (store('sohbet-acik') === '1' && history.length) setOpen(true);

  // Yazdıkça kutu büyür; Enter gönderir, Shift+Enter yeni satır
  input.addEventListener('input', () => { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 120) + 'px'; });
  input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });

  let busy = false;
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const text = input.value.trim();
    if (!text || busy) return;
    busy = true; form.classList.add('busy');
    add('me', text);
    input.value = ''; input.style.height = 'auto';
    const typing = add('bot typing', '…', false);
    try {
      const r = await fetch('/api/sohbet', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sid, mesaj: text, lang, sayfa: location.pathname }) });
      const d = await r.json();
      typing.remove();
      add('bot', d.cevap || errText);
    } catch (err) {
      typing.remove();
      add('bot', errText, false);
    }
    busy = false; form.classList.remove('busy');
    input.focus();
  });
})();
