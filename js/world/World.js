import { WorldObject } from './WorldObject.js';
import { Terrain } from './Terrain.js';
import { Water } from './Water.js';
import { Paths } from './Paths.js';
import { Bridges } from './Bridges.js';
import { Bases } from './Bases.js';
import { BuildSlots } from './BuildSlots.js';
import { nextId, registerExistingId, resetIdCounters } from '../utils/IDGenerator.js';

/** Rendering/authoring layer order (spec section 15). */
export const LAYER_ORDER = ['terrain', 'water', 'paths', 'objects', 'decoration', 'buildSlots', 'bases', 'editorOverlay'];

/**
 * Central world representation. Everything here is DATA - the demo battlefield
 * lives in maps/test/editor_test.json, never hardcoded into this class.
 */
export class World {
  constructor(mapData) {
    this.load(mapData);
  }

  load(mapData) {
    resetIdCounters();
    this.width = mapData.world?.width ?? 6000;
    this.height = mapData.world?.height ?? 4000;
    this.metadata = mapData.metadata ?? {};
    this.settings = { gridSize: 25, snapEnabled: true, ...mapData.settings };

    this.terrain = new Terrain(mapData.terrain);
    this.water = new Water(mapData.water);
    this.paths = new Paths(mapData.paths);
    this.bridges = new Bridges(mapData.bridges);
    this.bases = new Bases(mapData.bases);
    this.buildSlots = new BuildSlots(mapData.buildSlots);

    this.objects = (mapData.objects ?? []).map((o) => new WorldObject(o));
    this.objects.forEach((o) => registerExistingId(o.id));
  }

  addObject(data) {
    const obj = new WorldObject({ id: nextId('obj'), layer: 'objects', ...data });
    this.objects.push(obj);
    return obj;
  }

  removeObject(id) {
    this.objects = this.objects.filter((o) => o.id !== id);
  }

  getObject(id) {
    return this.objects.find((o) => o.id === id) ?? null;
  }

  insertObject(obj) {
    this.objects.push(obj);
  }

  /** All sprite-based collections that participate in click hit-testing/deletion. */
  getSpriteCollections() {
    return [
      { list: this.objects, remove: (id) => this.removeObject(id), insert: (o) => this.objects.push(o) },
      { list: this.bridges.getAll(), remove: (id) => this.bridges.remove(id), insert: (o) => this.bridges.items.push(o) },
      { list: this.bases.getAll(), remove: (id) => this.bases.remove(id), insert: (o) => this.bases.items.push(o) }
    ];
  }

  findObjectAt(worldX, worldY, assetManager) {
    const collections = this.getSpriteCollections();
    for (let ci = collections.length - 1; ci >= 0; ci--) {
      const list = collections[ci].list;
      for (let i = list.length - 1; i >= 0; i--) {
        if (list[i].containsPoint(worldX, worldY, assetManager)) return list[i];
      }
    }
    return null;
  }

  removeById(id) {
    for (const col of this.getSpriteCollections()) {
      if (col.list.some((o) => o.id === id)) {
        col.remove(id);
        return true;
      }
    }
    return false;
  }

  insertById(obj) {
    for (const col of this.getSpriteCollections()) {
      if (obj.type === 'bridge' && col.list === this.bridges.getAll()) return col.insert(obj);
      if ((obj.type === 'playerBase' || obj.type === 'enemyBase') && col.list === this.bases.getAll()) return col.insert(obj);
    }
    this.objects.push(obj);
  }

  render(ctx, assetManager, camera, editorState = {}) {
    this.terrain.render(ctx);
    this.water.render(ctx);
    this.paths.render(ctx, { showEditorOverlay: editorState.pathEditing, selectedPoint: editorState.selectedPathPoint });

    const drawable = [...this.objects, ...this.bridges.getAll()].sort((a, b) => a.y - b.y);
    for (const obj of drawable) obj.draw(ctx, assetManager);

    this.buildSlots.render(ctx, { visible: editorState.showBuildSlots !== false });
    for (const base of this.bases.getAll()) base.draw(ctx, assetManager);
  }

  serialize() {
    return {
      version: 1,
      metadata: { ...this.metadata, modified: new Date().toISOString() },
      world: { width: this.width, height: this.height },
      terrain: this.terrain.toJSON(),
      water: this.water.toJSON(),
      paths: this.paths.toJSON(),
      objects: this.objects.map((o) => o.toJSON()),
      bridges: this.bridges.toJSON(),
      buildSlots: this.buildSlots.toJSON(),
      bases: this.bases.toJSON(),
      settings: this.settings
    };
  }
}
