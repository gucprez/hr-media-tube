import { nextId, registerExistingId } from '../utils/IDGenerator.js';

const WATER_COLORS = {
  river: '#2f5266',
  deep: '#203a4a',
  shallow: '#3d6b78',
  riverbank: '#5a5038'
};

/**
 * Water bodies support either a polygon (`points`) or an axis-aligned rect
 * (`x,y,width,height`) so the same module covers rivers, banks and ponds.
 * Kept simple by design - animated ripples/reflections/navigation are later phases.
 */
export class Water {
  constructor(entries = []) {
    this.entries = entries.map((e) => ({ id: e.id ?? nextId('water'), ...e }));
    this.entries.forEach((e) => registerExistingId(e.id));
  }

  add(entry) {
    const record = { id: nextId('water'), type: 'river', ...entry };
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
    for (const body of this.entries) {
      ctx.fillStyle = WATER_COLORS[body.type] ?? WATER_COLORS.river;
      ctx.beginPath();
      if (Array.isArray(body.points) && body.points.length) {
        ctx.moveTo(body.points[0].x, body.points[0].y);
        for (const p of body.points.slice(1)) ctx.lineTo(p.x, p.y);
        ctx.closePath();
      } else {
        ctx.rect(body.x, body.y, body.width, body.height);
      }
      ctx.fill();

      if (body.type === 'river' || body.type === 'deep' || body.type === 'shallow') {
        ctx.strokeStyle = 'rgba(255,255,255,0.08)';
        ctx.lineWidth = 3;
        ctx.stroke();
      }
    }
  }

  toJSON() {
    return this.entries.map((e) => ({ ...e }));
  }
}
