import { mulberry32, makeCanvas } from '../core/utils.js';

// Catálogo de terrenos + fábrica de texturas placeholder (100% Canvas, sin dependencias
// externas). Cada textura se pre-renderiza UNA vez en un canvas fuera de pantalla y se
// reutiliza con drawImage — nada de gradientes por-tile (eso genera costuras visibles
// en los bordes de cada casilla); la variación viene de ruido/manchas orgánicas y de
// varias variantes por tipo elegidas por posición, y la "luz" es una única pasada
// direccional a nivel de escena (ver Renderer.js), no por tile.
export const TileType = Object.freeze({
    GRASS: 'grass',
    DIRT: 'dirt',
    PATH: 'path',
    WATER: 'water',
    FOREST: 'forest',
    ROCK: 'rock',
    MOUNTAIN: 'mountain',
    VILLAGE: 'village',
});

// walkable/buildable no tienen efecto todavía (no hay unidades ni construcción en la
// Fase 1), pero quedan definidos para que las fases futuras los consuman sin tener
// que tocar el catálogo de terrenos.
export const TILE_DEFS = {
    [TileType.GRASS]: { walkable: true, buildable: true, variants: 4 },
    [TileType.DIRT]: { walkable: true, buildable: true, variants: 3 },
    [TileType.PATH]: { walkable: true, buildable: false, variants: 2 },
    [TileType.WATER]: { walkable: false, buildable: false, variants: 1 },
    [TileType.FOREST]: { walkable: true, buildable: false, variants: 3 },
    [TileType.ROCK]: { walkable: true, buildable: false, variants: 3 },
    [TileType.MOUNTAIN]: { walkable: false, buildable: false, variants: 2 },
    [TileType.VILLAGE]: { walkable: true, buildable: false, variants: 2 },
};

export const TILE_SIZE = 64;
const WATER_FRAMES = 4;
// Supersampling: texturas al doble de resolución real para que se vean nítidas al
// máximo nivel de zoom de la cámara (2.0x) en vez de borrosas por escalado bilineal.
const RES = TILE_SIZE * 2;

function speckle(ctx, size, rng, count, colorFn, minR, maxR) {
    for (let i = 0; i < count; i++) {
        ctx.fillStyle = colorFn(rng);
        ctx.beginPath();
        ctx.arc(rng() * size, rng() * size, minR + rng() * (maxR - minR), 0, Math.PI * 2);
        ctx.fill();
    }
}

function buildGrass(size, seed) {
    const c = makeCanvas(size, size);
    const ctx = c.getContext('2d');
    const rng = mulberry32(seed);

    ctx.fillStyle = '#4a8a3c';
    ctx.fillRect(0, 0, size, size);

    for (let i = 0; i < 5; i++) {
        ctx.fillStyle = `rgba(${rng() < 0.5 ? '90,160,65' : '60,120,50'},${0.08 + rng() * 0.1})`;
        ctx.beginPath();
        ctx.ellipse(rng() * size, rng() * size, 10 + rng() * 16, 6 + rng() * 10, rng() * Math.PI, 0, Math.PI * 2);
        ctx.fill();
    }

    ctx.strokeStyle = 'rgba(95,170,75,0.5)';
    ctx.lineWidth = 1.3;
    for (let i = 0; i < 26; i++) {
        const x = rng() * size;
        const y = rng() * size;
        const h = 3 + rng() * 5;
        ctx.beginPath();
        ctx.moveTo(x, y);
        ctx.lineTo(x + (rng() - 0.5) * 3, y - h);
        ctx.stroke();
    }

    if (rng() < 0.35) {
        const colors = ['#e8d24b', '#f2f2f2', '#e79fd0'];
        for (let i = 0; i < 3; i++) {
            ctx.fillStyle = colors[Math.floor(rng() * colors.length)];
            ctx.beginPath();
            ctx.arc(rng() * size, rng() * size, 1.6, 0, Math.PI * 2);
            ctx.fill();
        }
    }
    return c;
}

function buildDirt(size, seed) {
    const c = makeCanvas(size, size);
    const ctx = c.getContext('2d');
    const rng = mulberry32(seed);
    ctx.fillStyle = '#8a6a45';
    ctx.fillRect(0, 0, size, size);
    speckle(ctx, size, rng, 18, () => `rgba(55,40,26,${0.15 + rng() * 0.2})`, 1, 3.2);
    speckle(ctx, size, rng, 10, () => `rgba(165,135,95,${0.15 + rng() * 0.2})`, 1, 2.4);
    return c;
}

function buildPath(size, seed) {
    const c = makeCanvas(size, size);
    const ctx = c.getContext('2d');
    const rng = mulberry32(seed);
    ctx.fillStyle = '#b89b6c';
    ctx.fillRect(0, 0, size, size);
    speckle(ctx, size, rng, 14, () => `rgba(120,95,60,${0.2 + rng() * 0.2})`, 1.5, 3.5);
    // Pequeñas piedras del camino.
    for (let i = 0; i < 4; i++) {
        ctx.fillStyle = `rgba(140,130,115,${0.5 + rng() * 0.3})`;
        ctx.beginPath();
        ctx.ellipse(rng() * size, rng() * size, 3 + rng() * 2.5, 2 + rng() * 1.5, rng() * Math.PI, 0, Math.PI * 2);
        ctx.fill();
    }
    return c;
}

function buildVillageGround(size, seed) {
    const c = makeCanvas(size, size);
    const ctx = c.getContext('2d');
    const rng = mulberry32(seed);
    ctx.fillStyle = '#c2a877';
    ctx.fillRect(0, 0, size, size);
    // Losas de tierra apisonada / patio de la aldea.
    const cell = size / 3;
    ctx.strokeStyle = 'rgba(110,88,58,0.35)';
    ctx.lineWidth = 1.5;
    for (let gy = 0; gy <= 3; gy++) {
        for (let gx = 0; gx <= 3; gx++) {
            const jx = (rng() - 0.5) * 4;
            const jy = (rng() - 0.5) * 4;
            ctx.strokeRect(gx * cell + jx, gy * cell + jy, cell - 4, cell - 4);
        }
    }
    speckle(ctx, size, rng, 8, () => `rgba(90,70,45,${0.12 + rng() * 0.12})`, 1, 2.4);
    return c;
}

function buildRock(size, seed) {
    const c = makeCanvas(size, size);
    const ctx = c.getContext('2d');
    const rng = mulberry32(seed);
    ctx.fillStyle = '#83807c';
    ctx.fillRect(0, 0, size, size);
    for (let i = 0; i < 6; i++) {
        const cx = rng() * size;
        const cy = rng() * size;
        const r = 8 + rng() * 14;
        ctx.fillStyle = rng() < 0.5 ? 'rgba(255,255,255,0.09)' : 'rgba(0,0,0,0.14)';
        ctx.beginPath();
        const sides = 5 + Math.floor(rng() * 3);
        for (let s = 0; s < sides; s++) {
            const angle = (s / sides) * Math.PI * 2;
            const rr = r * (0.7 + rng() * 0.3);
            const px = cx + Math.cos(angle) * rr;
            const py = cy + Math.sin(angle) * rr;
            s === 0 ? ctx.moveTo(px, py) : ctx.lineTo(px, py);
        }
        ctx.closePath();
        ctx.fill();
    }
    return c;
}

function buildMountain(size, seed) {
    const c = makeCanvas(size, size);
    const ctx = c.getContext('2d');
    const rng = mulberry32(seed);
    ctx.fillStyle = '#5c6473';
    ctx.fillRect(0, 0, size, size);

    ctx.fillStyle = 'rgba(235,240,248,0.25)';
    for (let i = 0; i < 4; i++) {
        const x = rng() * size;
        const topY = rng() * size * 0.45;
        ctx.beginPath();
        ctx.moveTo(x, topY);
        ctx.lineTo(x + 11 + rng() * 8, topY + 24 + rng() * 10);
        ctx.lineTo(x - 11 - rng() * 8, topY + 24 + rng() * 10);
        ctx.closePath();
        ctx.fill();
    }

    ctx.strokeStyle = 'rgba(12,15,20,0.35)';
    ctx.lineWidth = 1.2;
    for (let i = 0; i < 5; i++) {
        ctx.beginPath();
        ctx.moveTo(rng() * size, rng() * size);
        ctx.lineTo(rng() * size, rng() * size);
        ctx.stroke();
    }
    return c;
}

function buildForestFloor(size, seed) {
    const c = makeCanvas(size, size);
    const ctx = c.getContext('2d');
    const rng = mulberry32(seed);
    ctx.fillStyle = '#33532c';
    ctx.fillRect(0, 0, size, size);
    speckle(ctx, size, rng, 14, () => `rgba(${rng() < 0.5 ? '150,110,50' : '90,70,40'},${0.22 + rng() * 0.2})`, 1.5, 3.5);
    speckle(ctx, size, rng, 10, () => `rgba(18,38,16,${0.2 + rng() * 0.2})`, 2, 5);
    return c;
}

function buildWaterFrame(size, seed, frame) {
    const c = makeCanvas(size, size);
    const ctx = c.getContext('2d');
    const rng = mulberry32(seed);
    ctx.fillStyle = '#2f6f96';
    ctx.fillRect(0, 0, size, size);

    const phase = (frame / WATER_FRAMES) * Math.PI * 2;
    ctx.strokeStyle = 'rgba(205,238,255,0.4)';
    ctx.lineWidth = 1.6;
    for (let row = 0; row < 4; row++) {
        const y = (row + 0.5) * (size / 4);
        ctx.beginPath();
        for (let x = 0; x <= size; x += 4) {
            const wave = Math.sin(x * 0.15 + phase + row) * 2.6;
            x === 0 ? ctx.moveTo(x, y + wave) : ctx.lineTo(x, y + wave);
        }
        ctx.stroke();
    }

    // Reflejos: destellos claros que se desplazan con el frame de animación.
    ctx.fillStyle = 'rgba(255,255,255,0.14)';
    for (let i = 0; i < 3; i++) {
        const x = (rng() * size + frame * 9) % size;
        ctx.beginPath();
        ctx.ellipse(x, rng() * size, 6, 2, 0, 0, Math.PI * 2);
        ctx.fill();
    }
    return c;
}

export class TerrainArt {
    constructor(seed = 4200) {
        this.textures = {};
        this.textures[TileType.GRASS] = [1, 2, 3].map((v) => buildGrass(RES, seed + v));
        this.textures[TileType.DIRT] = [1, 2, 3].map((v) => buildDirt(RES, seed + 100 + v));
        this.textures[TileType.PATH] = [1, 2].map((v) => buildPath(RES, seed + 200 + v));
        this.textures[TileType.VILLAGE] = [1, 2].map((v) => buildVillageGround(RES, seed + 300 + v));
        this.textures[TileType.ROCK] = [1, 2, 3].map((v) => buildRock(RES, seed + 400 + v));
        this.textures[TileType.MOUNTAIN] = [1, 2].map((v) => buildMountain(RES, seed + 500 + v));
        this.textures[TileType.FOREST] = [1, 2, 3].map((v) => buildForestFloor(RES, seed + 600 + v));
        this.textures[TileType.WATER] = Array.from({ length: WATER_FRAMES }, (_, f) => buildWaterFrame(RES, seed + 700, f));
    }

    getTexture(type, variantIndex, animFrame = 0) {
        const frames = this.textures[type];
        if (!frames) return null;
        if (type === TileType.WATER) return frames[animFrame % frames.length];
        return frames[variantIndex % frames.length];
    }
}
