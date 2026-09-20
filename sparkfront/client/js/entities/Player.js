// Representación ligera de un jugador en el cliente: solo datos de
// presentación (nombre, color de facción). El estado real vive en el servidor.
class ClientPlayer {
  constructor(data) {
    this.id = data.id;
    this.name = data.name;
    this.color = data.color;
    this.spawn = data.spawn || null;
    this.energy = data.energy || 0;
    this.alive = data.alive !== false;
  }

  static hex(colorInt) {
    return `#${colorInt.toString(16).padStart(6, '0')}`;
  }
}
