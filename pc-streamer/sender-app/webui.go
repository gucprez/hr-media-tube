package main

const pageHTML = `<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>HR Media Tube - Transmisor</title>
<style>
  body { background:#0B0F19; color:#fff; font-family:sans-serif; display:flex; justify-content:center; padding:32px; }
  .card { background:#111827; border-radius:12px; padding:32px; max-width:520px; width:100%; }
  h1 { font-size:20px; margin-top:0; }
  label { display:block; margin-top:16px; font-size:13px; color:#9CA3AF; }
  select, input[type=text] { width:100%; padding:8px; margin-top:4px; border-radius:6px; border:1px solid #374151; background:#1F2937; color:#fff; }
  .row { display:flex; gap:16px; }
  .row > div { flex:1; }
  button { margin-top:24px; padding:12px 20px; border-radius:8px; border:none; font-size:15px; cursor:pointer; }
  #btnStart { background:#3B82F6; color:#fff; }
  #btnStop { background:#EF4444; color:#fff; display:none; }
  #btnExit { background:#374151; color:#fff; margin-left:8px; }
  #status { margin-top:20px; padding:16px; background:#1F2937; border-radius:8px; font-size:14px; line-height:1.6; }
  .ok { color:#34D399; }
  .warn { color:#FBBF24; }
  .err { color:#F87171; }
  small { color:#9CA3AF; }
</style>
</head>
<body>
<div class="card">
  <h1>HR Media Tube — Transmisor de PC</h1>

  <label>Qué compartir con las TV</label>
  <select id="window">
    <option value="">Todo mi escritorio (recomendado, siempre funciona)</option>
  </select>
  <small><a href="#" id="refreshWindows" style="color:#3B82F6;">Actualizar lista de ventanas</a></small>

  <div class="row">
    <div>
      <label>Modo</label>
      <select id="mode">
        <option value="lowlatency">Llamadas (tiempo real)</option>
        <option value="stable">Película (más estable)</option>
      </select>
    </div>
    <div>
      <label>Calidad (bitrate)</label>
      <select id="bitrate">
        <option value="3M">3 Mbps</option>
        <option value="4M">4 Mbps</option>
        <option value="6M" selected>6 Mbps (recomendado)</option>
        <option value="8M">8 Mbps</option>
      </select>
    </div>
  </div>

  <button id="btnStart" onclick="start()">Iniciar transmisión</button>
  <button id="btnStop" onclick="stop()">Detener</button>
  <button id="btnExit" onclick="exitApp()">Salir</button>

  <div id="status">Cargando estado...</div>
</div>

<script>
async function refreshWindows() {
  const res = await fetch('/windows');
  const data = await res.json();
  const sel = document.getElementById('window');
  const current = sel.value;
  sel.innerHTML = '<option value="">Todo mi escritorio (recomendado, siempre funciona)</option>';
  for (const title of data.windows) {
    const opt = document.createElement('option');
    opt.value = title;
    opt.textContent = title;
    sel.appendChild(opt);
  }
  sel.value = current;
}
document.getElementById('refreshWindows').onclick = (e) => { e.preventDefault(); refreshWindows(); };

async function start() {
  const body = new URLSearchParams({
    window: document.getElementById('window').value,
    mode: document.getElementById('mode').value,
    bitrate: document.getElementById('bitrate').value
  });
  const res = await fetch('/start', { method: 'POST', body });
  const data = await res.json();
  if (!data.ok) alert(data.error);
  poll();
}
async function stop() {
  await fetch('/stop', { method: 'POST' });
  poll();
}
async function exitApp() {
  await fetch('/exit', { method: 'POST' });
  document.body.innerHTML = '<div class="card"><h1>Programa cerrado</h1><p>Puedes cerrar esta pestaña.</p></div>';
}

async function poll() {
  const res = await fetch('/status');
  const s = await res.json();
  const el = document.getElementById('status');
  const startBtn = document.getElementById('btnStart');
  const stopBtn = document.getElementById('btnStop');

  if (s.preparing) {
    el.innerHTML = '<span class="warn">' + s.prepareMessage + '</span><br><small>Solo pasa la primera vez. Necesita internet.</small>';
    startBtn.style.display = 'none';
    stopBtn.style.display = 'none';
  } else if (s.running) {
    let html = '<span class="ok">Transmitiendo</span><br>';
    html += 'IP para configurar en las TV: <b>' + s.ip + '</b><br>';
    html += 'Puerto: <b>8554</b> &nbsp; Stream: <b>pc</b><br>';
    html += '<small>Las TV con autodescubrimiento activado deberían encontrar esta IP solas.</small>';
    el.innerHTML = html;
    startBtn.style.display = 'none';
    stopBtn.style.display = 'inline-block';
  } else {
    let html = '<span>Detenido.</span>';
    if (s.lastError) html += '<br><span class="err">' + s.lastError + '</span>';
    el.innerHTML = html;
    startBtn.style.display = 'inline-block';
    stopBtn.style.display = 'none';
  }
}

refreshWindows();
poll();
setInterval(poll, 2000);
</script>
</body>
</html>`
