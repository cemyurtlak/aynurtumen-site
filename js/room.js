// Görüşme odası: cihaz kontrolü, geri sayım, görüşmeye katılma
(() => {
  const room = document.getElementById('room');
  if (!room) return;
  const start = new Date(room.dataset.start).getTime();
  const join = document.getElementById('join'), cd = document.getElementById('countdown');
  const OPEN_BEFORE = 15 * 60000;
  let stream = null;

  const tick = () => {
    const ms = start - Date.now();
    if (ms > 86400000) cd.textContent = `${Math.floor(ms / 86400000)} gün ${Math.floor(ms % 86400000 / 3600000)} saat kaldı`;
    else if (ms > 0) cd.textContent = `${Math.floor(ms / 3600000)} sa ${Math.floor(ms % 3600000 / 60000)} dk kaldı`;
    else cd.textContent = 'Görüşme saati geldi';
    const ready = ms <= OPEN_BEFORE || new URLSearchParams(location.search).has('demo');
    join.disabled = !ready;
    join.textContent = ready ? 'Görüşmeye katıl' : 'Oda, seanstan 15 dk önce açılır';
  };
  tick(); setInterval(tick, 20000);

  const set = (id, text, ok) => { const li = document.getElementById(id); li.querySelector('span').textContent = text; li.classList.toggle('ok', ok); };
  set('c-net', navigator.onLine ? 'Bağlı' : 'Bağlantı yok', navigator.onLine);

  document.getElementById('test-btn').addEventListener('click', async () => {
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
      const v = document.getElementById('selfview'); v.srcObject = stream;
      document.getElementById('stage-msg').hidden = true;
      set('c-cam', 'Çalışıyor', true); set('c-mic', 'Çalışıyor', true);
      const ac = new AudioContext(), an = ac.createAnalyser(); an.fftSize = 256;
      ac.createMediaStreamSource(stream).connect(an);
      const buf = new Uint8Array(an.frequencyBinCount), meter = document.getElementById('meter');
      const loop = () => { an.getByteFrequencyData(buf); meter.style.width = Math.min(100, buf.reduce((a, b) => a + b, 0) / buf.length * 2) + '%'; requestAnimationFrame(loop); };
      loop();
    } catch {
      document.querySelector('#stage-msg p').textContent = 'Kamera veya mikrofona erişilemedi. Adres çubuğundaki kamera simgesinden izin verip tekrar dene.';
      set('c-cam', 'İzin verilmedi', false); set('c-mic', 'İzin verilmedi', false);
    }
  });

  join.addEventListener('click', () => {
    const provider = room.dataset.provider;
    if (provider !== 'jitsi' || !window.JitsiMeetExternalAPI) { window.open(room.dataset.join, '_blank', 'noopener'); return; }
    stream?.getTracks().forEach(t => t.stop());
    const call = document.getElementById('call'); call.hidden = false;
    const api = new JitsiMeetExternalAPI(room.dataset.domain, {
      roomName: room.dataset.room, parentNode: document.getElementById('jitsi'), lang: room.dataset.lang || 'tr',
      userInfo: { displayName: room.dataset.name, email: room.dataset.email },
      configOverwrite: { prejoinPageEnabled: false, disableDeepLinking: true },
      interfaceConfigOverwrite: { MOBILE_APP_PROMO: false }
    });
    const leave = () => { api.dispose(); call.hidden = true; };
    api.addListener('readyToClose', leave);
    document.getElementById('leave').onclick = leave;
  });
})();
