import { makeCanvas } from '../core/utils.js';

const TERRAIN_COLORS = {
    grass: '#4a8a3c',
    dirt: '#8a6a45',
    path: '#c2a56f',
    water: '#2f6f96',
    forest: '#233d1f',
    rock: '#7c7975',
    mountain: '#4d5563',
    village: '#c2a877',
};

// Minimapa básico: la forma del terreno se pre-renderiza UNA vez (el mapa es estático
// en esta fase) en un canvas pequeño; cada frame sólo se repinta el rectángulo de la
// cámara encima, que es barato. Todavía no dibuja unidades ni enemigos (no existen).
export class Minimap {
    constructor(canvas, world) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.world = world;
        this.base = this._buildBase();
    }

    _buildBase() {
        const w = this.canvas.width;
        const h = this.canvas.height;
        const base = makeCanvas(w, h);
        const bctx = base.getContext('2d');
        const map = this.world.map;

        for (let y = 0; y < h; y++) {
            for (let x = 0; x < w; x++) {
                const tx = Math.floor((x / w) * map.width);
                const ty = Math.floor((y / h) * map.height);
                bctx.fillStyle = TERRAIN_COLORS[map.getTileTypeAt(tx, ty)] || '#111';
                bctx.fillRect(x, y, 1, 1);
            }
        }
        return base;
    }

    render(camera) {
        const ctx = this.ctx;
        const w = this.canvas.width;
        const h = this.canvas.height;
        const map = this.world.map;

        ctx.clearRect(0, 0, w, h);
        ctx.drawImage(this.base, 0, 0);

        const view = camera.getVisibleWorldRect();
        const sx = (view.left / map.pixelWidth) * w;
        const sy = (view.top / map.pixelHeight) * h;
        const sw = ((view.right - view.left) / map.pixelWidth) * w;
        const sh = ((view.bottom - view.top) / map.pixelHeight) * h;

        ctx.strokeStyle = 'rgba(255,255,255,0.9)';
        ctx.lineWidth = 1.5;
        ctx.strokeRect(sx, sy, sw, sh);

        ctx.strokeStyle = 'rgba(216,180,95,0.85)';
        ctx.lineWidth = 2;
        ctx.strokeRect(0.5, 0.5, w - 1, h - 1);
    }
}
