/**
 * Tracks the single active selection. `entry` shapes:
 *   { kind: 'object', ref: WorldObject }
 *   { kind: 'buildSlot', ref: buildSlotRecord }
 *   { kind: 'pathPoint', pathId, index }
 */
export class Selection {
  constructor(onChange) {
    this.selected = null;
    this.onChange = onChange;
  }

  select(entry) {
    this.selected = entry;
    this.onChange?.(entry);
  }

  clear() {
    this.selected = null;
    this.onChange?.(null);
  }

  get() {
    return this.selected;
  }

  render(ctx, camera, assetManager) {
    if (!this.selected) return;
    const { kind, ref } = this.selected;

    if (kind === 'object' && ref) {
      const corners = ref.getBoundingCorners(assetManager);
      ctx.save();
      ctx.strokeStyle = '#ffcf4d';
      ctx.lineWidth = 2 / camera.zoom;
      ctx.beginPath();
      corners.forEach((c, i) => (i === 0 ? ctx.moveTo(c.x, c.y) : ctx.lineTo(c.x, c.y)));
      ctx.closePath();
      ctx.stroke();

      ctx.fillStyle = '#ffcf4d';
      ctx.beginPath();
      ctx.arc(ref.x, ref.y, 4 / camera.zoom, 0, Math.PI * 2);
      ctx.fill();
      ctx.restore();
    } else if (kind === 'buildSlot' && ref) {
      ctx.save();
      ctx.strokeStyle = '#ffcf4d';
      ctx.lineWidth = 2 / camera.zoom;
      ctx.beginPath();
      ctx.arc(ref.x, ref.y, ref.radius, 0, Math.PI * 2);
      ctx.stroke();
      ctx.restore();
    }
  }
}
