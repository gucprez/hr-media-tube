import { nextId, registerExistingId } from '../utils/IDGenerator.js';

const TERRAIN_COLORS = {
  grass: '#3f5b34',
  dirt: '#6b4f36',
  mud: '#4a3a2a',
  stone: '#5f6266'
};

/** Data-driven terrain patches. Rendering blends each patch's edges so the map never reads as a flat single color. */
export class Terrain {
  constructor(entries = []) {
    this.entries = entries.map((e) => ({ id: e.id ?? nextId('terrain'), ...e }));
    this.entries.forEach((e) => registerExistingId(e.id));
  }

  add(entry) {
    const record = { id: nextId('terrain'), type: 'grass', x: 0, y: 0, width: 200, height: 200, ...entry };
    this.entries.push(record);
    return record;
  }

  remove(id) {
    this.entries = this.entries.filter((e) => e.id !== id);
  }

  getAll() {
    return this.entries;
  }

  render(ctx) {
    for (const patch of this.entries) {
      const color = TERRAIN_COLORS[patch.type] ?? TERRAIN_COLORS.grass;
      ctx.fillStyle = color;
      ctx.fillRect(patch.x, patch.y, patch.width, patch.height);

      const gradient = ctx.createLinearGradient(patch.x, patch.y, patch.x, patch.y + patch.height);
      gradient.addColorStop(0, 'rgba(255,255,255,0.05)');
      gradient.addColorStop(0.5, 'rgba(0,0,0,0)');
      gradient.addColorStop(1, 'rgba(0,0,0,0.12)');
      ctx.fillStyle = gradient;
      ctx.fillRect(patch.x, patch.y, patch.width, patch.height);

      ctx.strokeStyle = 'rgba(0,0,0,0.15)';
      ctx.lineWidth = 18;
      ctx.strokeRect(patch.x, patch.y, patch.width, patch.height);
    }
  }

  toJSON() {
    return this.entries.map((e) => ({ ...e }));
  }
}
