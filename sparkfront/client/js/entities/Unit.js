// Representación visual de una unidad: formas geométricas suaves teñidas con
// el color de facción, con una pequeña interpolación de movimiento para que
// se vea fluida aunque el servidor solo envíe ~20 posiciones por segundo.
class UnitSprite {
  constructor(scene, data, color, def) {
    this.scene = scene;
    this.id = data.id;
    this.type = data.type;
    this.ownerId = data.ownerId;
    this.color = color;
    this.maxHp = data.maxHp;
    this.radius = def.radius;

    this.container = scene.add.container(data.x, data.y);

    this.glow = scene.add.circle(0, 0, this.radius * 1.8, color, 0.16);
    this.shape = scene.add.graphics();
    this.drawShape();

    const barW = this.radius * 2.4;
    this.hpBg = scene.add.rectangle(0, -this.radius - 10, barW, 5, 0x000000, 0.45).setOrigin(0.5);
    this.hpFg = scene.add.rectangle(-barW / 2, -this.radius - 10, barW, 5, 0x5cff8a).setOrigin(0, 0.5);

    this.container.add([this.glow, this.shape, this.hpBg, this.hpFg]);
    this.container.setScale(0.3);
    scene.tweens.add({ targets: this.container, scale: 1, duration: 180, ease: 'Back.Out' });

    this.update(data);
  }

  drawShape() {
    const g = this.shape;
    const r = this.radius;
    const light = Phaser.Display.Color.IntegerToColor(this.color).lighten(30).color;
    g.clear();
    g.fillStyle(this.color, 1);
    g.lineStyle(3, light, 0.95);

    if (this.type === 'golem') {
      g.fillCircle(0, 0, r);
      g.strokeCircle(0, 0, r);
      g.fillStyle(0xffffff, 0.8);
      g.fillCircle(0, 0, r * 0.4);
    } else if (this.type === 'bolt') {
      g.fillTriangle(0, -r, r * 0.85, r * 0.7, -r * 0.85, r * 0.7);
      g.strokeTriangle(0, -r, r * 0.85, r * 0.7, -r * 0.85, r * 0.7);
    } else {
      g.fillCircle(0, 0, r);
      g.strokeCircle(0, 0, r);
      g.fillStyle(0xffffff, 0.85);
      g.fillCircle(0, 0, r * 0.35);
    }
  }

  update(data) {
    this.hp = data.hp;
    const ratio = Math.max(0, this.hp / this.maxHp);
    this.hpFg.width = (this.radius * 2.4) * ratio;
    this.hpFg.fillColor = ratio > 0.4 ? 0x5cff8a : 0xff5f5f;

    this.scene.tweens.killTweensOf(this.container);
    this.scene.tweens.add({
      targets: this.container,
      x: data.x,
      y: data.y,
      duration: 90,
      ease: 'Linear',
    });

    this.shape.setAlpha(data.state === 'attacking' ? 0.75 : 1);
  }

  get x() { return this.container.x; }
  get y() { return this.container.y; }

  destroy() {
    this.container.destroy();
  }
}
