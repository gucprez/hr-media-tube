// HUD mínimo de esta fase: un panel de estado (fps, cámara, terreno bajo el cursor)
// y un panel de controles. Ambos siguen el lenguaje visual del HUD final (paneles
// translúcidos con marco, esquinas cortadas, tipografía táctica) que se ampliará
// en la Fase 11 con recursos, minimapa y panel de selección.
export class DevHUD {
    constructor() {
        this.root = document.getElementById('dev-hud');
        this.fpsEl = document.getElementById('hud-fps');
        this.coordsEl = document.getElementById('hud-coords');
        this.zoomEl = document.getElementById('hud-zoom');
        this.terrainEl = document.getElementById('hud-terrain');

        this._fpsAccum = 0;
        this._fpsFrames = 0;
        this._fpsTimer = 0;
        this._lastFps = 0;
    }

    update(dt, camera, gameMap, mouseWorld) {
        this._fpsTimer += dt;
        this._fpsFrames++;
        if (this._fpsTimer >= 0.4) {
            this._lastFps = Math.round(this._fpsFrames / this._fpsTimer);
            this._fpsFrames = 0;
            this._fpsTimer = 0;
        }

        this.fpsEl.textContent = `${this._lastFps}`;
        this.coordsEl.textContent = `${Math.round(camera.x)}, ${Math.round(camera.y)}`;
        this.zoomEl.textContent = `${camera.zoom.toFixed(2)}x`;

        const tx = Math.floor(mouseWorld.x / gameMap.tileSize);
        const ty = Math.floor(mouseWorld.y / gameMap.tileSize);
        const type = gameMap.getTileTypeAt(tx, ty);
        this.terrainEl.textContent = type ? TERRAIN_LABELS[type] ?? type : '—';
    }
}

const TERRAIN_LABELS = {
    grass: 'Pradera',
    dirt: 'Tierra',
    rock: 'Roca',
    water: 'Agua',
    forest: 'Bosque',
    mountain: 'Montaña',
    road: 'Camino',
    buildable: 'Zona construible',
    obstacle: 'Obstáculo',
};
