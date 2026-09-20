// Representación visual de un edificio: formas redondeadas "cartoon" teñidas
// con el color de facción del dueño, barra de vida y anillo de progreso de
// producción. La lógica real (vida, cola de producción) vive en el servidor;
// esta clase solo dibuja lo que el servidor reporta.
class BuildingSprite {
  constructor(scene, data, color, def) {
    this.scene = scene;
    this.id = data.id;
    this.type = data.type;
    this.ownerId = data.ownerId;
    this.color = color;
    this.size = def.size;
    this.maxHp = data.maxHp;

    const s = this.size;
    this.container = scene.add.container(data.x, data.y);

    // Halo de brillo neón detrás de la forma principal.
    this.glow = scene.add.circle(0, 0, s * 0.72, color, 0.18);

    this.shape = scene.add.graphics();
    this.drawShape();

    this.hpBg = scene.add.rectangle(0, -s / 2 - 14, s * 0.9, 6, 0x000000, 0.45).setOrigin(0.5);
    this.hpFg = scene.add.rectangle(-s * 0.45, -s / 2 - 14, s * 0.9, 6, 0x5cff8a).setOrigin(0, 0.5);

    this.progressRing = scene.add.graphics();

    this.container.add([this.glow, this.shape, this.progressRing, this.hpBg, this.hpFg]);
    this.container.setScale(0.2);
    scene.tweens.add({ targets: this.container, scale: 1, duration: 260, ease: 'Back.Out' });

    this.update(data);
  }

  drawShape() {
    const g = this.shape;
    const s = this.size;
    const half = s / 2;
    const light = Phaser.Display.Color.IntegerToColor(this.color).lighten(25).color;
    g.clear();
    g.lineStyle(4, light, 0.9);

    switch (this.type) {
      case 'core':
        g.fillStyle(this.color, 1);
        g.fillCircle(0, 0, half);
        g.strokeCircle(0, 0, half);
        g.fillStyle(0xffffff, 0.85);
        g.fillCircle(0, 0, half * 0.35);
        break;
      case 'generator':
        g.fillStyle(this.color, 1);
        g.fillRoundedRect(-half, -half, s, s, 10);
        g.strokeRoundedRect(-half, -half, s, s, 10);
        g.fillStyle(0xffffff, 0.9);
        g.fillTriangle(0, -half * 0.5, half * 0.35, half * 0.35, -half * 0.35, half * 0.35);
        break;
      case 'forge':
        g.fillStyle(this.color, 1);
        g.fillRoundedRect(-half, -half, s, s, 14);
        g.strokeRoundedRect(-half, -half, s, s, 14);
        g.fillStyle(0xffffff, 0.85);
        g.fillRoundedRect(-half * 0.5, -half * 0.15, s * 0.5, half * 0.5, 4);
        break;
      case 'plasmaTower':
        g.fillStyle(this.color, 1);
        g.fillCircle(0, 0, half);
        g.strokeCircle(0, 0, half);
        g.fillStyle(0xffffff, 0.9);
        g.fillRoundedRect(-half * 0.18, -half * 1.15, half * 0.36, half * 0.9, 4);
        break;
      case 'barrier':
        g.fillStyle(this.color, 1);
        g.fillRoundedRect(-half, -half * 0.7, s, s * 0.7, 8);
        g.strokeRoundedRect(-half, -half * 0.7, s, s * 0.7, 8);
        for (let i = -1; i <= 1; i++) {
          g.lineBetween(i * half * 0.5, -half * 0.6, i * half * 0.5 + half * 0.3, half * 0.55);
        }
        break;
      default:
        g.fillStyle(this.color, 1);
        g.fillRoundedRect(-half, -half, s, s, 8);
    }
  }

  update(data) {
    this.hp = data.hp;
    const ratio = Math.max(0, this.hp / this.maxHp);
    this.hpFg.width = this.size * 0.9 * ratio;
    this.hpFg.fillColor = ratio > 0.4 ? 0x5cff8a : 0xff5f5f;

    this.progressRing.clear();
    if (data.queue && data.queue.length > 0) {
      const progress = data.queue[0].progress;
      this.progressRing.lineStyle(4, 0xffffff, 0.85);
      this.progressRing.beginPath();
      this.progressRing.arc(0, 0, this.size / 2 + 8, Phaser.Math.DegToRad(-90),
        Phaser.Math.DegToRad(-90 + 360 * progress), false);
      this.progressRing.strokePath();
    }
  }

  playHit() {
    this.scene.tweens.add({ targets: this.shape, alpha: 0.4, duration: 60, yoyo: true });
  }

  destroy() {
    this.container.destroy();
  }
}
