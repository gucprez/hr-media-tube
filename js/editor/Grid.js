/** Optional world grid + independent snap toggle (spec section 14). */
export class Grid {
  constructor({ size = 25, enabled = true, snapEnabled = true } = {}) {
    this.size = size;
    this.enabled = enabled;
    this.snapEnabled = snapEnabled;
  }

  snap(x, y) {
    if (!this.snapEnabled) return { x, y };
    return { x: Math.round(x / this.size) * this.size, y: Math.round(y / this.size) * this.size };
  }

  render(ctx, camera, world) {
    if (!this.enabled) return;
    const rect = camera.getVisibleWorldRect();
    const minX = Math.max(0, rect.x);
    const maxX = Math.min(world.width, rect.x + rect.width);
    const minY = Math.max(0, rect.y);
    const maxY = Math.min(world.height, rect.y + rect.height);
    const startX = Math.floor(minX / this.size) * this.size;
    const startY = Math.floor(minY / this.size) * this.size;

    ctx.save();
    ctx.strokeStyle = 'rgba(255,255,255,0.07)';
    ctx.lineWidth = 1 / camera.zoom;
    ctx.beginPath();
    for (let x = startX; x <= maxX; x += this.size) {
      ctx.moveTo(x, minY);
      ctx.lineTo(x, maxY);
    }
    for (let y = startY; y <= maxY; y += this.size) {
      ctx.moveTo(minX, y);
      ctx.lineTo(maxX, y);
    }
    ctx.stroke();
    ctx.restore();
  }
}
