'use strict';
/* Interfaz del estudio (laptop). Habla solo con el servidor local por /ws/ui y /api.
   El video llega por WebRTC directo desde la cámara (misma red); el servidor solo hace de señalización. */

const $ = (id) => document.getElementById(id);
const state = { devices: [], pairing: null };
const peers = new Map();            // deviceId -> { pc, stream, pendingIce: [], prev: {bytes, ts} }
let previewId = null, programId = null;
let ws = null;
const csvRows = [['hora_iso','dispositivo','nombre','rx_ancho','rx_alto','rx_fps','rx_kbps','rx_jitterbuffer_ms','rx_congelamientos','rx_perdidos','rx_codec','rtt_ms','tx_fps','tx_kbps','tx_encoder','tx_hw','tx_limitacion','bateria_pct','bateria_c','termico','carga_uAh','corriente_uA','lente','zoom','audio']];

// ---------- WebSocket con la propia laptop ----------
function connectUi() {
  ws = new WebSocket(`ws://${location.host}/ws/ui`);
  ws.onmessage = (ev) => onServer(JSON.parse(ev.data));
  ws.onclose = () => setTimeout(connectUi, 1000);
}
const sendUi = (o) => ws && ws.readyState === 1 && ws.send(JSON.stringify(o));

async function onServer(m) {
  if (m.type === 'state') { state.devices = m.devices; state.pairing = m.pairing || null; render(); syncOnAir(); }
  else if (m.type === 'notice') { showNotice(m.text); }
  else if (m.type === 'device_connected') { closePeer(m.deviceId); }   // llegará una oferta nueva: sin duplicar
  else if (m.type === 'signal') { await onSignal(m.deviceId, m.msg); }
}

// ---------- WebRTC (lado estudio: recibe) ----------
function closePeer(id) {
  const p = peers.get(id);
  if (p) { try { p.pc.close(); } catch (e) {} peers.delete(id); }
  attachVideos();
}

async function onSignal(id, msg) {
  if (msg.type === 'offer') {
    closePeer(id);
    const pc = new RTCPeerConnection({ iceServers: [] });          // red local: no hace falta STUN
    const peer = { pc, stream: new MediaStream(), pendingIce: [], prev: null };
    peers.set(id, peer);
    pc.ontrack = (e) => { peer.stream = e.streams[0] || peer.stream; if (!e.streams[0]) peer.stream.addTrack(e.track); attachVideos(); render(); };
    pc.onicecandidate = (e) => { if (e.candidate) sendUi({ type: 'ice', deviceId: id, candidate: e.candidate.candidate, sdpMid: e.candidate.sdpMid, sdpMLineIndex: e.candidate.sdpMLineIndex }); };
    pc.onconnectionstatechange = () => { render(); attachVideos(); };
    await pc.setRemoteDescription({ type: 'offer', sdp: msg.sdp });
    for (const c of peer.pendingIce) await pc.addIceCandidate(c).catch(() => {});
    peer.pendingIce = [];
    await pc.setLocalDescription(await pc.createAnswer());
    sendUi({ type: 'answer', deviceId: id, sdp: pc.localDescription.sdp });
  } else if (msg.type === 'ice') {
    const p = peers.get(id); if (!p) return;
    const cand = { candidate: msg.candidate, sdpMid: msg.sdpMid, sdpMLineIndex: msg.sdpMLineIndex };
    if (p.pc.remoteDescription) await p.pc.addIceCandidate(cand).catch(() => {}); else p.pendingIce.push(cand);
  }
}

const isLive = (id) => { const p = peers.get(id); return !!p && p.pc.connectionState === 'connected' && p.stream.getVideoTracks().some((t) => t.readyState === 'live'); };
const dev = (id) => state.devices.find((d) => d.id === id);

function attachVideos() {
  setVideo('v-preview', 'panel-preview', previewId);
  setVideo('v-program', 'panel-program', programId);
}
function setVideo(videoId, panelId, id) {
  const v = $(videoId), live = id && isLive(id);
  const stream = live ? peers.get(id).stream : null;
  if (v.srcObject !== stream) v.srcObject = stream;
  if (live) v.setAttribute('data-live', ''); else v.removeAttribute('data-live');
  $(panelId).classList.toggle('has', !!id);
  const empty = $(videoId === 'v-preview' ? 'e-preview' : 'e-program');
  if (id && !live) empty.textContent = `${dev(id)?.name || 'Cámara'}: sin señal (reconectando…)`;
  else if (!id) empty.textContent = videoId === 'v-preview' ? 'Elige una fuente para prepararla' : 'Nada al aire. Prepara una fuente y pulsa «Poner al aire».';
}

// ---------- Selección: Vista previa y Poner al aire son pasos separados ----------
function selectSource(id) { previewId = id; render(); }
function takeToAir() {
  if (!previewId || previewId === programId || !isLive(previewId)) return;
  const old = programId;
  programId = previewId;
  if (old) sendUi({ type: 'onair', deviceId: old, active: false });
  sendUi({ type: 'onair', deviceId: programId, active: true });
  render();
}
// Tras una reconexión el servidor olvida el AL AIRE: se vuelve a avisar a la cámara que sigue en la salida.
function syncOnAir() {
  const d = programId && dev(programId);
  if (programId && !d) { programId = null; }
  if (d && d.online && !d.onAir) sendUi({ type: 'onair', deviceId: d.id, active: true });
  if (previewId && !dev(previewId)) previewId = null;
}

function showNotice(text) {
  let n = document.getElementById('notice');
  if (!n) { n = document.createElement('div'); n.id = 'notice'; n.setAttribute('role', 'alert'); document.body.appendChild(n); }
  n.textContent = text; n.hidden = false;
  clearTimeout(showNotice.t); showNotice.t = setTimeout(() => { n.hidden = true; }, 8000);
}

// ---------- Render ----------
const chip = (t, cls) => `<span class="chip ${cls || ''}">${t}</span>`;
function render() {
  const accepted = state.devices.filter((d) => d.state === 'accepted' && d.role === 'camera');
  // Fuentes: una por dispositivo aceptado (clave = deviceId): una reconexión nunca crea otra.
  $('sources').innerHTML = accepted.length ? '' : '<div class="nosrc">Sin cámaras aceptadas. Acepta un dispositivo para usarlo como fuente.</div>';
  for (const d of accepted) {
    const b = document.createElement('button');
    b.className = 'tile' + (d.id === previewId ? ' sel' : '') + (d.id === programId ? ' air' : '') + (isLive(d.id) ? '' : ' off');
    b.setAttribute('aria-pressed', d.id === previewId);
    const status = isLive(d.id) ? chip('EN LÍNEA', 'turq') : (d.online ? chip('CONECTANDO', 'amber') : chip('SIN CONEXIÓN', ''));
    b.innerHTML = `<b>${esc(d.name).toUpperCase()}</b><span class="chips">${status}${d.id === previewId ? chip('PREVIA', 'orange') : ''}${d.id === programId ? chip('AL AIRE', 'red') : ''}</span>`;
    b.onclick = () => selectSource(d.id);
    $('sources').appendChild(b);
  }
  const pv = previewId && dev(previewId), pg = programId && dev(programId);
  $('preview-chip').hidden = !pv; if (pv) $('preview-chip').textContent = pv.name.toUpperCase();
  $('program-chip').hidden = !pg; if (pg) $('program-chip').textContent = pg.name.toUpperCase();
  $('btn-take').disabled = !(previewId && previewId !== programId && isLive(previewId));
  $('btn-take').textContent = previewId && previewId === programId ? 'YA ESTÁ AL AIRE' : 'PONER AL AIRE';
  attachVideos();

  // Dispositivos
  $('devices').innerHTML = state.devices.length ? '' : '<div class="hint">Sin dispositivos. Crea el estudio y escanea el QR.</div>';
  for (const d of state.devices) {
    const el = document.createElement('div'); el.className = 'dev';
    const st = d.state === 'pending' ? chip('SOLICITA VINCULARSE', 'amber') : (d.online ? chip('CONECTADO', 'turq') : chip('SIN CONEXIÓN', ''));
    const role = d.role === 'camera' ? 'Cámara remota' : 'Lector de pizarra';
    el.innerHTML = `<div class="row"><b>${esc(d.name)}</b>${st}</div><div class="hint">${role} · ${esc(d.id.slice(0, 8))}</div><div class="row"></div>`;
    const row = el.lastChild;
    if (d.state === 'pending') {
      row.append(btn('Aceptar', 'primary small', () => sendUi({ type: 'accept', deviceId: d.id })), btn('Rechazar', 'danger small', () => sendUi({ type: 'reject', deviceId: d.id })));
    } else {
      row.append(btn('Desconectar', 'danger small', () => { if (programId === d.id) programId = null; if (previewId === d.id) previewId = null; closePeer(d.id); sendUi({ type: 'forget', deviceId: d.id }); }));
    }
    $('devices').appendChild(el);
  }

  // Estado del estudio
  const ch = $('link-chip');
  if (state.pairing) { ch.textContent = `Estudio activo · ${state.pairing.host}:${state.pairing.port}`; ch.className = 'chip turq'; }
  else { ch.textContent = 'Sin estudio'; ch.className = 'chip'; }
}
const btn = (t, cls, fn) => { const b = document.createElement('button'); b.className = 'btn ' + cls; b.textContent = t; b.onclick = fn; return b; };
const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// ---------- Crear estudio / QR ----------
async function loadHosts() {
  const hosts = await (await fetch('/api/hosts')).json();
  $('host').innerHTML = hosts.length ? hosts.map((h) => `<option value="${h.ip}">${esc(h.ip)} (${esc(h.name)})</option>`).join('') : '<option value="127.0.0.1">127.0.0.1 (sin red detectada)</option>';
}
async function createStudio() {
  const r = await fetch('/api/studio?host=' + encodeURIComponent($('host').value), { method: 'POST' });
  const p = await r.json();
  previewId = programId = null; peers.forEach((_, id) => closePeer(id));
  $('qr').src = '/api/qr.png?ts=' + Date.now();
  $('pair-uri').textContent = p.uri;
  $('pairing').hidden = false;
  tickExpiry(p.expiresAt);
  render();
}
let expTimer = null;
function tickExpiry(at) {
  clearInterval(expTimer);
  const f = () => {
    const s = Math.round((at - Date.now()) / 1000);
    $('pair-exp').textContent = s > 0 ? `El QR vence en ${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}. Los dispositivos ya aceptados se reconectan solos.` : 'El QR venció. Pulsa «Crear estudio» para generar uno nuevo (borra los dispositivos actuales).';
  };
  f(); expTimer = setInterval(f, 1000);
}

// ---------- Calidad y registro de validación ----------
async function sample() {
  const lines = [];
  for (const d of state.devices.filter((x) => x.state === 'accepted' && x.role === 'camera')) {
    const p = peers.get(d.id); const t = d.telemetry; let rx = {};
    if (p && p.pc.connectionState === 'connected') {
      const rep = await p.pc.getStats(); let inb, pair, codec;
      rep.forEach((s) => { if (s.type === 'inbound-rtp' && s.kind === 'video') inb = s; if (s.type === 'candidate-pair' && s.state === 'succeeded' && s.nominated) pair = s; });
      if (inb) { rep.forEach((s) => { if (s.id === inb.codecId) codec = s; });
        const now = performance.now(); const kbps = p.prev ? Math.round(((inb.bytesReceived - p.prev.bytes) * 8) / (now - p.prev.ts)) : 0;
        p.prev = { bytes: inb.bytesReceived, ts: now };
        rx = { w: inb.frameWidth, h: inb.frameHeight, fps: inb.framesPerSecond, kbps,
          jb: inb.jitterBufferEmittedCount ? Math.round((inb.jitterBufferDelay / inb.jitterBufferEmittedCount) * 1000) : null,
          freeze: inb.freezeCount, lost: inb.packetsLost, codec: codec ? codec.mimeType : '', rtt: pair && pair.currentRoundTripTime != null ? Math.round(pair.currentRoundTripTime * 1000) : null };
      }
    }
    lines.push({ d, rx, t });
    if (rx.w || t) csvRows.push([new Date().toISOString(), d.id, d.name, rx.w ?? '', rx.h ?? '', rx.fps ?? '', rx.kbps ?? '', rx.jb ?? '', rx.freeze ?? '', rx.lost ?? '', rx.codec ?? '', rx.rtt ?? '',
      t?.fps ?? '', t?.bitrateKbps ?? '', t?.encoder ?? '', t?.hardwareEncoder ?? '', t?.limitation ?? '', t?.batteryPct ?? '', t?.batteryTempC ?? '', t?.thermalStatus ?? '', t?.chargeCounterUah ?? '', t?.currentNowUa ?? '', t?.lens ?? '', t?.zoom ?? '', t?.audioOn ?? '']);
  }
  $('stats').innerHTML = lines.length ? lines.map(({ d, rx, t }) => `<div class="kv"><span>Cámara</span><span>${esc(d.name)}</span>
    <span>Recibido</span><span>${rx.w ? `${rx.w}×${rx.h} · ${rx.fps ?? '–'} fps · ${rx.kbps ?? 0} kbps` : '–'}</span>
    <span>Códec</span><span>${esc(rx.codec || '–')}</span>
    <span>Retardo búfer</span><span>${rx.jb != null ? rx.jb + ' ms' : '–'}</span>
    <span>RTT</span><span>${rx.rtt != null ? rx.rtt + ' ms' : '–'}</span>
    <span>Congelam. / perdidos</span><span>${rx.freeze ?? '–'} / ${rx.lost ?? '–'}</span>
    <span>Codificador</span><span>${t ? esc(t.encoder || '–') + (t.hardwareEncoder == null ? '' : t.hardwareEncoder ? ' (hardware)' : ' (software)') : '–'}</span>
    <span>Batería / temp.</span><span>${t && t.batteryPct != null ? t.batteryPct + ' % · ' + (t.batteryTempC ?? '–') + ' °C' : '–'}</span>
    <span>Limitación</span><span>${t ? esc(t.limitation || 'ninguna') : '–'}</span></div>`).join('<hr>') : 'Sin cámara conectada.';
}
function downloadCsv() {
  const csv = csvRows.map((r) => r.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n');
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
  a.download = `registro-validacion-${new Date().toISOString().replace(/[:.]/g, '-')}.csv`; a.click();
}

$('btn-create').onclick = createStudio;
$('btn-take').onclick = takeToAir;
$('btn-csv').onclick = downloadCsv;
loadHosts(); connectUi(); render();
setInterval(() => sample().catch(() => {}), 2000);
window.__studio = { state, peers, get previewId() { return previewId; }, get programId() { return programId; }, csvRows };  // para pruebas automáticas
