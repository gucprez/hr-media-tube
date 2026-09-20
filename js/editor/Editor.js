import { TOOLS, TOOL_ASSET_CATEGORIES, TOOL_OBJECT_META, ToolManager } from './ToolManager.js';
import { WorldObject } from '../world/WorldObject.js';
import { Grid } from './Grid.js';
import { History, createCommand } from './History.js';
import { Selection } from './Selection.js';
import { Inspector } from './Inspector.js';
import { drawTransformHandles, hitTestHandle, angleBetween } from './Transform.js';
import { MapSerializer, MapValidationError } from '../data/MapSerializer.js';
import { createEmptyMap } from '../data/MapData.js';
import { clamp } from '../utils/MathUtils.js';
import { nextId } from '../utils/IDGenerator.js';

const TERRAIN_TYPES = ['grass', 'dirt', 'mud', 'stone'];
const WATER_TYPES = ['river', 'shallow', 'deep', 'riverbank'];

/** Top-level orchestrator: wires DOM controls, owns interaction state, drives the render loop. */
export class Editor {
  constructor({ canvas, ctx, camera, input, renderer, world, assetManager, manifest, dom }) {
    this.canvas = canvas;
    this.ctx = ctx;
    this.camera = camera;
    this.input = input;
    this.renderer = renderer;
    this.world = world;
    this.assetManager = assetManager;
    this.manifest = manifest;
    this.dom = dom;

    this.grid = new Grid({ size: world.settings.gridSize, enabled: true, snapEnabled: world.settings.snapEnabled });
    this.history = new History(() => this._refreshHistoryButtons());
    this.selection = new Selection((entry) => this.inspector.show(entry));
    this.inspector = new Inspector(dom.inspectorContent, {
      onChange: (field, value) => this._onInspectorChange(field, value),
      onDelete: () => this._deleteSelection(),
      onDuplicate: () => this._duplicateSelection()
    });
    this.toolManager = new ToolManager((tool) => this._onToolChanged(tool));

    this.drag = null;
    this.activePathId = null;
    this.currentTerrainType = 'grass';
    this.currentWaterType = 'river';
    this.debugMode = false;
    this._lastFrameTime = performance.now();
    this._fps = 0;

    this._bindInput();
    this._bindDom();
    this._onToolChanged(this.toolManager.current);
    this.inspector.clear();
  }

  start() {
    const tick = (now) => {
      const dt = (now - this._lastFrameTime) / 1000;
      this._lastFrameTime = now;
      this._fps = dt > 0 ? Math.round(1 / dt) : this._fps;
      this._handleContinuousPan(dt);
      this._render();
      requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  }

  // ---------------------------------------------------------------- Input

  _bindInput() {
    this.input.on('pointerdown', (e) => this._onPointerDown(e));
    this.input.on('pointermove', (e) => this._onPointerMove(e));
    this.input.on('pointerup', (e) => this._onPointerUp(e));
    this.input.on('wheel', (e) => this.camera.zoomAt(e.x, e.y, e.deltaY));
    this.input.on('keydown', (e) => this._onKeyDown(e));
  }

  _onPointerDown(e) {
    if (e.button === 1) {
      this.drag = { type: 'pan', lastX: e.x, lastY: e.y };
      this.canvas.style.cursor = 'grabbing';
      return;
    }
    if (e.button === 2) {
      if (this.activePathId) this.activePathId = null;
      else this.selection.clear();
      this._refreshStatus();
      return;
    }
    if (e.button !== 0) return;

    const world = this.camera.screenToWorld(e.x, e.y);
    const tool = this.toolManager.current;

    const sel = this.selection.get();
    if (sel?.kind === 'object' && (tool === TOOLS.SELECT || tool === TOOLS.MOVE)) {
      const handle = hitTestHandle(world.x, world.y, sel.ref, this.assetManager, this.camera);
      if (handle === 'rotate') {
        this.drag = { type: 'rotate', ref: sel.ref, before: { rotation: sel.ref.rotation } };
        return;
      }
      if (handle === 'scale') {
        const dist = Math.max(1, Math.hypot(world.x - sel.ref.x, world.y - sel.ref.y));
        this.drag = { type: 'scale', ref: sel.ref, initialDist: dist, initialScaleX: sel.ref.scaleX, initialScaleY: sel.ref.scaleY, before: { scaleX: sel.ref.scaleX, scaleY: sel.ref.scaleY } };
        return;
      }
    }

    switch (tool) {
      case TOOLS.SELECT:
      case TOOLS.MOVE:
        this._handleSelectClick(world);
        break;
      case TOOLS.DELETE:
        this._handleDeleteClick(world);
        break;
      case TOOLS.TERRAIN: {
        const start = this.grid.snap(world.x, world.y);
        this.drag = { type: 'terrainDraw', start, current: world };
        break;
      }
      case TOOLS.WATER: {
        const start = this.grid.snap(world.x, world.y);
        this.drag = { type: 'waterDraw', start, current: world };
        break;
      }
      case TOOLS.PATH:
        this._handlePathClick(world);
        break;
      case TOOLS.BUILD_SLOT:
        this._placeBuildSlot(world);
        break;
      case TOOLS.PLAYER_BASE:
        this._placeBase('playerBase', world);
        break;
      case TOOLS.ENEMY_BASE:
        this._placeBase('enemyBase', world);
        break;
      default:
        if (this.toolManager.isAssetPlacementTool()) this._placeAssetObject(tool, world);
    }
  }

  _onPointerMove(e) {
    this._lastMouseScreen = { x: e.x, y: e.y };
    if (!this.drag) return;
    const world = this.camera.screenToWorld(e.x, e.y);

    switch (this.drag.type) {
      case 'pan': {
        const dx = (e.x - this.drag.lastX) / this.camera.zoom;
        const dy = (e.y - this.drag.lastY) / this.camera.zoom;
        this.camera.pan(-dx, -dy);
        this.drag.lastX = e.x;
        this.drag.lastY = e.y;
        break;
      }
      case 'moveObject': {
        const snapped = this.grid.snap(world.x - this.drag.offsetX, world.y - this.drag.offsetY);
        this.drag.ref.x = snapped.x;
        this.drag.ref.y = snapped.y;
        this.inspector.show(this.selection.get());
        break;
      }
      case 'moveBuildSlot': {
        const snapped = this.grid.snap(world.x - this.drag.offsetX, world.y - this.drag.offsetY);
        this.drag.ref.x = snapped.x;
        this.drag.ref.y = snapped.y;
        this.inspector.show(this.selection.get());
        break;
      }
      case 'rotate': {
        this.drag.ref.rotation = angleBetween(this.drag.ref.x, this.drag.ref.y, world.x, world.y);
        this.inspector.show(this.selection.get());
        break;
      }
      case 'scale': {
        const dist = Math.max(1, Math.hypot(world.x - this.drag.ref.x, world.y - this.drag.ref.y));
        const factor = dist / this.drag.initialDist;
        this.drag.ref.scaleX = clamp(this.drag.initialScaleX * factor, 0.1, 8);
        this.drag.ref.scaleY = clamp(this.drag.initialScaleY * factor, 0.1, 8);
        this.inspector.show(this.selection.get());
        break;
      }
      case 'pathPointMove': {
        const snapped = this.grid.snap(world.x, world.y);
        this.world.paths.movePoint(this.drag.pathId, this.drag.index, snapped.x, snapped.y);
        this.inspector.show(this.selection.get());
        break;
      }
      case 'terrainDraw':
      case 'waterDraw':
        this.drag.current = this.grid.snap(world.x, world.y);
        break;
      default:
        break;
    }
  }

  _onPointerUp() {
    if (!this.drag) return;
    const drag = this.drag;
    this.drag = null;
    this.canvas.style.cursor = this._cursorForTool(this.toolManager.current);

    switch (drag.type) {
      case 'moveObject':
      case 'rotate':
      case 'scale': {
        const ref = drag.ref;
        const before = drag.before;
        const after = { ...before };
        for (const key of Object.keys(before)) after[key] = ref[key];
        if (JSON.stringify(before) !== JSON.stringify(after)) {
          this.history.execute(createCommand(() => Object.assign(ref, after), () => Object.assign(ref, before)));
        }
        break;
      }
      case 'moveBuildSlot': {
        const ref = drag.ref;
        const before = drag.before;
        const after = { x: ref.x, y: ref.y };
        if (before.x !== after.x || before.y !== after.y) {
          this.history.execute(createCommand(() => Object.assign(ref, after), () => Object.assign(ref, before)));
        }
        break;
      }
      case 'pathPointMove': {
        const path = this.world.paths.getPath(drag.pathId);
        const point = path?.points[drag.index];
        if (point) {
          const before = drag.before;
          const after = { x: point.x, y: point.y };
          if (before.x !== after.x || before.y !== after.y) {
            this.history.execute(createCommand(() => Object.assign(point, after), () => Object.assign(point, before)));
          }
        }
        break;
      }
      case 'terrainDraw':
        this._commitTerrainDraw(drag);
        break;
      case 'waterDraw':
        this._commitWaterDraw(drag);
        break;
      default:
        break;
    }
  }

  _onKeyDown(e) {
    const target = e.target;
    const typing = target && (target.tagName === 'INPUT' || target.tagName === 'SELECT' || target.tagName === 'TEXTAREA');
    if (typing) return;

    if (e.ctrlKey && e.code === 'KeyZ') {
      e.preventDefault();
      this.history.undo();
      this.inspector.show(this.selection.get());
      return;
    }
    if (e.ctrlKey && (e.code === 'KeyY' || (e.shiftKey && e.code === 'KeyZ'))) {
      e.preventDefault();
      this.history.redo();
      this.inspector.show(this.selection.get());
      return;
    }
    if (e.ctrlKey && e.code === 'KeyD') {
      e.preventDefault();
      this._duplicateSelection();
      return;
    }
    if (e.code === 'Delete' || e.code === 'Backspace') {
      e.preventDefault();
      this._deleteSelection();
      return;
    }
    if (e.code === 'Escape') {
      this.activePathId = null;
      this.selection.clear();
      this._refreshStatus();
    }
  }

  _handleContinuousPan(dt) {
    const speed = 900 / this.camera.zoom;
    let dx = 0;
    let dy = 0;
    if (this.input.isKeyDown('KeyW') || this.input.isKeyDown('ArrowUp')) dy -= 1;
    if (this.input.isKeyDown('KeyS') || this.input.isKeyDown('ArrowDown')) dy += 1;
    if (this.input.isKeyDown('KeyA') || this.input.isKeyDown('ArrowLeft')) dx -= 1;
    if (this.input.isKeyDown('KeyD') || this.input.isKeyDown('ArrowRight')) dx += 1;
    if (dx !== 0 || dy !== 0) {
      const len = Math.hypot(dx, dy) || 1;
      this.camera.pan((dx / len) * speed * dt, (dy / len) * speed * dt);
    }
  }

  // ---------------------------------------------------------------- Tool actions

  _handleSelectClick(world) {
    const obj = this.world.findObjectAt(world.x, world.y, this.assetManager);
    if (obj) {
      this.selection.select({ kind: 'object', ref: obj });
      this.drag = { type: 'moveObject', ref: obj, offsetX: world.x - obj.x, offsetY: world.y - obj.y, before: { x: obj.x, y: obj.y } };
      return;
    }
    const slot = this.world.buildSlots.hitTest(world.x, world.y);
    if (slot) {
      this.selection.select({ kind: 'buildSlot', ref: slot });
      this.drag = { type: 'moveBuildSlot', ref: slot, offsetX: world.x - slot.x, offsetY: world.y - slot.y, before: { x: slot.x, y: slot.y } };
      return;
    }
    this.selection.clear();
  }

  _handleDeleteClick(world) {
    const obj = this.world.findObjectAt(world.x, world.y, this.assetManager);
    if (obj) return this._executeRemove({ kind: 'object', ref: obj });

    const slot = this.world.buildSlots.hitTest(world.x, world.y);
    if (slot) return this._executeRemove({ kind: 'buildSlot', ref: slot });

    const point = this.world.paths.hitTestPoint(world.x, world.y);
    if (point) return this._executeRemove({ kind: 'pathPoint', pathId: point.pathId, index: point.index });
  }

  _handlePathClick(world) {
    const hit = this.world.paths.hitTestPoint(world.x, world.y);
    if (hit) {
      this.activePathId = hit.pathId;
      const path = this.world.paths.getPath(hit.pathId);
      this.selection.select({ kind: 'pathPoint', pathId: hit.pathId, index: hit.index, point: path.points[hit.index] });
      this.drag = { type: 'pathPointMove', pathId: hit.pathId, index: hit.index, before: { x: path.points[hit.index].x, y: path.points[hit.index].y } };
      this._refreshStatus();
      return;
    }

    if (!this.activePathId) this._createNewPath();
    const point = this.grid.snap(world.x, world.y);
    const pathId = this.activePathId;
    const path = this.world.paths.getPath(pathId);
    const index = path.points.length;
    this.history.execute(
      createCommand(
        () => this.world.paths.addPoint(pathId, { x: point.x, y: point.y }, index),
        () => this.world.paths.deletePoint(pathId, index)
      )
    );
    this.selection.select({ kind: 'pathPoint', pathId, index, point: path.points[index] });
    this._refreshStatus();
  }

  _createNewPath() {
    const path = { id: nextId('path'), type: 'ground', points: [] };
    this.history.execute(
      createCommand(
        () => {
          if (!this.world.paths.paths.includes(path)) this.world.paths.paths.push(path);
        },
        () => {
          this.world.paths.paths = this.world.paths.paths.filter((p) => p !== path);
        }
      )
    );
    this.activePathId = path.id;
    this._refreshStatus();
    return path;
  }

  _placeBuildSlot(world) {
    const pos = this.grid.snap(world.x, world.y);
    const slot = { id: nextId('slot'), x: pos.x, y: pos.y, radius: 80, allowedCategories: [] };
    this.history.execute(
      createCommand(
        () => {
          if (!this.world.buildSlots.items.includes(slot)) this.world.buildSlots.items.push(slot);
        },
        () => {
          this.world.buildSlots.items = this.world.buildSlots.items.filter((s) => s !== slot);
        }
      )
    );
    this.selection.select({ kind: 'buildSlot', ref: slot });
  }

  _placeBase(type, world) {
    const pos = this.grid.snap(world.x, world.y);
    const asset = type === 'playerBase' ? 'base_player_marker' : 'base_enemy_marker';
    this._insertObjectCommand(
      { id: nextId('base'), type, asset, x: pos.x, y: pos.y, rotation: 0, layer: 'bases' },
      () => this.world.bases.items
    );
  }

  _placeAssetObject(tool, world) {
    const pos = this.grid.snap(world.x, world.y);
    const asset = this.toolManager.activeAsset ?? this.manifest.getByCategories(TOOL_ASSET_CATEGORIES[tool])[0]?.id;
    if (!asset) {
      console.error(`[Editor] No assets available for tool "${tool}".`);
      return;
    }

    if (tool === TOOLS.BRIDGE) {
      this._insertObjectCommand({ id: nextId('bridge'), type: 'bridge', asset, x: pos.x, y: pos.y, rotation: 0, layer: 'objects' }, () => this.world.bridges.items);
      return;
    }

    const meta = TOOL_OBJECT_META[tool];
    this._insertObjectCommand({ id: nextId('obj'), type: meta.type, asset, x: pos.x, y: pos.y, rotation: 0, layer: meta.layer }, () => this.world.objects);
  }

  _insertObjectCommand(data, getArray) {
    const obj = new WorldObject(data);
    this.history.execute(
      createCommand(
        () => {
          const arr = getArray();
          if (!arr.includes(obj)) arr.push(obj);
        },
        () => {
          const arr = getArray();
          const idx = arr.indexOf(obj);
          if (idx !== -1) arr.splice(idx, 1);
        }
      )
    );
    this.selection.select({ kind: 'object', ref: obj });
  }

  _getOwningArray(ref) {
    if (ref.type === 'bridge') return this.world.bridges.items;
    if (ref.type === 'playerBase' || ref.type === 'enemyBase') return this.world.bases.items;
    return this.world.objects;
  }

  _executeRemove(entry) {
    if (entry.kind === 'object') {
      const ref = entry.ref;
      const arr = this._getOwningArray(ref);
      this.history.execute(
        createCommand(
          () => {
            const idx = arr.indexOf(ref);
            if (idx !== -1) arr.splice(idx, 1);
          },
          () => {
            if (!arr.includes(ref)) arr.push(ref);
          }
        )
      );
    } else if (entry.kind === 'buildSlot') {
      const ref = entry.ref;
      const arr = this.world.buildSlots.items;
      this.history.execute(
        createCommand(
          () => {
            const idx = arr.indexOf(ref);
            if (idx !== -1) arr.splice(idx, 1);
          },
          () => {
            if (!arr.includes(ref)) arr.push(ref);
          }
        )
      );
    } else if (entry.kind === 'pathPoint') {
      const path = this.world.paths.getPath(entry.pathId);
      if (!path) return;
      const index = entry.index;
      const point = path.points[index];
      this.history.execute(
        createCommand(
          () => {
            const idx = path.points.indexOf(point);
            if (idx !== -1) path.points.splice(idx, 1);
          },
          () => {
            if (!path.points.includes(point)) path.points.splice(index, 0, point);
          }
        )
      );
    }
    if (this.selection.get() === entry || this.selection.get()?.ref === entry.ref) this.selection.clear();
  }

  _deleteSelection() {
    const entry = this.selection.get();
    if (!entry) return;
    this._executeRemove(entry);
    this.selection.clear();
  }

  _duplicateSelection() {
    const entry = this.selection.get();
    if (!entry) return;

    if (entry.kind === 'object') {
      const original = entry.ref;
      const prefix = original.type === 'bridge' ? 'bridge' : original.type === 'playerBase' || original.type === 'enemyBase' ? 'base' : 'obj';
      const clone = original.clone(nextId(prefix));
      const arr = this._getOwningArray(clone);
      this.history.execute(
        createCommand(
          () => {
            if (!arr.includes(clone)) arr.push(clone);
          },
          () => {
            const idx = arr.indexOf(clone);
            if (idx !== -1) arr.splice(idx, 1);
          }
        )
      );
      this.selection.select({ kind: 'object', ref: clone });
    } else if (entry.kind === 'buildSlot') {
      const original = entry.ref;
      const clone = { id: nextId('slot'), x: original.x + 40, y: original.y + 40, radius: original.radius, allowedCategories: [...original.allowedCategories] };
      const arr = this.world.buildSlots.items;
      this.history.execute(
        createCommand(
          () => {
            if (!arr.includes(clone)) arr.push(clone);
          },
          () => {
            const idx = arr.indexOf(clone);
            if (idx !== -1) arr.splice(idx, 1);
          }
        )
      );
      this.selection.select({ kind: 'buildSlot', ref: clone });
    }
  }

  _commitTerrainDraw(drag) {
    const x = Math.min(drag.start.x, drag.current.x);
    const y = Math.min(drag.start.y, drag.current.y);
    const width = Math.abs(drag.current.x - drag.start.x);
    const height = Math.abs(drag.current.y - drag.start.y);
    if (width < this.grid.size || height < this.grid.size) return;

    const record = { id: nextId('terrain'), type: this.currentTerrainType, x, y, width, height };
    this.history.execute(
      createCommand(
        () => {
          if (!this.world.terrain.entries.includes(record)) this.world.terrain.entries.push(record);
        },
        () => {
          this.world.terrain.entries = this.world.terrain.entries.filter((e) => e !== record);
        }
      )
    );
  }

  _commitWaterDraw(drag) {
    const x = Math.min(drag.start.x, drag.current.x);
    const y = Math.min(drag.start.y, drag.current.y);
    const width = Math.abs(drag.current.x - drag.start.x);
    const height = Math.abs(drag.current.y - drag.start.y);
    if (width < this.grid.size || height < this.grid.size) return;

    const record = { id: nextId('water'), type: this.currentWaterType, x, y, width, height };
    this.history.execute(
      createCommand(
        () => {
          if (!this.world.water.entries.includes(record)) this.world.water.entries.push(record);
        },
        () => {
          this.world.water.entries = this.world.water.entries.filter((e) => e !== record);
        }
      )
    );
  }

  _onInspectorChange(field, value) {
    const entry = this.selection.get();
    if (!entry) return;

    if (entry.kind === 'object') {
      const ref = entry.ref;
      const before = { [field]: ref[field] };
      const after = { [field]: value };
      this.history.execute(createCommand(() => Object.assign(ref, after), () => Object.assign(ref, before)));
    } else if (entry.kind === 'buildSlot') {
      const ref = entry.ref;
      const before = { [field]: ref[field] };
      const after = { [field]: value };
      this.history.execute(createCommand(() => Object.assign(ref, after), () => Object.assign(ref, before)));
    } else if (entry.kind === 'pathPoint') {
      const path = this.world.paths.getPath(entry.pathId);
      const point = path?.points[entry.index];
      if (!point) return;
      const before = { [field]: point[field] };
      const after = { [field]: value };
      this.history.execute(createCommand(() => Object.assign(point, after), () => Object.assign(point, before)));
    }
    this.inspector.show(this.selection.get());
  }

  // ---------------------------------------------------------------- DOM / UI

  _bindDom() {
    const dom = this.dom;

    for (const btn of dom.toolButtons) {
      btn.addEventListener('click', () => this.toolManager.setTool(btn.dataset.tool));
    }

    dom.btnNew.addEventListener('click', () => this._newMap());
    dom.btnOpen.addEventListener('click', () => dom.fileInput.click());
    dom.fileInput.addEventListener('change', (e) => this._openMapFromInput(e));
    dom.btnSave.addEventListener('click', () => this._saveMap(false));
    dom.btnSaveAs.addEventListener('click', () => this._saveMap(true));
    dom.btnUndo.addEventListener('click', () => {
      this.history.undo();
      this.inspector.show(this.selection.get());
    });
    dom.btnRedo.addEventListener('click', () => {
      this.history.redo();
      this.inspector.show(this.selection.get());
    });

    dom.gridToggle.addEventListener('change', () => (this.grid.enabled = dom.gridToggle.checked));
    dom.snapToggle.addEventListener('change', () => {
      this.grid.snapEnabled = dom.snapToggle.checked;
      this.world.settings.snapEnabled = dom.snapToggle.checked;
    });
    dom.gridSizeInput.addEventListener('change', () => {
      const size = Math.max(1, parseInt(dom.gridSizeInput.value, 10) || this.grid.size);
      this.grid.size = size;
      this.world.settings.gridSize = size;
    });

    dom.btnZoomIn.addEventListener('click', () => this.camera.setZoom(this.camera.zoom * 1.25));
    dom.btnZoomOut.addEventListener('click', () => this.camera.setZoom(this.camera.zoom / 1.25));
    dom.btnDebug.addEventListener('click', () => {
      this.debugMode = !this.debugMode;
      dom.debugOverlay.classList.toggle('hidden', !this.debugMode);
    });

    dom.terrainTypeSelect.addEventListener('change', () => (this.currentTerrainType = dom.terrainTypeSelect.value));
    dom.waterTypeSelect.addEventListener('change', () => (this.currentWaterType = dom.waterTypeSelect.value));
    dom.btnNewPath.addEventListener('click', () => this._createNewPath());

    dom.gridToggle.checked = this.grid.enabled;
    dom.snapToggle.checked = this.grid.snapEnabled;
    dom.gridSizeInput.value = this.grid.size;
    this._refreshHistoryButtons();
  }

  _onToolChanged(tool) {
    const dom = this.dom;
    for (const btn of dom.toolButtons) btn.classList.toggle('active', btn.dataset.tool === tool);

    dom.assetPalette.classList.toggle('hidden', !TOOL_ASSET_CATEGORIES[tool]);
    dom.terrainControls.classList.toggle('hidden', tool !== TOOLS.TERRAIN);
    dom.waterControls.classList.toggle('hidden', tool !== TOOLS.WATER);
    dom.pathControls.classList.toggle('hidden', tool !== TOOLS.PATH);

    if (TOOL_ASSET_CATEGORIES[tool]) this._renderAssetPalette(tool);
    if (tool !== TOOLS.PATH) this.activePathId = null;

    this.canvas.style.cursor = this._cursorForTool(tool);
    this._refreshStatus();
  }

  _cursorForTool(tool) {
    if (tool === TOOLS.DELETE) return 'not-allowed';
    if (tool === TOOLS.SELECT || tool === TOOLS.MOVE) return 'default';
    return 'crosshair';
  }

  _renderAssetPalette(tool) {
    const dom = this.dom;
    dom.assetPalette.innerHTML = '';
    const entries = this.manifest.getByCategories(TOOL_ASSET_CATEGORIES[tool]);
    entries.forEach((entry, i) => {
      const btn = document.createElement('button');
      btn.className = 'asset-swatch';
      btn.type = 'button';
      btn.title = entry.id;
      btn.textContent = entry.id.replace(/_/g, ' ');
      if (i === 0) {
        btn.classList.add('active');
        this.toolManager.setActiveAsset(entry.id);
      }
      btn.addEventListener('click', () => {
        this.toolManager.setActiveAsset(entry.id);
        dom.assetPalette.querySelectorAll('.asset-swatch').forEach((el) => el.classList.remove('active'));
        btn.classList.add('active');
      });
      dom.assetPalette.appendChild(btn);
    });
  }

  _refreshHistoryButtons() {
    this.dom.btnUndo.disabled = !this.history.canUndo();
    this.dom.btnRedo.disabled = !this.history.canRedo();
  }

  _refreshStatus() {
    const tool = this.toolManager.current;
    let hint = `Tool: ${tool}`;
    if (tool === TOOLS.PATH) hint += this.activePathId ? ` — editing ${this.activePathId}` : ' — click to start a new path';
    this.dom.statusBar.textContent = hint;
  }

  _newMap() {
    if (!window.confirm('Start a new map? Unsaved changes will be lost.')) return;
    this.world.load(createEmptyMap('New Battlefield'));
    this._afterMapLoaded();
  }

  async _openMapFromInput(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    try {
      const data = await MapSerializer.loadFromFile(file);
      this.world.load(data);
      this._afterMapLoaded();
    } catch (err) {
      const message = err instanceof MapValidationError ? err.errors.join('\n') : err.message;
      window.alert(`Could not load map:\n${message}`);
      console.error('[Editor] Failed to load map:', err);
    }
  }

  _saveMap(saveAs) {
    let filename = this.world.metadata.name || 'map';
    if (saveAs) {
      const input = window.prompt('Save map as:', filename);
      if (!input) return;
      filename = input;
      this.world.metadata.name = filename;
    }
    MapSerializer.download(this.world.serialize(), filename);
  }

  _afterMapLoaded() {
    this.history.clear();
    this.selection.clear();
    this.activePathId = null;
    this.grid.size = this.world.settings.gridSize;
    this.grid.snapEnabled = this.world.settings.snapEnabled;
    this.dom.gridSizeInput.value = this.grid.size;
    this.dom.snapToggle.checked = this.grid.snapEnabled;
    this.camera.worldWidth = this.world.width;
    this.camera.worldHeight = this.world.height;
    this.camera.x = this.world.width / 2;
    this.camera.y = this.world.height / 2;
  }

  // ---------------------------------------------------------------- Render

  _render() {
    const editorState = {
      pathEditing: this.toolManager.current === TOOLS.PATH,
      selectedPathPoint: this.selection.get()?.kind === 'pathPoint' ? this.selection.get() : null,
      showBuildSlots: true
    };

    this.renderer.renderFrame(
      this.world,
      this.assetManager,
      (ctx) => {
        this.grid.render(ctx, this.camera, this.world);
        this._renderDragPreview(ctx);
        this.selection.render(ctx, this.camera, this.assetManager);
        const sel = this.selection.get();
        if (sel?.kind === 'object' && (this.toolManager.current === TOOLS.SELECT || this.toolManager.current === TOOLS.MOVE)) {
          drawTransformHandles(ctx, this.camera, sel.ref, this.assetManager);
        }
      },
      null
    );
    this._updateDebugOverlay();
  }

  _renderDragPreview(ctx) {
    if (!this.drag) return;
    if (this.drag.type !== 'terrainDraw' && this.drag.type !== 'waterDraw') return;
    const x = Math.min(this.drag.start.x, this.drag.current.x);
    const y = Math.min(this.drag.start.y, this.drag.current.y);
    const width = Math.abs(this.drag.current.x - this.drag.start.x);
    const height = Math.abs(this.drag.current.y - this.drag.start.y);
    ctx.save();
    ctx.strokeStyle = this.drag.type === 'terrainDraw' ? 'rgba(200,180,120,0.9)' : 'rgba(120,200,255,0.9)';
    ctx.setLineDash([8, 6]);
    ctx.lineWidth = 2 / this.camera.zoom;
    ctx.strokeRect(x, y, width, height);
    ctx.restore();
  }

  _updateDebugOverlay() {
    if (!this.debugMode) return;
    const mouseWorld = this._lastMouseScreen ? this.camera.screenToWorld(this._lastMouseScreen.x, this._lastMouseScreen.y) : { x: 0, y: 0 };
    const sel = this.selection.get();
    const lines = [
      `FPS: ${this._fps}`,
      `Camera: ${Math.round(this.camera.x)}, ${Math.round(this.camera.y)} (zoom ${this.camera.zoom.toFixed(2)})`,
      `Mouse world: ${Math.round(mouseWorld.x)}, ${Math.round(mouseWorld.y)}`,
      `Selected: ${sel ? (sel.ref?.id ?? sel.pathId ?? '-') : '-'} (${sel ? (sel.ref?.type ?? sel.kind) : '-'})`,
      `Objects: ${this.world.objects.length + this.world.bridges.getAll().length + this.world.bases.getAll().length}`,
      `Paths: ${this.world.paths.getAll().length}`
    ];
    this.dom.debugOverlay.textContent = lines.join('\n');
  }
}

export { TERRAIN_TYPES, WATER_TYPES };
