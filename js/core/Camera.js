import { clamp } from '../utils/MathUtils.js';

/**
 * Owns the world<->screen transform. Every world object stores WORLD coordinates;
 * this is the only place screen coordinates are computed.
 */
export class Camera {
  constructor({ worldWidth, worldHeight, viewportWidth, viewportHeight, minZoom = 0.35, maxZoom = 2.5 }) {
    this.worldWidth = worldWidth;
    this.worldHeight = worldHeight;
    this.viewportWidth = viewportWidth;
    this.viewportHeight = viewportHeight;
    this.minZoom = minZoom;
    this.maxZoom = maxZoom;
    this.zoom = Math.min(1, viewportWidth / worldWidth < 1 ? 1 : 1);
    this.x = worldWidth / 2;
    this.y = worldHeight / 2;
    this._clamp();
  }

  resize(width, height) {
    this.viewportWidth = width;
    this.viewportHeight = height;
    this._clamp();
  }

  pan(dx, dy) {
    this.x += dx;
    this.y += dy;
    this._clamp();
  }

  zoomAt(screenX, screenY, wheelDeltaY) {
    const worldBefore = this.screenToWorld(screenX, screenY);
    const factor = Math.exp(-wheelDeltaY * 0.0015);
    this.zoom = clamp(this.zoom * factor, this.minZoom, this.maxZoom);
    const worldAfter = this.screenToWorld(screenX, screenY);
    this.x += worldBefore.x - worldAfter.x;
    this.y += worldBefore.y - worldAfter.y;
    this._clamp();
  }

  setZoom(zoom, screenX = this.viewportWidth / 2, screenY = this.viewportHeight / 2) {
    const worldBefore = this.screenToWorld(screenX, screenY);
    this.zoom = clamp(zoom, this.minZoom, this.maxZoom);
    const worldAfter = this.screenToWorld(screenX, screenY);
    this.x += worldBefore.x - worldAfter.x;
    this.y += worldBefore.y - worldAfter.y;
    this._clamp();
  }

  worldToScreen(wx, wy) {
    return {
      x: (wx - this.x) * this.zoom + this.viewportWidth / 2,
      y: (wy - this.y) * this.zoom + this.viewportHeight / 2
    };
  }

  screenToWorld(sx, sy) {
    return {
      x: (sx - this.viewportWidth / 2) / this.zoom + this.x,
      y: (sy - this.viewportHeight / 2) / this.zoom + this.y
    };
  }

  getVisibleWorldRect() {
    const halfW = this.viewportWidth / (2 * this.zoom);
    const halfH = this.viewportHeight / (2 * this.zoom);
    return { x: this.x - halfW, y: this.y - halfH, width: halfW * 2, height: halfH * 2 };
  }

  /** Applies the camera transform to a canvas context already reset to identity. */
  applyTransform(ctx) {
    ctx.translate(this.viewportWidth / 2, this.viewportHeight / 2);
    ctx.scale(this.zoom, this.zoom);
    ctx.translate(-this.x, -this.y);
  }

  _clamp() {
    const halfW = this.viewportWidth / (2 * this.zoom);
    const halfH = this.viewportHeight / (2 * this.zoom);
    const margin = 500; // world units of allowed overscroll past the map edge
    this.x = clamp(this.x, -margin + halfW, this.worldWidth + margin - halfW);
    this.y = clamp(this.y, -margin + halfH, this.worldHeight + margin - halfH);
  }
}
