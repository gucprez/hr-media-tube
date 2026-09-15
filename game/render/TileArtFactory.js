import { mulberry32 } from '../core/utils.js';
import { TileType, TILE_SIZE } from '../maps/TileTypes.js';

// Fábrica de arte "placeholder" de calidad profesional generado 100% por código.
// Cada textura de tile y cada sprite de decoración se dibuja UNA sola vez sobre un
// canvas fuera de pantalla y se reutiliza (drawImage) el resto de la partida: así
// obtenemos detalle visual (gradientes, ruido, sombreado) sin coste de CPU por frame.
//
// El día que existan sprites PNG/WebP definitivos, basta con sustituir las funciones
// `build*` de este archivo por `image.src = "assets/..."`: el resto del motor sólo
// conoce `canvas` / `width` / `height`, nunca cómo se generó el dibujo.

const WATER_FRAMES = 4;
// Las texturas se generan al doble de la resolución de tile real (supersampling) para
// que se vean nítidas incluso al máximo nivel de zoom de la cámara.
const TEXTURE_RES = TILE_SIZE * 2;

function makeCanvas(w, h) {
    const c = document.createElement('canvas');
    c.width = w;
    c.height = h;
    return c;
}

function speckle(ctx, size, rng, count, colorFn, minR, maxR) {
    for (let i = 0; i < count; i++) {
        const x = rng() * size;
        const y = rng() * size;
        const r = minR + rng() * (maxR - minR);
        ctx.fillStyle = colorFn(rng);
        ctx.beginPath();
        ctx.arc(x, y, r, 0, Math.PI * 2);
        ctx.fill();
    }
}

function buildGrassTile(size, seed) {
    const canvas = makeCanvas(size, size);
    const ctx = canvas.getContext('2d');
    const rng = mulberry32(seed);

    ctx.fillStyle = '#437c38';
    ctx.fillRect(0, 0, size, size);

    // Manchas orgánicas de tono para romper la uniformidad del color base.
    for (let i = 0; i < 5; i++) {
        ctx.fillStyle = `rgba(${rng() < 0.5 ? '80,150,60' : '55,110,45'}, ${0.08 + rng() * 0.1})`;
        ctx.beginPath();
        ctx.ellipse(rng() * size, rng() * size, 10 + rng() * 14, 6 + rng() * 10, rng() * Math.PI, 0, Math.PI * 2);
        ctx.fill();
    }

    // Briznas de hierba individuales.
    ctx.strokeStyle = 'rgba(90,160,70,0.55)';
    ctx.lineWidth = 1.4;
    for (let i = 0; i < 26; i++) {
        const x = rng() * size;
        const y = rng() * size;
        const h = 3 + rng() * 5;
        ctx.beginPath();
        ctx.moveTo(x, y);
        ctx.lineTo(x + (rng() - 0.5) * 3, y - h);
        ctx.stroke();
    }

    if (rng() < 0.4) {
        for (let i = 0; i < 3; i++) {
            ctx.fillStyle = ['#e8d24b', '#e6e6e6', '#e6a3c4'][Math.floor(rng() * 3)];
            ctx.beginPath();
            ctx.arc(rng() * size, rng() * size, 1.6, 0, Math.PI * 2);
            ctx.fill();
        }
    }

    return canvas;
}

function buildDirtTile(size, seed) {
    const canvas = makeCanvas(size, size);
    const ctx = canvas.getContext('2d');
    const rng = mulberry32(seed);

    ctx.fillStyle = '#7c5d3e';
    ctx.fillRect(0, 0, size, size);

    speckle(ctx, size, rng, 18, () => `rgba(60,45,30,${0.15 + rng() * 0.2})`, 1, 3.2);
    speckle(ctx, size, rng, 10, () => `rgba(160,130,90,${0.15 + rng() * 0.2})`, 1, 2.4);

    ctx.strokeStyle = 'rgba(50,36,24,0.25)';
    ctx.lineWidth = 1;
    for (let i = 0; i < 4; i++) {
        ctx.beginPath();
        ctx.moveTo(rng() * size, rng() * size);
        ctx.bezierCurveTo(rng() * size, rng() * size, rng() * size, rng() * size, rng() * size, rng() * size);
        ctx.stroke();
    }

    return canvas;
}

function buildRockTile(size, seed) {
    const canvas = makeCanvas(size, size);
    const ctx = canvas.getContext('2d');
    const rng = mulberry32(seed);

    ctx.fillStyle = '#797a7e';
    ctx.fillRect(0, 0, size, size);

    // Facetas rocosas: polígonos suaves con sombreado alterno para simular relieve.
    for (let i = 0; i < 6; i++) {
        const cx = rng() * size;
        const cy = rng() * size;
        const r = 8 + rng() * 14;
        ctx.fillStyle = rng() < 0.5 ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.12)';
        ctx.beginPath();
        const sides = 5 + Math.floor(rng() * 3);
        for (let s = 0; s < sides; s++) {
            const angle = (s / sides) * Math.PI * 2;
            const rr = r * (0.7 + rng() * 0.3);
            const px = cx + Math.cos(angle) * rr;
            const py = cy + Math.sin(angle) * rr;
            if (s === 0) ctx.moveTo(px, py);
            else ctx.lineTo(px, py);
        }
        ctx.closePath();
        ctx.fill();
    }

    ctx.strokeStyle = 'rgba(30,30,32,0.3)';
    ctx.lineWidth = 1;
    for (let i = 0; i < 3; i++) {
        ctx.beginPath();
        ctx.moveTo(rng() * size, rng() * size);
        ctx.lineTo(rng() * size, rng() * size);
        ctx.stroke();
    }

    return canvas;
}

function buildMountainTile(size, seed) {
    const canvas = makeCanvas(size, size);
    const ctx = canvas.getContext('2d');
    const rng = mulberry32(seed);

    ctx.fillStyle = '#4d5563';
    ctx.fillRect(0, 0, size, size);

    // Picos y aristas afiladas.
    ctx.fillStyle = 'rgba(230,235,245,0.22)';
    for (let i = 0; i < 4; i++) {
        const x = rng() * size;
        const topY = rng() * size * 0.5;
        ctx.beginPath();
        ctx.moveTo(x, topY);
        ctx.lineTo(x + 10 + rng() * 8, topY + 22 + rng() * 10);
        ctx.lineTo(x - 10 - rng() * 8, topY + 22 + rng() * 10);
        ctx.closePath();
        ctx.fill();
    }

    ctx.strokeStyle = 'rgba(10,12,16,0.35)';
    ctx.lineWidth = 1.2;
    for (let i = 0; i < 5; i++) {
        ctx.beginPath();
        ctx.moveTo(rng() * size, rng() * size);
        ctx.lineTo(rng() * size, rng() * size);
        ctx.stroke();
    }

    return canvas;
}

function buildForestFloorTile(size, seed) {
    const canvas = makeCanvas(size, size);
    const ctx = canvas.getContext('2d');
    const rng = mulberry32(seed);

    ctx.fillStyle = '#2f4d29';
    ctx.fillRect(0, 0, size, size);

    speckle(ctx, size, rng, 14, () => `rgba(${rng() < 0.5 ? '150,110,50' : '90,70,40'},${0.25 + rng() * 0.2})`, 1.5, 3.5);
    speckle(ctx, size, rng, 10, () => `rgba(20,40,18,${0.2 + rng() * 0.2})`, 2, 5);

    return canvas;
}

function buildRoadTile(size, seed) {
    const canvas = makeCanvas(size, size);
    const ctx = canvas.getContext('2d');
    const rng = mulberry32(seed);

    ctx.fillStyle = '#977c56';
    ctx.fillRect(0, 0, size, size);

    // Adoquines: rejilla ligeramente irregular.
    const cell = size / 4;
    ctx.strokeStyle = 'rgba(60,45,28,0.35)';
    ctx.lineWidth = 1.4;
    for (let gy = 0; gy <= 4; gy++) {
        for (let gx = 0; gx <= 4; gx++) {
            const jx = (rng() - 0.5) * 3;
            const jy = (rng() - 0.5) * 3;
            ctx.strokeRect(gx * cell + jx, gy * cell + jy, cell - 3, cell - 3);
        }
    }

    return canvas;
}

function buildBuildableTile(size, seed) {
    const canvas = buildGrassTile(size, seed);
    const ctx = canvas.getContext('2d');
    // Tinte dorado translúcido y sutil para señalar "zona construible" sin
    // generar una rejilla visible (nada de bordes por-tile: eso se dibuja
    // como un contorno único de la zona completa, no por casilla).
    ctx.fillStyle = 'rgba(255, 221, 140, 0.10)';
    ctx.fillRect(0, 0, size, size);
    return canvas;
}

function buildObstacleTile(size, seed) {
    const canvas = makeCanvas(size, size);
    const ctx = canvas.getContext('2d');
    const rng = mulberry32(seed);

    ctx.fillStyle = '#63563f';
    ctx.fillRect(0, 0, size, size);

    for (let i = 0; i < 5; i++) {
        const cx = size / 2 + (rng() - 0.5) * size * 0.5;
        const cy = size / 2 + (rng() - 0.5) * size * 0.5;
        const r = 6 + rng() * 10;
        ctx.fillStyle = `rgba(${40 + rng() * 40},${35 + rng() * 30},${28 + rng() * 24},0.9)`;
        ctx.beginPath();
        ctx.arc(cx, cy, r, 0, Math.PI * 2);
        ctx.fill();
        ctx.strokeStyle = 'rgba(0,0,0,0.4)';
        ctx.stroke();
    }

    return canvas;
}

function buildWaterFrame(size, seed, frame) {
    const canvas = makeCanvas(size, size);
    const ctx = canvas.getContext('2d');
    const rng = mulberry32(seed);

    ctx.fillStyle = '#2c6994';
    ctx.fillRect(0, 0, size, size);

    const phase = (frame / WATER_FRAMES) * Math.PI * 2;
    ctx.strokeStyle = 'rgba(200,235,255,0.35)';
    ctx.lineWidth = 1.6;
    for (let row = 0; row < 4; row++) {
        const y = (row + 0.5) * (size / 4);
        ctx.beginPath();
        for (let x = 0; x <= size; x += 4) {
            const wave = Math.sin(x * 0.15 + phase + row) * 2.5;
            if (x === 0) ctx.moveTo(x, y + wave);
            else ctx.lineTo(x, y + wave);
        }
        ctx.stroke();
    }

    ctx.fillStyle = 'rgba(255,255,255,0.12)';
    for (let i = 0; i < 3; i++) {
        const x = (rng() * size + frame * 9) % size;
        const y = rng() * size;
        ctx.beginPath();
        ctx.ellipse(x, y, 6, 2, 0, 0, Math.PI * 2);
        ctx.fill();
    }

    return canvas;
}

// ---- Sprites de decoración (más altos que anchos: se dibujan "de pie" sobre el tile) ----

function buildTreeSprite(seed, pine) {
    const w = 56;
    const h = 84;
    const canvas = makeCanvas(w, h);
    const ctx = canvas.getContext('2d');
    const rng = mulberry32(seed);
    const baseX = w / 2;
    const groundY = h - 6;

    // Tronco
    const trunkGrad = ctx.createLinearGradient(baseX - 5, 0, baseX + 5, 0);
    trunkGrad.addColorStop(0, '#4a3320');
    trunkGrad.addColorStop(1, '#6b4a2c');
    ctx.fillStyle = trunkGrad;
    ctx.beginPath();
    ctx.moveTo(baseX - 5, groundY);
    ctx.lineTo(baseX - 3, groundY - 26);
    ctx.lineTo(baseX + 3, groundY - 26);
    ctx.lineTo(baseX + 5, groundY);
    ctx.closePath();
    ctx.fill();

    function canopyBlob(cx, cy, r, colorTop, colorBottom) {
        const g = ctx.createRadialGradient(cx - r * 0.3, cy - r * 0.4, r * 0.2, cx, cy, r);
        g.addColorStop(0, colorTop);
        g.addColorStop(1, colorBottom);
        ctx.fillStyle = g;
        ctx.beginPath();
        ctx.arc(cx, cy, r, 0, Math.PI * 2);
        ctx.fill();
    }

    if (pine) {
        // Copa tipo conífera: capas triangulares apiladas.
        const layers = 3;
        for (let i = 0; i < layers; i++) {
            const ly = groundY - 26 - i * 16;
            const lw = 22 - i * 4 + rng() * 3;
            const grad = ctx.createLinearGradient(baseX - lw, ly, baseX + lw, ly - 18);
            grad.addColorStop(0, '#1f4a24');
            grad.addColorStop(1, '#3d7a3a');
            ctx.fillStyle = grad;
            ctx.beginPath();
            ctx.moveTo(baseX, ly - 22);
            ctx.lineTo(baseX + lw, ly);
            ctx.lineTo(baseX - lw, ly);
            ctx.closePath();
            ctx.fill();
        }
    } else {
        // Copa redondeada de frondosa: varios blobs superpuestos.
        canopyBlob(baseX, groundY - 44, 20, '#5da349', '#2c5a28');
        canopyBlob(baseX - 14, groundY - 36, 14, '#4f9440', '#254f22');
        canopyBlob(baseX + 13, groundY - 38, 15, '#4f9440', '#254f22');
        canopyBlob(baseX, groundY - 58, 13, '#6cb457', '#2f5f2a');
    }

    return canvas;
}

function buildRockDecoSprite(seed) {
    const w = 40;
    const h = 30;
    const canvas = makeCanvas(w, h);
    const ctx = canvas.getContext('2d');
    const rng = mulberry32(seed);
    const cx = w / 2;
    const cy = h - 10;

    ctx.fillStyle = 'rgba(0,0,0,0.25)';
    ctx.beginPath();
    ctx.ellipse(cx, h - 4, 15, 4, 0, 0, Math.PI * 2);
    ctx.fill();

    const grad = ctx.createLinearGradient(cx - 14, cy - 14, cx + 14, cy + 8);
    grad.addColorStop(0, '#a3a5a8');
    grad.addColorStop(1, '#6c6e72');
    ctx.fillStyle = grad;
    ctx.beginPath();
    const sides = 6;
    for (let s = 0; s < sides; s++) {
        const angle = (s / sides) * Math.PI * 2;
        const r = 13 * (0.75 + rng() * 0.35);
        const px = cx + Math.cos(angle) * r;
        const py = cy + Math.sin(angle) * r * 0.7;
        if (s === 0) ctx.moveTo(px, py);
        else ctx.lineTo(px, py);
    }
    ctx.closePath();
    ctx.fill();
    ctx.strokeStyle = 'rgba(40,40,42,0.5)';
    ctx.stroke();

    ctx.strokeStyle = 'rgba(255,255,255,0.25)';
    ctx.beginPath();
    ctx.moveTo(cx - 6, cy - 8);
    ctx.lineTo(cx + 2, cy - 4);
    ctx.stroke();

    return canvas;
}

function buildBushSprite(seed) {
    const w = 36;
    const h = 26;
    const canvas = makeCanvas(w, h);
    const ctx = canvas.getContext('2d');
    const rng = mulberry32(seed);
    const cy = h - 8;

    ctx.fillStyle = 'rgba(0,0,0,0.22)';
    ctx.beginPath();
    ctx.ellipse(w / 2, h - 3, 13, 3.5, 0, 0, Math.PI * 2);
    ctx.fill();

    const blobs = 4;
    for (let i = 0; i < blobs; i++) {
        const bx = 8 + i * 6 + (rng() - 0.5) * 3;
        const by = cy - rng() * 4;
        const r = 7 + rng() * 3;
        const g = ctx.createRadialGradient(bx - 2, by - 2, 1, bx, by, r);
        g.addColorStop(0, '#5f9a49');
        g.addColorStop(1, '#31601f');
        ctx.fillStyle = g;
        ctx.beginPath();
        ctx.arc(bx, by, r, 0, Math.PI * 2);
        ctx.fill();
    }

    if (rng() < 0.6) {
        ctx.fillStyle = '#b23a4a';
        for (let i = 0; i < 4; i++) {
            ctx.beginPath();
            ctx.arc(10 + rng() * 16, cy - rng() * 8, 1.3, 0, Math.PI * 2);
            ctx.fill();
        }
    }

    return canvas;
}

function buildReedSprite(seed) {
    const w = 20;
    const h = 34;
    const canvas = makeCanvas(w, h);
    const ctx = canvas.getContext('2d');
    const rng = mulberry32(seed);

    ctx.strokeStyle = 'rgba(0,0,0,0.2)';
    ctx.lineWidth = 3;
    ctx.beginPath();
    ctx.ellipse(w / 2, h - 2, 8, 2.4, 0, 0, Math.PI * 2);
    ctx.stroke();

    for (let i = 0; i < 5; i++) {
        const x = 3 + i * 3.4 + rng() * 2;
        const lean = (rng() - 0.5) * 8;
        const grad = ctx.createLinearGradient(x, h, x + lean, 0);
        grad.addColorStop(0, '#4c6b2c');
        grad.addColorStop(1, '#7fa348');
        ctx.strokeStyle = grad;
        ctx.lineWidth = 1.6;
        ctx.beginPath();
        ctx.moveTo(x, h - 4);
        ctx.quadraticCurveTo(x + lean * 0.5, h * 0.5, x + lean, 2);
        ctx.stroke();
    }

    return canvas;
}

function buildShadowSprite() {
    const size = 48;
    const canvas = makeCanvas(size, size);
    const ctx = canvas.getContext('2d');
    const grad = ctx.createRadialGradient(size / 2, size / 2, 2, size / 2, size / 2, size / 2);
    grad.addColorStop(0, 'rgba(0,0,0,0.38)');
    grad.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, size, size);
    return canvas;
}

export class TileArtFactory {
    constructor(seed = 2024) {
        this.tileTextures = {};
        this.decorSprites = {};

        this.tileTextures[TileType.GRASS] = [1, 2, 3].map((v) => buildGrassTile(TEXTURE_RES, seed + v));
        this.tileTextures[TileType.DIRT] = [1, 2, 3].map((v) => buildDirtTile(TEXTURE_RES, seed + 100 + v));
        this.tileTextures[TileType.ROCK] = [1, 2, 3].map((v) => buildRockTile(TEXTURE_RES, seed + 200 + v));
        this.tileTextures[TileType.MOUNTAIN] = [1, 2].map((v) => buildMountainTile(TEXTURE_RES, seed + 300 + v));
        this.tileTextures[TileType.FOREST] = [1, 2, 3].map((v) => buildForestFloorTile(TEXTURE_RES, seed + 400 + v));
        this.tileTextures[TileType.ROAD] = [1, 2].map((v) => buildRoadTile(TEXTURE_RES, seed + 500 + v));
        this.tileTextures[TileType.BUILDABLE] = [1, 2, 3].map((v) => buildBuildableTile(TEXTURE_RES, seed + 600 + v));
        this.tileTextures[TileType.OBSTACLE] = [1, 2].map((v) => buildObstacleTile(TEXTURE_RES, seed + 700 + v));
        this.tileTextures[TileType.WATER] = Array.from({ length: WATER_FRAMES }, (_, f) =>
            buildWaterFrame(TEXTURE_RES, seed + 800, f)
        );

        this.decorSprites.tree = [
            buildTreeSprite(seed + 900, false),
            buildTreeSprite(seed + 901, true),
            buildTreeSprite(seed + 902, false),
        ];
        this.decorSprites.rock = [buildRockDecoSprite(seed + 910), buildRockDecoSprite(seed + 911)];
        this.decorSprites.bush = [buildBushSprite(seed + 920), buildBushSprite(seed + 921)];
        this.decorSprites.reed = [buildReedSprite(seed + 930)];

        this.shadowSprite = buildShadowSprite();
    }

    getTileTexture(type, variantIndex, animFrame = 0) {
        const frames = this.tileTextures[type];
        if (!frames) return null;
        if (type === TileType.WATER) return frames[animFrame % frames.length];
        return frames[variantIndex % frames.length];
    }

    getDecorSprite(kind, variantIndex) {
        const list = this.decorSprites[kind];
        if (!list) return null;
        return list[variantIndex % list.length];
    }
}
