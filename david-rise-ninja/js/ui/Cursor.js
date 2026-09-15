import { makeCanvas } from '../core/utils.js';

// Cursor personalizado dibujado por Canvas (sin depender de imágenes externas) y
// aplicado como `cursor: url(...)` CSS. Sólo dos estados activos en esta fase
// (normal / selección); movimiento, ataque y construcción quedan como huecos ya
// previstos en el switch de `setState` para cuando existan esos sistemas.
function cursorDataURL(drawFn, size = 32) {
    const c = makeCanvas(size, size);
    const ctx = c.getContext('2d');
    drawFn(ctx, size);
    return c.toDataURL('image/png');
}

function drawNormalCursor(ctx, size) {
    ctx.fillStyle = 'rgba(20,14,8,0.85)';
    ctx.strokeStyle = '#d8b45f';
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.moveTo(4, 3);
    ctx.lineTo(4, 24);
    ctx.lineTo(10, 19);
    ctx.lineTo(14, 27);
    ctx.lineTo(18, 25);
    ctx.lineTo(14, 17);
    ctx.lineTo(21, 16);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();
}

function drawSelectCursor(ctx, size) {
    const cx = size / 2;
    const cy = size / 2;
    ctx.strokeStyle = '#7fd8d0';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.arc(cx, cy, 9, 0, Math.PI * 2);
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(cx - 13, cy);
    ctx.lineTo(cx - 6, cy);
    ctx.moveTo(cx + 6, cy);
    ctx.lineTo(cx + 13, cy);
    ctx.moveTo(cx, cy - 13);
    ctx.lineTo(cx, cy - 6);
    ctx.moveTo(cx, cy + 6);
    ctx.lineTo(cx, cy + 13);
    ctx.stroke();
}

const CURSORS = {
    normal: `url(${cursorDataURL(drawNormalCursor)}) 4 3, auto`,
    select: `url(${cursorDataURL(drawSelectCursor)}) 16 16, auto`,
    // Reservados para fases futuras (movimiento / ataque / construcción):
    move: `url(${cursorDataURL(drawSelectCursor)}) 16 16, auto`,
    attack: `url(${cursorDataURL(drawSelectCursor)}) 16 16, auto`,
    build: `url(${cursorDataURL(drawSelectCursor)}) 16 16, auto`,
};

export class Cursor {
    constructor(targetEl) {
        this.targetEl = targetEl;
        this.state = 'normal';
        this.setState('normal');
    }

    setState(state) {
        if (this.state === state) return;
        this.state = state;
        this.targetEl.style.cursor = CURSORS[state] || CURSORS.normal;
    }

    update(input) {
        this.setState(input.mouse.leftDown ? 'select' : 'normal');
    }
}
