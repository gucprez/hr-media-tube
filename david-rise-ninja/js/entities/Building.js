import { Entity } from './Entity.js';
import { drawSprite } from '../core/SpriteRenderer.js';

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
        drawSprite(ctx, camera, this.sprite, this.x, this.y, {
            scale: this.scale,
            anchorY: this.spriteAnchorY,
            shadowSprite: this.shadowSprite,
        });
    }
}
