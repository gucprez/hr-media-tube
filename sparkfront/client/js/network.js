// Pequeño bus de eventos para que la UI (DOM) y la escena de Phaser se
// comuniquen sin acoplarse directamente entre sí.
class MiniBus {
  constructor() { this.handlers = {}; }
  on(event, fn) {
    (this.handlers[event] = this.handlers[event] || []).push(fn);
    return fn;
  }
  off(event, fn) {
    if (!this.handlers[event]) return;
    this.handlers[event] = this.handlers[event].filter((h) => h !== fn);
  }
  emit(event, ...args) {
    (this.handlers[event] || []).forEach((fn) => fn(...args));
  }
}
window.GameEvents = new MiniBus();

// Capa de red: envuelve socket.io-client. El cliente solo envía "intenciones";
// toda la validación y el estado real del juego viven en el servidor.
const Network = (() => {
  const socket = io();

  function on(event, cb) {
    socket.on(event, cb);
  }

  function createRoom(name) {
    return new Promise((resolve, reject) => {
      socket.emit('lobby:create', { name }, (res) => {
        if (!res || res.error) reject((res && res.error) || 'Error al crear la sala');
        else resolve(res);
      });
    });
  }

  function joinRoom(code, name) {
    return new Promise((resolve, reject) => {
      socket.emit('lobby:join', { code, name }, (res) => {
        if (!res || res.error) reject((res && res.error) || 'Error al unirse a la sala');
        else resolve(res);
      });
    });
  }

  function startGame() {
    socket.emit('lobby:start');
  }

  function build(type, x, y) {
    socket.emit('game:build', { type, x, y });
  }

  function produce(buildingId, unitType) {
    socket.emit('game:produce', { buildingId, unitType });
  }

  function command(unitIds, x, y, targetId) {
    socket.emit('game:command', { unitIds, x, y, targetId: targetId || null });
  }

  function chat(text) {
    socket.emit('game:chat', { text });
  }

  return { socket, on, createRoom, joinRoom, startGame, build, produce, command, chat };
})();
