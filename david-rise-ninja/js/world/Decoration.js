import { mulberry32, hash2i, makeCanvas } from '../core/utils.js';
import { TileType, TILE_SIZE } from './Terrain.js';

// Vegetación y objetos decorativos: fábrica de sprites procedurales (árboles grandes y
// pequeños, arbustos, flores, troncos caídos, rocas sueltas, juncos) + el algoritmo que
// los reparte sobre el mapa según el tipo de terreno. Cada sprite se genera con varias
// variantes y una semilla distinta por instancia, así que ninguna copia visual se repite
// exactamente igual (tal como pide el diseño: "NO generar árboles idénticos").

function canopyBlob(ctx, cx, cy, r, top, bottom) {
    const g = ctx.createRadialGradient(cx - r * 0.3, cy - r * 0.4, r * 0.2, cx, cy, r);
    g.addColorStop(0, top);
    g.addColorStop(1, bottom);
    ctx.fillStyle = g;
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.fill();
}

function buildTreeSprite(seed, big) {
    const w = big ? 72 : 46;
    const h = big ? 108 : 68;
    const c = makeCanvas(w, h);
    const ctx = c.getContext('2d');
    const rng = mulberry32(seed);
    const baseX = w / 2;
    const groundY = h - 6;
    const trunkH = big ? 34 : 20;

    const trunkGrad = ctx.createLinearGradient(baseX - 6, 0, baseX + 6, 0);
    trunkGrad.addColorStop(0, '#4a3320');
    trunkGrad.addColorStop(1, '#6e4c2c');
    ctx.fillStyle = trunkGrad;
    const trunkW = big ? 7 : 4.5;
    ctx.beginPath();
    ctx.moveTo(baseX - trunkW, groundY);
    ctx.lineTo(baseX - trunkW * 0.55, groundY - trunkH);
    ctx.lineTo(baseX + trunkW * 0.55, groundY - trunkH);
    ctx.lineTo(baseX + trunkW, groundY);
    ctx.closePath();
    ctx.fill();

    const canopyY = groundY - trunkH;
    const rBase = big ? 24 : 15;
    canopyBlob(ctx, baseX, canopyY - rBase * 0.8, rBase, '#5fa84c', '#2b5c26');
    canopyBlob(ctx, baseX - rBase * 0.7, canopyY - rBase * 0.35, rBase * 0.72, '#529644', '#254f21');
    canopyBlob(ctx, baseX + rBase * 0.68, canopyY - rBase * 0.4, rBase * 0.75, '#529644', '#254f21');
    canopyBlob(ctx, baseX, canopyY - rBase * 1.5, rBase * 0.62, '#72c15b', '#2f6329');

    if (rng() < 0.5) {
        ctx.fillStyle = 'rgba(255,255,255,0.08)';
        ctx.beginPath();
        ctx.arc(baseX - rBase * 0.4, canopyY - rBase * 1.1, rBase * 0.4, 0, Math.PI * 2);
        ctx.fill();
    }
    return c;
}

function buildBushSprite(seed) {
    const w = 36;
    const h = 26;
    const c = makeCanvas(w, h);
    const ctx = c.getContext('2d');
    const rng = mulberry32(seed);
    const cy = h - 8;
    for (let i = 0; i < 4; i++) {
        const bx = 8 + i * 6 + (rng() - 0.5) * 3;
        const by = cy - rng() * 4;
        const r = 7 + rng() * 3;
        canopyBlob(ctx, bx, by, r, '#5f9a49', '#2f5e1f');
    }
    return c;
}

function buildFlowerClusterSprite(seed) {
    const w = 20;
    const h = 14;
    const c = makeCanvas(w, h);
    const ctx = c.getContext('2d');
    const rng = mulberry32(seed);
    const palette = ['#e8567a', '#f2d33c', '#ffffff', '#c77dde'];
    for (let i = 0; i < 6; i++) {
        const x = rng() * w;
        const y = h - rng() * 8;
        ctx.strokeStyle = 'rgba(60,100,40,0.7)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x, h);
        ctx.lineTo(x, y);
        ctx.stroke();
        ctx.fillStyle = palette[Math.floor(rng() * palette.length)];
        ctx.beginPath();
        ctx.arc(x, y, 1.8, 0, Math.PI * 2);
        ctx.fill();
    }
    return c;
}

function buildLogSprite(seed) {
    const w = 40;
    const h = 16;
    const c = makeCanvas(w, h);
    const ctx = c.getContext('2d');
    const rng = mulberry32(seed);
    const grad = ctx.createLinearGradient(0, h * 0.3, 0, h);
    grad.addColorStop(0, '#7c5a38');
    grad.addColorStop(1, '#4c3620');
    ctx.fillStyle = grad;
    ctx.beginPath();
    ctx.ellipse(w / 2, h * 0.65, w / 2 - 2, h * 0.32, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = '#caa876';
    ctx.beginPath();
    ctx.ellipse(4, h * 0.65, h * 0.28, h * 0.3, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.strokeStyle = 'rgba(40,26,14,0.4)';
    ctx.lineWidth = 1;
    for (let i = 0; i < 3; i++) {
        ctx.beginPath();
        ctx.moveTo(8 + i * 9, h * 0.5);
        ctx.lineTo(8 + i * 9 + 6, h * 0.85);
        ctx.stroke();
    }
    return c;
}

function buildRockSprite(seed, medium) {
    const w = medium ? 46 : 30;
    const h = medium ? 34 : 22;
    const c = makeCanvas(w, h);
    const ctx = c.getContext('2d');
    const rng = mulberry32(seed);
    const cx = w / 2;
    const cy = h - h * 0.34;
    const grad = ctx.createLinearGradient(cx - w * 0.3, cy - h * 0.3, cx + w * 0.3, cy + h * 0.2);
    grad.addColorStop(0, '#a3a5a8');
    grad.addColorStop(1, '#6c6e72');
    ctx.fillStyle = grad;
    ctx.beginPath();
    const sides = 6;
    const r = w * 0.36;
    for (let s = 0; s < sides; s++) {
        const angle = (s / sides) * Math.PI * 2;
        const rr = r * (0.75 + rng() * 0.3);
        const px = cx + Math.cos(angle) * rr;
        const py = cy + Math.sin(angle) * rr * 0.75;
        s === 0 ? ctx.moveTo(px, py) : ctx.lineTo(px, py);
    }
    ctx.closePath();
    ctx.fill();
    ctx.strokeStyle = 'rgba(40,40,42,0.5)';
    ctx.stroke();
    ctx.strokeStyle = 'rgba(255,255,255,0.25)';
    ctx.beginPath();
    ctx.moveTo(cx - r * 0.4, cy - r * 0.5);
    ctx.lineTo(cx + r * 0.1, cy - r * 0.2);
    ctx.stroke();
    return c;
}

function buildReedSprite(seed) {
    const w = 20;
    const h = 32;
    const c = makeCanvas(w, h);
    const ctx = c.getContext('2d');
    const rng = mulberry32(seed);
    for (let i = 0; i < 5; i++) {
        const x = 3 + i * 3.4 + rng() * 2;
        const lean = (rng() - 0.5) * 8;
        const grad = ctx.createLinearGradient(x, h, x + lean, 0);
        grad.addColorStop(0, '#4c6b2c');
        grad.addColorStop(1, '#83a94a');
        ctx.strokeStyle = grad;
        ctx.lineWidth = 1.6;
        ctx.beginPath();
        ctx.moveTo(x, h - 4);
        ctx.quadraticCurveTo(x + lean * 0.5, h * 0.5, x + lean, 2);
        ctx.stroke();
    }
    return c;
}

function buildShadowSprite() {
    const size = 48;
    const c = makeCanvas(size, size);
    const ctx = c.getContext('2d');
    const g = ctx.createRadialGradient(size / 2, size / 2, 2, size / 2, size / 2, size / 2);
    g.addColorStop(0, 'rgba(0,0,0,0.4)');
    g.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, size, size);
    return c;
}

export class DecorationArt {
    constructor(seed = 6100) {
        this.sprites = {
            treeBig: [buildTreeSprite(seed + 1, true), buildTreeSprite(seed + 2, true), buildTreeSprite(seed + 3, true)],
            treeSmall: [buildTreeSprite(seed + 4, false), buildTreeSprite(seed + 5, false)],
            bush: [buildBushSprite(seed + 6), buildBushSprite(seed + 7)],
            flowers: [buildFlowerClusterSprite(seed + 8), buildFlowerClusterSprite(seed + 9)],
            log: [buildLogSprite(seed + 10)],
            rockSmall: [buildRockSprite(seed + 11, false), buildRockSprite(seed + 12, false)],
            rockMedium: [buildRockSprite(seed + 13, true)],
            reed: [buildReedSprite(seed + 14)],
        };
        this.shadow = buildShadowSprite();
    }

    get(kind, variant) {
        const list = this.sprites[kind];
        return list ? list[variant % list.length] : null;
    }
}

function isNearWater(map, x, y) {
    for (let dy = -1; dy <= 1; dy++) {
        for (let dx = -1; dx <= 1; dx++) {
            if (map.getTileTypeAt(x + dx, y + dy) === TileType.WATER) return true;
        }
    }
    return false;
}

function place(kind, variantCount, worldX, worldY, seed) {
    return {
        kind,
        variant: Math.floor(hash2i(worldX, worldY, seed) * variantCount),
        x: worldX,
        y: worldY,
        scale: 0.85 + hash2i(worldX, worldY, seed + 1) * 0.4,
        flip: hash2i(worldX, worldY, seed + 2) < 0.5 ? -1 : 1,
    };
}

// Reparte la vegetación/objetos por el mapa según el tipo de terreno de cada casilla.
// Devuelve una lista ya ordenada por Y (profundidad) para que el Renderer sólo tenga
// que recorrerla en orden (algoritmo del pintor) sin re-ordenar cada frame.
export function scatterDecorations(map, seed = 7331) {
    const decorations = [];
    const rng = mulberry32(seed);

    for (let y = 0; y < map.height; y++) {
        for (let x = 0; x < map.width; x++) {
            const type = map.getTileTypeAt(x, y);
            const wx = x * TILE_SIZE + rng() * TILE_SIZE;
            const wy = y * TILE_SIZE + rng() * TILE_SIZE;
            const roll = hash2i(x, y, seed);

            const nearShore = (type === TileType.GRASS || type === TileType.DIRT) && isNearWater(map, x, y);

            if (type === TileType.FOREST) {
                if (roll < 0.55) decorations.push(place('treeBig', 3, wx, wy, seed));
                else if (roll < 0.8) decorations.push(place('treeSmall', 2, wx, wy, seed + 1));
                else if (roll < 0.9) decorations.push(place('bush', 2, wx, wy, seed + 2));
                else if (roll < 0.95) decorations.push(place('log', 1, wx, wy, seed + 3));
            } else if (type === TileType.ROCK) {
                if (roll > 0.55) decorations.push(place('rockMedium', 1, wx, wy, seed + 8));
                else if (roll > 0.3) decorations.push(place('rockSmall', 2, wx, wy, seed + 9));
            } else if (nearShore && roll > 0.45) {
                decorations.push(place('reed', 1, wx, wy, seed + 11));
            } else if (type === TileType.GRASS) {
                if (roll > 0.985) decorations.push(place('treeSmall', 2, wx, wy, seed + 4));
                else if (roll > 0.95) decorations.push(place('bush', 2, wx, wy, seed + 5));
                else if (roll > 0.9) decorations.push(place('flowers', 2, wx, wy, seed + 6));
                else if (roll > 0.88) decorations.push(place('rockSmall', 2, wx, wy, seed + 7));
            } else if (type === TileType.DIRT && roll > 0.97) {
                decorations.push(place('rockSmall', 2, wx, wy, seed + 10));
            }
        }
    }

    decorations.sort((a, b) => a.y - b.y);
    return decorations;
}
