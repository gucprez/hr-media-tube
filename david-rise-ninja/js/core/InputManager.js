// Sistema de input centralizado. Sólo reporta hechos (teclas, posición y botones del
// ratón, rueda, arrastre); no decide nada de gameplay. Preparado para que fases futuras
// (selección, construcción, ataque) añadan más estados sin tocar esta clase.
export class InputManager {
    constructor(canvas) {
        this.canvas = canvas;
        this.keys = new Set();

        this.mouse = {
            x: 0,
            y: 0,
            insideCanvas: false,
            leftDown: false,
            rightDown: false,
            wheelDelta: 0,
            dragging: false,
            dragDX: 0,
            dragDY: 0,
        };

        this._bind();
    }

    _bind() {
        window.addEventListener('keydown', (e) => this.keys.add(e.code));
        window.addEventListener('keyup', (e) => this.keys.delete(e.code));
        window.addEventListener('blur', () => {
            this.keys.clear();
            this.mouse.leftDown = false;
            this.mouse.rightDown = false;
            this.mouse.dragging = false;
        });

        this.canvas.addEventListener('contextmenu', (e) => e.preventDefault());
        this.canvas.addEventListener('mouseenter', () => (this.mouse.insideCanvas = true));
        this.canvas.addEventListener('mouseleave', () => (this.mouse.insideCanvas = false));

        this.canvas.addEventListener('mousemove', (e) => {
            const rect = this.canvas.getBoundingClientRect();
            const nx = e.clientX - rect.left;
            const ny = e.clientY - rect.top;
            if (this.mouse.dragging) {
                this.mouse.dragDX += nx - this.mouse.x;
                this.mouse.dragDY += ny - this.mouse.y;
            }
            this.mouse.x = nx;
            this.mouse.y = ny;
        });

        this.canvas.addEventListener('mousedown', (e) => {
            if (e.button === 0) {
                this.mouse.leftDown = true;
                this.mouse.dragging = true;
                this.mouse.dragDX = 0;
                this.mouse.dragDY = 0;
            }
            if (e.button === 2) this.mouse.rightDown = true;
        });

        window.addEventListener('mouseup', (e) => {
            if (e.button === 0) {
                this.mouse.leftDown = false;
                this.mouse.dragging = false;
            }
            if (e.button === 2) this.mouse.rightDown = false;
        });

        this.canvas.addEventListener(
            'wheel',
            (e) => {
                e.preventDefault();
                this.mouse.wheelDelta += e.deltaY;
            },
            { passive: false }
        );
    }

    isKeyDown(...codes) {
        return codes.some((c) => this.keys.has(c));
    }

    // Se llama al cierre de cada frame para limpiar deltas de "un solo frame".
    endFrame() {
        this.mouse.wheelDelta = 0;
        this.mouse.dragDX = 0;
        this.mouse.dragDY = 0;
    }
}
