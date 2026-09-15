let nextId = 1;

// Clase base para todo lo que existe en el mundo (edificios, y más adelante ninjas,
// enemigos, efectos, objetos). Deliberadamente mínima en esta fase: sin vida, sin
// facción, sin colisión — eso lo añadirán las fases de combate/unidades sobre esta base.
export class Entity {
    constructor({ x = 0, y = 0, width = 1, height = 1, rotation = 0, scale = 1, visible = true, zIndex = 0 } = {}) {
        this.id = nextId++;
        this.x = x;
        this.y = y;
        this.width = width;
        this.height = height;
        this.rotation = rotation;
        this.scale = scale;
        this.visible = visible;
        this.zIndex = zIndex;
        this.destroyed = false;
    }

    // Orden de profundidad por defecto: la base del sprite (y + mitad de alto) marca
    // "dónde toca el suelo" el objeto, que es lo que debe determinar el orden pintor.
    get depthY() {
        return this.y + this.height / 2 + this.zIndex;
    }

    update(dt) {
        // Sin comportamiento propio todavía; las subclases lo añaden.
    }

    render(ctx, camera) {
        // Las subclases implementan el dibujado real.
    }

    destroy() {
        this.destroyed = true;
    }
}
