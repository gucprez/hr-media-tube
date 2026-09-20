const { BUILDING_TYPES } = require('./constants');

let nextId = 1;

class Building {
  constructor(type, ownerId, x, y) {
    const def = BUILDING_TYPES[type];
    this.id = `b${nextId++}`;
    this.type = type;
    this.ownerId = ownerId;
    this.x = x;
    this.y = y;
    this.size = def.size;
    this.maxHp = def.hp;
    this.hp = def.hp;
    this.energyRate = def.energyRate;
    this.canProduce = def.canProduce;
    this.range = def.range || 0;
    this.damage = def.damage || 0;
    this.attackRate = def.attackRate || 0;
    this.attackCooldown = 0;
    this.productionQueue = []; // { unitType, timeRemaining, totalTime }
    this.destroyed = false;
  }

  get rect() {
    return { x: this.x, y: this.y, w: this.size, h: this.size };
  }

  takeDamage(amount) {
    this.hp -= amount;
    if (this.hp <= 0) {
      this.hp = 0;
      this.destroyed = true;
    }
    return this.destroyed;
  }

  serialize() {
    return {
      id: this.id,
      type: this.type,
      ownerId: this.ownerId,
      x: this.x,
      y: this.y,
      hp: this.hp,
      maxHp: this.maxHp,
      queue: this.productionQueue.map((q) => ({
        unitType: q.unitType,
        progress: 1 - q.timeRemaining / q.totalTime,
      })),
    };
  }
}

module.exports = Building;
