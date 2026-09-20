const GameMap = require('./Map');
const Building = require('./Building');
const Unit = require('./Unit');
const Player = require('./Player');
const {
  TICK_MS,
  MAX_PLAYERS,
  MIN_PLAYERS,
  STARTING_ENERGY,
  MAX_GAME_DURATION_MS,
  MAP_WIDTH,
  MAP_HEIGHT,
  SPAWN_POINTS,
  PLAYER_COLORS,
  OBSTACLES,
  BUILD_RADIUS,
  BUILDING_TYPES,
  UNIT_TYPES,
} = require('./constants');

const MAX_QUEUE = 5;

class GameRoom {
  constructor(code, io) {
    this.code = code;
    this.io = io;
    this.players = new Map();
    this.buildings = new Map();
    this.units = new Map();
    this.map = new GameMap();
    this.state = 'lobby'; // lobby | playing | ended
    this.hostId = null;
    this.interval = null;
    this.startTime = null;
    this.tickCount = 0;
    this.events = [];
  }

  // ---------- Lobby ----------

  addPlayer(socketId, name) {
    if (this.state !== 'lobby') return { error: 'La partida ya comenzó.' };
    if (this.players.size >= MAX_PLAYERS) return { error: 'La sala está llena.' };

    const color = PLAYER_COLORS[this.players.size];
    const player = new Player(socketId, (name || '').slice(0, 16) || `Jugador ${this.players.size + 1}`, color, null);
    if (this.players.size === 0) {
      player.isHost = true;
      this.hostId = player.id;
    }
    this.players.set(player.id, player);
    return { player };
  }

  removePlayer(playerId) {
    if (!this.players.has(playerId)) return;
    const wasPlaying = this.state === 'playing';
    this.players.delete(playerId);

    if (wasPlaying) {
      this.eliminatePlayer(playerId, true);
      this.checkWinCondition();
    } else if (this.hostId === playerId && this.players.size > 0) {
      const next = this.players.values().next().value;
      next.isHost = true;
      this.hostId = next.id;
    }
  }

  isEmpty() {
    return this.players.size === 0;
  }

  canStart() {
    return this.state === 'lobby' && this.players.size >= MIN_PLAYERS;
  }

  serializeLobby() {
    return {
      code: this.code,
      hostId: this.hostId,
      state: this.state,
      players: [...this.players.values()].map((p) => p.serializeLobby()),
    };
  }

  // ---------- Ciclo de partida ----------

  start() {
    if (!this.canStart()) return false;
    this.state = 'playing';
    this.startTime = Date.now();

    const spawns = SPAWN_POINTS.slice(0, this.players.size);
    let i = 0;
    for (const player of this.players.values()) {
      player.spawn = spawns[i++];
      player.energy = STARTING_ENERGY;
      player.alive = true;
      const base = new Building('core', player.id, player.spawn.x, player.spawn.y);
      this.buildings.set(base.id, base);
      this.map.addBlocker(base.id, base.rect);
      player.baseId = base.id;
    }

    this.interval = setInterval(() => this.tick(), TICK_MS);
    return true;
  }

  getStartPayload() {
    return {
      mapWidth: MAP_WIDTH,
      mapHeight: MAP_HEIGHT,
      obstacles: OBSTACLES,
      buildingTypes: BUILDING_TYPES,
      unitTypes: UNIT_TYPES,
      players: [...this.players.values()].map((p) => ({
        id: p.id,
        name: p.name,
        color: p.color,
        spawn: p.spawn,
      })),
      you: null, // el servidor rellena esto por socket antes de emitir
    };
  }

  destroy() {
    if (this.interval) clearInterval(this.interval);
  }

  // ---------- Comandos del cliente ----------

  handleBuild(playerId, type, x, y) {
    const player = this.players.get(playerId);
    if (!player || !player.alive || this.state !== 'playing') return { error: 'No disponible.' };
    const def = BUILDING_TYPES[type];
    if (!def || type === 'core') return { error: 'Edificio inválido.' };
    if (player.energy < def.cost) return { error: 'Energía insuficiente.' };

    const half = def.size / 2;
    if (x - half < 0 || y - half < 0 || x + half > MAP_WIDTH || y + half > MAP_HEIGHT) {
      return { error: 'Fuera del mapa.' };
    }

    const rect = { x, y, w: def.size, h: def.size };
    if (!this.map.isRectFree(rect)) return { error: 'Espacio ocupado.' };

    const ownedNearby = [...this.buildings.values()].some(
      (b) => b.ownerId === playerId && Math.hypot(b.x - x, b.y - y) <= BUILD_RADIUS
    );
    if (!ownedNearby) return { error: 'Debes construir cerca de tus edificios.' };

    player.energy -= def.cost;
    const building = new Building(type, playerId, x, y);
    this.buildings.set(building.id, building);
    this.map.addBlocker(building.id, building.rect);
    this.events.push({ type: 'built', id: building.id, entityType: type, x, y, ownerId: playerId });
    return { building: building.serialize() };
  }

  handleProduce(playerId, buildingId, unitType) {
    const player = this.players.get(playerId);
    if (!player || !player.alive || this.state !== 'playing') return { error: 'No disponible.' };
    const building = this.buildings.get(buildingId);
    if (!building || building.ownerId !== playerId) return { error: 'Edificio inválido.' };
    if (!building.canProduce.includes(unitType)) return { error: 'Esa unidad no se produce aquí.' };
    if (building.productionQueue.length >= MAX_QUEUE) return { error: 'Cola llena.' };

    const def = UNIT_TYPES[unitType];
    if (player.energy < def.cost) return { error: 'Energía insuficiente.' };

    player.energy -= def.cost;
    building.productionQueue.push({ unitType, timeRemaining: def.buildTime, totalTime: def.buildTime });
    return { ok: true };
  }

  handleCommand(playerId, unitIds, x, y, targetId) {
    const player = this.players.get(playerId);
    if (!player || !player.alive || this.state !== 'playing') return;
    if (!Array.isArray(unitIds)) return;

    const target = targetId ? (this.units.get(targetId) || this.buildings.get(targetId)) : null;
    const isEnemyTarget = target && target.ownerId !== playerId && !target.destroyed;

    for (const unitId of unitIds) {
      const unit = this.units.get(unitId);
      if (!unit || unit.ownerId !== playerId) continue;

      if (isEnemyTarget) {
        const path = this.map.findPath(unit.x, unit.y, target.x, target.y);
        unit.setAttackTarget(target.id, path);
        unit.repathTimer = 0.5;
      } else {
        const clampedX = Math.max(0, Math.min(MAP_WIDTH, x));
        const clampedY = Math.max(0, Math.min(MAP_HEIGHT, y));
        const path = this.map.findPath(unit.x, unit.y, clampedX, clampedY);
        unit.setMove(path, { x: clampedX, y: clampedY });
      }
    }
  }

  handleChat(playerId, text) {
    const player = this.players.get(playerId);
    if (!player || typeof text !== 'string') return null;
    const clean = text.slice(0, 140).trim();
    if (!clean) return null;
    return { playerId, name: player.name, text: clean };
  }

  // ---------- Simulación ----------

  tick() {
    const dt = TICK_MS / 1000;
    this.updateEnergy(dt);
    this.updateBuildings(dt);
    this.updateUnits(dt);
    this.checkWinCondition();
    if (this.state === 'playing') {
      this.tickCount++;
      this.broadcastState();
    }
  }

  updateEnergy(dt) {
    for (const player of this.players.values()) {
      if (!player.alive) continue;
      let rate = 0;
      for (const b of this.buildings.values()) {
        if (b.ownerId === player.id && !b.destroyed) rate += b.energyRate;
      }
      player.energy += rate * dt;
    }
  }

  updateBuildings(dt) {
    for (const building of this.buildings.values()) {
      if (building.destroyed) continue;

      if (building.productionQueue.length > 0) {
        const job = building.productionQueue[0];
        job.timeRemaining -= dt;
        if (job.timeRemaining <= 0) {
          building.productionQueue.shift();
          this.spawnUnit(building, job.unitType);
        }
      }

      if (building.range > 0) {
        building.attackCooldown -= dt;
        if (building.attackCooldown <= 0) {
          const enemy = this.findNearestEnemy(building.ownerId, building.x, building.y, building.range);
          if (enemy) {
            building.attackCooldown = 1 / building.attackRate;
            this.events.push({ type: 'attack', from: building.id, to: enemy.id });
            const destroyed = enemy.takeDamage(building.damage);
            if (destroyed) this.onEntityDestroyed(enemy);
          }
        }
      }
    }
  }

  updateUnits(dt) {
    for (const unit of this.units.values()) {
      if (unit.destroyed) continue;
      if (unit.attackCooldown > 0) unit.attackCooldown -= dt;

      if (unit.targetEntityId) {
        const target = this.units.get(unit.targetEntityId) || this.buildings.get(unit.targetEntityId);
        if (!target || target.destroyed) {
          unit.stop();
          continue;
        }
        const targetSize = target.size ? target.size / 2 : 12;
        const dist = Math.hypot(target.x - unit.x, target.y - unit.y);

        if (dist <= unit.range + targetSize) {
          unit.path = [];
          unit.state = 'attacking';
          if (unit.attackCooldown <= 0) {
            unit.attackCooldown = 1 / unit.attackRate;
            this.events.push({ type: 'attack', from: unit.id, to: target.id });
            const destroyed = target.takeDamage(unit.damage);
            if (destroyed) this.onEntityDestroyed(target);
          }
        } else {
          unit.repathTimer = (unit.repathTimer || 0) - dt;
          if (unit.path.length === 0 || unit.repathTimer <= 0) {
            unit.path = this.map.findPath(unit.x, unit.y, target.x, target.y);
            unit.repathTimer = 0.5;
          }
          unit.state = 'moving';
          unit.advance(dt);
        }
      } else if (unit.path.length > 0) {
        unit.advance(dt);
      }
    }
  }

  findNearestEnemy(ownerId, x, y, range) {
    let nearest = null;
    let bestDist = range;
    for (const collection of [this.units, this.buildings]) {
      for (const entity of collection.values()) {
        if (entity.ownerId === ownerId || entity.destroyed) continue;
        const size = entity.size ? entity.size / 2 : 0;
        const dist = Math.hypot(entity.x - x, entity.y - y) - size;
        if (dist <= bestDist) {
          bestDist = dist;
          nearest = entity;
        }
      }
    }
    return nearest;
  }

  spawnUnit(building, unitType) {
    const angle = Math.random() * Math.PI * 2;
    const dist = building.size / 2 + 30;
    const x = Math.max(20, Math.min(MAP_WIDTH - 20, building.x + Math.cos(angle) * dist));
    const y = Math.max(20, Math.min(MAP_HEIGHT - 20, building.y + Math.sin(angle) * dist));
    const unit = new Unit(unitType, building.ownerId, x, y);
    this.units.set(unit.id, unit);
    this.events.push({ type: 'spawned', id: unit.id, entityType: unitType, x, y, ownerId: building.ownerId });
  }

  onEntityDestroyed(entity) {
    const isBuilding = entity instanceof Building;
    this.events.push({
      type: 'destroyed',
      id: entity.id,
      isBuilding,
      entityType: entity.type,
      x: entity.x,
      y: entity.y,
    });
    if (isBuilding) {
      this.map.removeBlocker(entity.id);
      this.buildings.delete(entity.id);
      if (entity.type === 'core') this.eliminatePlayer(entity.ownerId, false);
    } else {
      this.units.delete(entity.id);
    }
  }

  eliminatePlayer(playerId, silent) {
    const player = this.players.get(playerId);
    if (!player || !player.alive) return;
    player.alive = false;

    for (const [id, b] of [...this.buildings]) {
      if (b.ownerId === playerId) {
        this.map.removeBlocker(id);
        this.buildings.delete(id);
        if (!silent) {
          this.events.push({ type: 'destroyed', id, isBuilding: true, entityType: b.type, x: b.x, y: b.y });
        }
      }
    }
    for (const [id, u] of [...this.units]) {
      if (u.ownerId === playerId) {
        this.units.delete(id);
        if (!silent) {
          this.events.push({ type: 'destroyed', id, isBuilding: false, entityType: u.type, x: u.x, y: u.y });
        }
      }
    }
  }

  checkWinCondition() {
    if (this.state !== 'playing') return;
    const alivePlayers = [...this.players.values()].filter((p) => p.alive);

    if (alivePlayers.length <= 1) {
      this.endGame(alivePlayers[0] ? alivePlayers[0].id : null);
      return;
    }

    if (Date.now() - this.startTime >= MAX_GAME_DURATION_MS) {
      let winner = null;
      let bestHp = -1;
      for (const p of alivePlayers) {
        const base = this.buildings.get(p.baseId);
        const hp = base ? base.hp : 0;
        if (hp > bestHp) {
          bestHp = hp;
          winner = p.id;
        }
      }
      this.endGame(winner);
    }
  }

  endGame(winnerId) {
    this.state = 'ended';
    if (this.interval) clearInterval(this.interval);
    const winner = winnerId ? this.players.get(winnerId) : null;
    this.io.to(this.code).emit('game:over', {
      winnerId: winnerId || null,
      winnerName: winner ? winner.name : null,
    });
  }

  broadcastState() {
    const payload = {
      tick: this.tickCount,
      time: this.startTime ? Date.now() - this.startTime : 0,
      players: [...this.players.values()].map((p) => p.serializeGame()),
      buildings: [...this.buildings.values()].map((b) => b.serialize()),
      units: [...this.units.values()].map((u) => u.serialize()),
      events: this.events,
    };
    this.io.to(this.code).emit('game:state', payload);
    this.events = [];
  }
}

module.exports = GameRoom;
