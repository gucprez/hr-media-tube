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
        this._lastTimestamp = performance.now();
        requestAnimationFrame(this._tick);
    }

    stop() {
        this.running = false;
    }

    _tick(timestamp) {
        if (!this.running) return;
        const dt = Math.min((timestamp - this._lastTimestamp) / 1000, 0.1);
        this._lastTimestamp = timestamp;
        this.callback(dt);
        requestAnimationFrame(this._tick);
    }
}
