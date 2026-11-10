/* Prueba de extremo a extremo (misma máquina): estudio real + "cámara" simulada en un segundo Chromium.
   La cámara usa una implementación INDEPENDIENTE del protocolo (JavaScript/WebCrypto), así que además
   comprueba que la especificación (docs/protocolo-conexion.md) es suficiente para interoperar.
   NO prueba Android, ni una red real, ni codificación por hardware.
   Uso:  node e2e.cjs <ruta a hr-studio> [puerto] */
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const fs = require('fs');

const BIN = process.argv[2];
const PORT = Number(process.argv[3] || 8799);
const OUT = process.env.E2E_OUT || '/tmp/e2e';
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let failures = 0;
const check = (name, ok, extra = '') => { console.log(`${ok ? 'OK  ' : 'FALLA'} ${name} ${extra}`); if (!ok) failures++; };

// Código del dispositivo simulado (corre dentro de la página).
const DEVICE_JS = `
const enc = new TextEncoder();
const hex = (b) => [...new Uint8Array(b)].map((x) => x.toString(16).padStart(2, '0')).join('');
const b64u = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
const rnd = (n) => hex(crypto.getRandomValues(new Uint8Array(n)));
async function hmac(keyBytes, data) { const k = await crypto.subtle.importKey('raw', keyBytes, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']); return new Uint8Array(await crypto.subtle.sign('HMAC', k, enc.encode(data))); }
window.dev = { log: [], onair: null, state: null, error: null };
window.startDevice = async (uri, deviceId, auth, name) => {
  const q = new URL(uri.replace('hrms://', 'http://')).searchParams;
  const secret = b64u(q.get('k')); const host = q.get('h'), port = q.get('p');
  const key = auth === 'token' ? await hmac(secret, 'token|' + deviceId) : secret;
  const ws = new WebSocket('ws://' + host + ':' + port + '/ws/device'); window.dev.ws = ws;
  let sk, seq = 0, last = 0, pc = null, handshake = 0, nonceS, nonceD, sid;
  const sealed = async (m) => { const msg = JSON.stringify(m); seq++; const mac = hex(await hmac(sk, 'd2s|' + seq + '|' + msg)); ws.send(JSON.stringify({ seq, msg, mac })); };
  ws.onclose = () => { window.dev.closed = true; };
  ws.onmessage = async (ev) => {
    const t = JSON.parse(ev.data);
    if (handshake === 0) {          // challenge
      sid = t.sessionId; nonceS = t.nonceS; nonceD = rnd(16); handshake = 1;
      const proof = hex(await hmac(key, 'dev|' + auth + '|' + sid + '|' + nonceS + '|' + nonceD + '|' + deviceId));
      ws.send(JSON.stringify({ type: 'hello', v: 1, deviceId, name, role: 'camera', auth, nonceD, proof }));
    } else if (handshake === 1) {   // hello_ok / hello_error
      if (t.type === 'hello_error') { window.dev.error = t.code; return; }
      const expect = hex(await hmac(key, 'srv|' + sid + '|' + nonceS + '|' + nonceD + '|' + deviceId));
      if (expect !== t.proof) { window.dev.error = 'servidor_no_autentico'; return; }
      sk = await hmac(key, 'sess|' + sid + '|' + nonceS + '|' + nonceD); handshake = 2; window.dev.state = t.state;
    } else {                        // sobres firmados
      const e = t; if (e.seq <= last) return;
      const mac = hex(await hmac(sk, 's2d|' + e.seq + '|' + e.msg)); if (mac !== e.mac) { window.dev.error = 'mac_invalido'; return; }
      last = e.seq; const m = JSON.parse(e.msg); window.dev.log.push(m.type);
      if (m.type === 'accepted') { window.dev.state = 'accepted'; await startMedia(); }
      else if (m.type === 'answer') await pc.setRemoteDescription({ type: 'answer', sdp: m.sdp });
      else if (m.type === 'ice') await pc.addIceCandidate({ candidate: m.candidate, sdpMid: m.sdpMid, sdpMLineIndex: m.sdpMLineIndex });
      else if (m.type === 'onair') window.dev.onair = m.active;
      else if (m.type === 'rejected') window.dev.state = 'rejected';
    }
  };
  async function startMedia() {
    const stream = await navigator.mediaDevices.getUserMedia({ video: { width: 1280, height: 720, frameRate: 30 }, audio: true });
    pc = new RTCPeerConnection({ iceServers: [] }); window.dev.pc = pc;
    stream.getTracks().forEach((tr) => pc.addTrack(tr, stream));
    // 720p/30: tasa máxima explícita y preferir mantener la resolución (si no, WebRTC baja a 320x180 al arrancar).
    const vs = pc.getSenders().find((x) => x.track && x.track.kind === 'video');
    stream.getVideoTracks()[0].contentHint = 'motion';
    const prm = vs.getParameters(); prm.encodings = [{ maxBitrate: 2500000, maxFramerate: 30 }]; prm.degradationPreference = 'maintain-resolution';
    await vs.setParameters(prm).catch((e) => console.log('setParameters', e.message));
    window.dev.vs = vs; window.dev.vtrack = stream.getVideoTracks()[0];
    pc.onicecandidate = (e) => { if (e.candidate) sealed({ type: 'ice', candidate: e.candidate.candidate, sdpMid: e.candidate.sdpMid, sdpMLineIndex: e.candidate.sdpMLineIndex }); };
    await pc.setLocalDescription(await pc.createOffer());
    await sealed({ type: 'offer', sdp: pc.localDescription.sdp });
    setInterval(() => sealed({ type: 'telemetry', t: Date.now(), width: 1280, height: 720, fps: 30, encoder: 'simulado-chromium', hardwareEncoder: false, batteryPct: 100, lens: 'trasera', zoom: 1, audioOn: true }), 2000);
  }
  return true;
};`;

(async () => {
  const srv = spawn(BIN, ['--no-browser', '--port', String(PORT)], { stdio: process.env.E2E_DEBUG ? 'inherit' : 'ignore' });
  await sleep(3000);
  const browser = await chromium.launch({ args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream', '--no-sandbox', '--disable-features=LocalNetworkAccessChecks,BlockInsecurePrivateNetworkRequests,PrivateNetworkAccessSendPreflights'] });
  try {
    const studioCtx = await browser.newContext({ viewport: { width: 1280, height: 720 } });
    const studio = await studioCtx.newPage();
    studio.on('pageerror', (e) => { console.log('pageerror(studio):', e.message); failures++; });
    await studio.goto(`http://localhost:${PORT}/`);
    await studio.selectOption('#host', { index: 0 }).catch(() => {});
    await studio.click('#btn-create');
    await studio.waitForSelector('#pairing:not([hidden])');
    const uri = await studio.textContent('#pair-uri');
    await studio.waitForFunction(() => document.getElementById('qr').naturalWidth > 100, null, { timeout: 5000 }).catch(() => {});
    check('Crear estudio muestra QR y enlace de vinculación', uri.startsWith('hrms://pair?v=1') && await studio.$eval('#qr', (i) => i.naturalWidth > 100), uri.slice(0, 60));

    // Dispositivo simulado (contexto aparte, página servida desde localhost = contexto seguro para WebCrypto)
    const devCtx = await browser.newContext();
    await devCtx.grantPermissions(['camera', 'microphone']);
    const cam = await devCtx.newPage();
    await cam.route('http://localhost:9/**', (r) => r.fulfill({ contentType: 'text/html', body: '<html><body>cam</body></html>' }));
    cam.on('console', (m) => process.env.E2E_DEBUG && console.log('[cam]', m.text()));
    cam.on('pageerror', (e) => console.log('[cam pageerror]', e.message));
    await cam.goto('http://localhost:9/');
    await cam.evaluate(DEVICE_JS);
    // El host del QR puede ser la IP de la LAN o 127.0.0.1; ambas llegan al servidor local.
    await cam.evaluate(([u]) => startDevice(u, 'cam-e2e-1', 'pairing', 'Celular de prueba'), [uri]);

    await sleep(1500);
    console.log('estado dispositivo:', JSON.stringify(await cam.evaluate(() => ({ s: window.dev.state, e: window.dev.error, c: window.dev.closed }))));
    await studio.waitForSelector('text=SOLICITA VINCULARSE', { timeout: 10000 });
    check('El dispositivo aparece como solicitud pendiente', true);
    check('Una cámara pendiente NO es fuente todavía', (await studio.$$('.tile')).length === 0);
    await studio.click('button:has-text("Aceptar")');
    await studio.waitForSelector('.tile', { timeout: 10000 });
    check('Al aceptar, la cámara aparece como fuente', (await studio.$$('.tile')).length === 1);

    await studio.waitForSelector('.tile:has-text("EN LÍNEA")', { timeout: 20000 });
    check('Video WebRTC conectado (tile EN LÍNEA)', true);
    const readRx = () => studio.evaluate(async () => {
      const p = [...window.__studio.peers.values()][0]; const rep = await p.pc.getStats(); let o = null;
      rep.forEach((s) => { if (s.type === 'inbound-rtp' && s.kind === 'video') o = { w: s.frameWidth, h: s.frameHeight, fps: s.framesPerSecond, dec: s.framesDecoded }; });
      return o;
    });
    // La estimación de ancho de banda tarda unos segundos en subir: se espera hasta 30 s a 720p.
    let rx = null, t0 = Date.now();
    while (Date.now() - t0 < 30000) { rx = await readRx(); if (rx && rx.w === 1280 && rx.fps >= 25) break; if (rx && rx.w === 1280 && Date.now() - t0 > 12000) break; await sleep(1000); }
    console.log('  (720p alcanzado en', Math.round((Date.now() - t0) / 1000), 's)');
    check('Se recibe 1280×720', rx && rx.w === 1280 && rx.h === 720, JSON.stringify(rx));
    // Informativo: los fps en este sandbox dependen de la CPU y del códec por software; la meta 720p/30 se valida en dispositivos reales.
    console.log(`INFO fps recibidos en este entorno: ${rx && rx.fps} (no es una medición de un Android real)`);

    // Vista previa y Poner al aire son pasos separados
    await studio.click('.tile');
    check('Seleccionar prepara en Vista previa', await studio.evaluate(() => window.__studio.previewId === 'cam-e2e-1' && window.__studio.programId === null));
    check('Aún nada al aire', await cam.evaluate(() => window.dev.onair === null));
    await studio.click('#btn-take');
    await sleep(800);
    check('Poner al aire envía AL AIRE a la cámara', await cam.evaluate(() => window.dev.onair === true));
    check('El tile muestra PREVIA y AL AIRE', (await studio.textContent('.tile')).includes('AL AIRE'));
    await studio.screenshot({ path: `${OUT}/estudio-laptop.png` });

    // Congelamiento provocado: la cámara deja de enviar cuadros 1,5 s. El estudio debe medirlo (definición: > 200 ms sin cuadro nuevo).
    const antes = await studio.evaluate(() => { const m = window.__studio.meters.get('cam-e2e-1'); return m ? { c: m.count, max: m.maxMs, tot: m.totalMs } : null; });
    check('El estudio tiene medidor de congelamientos para la cámara', antes !== null, JSON.stringify(antes));
    await cam.evaluate(async () => { await window.dev.vs.replaceTrack(null); await new Promise((r) => setTimeout(r, 1500)); await window.dev.vs.replaceTrack(window.dev.vtrack); });
    await sleep(1500);
    const despues = await studio.evaluate(() => { const m = window.__studio.meters.get('cam-e2e-1'); return { c: m.count, max: m.maxMs, tot: m.totalMs }; });
    check('Un corte de 1,5 s se registra como congelamiento', despues.c > antes.c, JSON.stringify(despues));
    check('Se registra el congelamiento más largo (>= 1 s)', despues.max >= 1000, `(${Math.round(despues.max)} ms)`);
    check('Se acumula el tiempo total congelado', despues.tot - antes.tot >= 1000, `(${Math.round(despues.tot - antes.tot)} ms)`);

    // Telemetría firmada llega a la interfaz
    await sleep(2500);
    check('La telemetría de la cámara llega al panel de calidad', (await studio.textContent('#stats')).includes('simulado-chromium'));

    // Recuperación: se corta el canal y la cámara vuelve con su token SIN duplicar la fuente
    await cam.evaluate(() => { window.dev.pc.close(); window.dev.ws.close(); });
    await studio.waitForSelector('.tile.off', { timeout: 10000 });
    check('Al cortarse, la fuente pasa a "sin conexión" (no desaparece)', (await studio.$$('.tile')).length === 1);
    await cam.evaluate(() => { window.dev = { log: [], onair: null, state: null, error: null }; });
    await cam.evaluate(([u]) => startDevice(u, 'cam-e2e-1', 'token', 'Celular de prueba'), [uri]);
    await studio.waitForSelector('.tile:not(.off):has-text("EN LÍNEA")', { timeout: 20000 });
    check('Reconexión con token: sigue habiendo UNA sola fuente', (await studio.$$('.tile')).length === 1);
    let rec = [];
    for (let i = 0; i < 50 && rec.length === 0; i++) { rec = await studio.evaluate(() => (window.__studio.liveState.get('cam-e2e-1') || { all: [] }).all); if (!rec.length) await sleep(200); }
    check('Se mide el tiempo de reconexión (último cuadro antes → primer cuadro después)', rec.length === 1 && rec[0] > 1 && rec[0] < 60, `(${rec.map((x) => x.toFixed(1))} s)`);
    check('Reconexión: el estudio vuelve a avisar AL AIRE', await (async () => { for (let i = 0; i < 30; i++) { if (await cam.evaluate(() => window.dev.onair === true)) return true; await sleep(200); } return false; })());
    check('Reconexión: la fuente conserva su rol AL AIRE', (await studio.textContent('.tile')).includes('AL AIRE'));

    // Un intruso sin el secreto no puede vincularse
    const evil = await devCtx.newPage();
    await evil.route('http://localhost:9/**', (r) => r.fulfill({ contentType: 'text/html', body: '<html></html>' }));
    await evil.goto('http://localhost:9/'); await evil.evaluate(DEVICE_JS);
    const forged = uri.replace(/k=[^&]+/, 'k=AAAAAAAAAAAAAAAAAAAAAA');
    await evil.evaluate(([u]) => startDevice(u, 'intruso', 'pairing', 'Intruso'), [forged]);
    await sleep(1500);
    check('Un dispositivo con secreto falso es rechazado', (await evil.evaluate(() => window.dev.error)) === 'bad_proof');
    check('El intruso no aparece en la lista', !(await studio.textContent('#devices')).includes('Intruso'));

    // Desconectar desde el estudio
    await studio.click('button:has-text("Desconectar")');
    await studio.waitForSelector('.tile', { state: 'detached', timeout: 5000 });
    check('Desconectar elimina la fuente', (await studio.$$('.tile')).length === 0);
    check('El dispositivo desconectado recibe el cierre', await (async () => { for (let i = 0; i < 20; i++) { if (await cam.evaluate(() => window.dev.closed === true || window.dev.state === 'rejected')) return true; await sleep(200); } return false; })());

    const cab = await studio.evaluate(() => window.__studio.csvRows[0]);
    check('El CSV incluye congelamiento más largo, total congelado y reconexión', ['med_congelamiento_max_ms', 'med_congelado_total_ms', 'reconexion_s', 'pestana_visible'].every((k) => cab.includes(k)));
    const conRec = await studio.evaluate(() => { const i = window.__studio.csvRows[0].indexOf('reconexion_s'); return window.__studio.csvRows.slice(1).some((r) => r[i] !== ''); });
    check('La reconexión queda escrita en una fila del CSV', conRec);
    if (process.env.E2E_CSV) {
      const texto = await studio.evaluate(() => window.__studio.csvRows.map((r) => r.map((c) => JSON.stringify(String(c))).join(',')).join(String.fromCharCode(10)));
      fs.writeFileSync(process.env.E2E_CSV, texto);
    }
    const csv = await studio.evaluate(() => window.__studio.csvRows.length);
    check('Se generó registro de validación (CSV)', csv > 3, `(${csv} filas)`);
  } catch (e) {
    console.log('EXCEPCION', e.message); failures++;
  } finally {
    await browser.close(); srv.kill();
  }
  console.log(failures === 0 ? '\nTODO OK' : `\n${failures} FALLO(S)`);
  process.exit(failures === 0 ? 0 : 1);
})();
