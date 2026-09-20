/**
 * Centralizes DOM input handling and re-emits a small set of canvas-local events.
 * Coordinates delivered to listeners are in SCREEN space (canvas-local pixels);
 * consumers convert to world space via Camera.screenToWorld when needed.
 */
export class Input {
  constructor(canvas) {
    this.canvas = canvas;
    this.keys = new Set();
    this.mouse = { x: 0, y: 0, buttons: 0 };
    this.listeners = {};
    this._bind();
  }

  on(event, handler) {
    (this.listeners[event] ??= []).push(handler);
  }

  _emit(event, payload) {
    for (const handler of this.listeners[event] ?? []) handler(payload);
  }

  _bind() {
    const canvas = this.canvas;

    canvas.addEventListener('pointerdown', (e) => {
      canvas.setPointerCapture(e.pointerId);
      this.mouse.buttons = e.buttons;
      this._updateMouse(e);
      this._emit('pointerdown', this._pointerPayload(e));
    });

    canvas.addEventListener('pointermove', (e) => {
      this._updateMouse(e);
      this._emit('pointermove', this._pointerPayload(e));
    });

    window.addEventListener('pointerup', (e) => {
      this.mouse.buttons = e.buttons;
      this._emit('pointerup', this._pointerPayload(e));
    });

    canvas.addEventListener(
      'wheel',
      (e) => {
        e.preventDefault();
        this._emit('wheel', { x: this._localX(e), y: this._localY(e), deltaY: e.deltaY });
      },
      { passive: false }
    );

    canvas.addEventListener('contextmenu', (e) => e.preventDefault());

    window.addEventListener('keydown', (e) => {
      this.keys.add(e.code);
      this._emit('keydown', e);
    });

    window.addEventListener('keyup', (e) => {
      this.keys.delete(e.code);
      this._emit('keyup', e);
    });

    window.addEventListener('blur', () => this.keys.clear());
  }

  _localX(e) {
    const rect = this.canvas.getBoundingClientRect();
    return e.clientX - rect.left;
  }

  _localY(e) {
    const rect = this.canvas.getBoundingClientRect();
    return e.clientY - rect.top;
  }

  _updateMouse(e) {
    this.mouse.x = this._localX(e);
    this.mouse.y = this._localY(e);
  }

  _pointerPayload(e) {
    return {
      x: this._localX(e),
      y: this._localY(e),
      button: e.button,
      buttons: e.buttons,
      shiftKey: e.shiftKey,
      ctrlKey: e.ctrlKey || e.metaKey,
      altKey: e.altKey
    };
  }

  isKeyDown(code) {
    return this.keys.has(code);
  }
}
