// Gestiona todo el input crudo (teclado + ratón) y expone un estado consultable
// por el resto del motor. No toma decisiones de gameplay: sólo reporta hechos.
export class InputManager {
    constructor(canvas) {
        this.canvas = canvas;
        this.keys = new Set();
        this.mouse = {
            x: 0,
            y: 0,
            screenX: 0,
            screenY: 0,
            worldX: 0,
            worldY: 0,
            leftDown: false,
            rightDown: false,
            middleDown: false,
            insideCanvas: false,
            wheelDelta: 0,
            dragButton: -1,
            dragDX: 0,
            dragDY: 0,
        };

        // Cola de eventos "de intención" (click simple, doble click, arrastre soltado...)
        // que los sistemas de gameplay consumen y vacían cada frame.
        this.clickEvents = [];

        this._lastClickTime = 0;
        this._lastClickButton = -1;

        this._bindEvents();
    }

    _bindEvents() {
        window.addEventListener('keydown', (e) => {
            this.keys.add(e.code);
        });
        window.addEventListener('keyup', (e) => {
            this.keys.delete(e.code);
        });
        window.addEventListener('blur', () => {
            this.keys.clear();
            this.mouse.leftDown = this.mouse.rightDown = this.mouse.middleDown = false;
        });

        this.canvas.addEventListener('contextmenu', (e) => e.preventDefault());

        this.canvas.addEventListener('mouseenter', () => {
            this.mouse.insideCanvas = true;
        });
        this.canvas.addEventListener('mouseleave', () => {
            this.mouse.insideCanvas = false;
        });

        this.canvas.addEventListener('mousemove', (e) => {
            const rect = this.canvas.getBoundingClientRect();
            const nx = e.clientX - rect.left;
            const ny = e.clientY - rect.top;
            if (this.mouse.dragButton !== -1) {
                this.mouse.dragDX += nx - this.mouse.x;
                this.mouse.dragDY += ny - this.mouse.y;
            }
            this.mouse.x = nx;
            this.mouse.y = ny;
            this.mouse.screenX = e.clientX;
            this.mouse.screenY = e.clientY;
        });

        this.canvas.addEventListener('mousedown', (e) => {
            if (e.button === 0) this.mouse.leftDown = true;
            if (e.button === 1) {
                this.mouse.middleDown = true;
                this.mouse.dragButton = 1;
                this.mouse.dragDX = 0;
                this.mouse.dragDY = 0;
                e.preventDefault();
            }
            if (e.button === 2) this.mouse.rightDown = true;
        });

        window.addEventListener('mouseup', (e) => {
            if (e.button === 0) {
                if (this.mouse.leftDown) this._registerClick(0);
                this.mouse.leftDown = false;
            }
            if (e.button === 1) {
                this.mouse.middleDown = false;
                if (this.mouse.dragButton === 1) this.mouse.dragButton = -1;
            }
            if (e.button === 2) {
                if (this.mouse.rightDown) this._registerClick(2);
                this.mouse.rightDown = false;
            }
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

    _registerClick(button) {
        const now = performance.now();
        const isDouble = button === this._lastClickButton && now - this._lastClickTime < 320;
        this.clickEvents.push({
            button,
            x: this.mouse.x,
            y: this.mouse.y,
            double: isDouble,
        });
        this._lastClickTime = isDouble ? 0 : now;
        this._lastClickButton = isDouble ? -1 : button;
    }

    isKeyDown(...codes) {
        return codes.some((c) => this.keys.has(c));
    }

    // Debe llamarse al final de cada frame para limpiar deltas de "un solo frame".
    endFrame() {
        this.mouse.wheelDelta = 0;
        this.mouse.dragDX = 0;
        this.mouse.dragDY = 0;
        this.clickEvents.length = 0;
    }
}
