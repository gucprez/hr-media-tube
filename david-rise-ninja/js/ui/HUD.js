// HUD deliberadamente mínimo para esta fase: título + etiqueta de build, y un panel
// de depuración opcional. Nada de recursos ni estadísticas de unidades todavía (no
// existen esos sistemas). El panel de selección/recursos llegará en la Fase 11.
export class HUD {
    constructor(config) {
        this.config = config;
        this.debugPanel = document.getElementById('debug-panel');
        this.fpsEl = document.getElementById('debug-fps');
        this.camEl = document.getElementById('debug-camera');
        this.zoomEl = document.getElementById('debug-zoom');
        this.mouseEl = document.getElementById('debug-mouse');
        this.mapSizeEl = document.getElementById('debug-mapsize');

        this.debug = config.get('debug', false);
        this._applyDebugVisibility();

        this._fpsFrames = 0;
        this._fpsTimer = 0;
        this._lastFps = 0;
    }

    toggleDebug() {
        this.debug = !this.debug;
        this.config.set('debug', this.debug);
        this._applyDebugVisibility();
    }

    _applyDebugVisibility() {
        this.debugPanel.hidden = !this.debug;
    }

    update(dt, camera, map, mouseWorld) {
        if (!this.debug) return;

        this._fpsTimer += dt;
        this._fpsFrames++;
        if (this._fpsTimer >= 0.4) {
            this._lastFps = Math.round(this._fpsFrames / this._fpsTimer);
            this._fpsFrames = 0;
            this._fpsTimer = 0;
        }

        this.fpsEl.textContent = `${this._lastFps}`;
        this.camEl.textContent = `${Math.round(camera.x)}, ${Math.round(camera.y)}`;
        this.zoomEl.textContent = `${camera.zoom.toFixed(2)}x`;
        this.mouseEl.textContent = `${Math.round(mouseWorld.x)}, ${Math.round(mouseWorld.y)}`;
        this.mapSizeEl.textContent = `${map.width}x${map.height} tiles (${map.pixelWidth}x${map.pixelHeight}px)`;
    }
}
