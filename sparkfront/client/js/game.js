// Radio visual de cada tipo de unidad (el servidor no envía tamaño de unidades,
// solo de edificios, así que el tamaño en pantalla es una decisión del cliente).
const UNIT_RADIUS = { spark: 12, golem: 20, bolt: 14 };
const BUILD_RADIUS_HINT = 550; // debe coincidir con BUILD_RADIUS del servidor

class GameScene extends Phaser.Scene {
  constructor() {
    super('GameScene');
  }

  init(payload) {
    this.startPayload = payload;
  }

  create() {
    const data = this.startPayload;
    this.mapWidth = data.mapWidth;
    this.mapHeight = data.mapHeight;
    this.buildingDefs = data.buildingTypes;
    this.unitDefs = data.unitTypes;
    this.you = data.you;
    this.players = new Map(data.players.map((p) => [p.id, new ClientPlayer(p)]));

    this.buildingSprites = new Map();
    this.unitSprites = new Map();
    this.selectedUnitIds = new Set();
    this.selectedBuildingId = null;
    this.buildMode = null;
    this.dragStart = null;
    this.rightDown = null;

    this.cameras.main.setBounds(0, 0, this.mapWidth, this.mapHeight);

    this.drawBackground();
    this.drawObstacles(data.obstacles);

    this.ghostGraphics = this.add.graphics().setDepth(50);
    this.selectionRings = this.add.graphics().setDepth(45);
    this.selectionBoxGfx = this.add.graphics().setDepth(60).setScrollFactor(0);

    this.ensureParticleTexture();

    const me = this.players.get(this.you);
    if (me && me.spawn) this.cameras.main.centerOn(me.spawn.x, me.spawn.y);

    this.setupInput();
    this.setupMinimap();

    this.cursors = this.input.keyboard.addKeys({ up: 'W', down: 'S', left: 'A', right: 'D' });

    Network.on('game:state', (s) => this.applyState(s));
    GameEvents.on('build-select', (type) => this.enterBuildMode(type));
    GameEvents.on('build-select-cancel', () => this.exitBuildMode());
  }

  ensureParticleTexture() {
    if (this.textures.exists('spark-particle')) return;
    const g = this.make.graphics({ x: 0, y: 0, add: false });
    g.fillStyle(0xffffff, 1);
    g.fillCircle(8, 8, 8);
    g.generateTexture('spark-particle', 16, 16);
    g.destroy();
  }

  update(time, delta) {
    const dt = delta / 1000;
    const cam = this.cameras.main;
    const speed = 620 * dt;
    if (this.cursors.left.isDown) cam.scrollX -= speed;
    if (this.cursors.right.isDown) cam.scrollX += speed;
    if (this.cursors.up.isDown) cam.scrollY -= speed;
    if (this.cursors.down.isDown) cam.scrollY += speed;

    if (this.buildMode) {
      const p = this.input.activePointer;
      const world = cam.getWorldPoint(p.x, p.y);
      this.updateGhost(world.x, world.y);
    }

    this.drawSelectionRings();
  }

  // ---------- Escenario ----------

  drawBackground() {
    const g = this.add.graphics();
    g.fillStyle(0x11162c, 1);
    g.fillRect(0, 0, this.mapWidth, this.mapHeight);
    g.lineStyle(1, 0x232a52, 0.5);
    const step = 100;
    for (let x = 0; x <= this.mapWidth; x += step) g.lineBetween(x, 0, x, this.mapHeight);
    for (let y = 0; y <= this.mapHeight; y += step) g.lineBetween(0, y, this.mapWidth, y);
    g.lineStyle(4, 0x00e5ff, 0.35);
    g.strokeRect(0, 0, this.mapWidth, this.mapHeight);
  }

  drawObstacles(list) {
    const g = this.add.graphics();
    g.fillStyle(0x2d3a6b, 1);
    g.lineStyle(3, 0x00e5ff, 0.3);
    for (const o of list) {
      g.beginPath();
      g.moveTo(o.x, o.y - o.h / 2);
      g.lineTo(o.x + o.w / 2, o.y);
      g.lineTo(o.x, o.y + o.h / 2);
      g.lineTo(o.x - o.w / 2, o.y);
      g.closePath();
      g.fillPath();
      g.strokePath();
    }
  }

  setupMinimap() {
    const w = 180;
    const h = 135;
    this.minimapCam = this.cameras
      .add(0, 0, w, h)
      .setZoom(Math.min(w / this.mapWidth, h / this.mapHeight))
      .setBackgroundColor(0x0b0e1f);
    this.minimapCam.centerOn(this.mapWidth / 2, this.mapHeight / 2);
    this.minimapCam.ignore([this.ghostGraphics, this.selectionRings, this.selectionBoxGfx]);
    this.positionMinimap();
    this.scale.on('resize', () => this.positionMinimap());
  }

  positionMinimap() {
    if (!this.minimapCam) return;
    const w = 180;
    const h = 135;
    const margin = 16;
    const bottomReserve = 200;
    this.minimapCam.setPosition(this.scale.width - w - margin, this.scale.height - h - bottomReserve);
  }

  colorOf(ownerId) {
    const p = this.players.get(ownerId);
    return p ? p.color : 0xffffff;
  }

  // ---------- Estado del servidor ----------

  applyState(data) {
    const seenB = new Set();
    for (const b of data.buildings) {
      seenB.add(b.id);
      let sprite = this.buildingSprites.get(b.id);
      if (!sprite) {
        sprite = new BuildingSprite(this, b, this.colorOf(b.ownerId), this.buildingDefs[b.type]);
        this.buildingSprites.set(b.id, sprite);
      } else {
        sprite.update(b);
      }
    }
    for (const [id, sprite] of [...this.buildingSprites]) {
      if (!seenB.has(id)) {
        sprite.destroy();
        this.buildingSprites.delete(id);
        if (this.selectedBuildingId === id) this.clearSelection();
      }
    }

    const seenU = new Set();
    for (const u of data.units) {
      seenU.add(u.id);
      let sprite = this.unitSprites.get(u.id);
      if (!sprite) {
        const radius = UNIT_RADIUS[u.type] || 14;
        sprite = new UnitSprite(this, u, this.colorOf(u.ownerId), { radius });
        this.unitSprites.set(u.id, sprite);
      } else {
        sprite.update(u);
      }
    }
    for (const [id, sprite] of [...this.unitSprites]) {
      if (!seenU.has(id)) {
        sprite.destroy();
        this.unitSprites.delete(id);
        this.selectedUnitIds.delete(id);
      }
    }

    for (const ev of data.events) this.handleEvent(ev);
  }

  handleEvent(ev) {
    if (ev.type === 'attack') {
      const from = this.entityPos(ev.from);
      const to = this.entityPos(ev.to);
      if (from && to) this.drawBeam(from.x, from.y, to.x, to.y, this.colorOf(this.ownerOf(ev.from)));
    } else if (ev.type === 'destroyed') {
      this.spawnBurst(ev.x, ev.y, ev.isBuilding ? 26 : 14);
    }
  }

  entityPos(id) {
    const b = this.buildingSprites.get(id);
    if (b) return { x: b.container.x, y: b.container.y };
    const u = this.unitSprites.get(id);
    if (u) return { x: u.x, y: u.y };
    return null;
  }

  ownerOf(id) {
    const b = this.buildingSprites.get(id);
    if (b) return b.ownerId;
    const u = this.unitSprites.get(id);
    if (u) return u.ownerId;
    return null;
  }

  drawBeam(x1, y1, x2, y2, color) {
    const g = this.add.graphics().setDepth(35).setBlendMode(Phaser.BlendModes.ADD);
    g.lineStyle(4, color, 0.9);
    g.lineBetween(x1, y1, x2, y2);
    this.minimapCam.ignore(g);
    this.tweens.add({ targets: g, alpha: 0, duration: 160, onComplete: () => g.destroy() });
  }

  spawnBurst(x, y, count) {
    const emitter = this.add.particles(x, y, 'spark-particle', {
      speed: { min: 60, max: 220 },
      angle: { min: 0, max: 360 },
      scale: { start: 1, end: 0 },
      lifespan: 420,
      quantity: count,
      tint: [0xffe08a, 0xff8a00, 0xffffff],
      blendMode: 'ADD',
      emitting: false,
    });
    this.minimapCam.ignore(emitter);
    emitter.explode(count);
    this.time.delayedCall(500, () => emitter.destroy());
  }

  flashOrderMarker(x, y, isAttack) {
    const g = this.add.graphics().setDepth(35);
    g.lineStyle(3, isAttack ? 0xff2fd6 : 0x00e5ff, 1);
    g.strokeCircle(x, y, 18);
    this.minimapCam.ignore(g);
    this.tweens.add({ targets: g, alpha: 0, scale: 1.6, duration: 350, onComplete: () => g.destroy() });
  }

  // ---------- Selección ----------

  findEntityAt(x, y, enemyOnly) {
    let best = null;
    let bestDist = Infinity;
    for (const sprite of this.unitSprites.values()) {
      if (enemyOnly && sprite.ownerId === this.you) continue;
      const d = Phaser.Math.Distance.Between(x, y, sprite.x, sprite.y);
      if (d <= sprite.radius + 6 && d < bestDist) { bestDist = d; best = sprite; }
    }
    for (const sprite of this.buildingSprites.values()) {
      if (enemyOnly && sprite.ownerId === this.you) continue;
      const half = sprite.size / 2;
      const d = Phaser.Math.Distance.Between(x, y, sprite.container.x, sprite.container.y);
      if (d <= half + 6 && d < bestDist) { bestDist = d; best = sprite; }
    }
    return best;
  }

  selectAtPoint(x, y) {
    const hit = this.findEntityAt(x, y, false);
    if (hit && hit.ownerId === this.you) {
      if (hit instanceof BuildingSprite) this.selectBuilding(hit);
      else this.selectUnits([hit.id]);
    } else {
      this.clearSelection();
    }
  }

  selectInRect(x1, y1, x2, y2) {
    const left = Math.min(x1, x2);
    const right = Math.max(x1, x2);
    const top = Math.min(y1, y2);
    const bottom = Math.max(y1, y2);
    const ids = [];
    for (const sprite of this.unitSprites.values()) {
      if (sprite.ownerId !== this.you) continue;
      if (sprite.x >= left && sprite.x <= right && sprite.y >= top && sprite.y <= bottom) ids.push(sprite.id);
    }
    if (ids.length > 0) {
      this.selectUnits(ids);
      return;
    }
    for (const sprite of this.buildingSprites.values()) {
      if (sprite.ownerId !== this.you) continue;
      const { x: bx, y: by } = sprite.container;
      if (bx >= left && bx <= right && by >= top && by <= bottom) {
        this.selectBuilding(sprite);
        return;
      }
    }
    this.clearSelection();
  }

  selectUnits(ids) {
    this.selectedBuildingId = null;
    this.selectedUnitIds = new Set(ids);
    GameEvents.emit('selection-changed', { kind: 'units', count: ids.length });
  }

  selectBuilding(sprite) {
    this.selectedUnitIds.clear();
    this.selectedBuildingId = sprite.id;
    GameEvents.emit('selection-changed', { kind: 'building', building: { id: sprite.id, type: sprite.type } });
  }

  clearSelection() {
    this.selectedUnitIds.clear();
    this.selectedBuildingId = null;
    GameEvents.emit('selection-changed', { kind: 'none' });
  }

  drawSelectionRings() {
    const g = this.selectionRings;
    g.clear();
    g.lineStyle(2, 0xffffff, 0.9);
    for (const id of this.selectedUnitIds) {
      const s = this.unitSprites.get(id);
      if (s) g.strokeCircle(s.x, s.y, s.radius + 6);
    }
    if (this.selectedBuildingId) {
      const b = this.buildingSprites.get(this.selectedBuildingId);
      if (b) {
        const half = b.size / 2 + 6;
        g.strokeRect(b.container.x - half, b.container.y - half, half * 2, half * 2);
      }
    }
  }

  // ---------- Entrada ----------

  setupInput() {
    if (this.input.mouse) this.input.mouse.disableContextMenu();

    this.input.on('pointerdown', (p) => {
      if (p.button === 0) this.onLeftDown(p);
      else if (p.button === 2) this.onRightDown(p);
    });
    this.input.on('pointermove', (p) => {
      if (this.dragStart) this.updateSelectionBox(p);
      if (this.rightDown) this.panCameraWith(p);
    });
    this.input.on('pointerup', (p) => {
      if (p.button === 0) this.onLeftUp(p);
      else if (p.button === 2) this.onRightUp(p);
    });
    this.input.keyboard.on('keydown-ESC', () => this.exitBuildMode());
  }

  worldPoint(p) {
    return this.cameras.main.getWorldPoint(p.x, p.y);
  }

  onLeftDown(p) {
    if (this.buildMode) return;
    const w = this.worldPoint(p);
    this.dragStart = { x: w.x, y: w.y, sx: p.x, sy: p.y };
  }

  onLeftUp(p) {
    if (this.buildMode) {
      const w = this.worldPoint(p);
      this.tryPlaceBuilding(w.x, w.y);
      return;
    }
    if (!this.dragStart) return;
    const w = this.worldPoint(p);
    const dist = Phaser.Math.Distance.Between(this.dragStart.sx, this.dragStart.sy, p.x, p.y);
    if (dist < 6) this.selectAtPoint(w.x, w.y);
    else this.selectInRect(this.dragStart.x, this.dragStart.y, w.x, w.y);
    this.dragStart = null;
    this.selectionBoxGfx.clear();
  }

  updateSelectionBox(p) {
    const g = this.selectionBoxGfx;
    g.clear();
    g.lineStyle(2, 0x00e5ff, 0.9);
    g.fillStyle(0x00e5ff, 0.12);
    const x = Math.min(this.dragStart.sx, p.x);
    const y = Math.min(this.dragStart.sy, p.y);
    const w = Math.abs(p.x - this.dragStart.sx);
    const h = Math.abs(p.y - this.dragStart.sy);
    g.fillRect(x, y, w, h);
    g.strokeRect(x, y, w, h);
  }

  onRightDown(p) {
    if (this.buildMode) { this.exitBuildMode(); return; }
    this.rightDown = { sx: p.x, sy: p.y, dragged: false };
  }

  panCameraWith(p) {
    const dx = p.x - this.rightDown.sx;
    const dy = p.y - this.rightDown.sy;
    if (Math.abs(dx) > 2 || Math.abs(dy) > 2) {
      this.cameras.main.scrollX -= dx / this.cameras.main.zoom;
      this.cameras.main.scrollY -= dy / this.cameras.main.zoom;
      this.rightDown.sx = p.x;
      this.rightDown.sy = p.y;
      this.rightDown.dragged = true;
    }
  }

  onRightUp(p) {
    if (!this.rightDown) return;
    if (!this.rightDown.dragged && this.selectedUnitIds.size > 0) {
      const w = this.worldPoint(p);
      const target = this.findEntityAt(w.x, w.y, true);
      Network.command([...this.selectedUnitIds], w.x, w.y, target ? target.id : null);
      this.flashOrderMarker(w.x, w.y, !!target);
    }
    this.rightDown = null;
  }

  // ---------- Construcción ----------

  enterBuildMode(type) {
    const def = this.buildingDefs[type];
    if (!def) return;
    this.buildMode = { type, def };
    GameEvents.emit('build-mode-changed', type);
  }

  exitBuildMode() {
    if (!this.buildMode) return;
    this.buildMode = null;
    this.ghostGraphics.clear();
    GameEvents.emit('build-mode-changed', null);
  }

  updateGhost(x, y) {
    const def = this.buildMode.def;
    const half = def.size / 2;
    const valid = this.isPlacementValidLocal(x, y, def);
    const g = this.ghostGraphics;
    g.clear();
    g.fillStyle(valid ? 0x5cff8a : 0xff5f5f, 0.35);
    g.lineStyle(2, valid ? 0x5cff8a : 0xff5f5f, 0.9);
    g.fillRoundedRect(x - half, y - half, def.size, def.size, 10);
    g.strokeRoundedRect(x - half, y - half, def.size, def.size, 10);
  }

  rectsOverlap(x1, y1, s1, x2, y2, w2, h2) {
    const h1 = s1 / 2;
    return Math.abs(x1 - x2) < h1 + w2 / 2 && Math.abs(y1 - y2) < h1 + h2 / 2;
  }

  isPlacementValidLocal(x, y, def) {
    const half = def.size / 2;
    if (x - half < 0 || y - half < 0 || x + half > this.mapWidth || y + half > this.mapHeight) return false;
    for (const o of this.startPayload.obstacles) {
      if (this.rectsOverlap(x, y, def.size, o.x, o.y, o.w, o.h)) return false;
    }
    for (const b of this.buildingSprites.values()) {
      if (this.rectsOverlap(x, y, def.size, b.container.x, b.container.y, b.size, b.size)) return false;
    }
    for (const b of this.buildingSprites.values()) {
      if (b.ownerId === this.you && Phaser.Math.Distance.Between(x, y, b.container.x, b.container.y) <= BUILD_RADIUS_HINT) {
        return true;
      }
    }
    return false;
  }

  tryPlaceBuilding(x, y) {
    const { type } = this.buildMode;
    Network.build(type, Math.round(x), Math.round(y));
    this.exitBuildMode();
  }
}
