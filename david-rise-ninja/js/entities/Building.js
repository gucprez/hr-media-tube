import { Entity } from './Entity.js';

// Edificio puramente visual por ahora (sin vida, sin producción, sin costo): la Fase 6
// (economía + construcción) convertirá estas mismas instancias en entidades funcionales
// añadiendo esos campos, sin tener que rehacer el arte ni el posicionamiento.
export class Building extends Entity {
    constructor({ name, buildingType, sprite, shadowSprite, spriteAnchorY = 1, ...rest }) {
        super(rest);
        this.name = name;
        this.buildingType = buildingType;
        this.sprite = sprite;
        this.shadowSprite = shadowSprite;
        // Fracción vertical del sprite que representa "el suelo" (para anclar la base
        // del edificio a su posición de mundo en vez de a la esquina superior izquierda).
        this.spriteAnchorY = spriteAnchorY;
    }

    render(ctx, camera) {
        if (!this.visible || !this.sprite) return;
        const screen = camera.worldToScreen(this.x, this.y);
        const w = this.sprite.width * this.scale * camera.zoom;
        const h = this.sprite.height * this.scale * camera.zoom;

        if (this.shadowSprite) {
            const sw = w * 0.9;
            const sh = this.shadowSprite.height * (sw / this.shadowSprite.width);
            ctx.drawImage(this.shadowSprite, screen.x - sw / 2, screen.y - sh * 0.35, sw, sh);
        }

        ctx.drawImage(this.sprite, screen.x - w / 2, screen.y - h * this.spriteAnchorY, w, h);
    }
}
