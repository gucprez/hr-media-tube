import { clamp } from './utils.js';

// Cámara RTS clásica: posición en coordenadas de mundo (centro de la vista),
// nivel de zoom y límites del mapa. Traduce mundo <-> pantalla para todo el render.
export class Camera {
    constructor(viewportWidth, viewportHeight) {
        this.x = 0;
        this.y = 0;
        this.zoom = 1;
        this.minZoom = 0.5;
        this.maxZoom = 2.2;
        this.viewportWidth = viewportWidth;
        this.viewportHeight = viewportHeight;
        this.mapWidth = 0;
        this.mapHeight = 0;

        // Objetivo de seguimiento opcional (p.ej. una unidad o un héroe).
        this.followTarget = null;
    }

    setMapBounds(mapWidthPx, mapHeightPx) {
        this.mapWidth = mapWidthPx;
        this.mapHeight = mapHeightPx;
    }

    setViewportSize(w, h) {
        this.viewportWidth = w;
        this.viewportHeight = h;
    }

    follow(target) {
        this.followTarget = target;
    }

    stopFollowing() {
        this.followTarget = null;
    }

    pan(dxWorld, dyWorld) {
        this.followTarget = null;
        this.x += dxWorld;
        this.y += dyWorld;
        this.clampToBounds();
    }

    zoomAt(screenX, screenY, factor) {
        const before = this.screenToWorld(screenX, screenY);
        this.zoom = clamp(this.zoom * factor, this.minZoom, this.maxZoom);
        const after = this.screenToWorld(screenX, screenY);
        this.x += before.x - after.x;
        this.y += before.y - after.y;
        this.clampToBounds();
    }

    update(dt) {
        if (this.followTarget) {
            this.x = this.followTarget.x;
            this.y = this.followTarget.y;
        }
        this.clampToBounds();
    }

    clampToBounds() {
        if (this.mapWidth <= 0 || this.mapHeight <= 0) return;
        const halfW = this.viewportWidth / 2 / this.zoom;
        const halfH = this.viewportHeight / 2 / this.zoom;

        if (halfW * 2 >= this.mapWidth) {
            this.x = this.mapWidth / 2;
        } else {
            this.x = clamp(this.x, halfW, this.mapWidth - halfW);
        }

        if (halfH * 2 >= this.mapHeight) {
            this.y = this.mapHeight / 2;
        } else {
            this.y = clamp(this.y, halfH, this.mapHeight - halfH);
        }
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

    // Rectángulo de mundo actualmente visible, útil para culling de tiles/entidades.
    getVisibleWorldRect(margin = 0) {
        const halfW = this.viewportWidth / 2 / this.zoom + margin;
        const halfH = this.viewportHeight / 2 / this.zoom + margin;
        return {
            left: this.x - halfW,
            right: this.x + halfW,
            top: this.y - halfH,
            bottom: this.y + halfH,
        };
    }
}
