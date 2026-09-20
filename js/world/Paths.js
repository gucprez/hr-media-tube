import { nextId, registerExistingId } from '../utils/IDGenerator.js';
import { distanceToSegment } from '../utils/Geometry.js';

/**
 * Editable navigation paths. Each path is an independent polyline of points;
 * editing one path never mutates another. The visual dirt-path stroke is the
 * "gameplay" rendering; waypoint markers/handles are drawn only as an editor
 * overlay and are never baked into the permanent map art.
 *
 * Future gameplay can consume this via world.paths.getPath(id) without any
 * rewrite of this module.
 */
export class Paths {
  constructor(entries = []) {
    this.paths = entries.map((e) => ({ id: e.id ?? nextId('path'), type: e.type ?? 'ground', points: (e.points ?? []).map((p) => ({ x: p.x, y: p.y })) }));
    this.paths.forEach((p) => registerExistingId(p.id));
  }

  createPath(type = 'ground') {
    const path = { id: nextId('path'), type, points: [] };
    this.paths.push(path);
    return path;
  }

  getPath(id) {
    return this.paths.find((p) => p.id === id) ?? null;
  }

  getAll() {
    return this.paths;
  }

  removePath(id) {
    this.paths = this.paths.filter((p) => p.id !== id);
  }

  addPoint(pathId, point, index = null) {
    const path = this.getPath(pathId);
    if (!path) return;
    if (index === null || index >= path.points.length) path.points.push(point);
    else path.points.splice(index, 0, point);
  }

  movePoint(pathId, index, x, y) {
    const path = this.getPath(pathId);
    if (!path?.points[index]) return;
    path.points[index].x = x;
    path.points[index].y = y;
  }

  deletePoint(pathId, index) {
    const path = this.getPath(pathId);
    if (!path) return;
    path.points.splice(index, 1);
  }

  hitTestPoint(worldX, worldY, radius = 14) {
    for (const path of this.paths) {
      for (let i = 0; i < path.points.length; i++) {
        const p = path.points[i];
        if (Math.hypot(p.x - worldX, p.y - worldY) <= radius) return { pathId: path.id, index: i };
      }
    }
    return null;
  }

  hitTestSegment(worldX, worldY, tolerance = 26) {
    for (const path of this.paths) {
      for (let i = 0; i < path.points.length - 1; i++) {
        const a = path.points[i];
        const b = path.points[i + 1];
        if (distanceToSegment(worldX, worldY, a.x, a.y, b.x, b.y) <= tolerance) return { pathId: path.id, index: i };
      }
    }
    return null;
  }

  render(ctx, options = {}) {
    for (const path of this.paths) {
      if (path.points.length < 2) continue;
      ctx.save();
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.strokeStyle = '#7a5c3c';
      ctx.lineWidth = 46;
      ctx.beginPath();
      ctx.moveTo(path.points[0].x, path.points[0].y);
      for (let i = 1; i < path.points.length; i++) ctx.lineTo(path.points[i].x, path.points[i].y);
      ctx.stroke();
      ctx.strokeStyle = '#9a7a52';
      ctx.lineWidth = 30;
      ctx.stroke();
      ctx.restore();
    }

    if (!options.showEditorOverlay) return;

    for (const path of this.paths) {
      if (!path.points.length) continue;
      ctx.save();
      ctx.strokeStyle = 'rgba(255,220,120,0.9)';
      ctx.lineWidth = 2;
      ctx.setLineDash([6, 4]);
      ctx.beginPath();
      path.points.forEach((p, i) => (i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y)));
      ctx.stroke();
      ctx.setLineDash([]);

      path.points.forEach((p, i) => {
        const isSelected = options.selectedPoint?.pathId === path.id && options.selectedPoint?.index === i;
        ctx.fillStyle = isSelected ? '#ffcf4d' : '#ffffff';
        ctx.beginPath();
        ctx.arc(p.x, p.y, 7, 0, Math.PI * 2);
        ctx.fill();
        ctx.strokeStyle = '#20242c';
        ctx.lineWidth = 2;
        ctx.stroke();
      });
      ctx.restore();
    }
  }

  toJSON() {
    return this.paths.map((p) => ({ id: p.id, type: p.type, points: p.points.map((pt) => ({ x: pt.x, y: pt.y })) }));
  }
}
