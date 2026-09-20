import { nextId, registerExistingId } from '../utils/IDGenerator.js';
import { pointInCircle } from '../utils/Geometry.js';

/**
 * Buildable-position markers. Visible in the editor; a future gameplay layer
 * hides them during normal play and queries world.buildSlots.getAvailableSlots().
 */
export class BuildSlots {
  constructor(entries = []) {
    this.items = entries.map((e) => ({ id: e.id ?? nextId('slot'), x: e.x, y: e.y, radius: e.radius ?? 80, allowedCategories: e.allowedCategories ?? [] }));
    this.items.forEach((i) => registerExistingId(i.id));
  }

  add(data) {
    const slot = { id: nextId('slot'), radius: 80, allowedCategories: [], ...data };
    this.items.push(slot);
    return slot;
  }

  remove(id) {
    this.items = this.items.filter((s) => s.id !== id);
  }

  getAll() {
    return this.items;
  }

  getAvailableSlots() {
    return this.items;
  }

  hitTest(x, y) {
    return this.items.find((s) => pointInCircle(x, y, s.x, s.y, s.radius)) ?? null;
  }

  render(ctx, { visible = true } = {}) {
    if (!visible) return;
    for (const slot of this.items) {
      ctx.save();
      ctx.strokeStyle = 'rgba(120,200,255,0.85)';
      ctx.setLineDash([8, 6]);
      ctx.lineWidth = 2;
      ctx.beginPath();
      ctx.arc(slot.x, slot.y, slot.radius, 0, Math.PI * 2);
      ctx.stroke();
      ctx.setLineDash([]);
      ctx.fillStyle = 'rgba(120,200,255,0.12)';
      ctx.fill();
      ctx.restore();
    }
  }

  toJSON() {
    return this.items.map((s) => ({ ...s }));
  }
}
