const { UNIT_TYPES } = require('./constants');

let nextId = 1;

class Unit {
  constructor(type, ownerId, x, y) {
    const def = UNIT_TYPES[type];
    this.id = `u${nextId++}`;
    this.type = type;
    this.ownerId = ownerId;
    this.x = x;
    this.y = y;
    this.speed = def.speed;
    this.damage = def.damage;
    this.maxHp = def.hp;
    this.hp = def.hp;
    this.range = def.range;
    this.attackRate = def.attackRate;
    this.attackCooldown = 0;

    this.path = []; // lista de waypoints {x,y} pendientes
    this.targetEntityId = null; // id de unidad/edificio a atacar o seguir
    this.moveGoal = null; // {x,y} destino final ordenado (para reintentar path)
    this.state = 'idle'; // idle | moving | attacking
    this.destroyed = false;
  }

  setMove(path, goal) {
    this.path = path.slice();
    this.moveGoal = goal;
    this.targetEntityId = null;
    this.state = this.path.length > 0 ? 'moving' : 'idle';
  }

  setAttackTarget(entityId, path) {
    this.targetEntityId = entityId;
    this.path = path ? path.slice() : [];
    this.state = this.path.length > 0 ? 'moving' : 'attacking';
  }

  stop() {
    this.path = [];
    this.moveGoal = null;
    this.targetEntityId = null;
    this.state = 'idle';
  }

  takeDamage(amount) {
    this.hp -= amount;
    if (this.hp <= 0) {
      this.hp = 0;
      this.destroyed = true;
    }
    return this.destroyed;
  }

  // Avanza la unidad a lo largo de su ruta. Devuelve la distancia recorrida.
  advance(dt) {
    if (this.path.length === 0) return;
    let remaining = this.speed * dt;

    while (remaining > 0 && this.path.length > 0) {
      const target = this.path[0];
      const dx = target.x - this.x;
      const dy = target.y - this.y;
      const dist = Math.hypot(dx, dy);

      if (dist <= remaining) {
        this.x = target.x;
        this.y = target.y;
        remaining -= dist;
        this.path.shift();
      } else {
        const ratio = remaining / dist;
        this.x += dx * ratio;
        this.y += dy * ratio;
        remaining = 0;
      }
    }

    if (this.path.length === 0 && this.state === 'moving' && !this.targetEntityId) {
      this.state = 'idle';
    }
  }

  serialize() {
    return {
      id: this.id,
      type: this.type,
      ownerId: this.ownerId,
      x: Math.round(this.x),
      y: Math.round(this.y),
      hp: this.hp,
      maxHp: this.maxHp,
      state: this.state,
    };
  }
}

module.exports = Unit;
