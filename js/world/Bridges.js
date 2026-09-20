import { WorldObject } from './WorldObject.js';
import { nextId, registerExistingId } from '../utils/IDGenerator.js';

/** Bridges are independent transform-able objects that visually align with water + paths. */
export class Bridges {
  constructor(entries = []) {
    this.items = entries.map(
      (e) =>
        new WorldObject({
          id: e.id ?? nextId('bridge'),
          type: 'bridge',
          asset: e.asset ?? 'bridge_wood_01',
          x: e.x,
          y: e.y,
          rotation: e.rotation ?? 0,
          scaleX: e.scale ?? e.scaleX ?? 1,
          scaleY: e.scale ?? e.scaleY ?? 1,
          layer: 'objects'
        })
    );
    this.items.forEach((i) => registerExistingId(i.id));
  }

  add(data) {
    const obj = new WorldObject({ id: nextId('bridge'), type: 'bridge', layer: 'objects', asset: 'bridge_wood_01', ...data });
    this.items.push(obj);
    return obj;
  }

  remove(id) {
    this.items = this.items.filter((i) => i.id !== id);
  }

  getAll() {
    return this.items;
  }

  toJSON() {
    return this.items.map((i) => ({ id: i.id, type: i.type, asset: i.asset, x: i.x, y: i.y, rotation: i.rotation, scale: i.scaleX }));
  }
}
