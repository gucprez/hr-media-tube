// Bucle de juego desacoplado del resto del motor: sólo mide deltaTime y llama a un
// callback. Así Game.js puede tratar INPUT -> UPDATE -> RENDER como una función pura
// del tiempo transcurrido, sin que la velocidad dependa del framerate real.
export class GameLoop {
    constructor(callback) {
        this.callback = callback;
        this.running = false;
        this._lastTimestamp = 0;
        this._tick = this._tick.bind(this);
    }

    start() {
        if (this.running) return;
        this.running = true;
        // `null` en vez de `performance.now()`: así el primer frame real fija su
        // propio punto de partida (ver más abajo) en lugar de medirse contra un
        // instante anterior a la creación del mundo, que puede haber bloqueado el
        // hilo principal varios segundos y dejar un timestamp de rAF "atrasado".
        this._lastTimestamp = null;
        requestAnimationFrame(this._tick);
    }

    stop() {
        this.running = false;
    }

    _tick(timestamp) {
        if (!this.running) return;
        if (this._lastTimestamp === null) this._lastTimestamp = timestamp;
        const dt = Math.max(0, Math.min((timestamp - this._lastTimestamp) / 1000, 0.1));
        this._lastTimestamp = timestamp;
        this.callback(dt);
        requestAnimationFrame(this._tick);
    }
}
