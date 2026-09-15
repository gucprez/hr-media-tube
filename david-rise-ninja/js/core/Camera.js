import { clamp, damp } from './utils.js';

const ZOOM_STEPS = [0.7, 1.0, 1.3, 1.6, 2.0];

// Cámara RTS independiente del mundo: posición y zoom "objetivo" que se persiguen
// con suavizado (damping), para que el paneo y el zoom se sientan fluidos en vez
// de saltar de golpe. Convierte mundo <-> pantalla para todo el pipeline de render.
export class Camera {
    constructor(viewportWidth, viewportHeight) {
        this.x = 0;
        this.y = 0;
        this.zoom = 1;
        this.targetX = 0;
        this.targetY = 0;
        this.targetZoom = 1;
        this.smoothing = 0.0001; // menor = respuesta más rápida (ver damp())
        this.viewportWidth = viewportWidth;
        this.viewportHeight = viewportHeight;
        this.mapWidth = 0;
        this.mapHeight = 0;
    }

    setMapBounds(w, h) {
        this.mapWidth = w;
        this.mapHeight = h;
    }

    setViewportSize(w, h) {
        this.viewportWidth = w;
        this.viewportHeight = h;
    }

    panTarget(dxWorld, dyWorld) {
        this.targetX += dxWorld;
        this.targetY += dyWorld;
    }

    zoomStepAt(screenX, screenY, direction) {
        const idx = ZOOM_STEPS.reduce(
            (best, z, i) => (Math.abs(z - this.targetZoom) < Math.abs(ZOOM_STEPS[best] - this.targetZoom) ? i : best),
            0
        );
        const nextIdx = clamp(idx + direction, 0, ZOOM_STEPS.length - 1);
        const newZoom = ZOOM_STEPS[nextIdx];

        // Ajusta el objetivo de paneo para que el punto de mundo bajo el cursor
        // se mantenga fijo mientras cambia el zoom.
        const before = this.screenToWorld(screenX, screenY);
        this.targetZoom = newZoom;
        const halfW = this.viewportWidth / 2 / newZoom;
        const halfH = this.viewportHeight / 2 / newZoom;
        const after = {
            x: (screenX - this.viewportWidth / 2) / newZoom + this.targetX,
            y: (screenY - this.viewportHeight / 2) / newZoom + this.targetY,
        };
        this.targetX += before.x - after.x;
        this.targetY += before.y - after.y;
        this._clampTarget(halfW, halfH);
    }

    _clampTarget(halfW, halfH) {
        if (this.mapWidth <= 0 || this.mapHeight <= 0) return;
        this.targetX = halfW * 2 >= this.mapWidth ? this.mapWidth / 2 : clamp(this.targetX, halfW, this.mapWidth - halfW);
        this.targetY = halfH * 2 >= this.mapHeight ? this.mapHeight / 2 : clamp(this.targetY, halfH, this.mapHeight - halfH);
    }

    update(dt) {
        const halfW = this.viewportWidth / 2 / this.targetZoom;
        const halfH = this.viewportHeight / 2 / this.targetZoom;
        this._clampTarget(halfW, halfH);

        this.x = damp(this.x, this.targetX, this.smoothing, dt);
        this.y = damp(this.y, this.targetY, this.smoothing, dt);
        this.zoom = damp(this.zoom, this.targetZoom, this.smoothing, dt);
    }

    worldToScreen(wx, wy) {
        return {
            x: (wx - this.x) * this.zoom + this.viewportWidth / 2,
            y: (wy - this.y) * this.zoom + this.viewportHeight / 2,
        };
    }

    screenToWorld(sx, sy) {
        return {
            x: (sx - this.viewportWidth / 2) / this.zoom + this.x,
            y: (sy - this.viewportHeight / 2) / this.zoom + this.y,
        };
    }

    getVisibleWorldRect(margin = 0) {
        const halfW = this.viewportWidth / 2 / this.zoom + margin;
        const halfH = this.viewportHeight / 2 / this.zoom + margin;
        return { left: this.x - halfW, right: this.x + halfW, top: this.y - halfH, bottom: this.y + halfH };
    }
}
