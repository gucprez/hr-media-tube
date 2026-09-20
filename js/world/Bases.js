import { WorldObject } from './WorldObject.js';
import { nextId, registerExistingId } from '../utils/IDGenerator.js';

/**
 * Player/enemy base markers. Phase 1 keeps these as plain world objects
 * (position/rotation/scale/selection/deletion only) - future phases attach
 * buildings, workers, resources, HP and spawning without changing this shape.
 */
export class Bases {
  constructor(entries = []) {
    this.items = entries.map(
      (e) =>
        new WorldObject({
          id: e.id ?? nextId('base'),
          type: e.type,
          asset: e.asset ?? (e.type === 'playerBase' ? 'base_player_marker' : 'base_enemy_marker'),
          x: e.x,
          y: e.y,
          rotation: e.rotation ?? 0,
          scaleX: e.scale ?? e.scaleX ?? 1,
          scaleY: e.scale ?? e.scaleY ?? 1,
          layer: 'bases'
        })
    );
    this.items.forEach((i) => registerExistingId(i.id));
  }

  add(data) {
    const obj = new WorldObject({ id: nextId('base'), layer: 'bases', ...data });
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
