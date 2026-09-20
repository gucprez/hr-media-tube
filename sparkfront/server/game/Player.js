const { STARTING_ENERGY } = require('./constants');

class Player {
  constructor(id, name, color, spawn) {
    this.id = id;
    this.name = name;
    this.color = color;
    this.spawn = spawn;
    this.energy = STARTING_ENERGY;
    this.baseId = null;
    this.alive = true;
    this.isHost = false;
  }

  serializeLobby() {
    return { id: this.id, name: this.name, color: this.color, isHost: this.isHost };
  }

  serializeGame() {
    return { id: this.id, name: this.name, color: this.color, energy: Math.floor(this.energy), alive: this.alive };
  }
}

module.exports = Player;
