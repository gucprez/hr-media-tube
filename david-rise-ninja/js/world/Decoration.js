import { mulberry32, hash2i, makeCanvas } from '../core/utils.js';
import { TileType, TILE_SIZE } from './Terrain.js';

// Vegetación y objetos decorativos: fábrica de sprites procedurales + el algoritmo que
// los reparte sobre el mapa según el tipo de terreno. Cada sprite se genera con varias
// variantes y una semilla distinta por instancia, así que ninguna copia visual se repite
// exactamente igual (tal como pide el diseño: "NO generar árboles idénticos").
//
// Los árboles usan una copa "nube" (muchos lóbulos pequeños superpuestos con luz de
// borde) en vez de 3-4 círculos perfectos apilados: se lee como follaje ilustrado, no
// como geometría reconocible.

function canopyBlob(ctx, cx, cy, r, top, bottom) {
    const g = ctx.createRadialGradient(cx - r * 0.3, cy - r * 0.4, r * 0.15, cx, cy, r);
    g.addColorStop(0, top);
    g.addColorStop(1, bottom);
    ctx.fillStyle = g;
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.fill();
}

// Copa de follaje "nube": muchos lóbulos pequeños y desplazados en vez de pocos
// círculos grandes, más un realce de luz arriba-izquierda para que se lea como una
// masa orgánica iluminada, no como manchas circulares individuales.
function cloudCanopy(ctx, cx, cy, rx, ry, top, bottom, seed, lobes = 11) {
    const rng = mulberry32(seed);
    for (let i = 0; i < lobes; i++) {
        const angle = rng() * Math.PI * 2;
        const dist = rng() * 0.5;
        const lx = cx + Math.cos(angle) * rx * dist;
        const ly = cy + Math.sin(angle) * ry * dist;
        const lr = (0.4 + rng() * 0.5) * Math.min(rx, ry);
        canopyBlob(ctx, lx, ly, lr, top, bottom);
    }
    ctx.fillStyle = 'rgba(255,255,255,0.12)';
    for (let i = 0; i < 3; i++) {
        const lx = cx - rx * 0.35 + rng() * rx * 0.35;
        const ly = cy - ry * 0.55 + rng() * ry * 0.3;
        ctx.beginPath();
        ctx.arc(lx, ly, Math.min(rx, ry) * 0.24, 0, Math.PI * 2);
        ctx.fill();
    }
}

function drawTrunk(ctx, baseX, groundY, trunkH, trunkW, lean = 0) {
    const grad = ctx.createLinearGradient(baseX - trunkW, 0, baseX + trunkW, 0);
    grad.addColorStop(0, '#3f2c1a');
    grad.addColorStop(0.5, '#6e4c2c');
    grad.addColorStop(1, '#4a3320');
    ctx.fillStyle = grad;
    ctx.beginPath();
    ctx.moveTo(baseX - trunkW, groundY);
    ctx.lineTo(baseX - trunkW * 0.5 + lean, groundY - trunkH);
    ctx.lineTo(baseX + trunkW * 0.5 + lean, groundY - trunkH);
    ctx.lineTo(baseX + trunkW, groundY);
    ctx.closePath();
    ctx.fill();
    // Raíces/ensanche en la base para anclar el tronco al suelo.
    ctx.beginPath();
    ctx.moveTo(baseX - trunkW * 1.5, groundY);
    ctx.lineTo(baseX - trunkW * 0.7, groundY - trunkH * 0.18);
    ctx.lineTo(baseX + trunkW * 0.7, groundY - trunkH * 0.18);
    ctx.lineTo(baseX + trunkW * 1.5, groundY);
    ctx.closePath();
    ctx.fill();
}

const TREE_GREENS = [
    ['#6cb457', '#2f6329'],
    ['#5fa84c', '#2b5c26'],
    ['#4f9440', '#254f21'],
    ['#79c164', '#356e2e'],
];

// Cinco siluetas distintas (tree_01..tree_05) para que la vegetación nunca se sienta
// repetida: redonda frondosa, cónica tipo conífera, asimétrica de doble copa,
// alta y esbelta, y de ramas caídas tipo sauce.
function buildTreeSprite(seed, archetype, big) {
    const scale = big ? 1 : 0.62;
    const w = Math.round(80 * scale);
    const h = Math.round(120 * scale);
    const c = makeCanvas(w, h);
    const ctx = c.getContext('2d');
    const rng = mulberry32(seed);
    const baseX = w / 2;
    const groundY = h - 6;
    const [top, bottom] = TREE_GREENS[Math.floor(rng() * TREE_GREENS.length)];

    if (archetype === 1) {
        // Conífera: capas triangulares apiladas con textura de lóbulos.
        const trunkH = h * 0.22;
        drawTrunk(ctx, baseX, groundY, trunkH, w * 0.05);
        const layers = 4;
        for (let i = 0; i < layers; i++) {
            const ly = groundY - trunkH - i * h * 0.16;
            const lw = w * (0.44 - i * 0.07);
            const grad = ctx.createLinearGradient(baseX - lw, ly, baseX + lw, ly - h * 0.2);
            grad.addColorStop(0, top);
            grad.addColorStop(1, bottom);
            ctx.fillStyle = grad;
            ctx.beginPath();
            ctx.moveTo(baseX, ly - h * 0.22);
            for (let s = -3; s <= 3; s++) {
                const t = s / 3;
                ctx.lineTo(baseX + t * lw, ly - h * 0.02 * (1 - Math.abs(t)));
            }
            ctx.closePath();
            ctx.fill();
        }
    } else if (archetype === 3) {
        // Alta y esbelta: tronco largo, copa alargada hacia arriba.
        const trunkH = h * 0.42;
        drawTrunk(ctx, baseX, groundY, trunkH, w * 0.06);
        cloudCanopy(ctx, baseX, groundY - trunkH - h * 0.16, w * 0.24, h * 0.26, top, bottom, seed + 1, 9);
        cloudCanopy(ctx, baseX, groundY - trunkH - h * 0.32, w * 0.17, h * 0.16, top, bottom, seed + 2, 6);
    } else if (archetype === 4) {
        // Ramas caídas (tipo sauce): copa ancha + trazos curvos colgando.
        const trunkH = h * 0.26;
        drawTrunk(ctx, baseX, groundY, trunkH, w * 0.06);
        const canopyY = groundY - trunkH - h * 0.14;
        cloudCanopy(ctx, baseX, canopyY, w * 0.36, h * 0.2, top, bottom, seed + 1, 12);
        ctx.strokeStyle = bottom;
        ctx.lineWidth = Math.max(1.2, w * 0.02);
        for (let i = 0; i < 6; i++) {
            const sx = baseX + (rng() - 0.5) * w * 0.6;
            const sy = canopyY + (rng() - 0.5) * h * 0.1;
            ctx.beginPath();
            ctx.moveTo(sx, sy);
            ctx.quadraticCurveTo(sx + (rng() - 0.5) * 10, sy + h * 0.18, sx + (rng() - 0.5) * 6, sy + h * 0.3);
            ctx.stroke();
        }
    } else {
        // Redonda frondosa (archetype 0) o asimétrica de doble copa (archetype 2).
        const trunkH = h * 0.3;
        const lean = archetype === 2 ? w * 0.08 : 0;
        drawTrunk(ctx, baseX, groundY, trunkH, w * 0.065, lean);
        const canopyY = groundY - trunkH;
        if (archetype === 2) {
            cloudCanopy(ctx, baseX - w * 0.14, canopyY - h * 0.12, w * 0.26, h * 0.2, top, bottom, seed + 1, 10);
            cloudCanopy(ctx, baseX + w * 0.16, canopyY - h * 0.22, w * 0.22, h * 0.18, top, bottom, seed + 2, 8);
        } else {
            cloudCanopy(ctx, baseX, canopyY - h * 0.18, w * 0.32, h * 0.24, top, bottom, seed + 1, 13);
        }
    }

    return c;
}

function buildBushSprite(seed) {
    const w = 36;
    const h = 26;
    const c = makeCanvas(w, h);
    const ctx = c.getContext('2d');
    cloudCanopy(ctx, w / 2, h * 0.6, w * 0.42, h * 0.5, '#5f9a49', '#2f5e1f', seed, 9);
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

const DEFAULT_META = { scaleMul: 1, anchorY: 0.92, hasOwnShadow: false };

// `overrides` viene de ArtOverrides.resolveArtOverrides(): con `trees`/`rocks`
// reales, sustituye los pools procedurales de esos tipos y guarda su propio
// multiplicador de escala/anclaje/sombra en `kindMeta` — el Renderer lo consulta en
// vez de asumir siempre la sombra y el ancla del placeholder de Canvas.
export class DecorationArt {
    constructor(seed = 6100, overrides = {}) {
        this.sprites = {
            treeBig: [0, 1, 2, 3, 4].map((archetype) => buildTreeSprite(seed + archetype, archetype, true)),
            treeSmall: [0, 1, 2, 3, 4].map((archetype) => buildTreeSprite(seed + 20 + archetype, archetype, false)),
            bush: [buildBushSprite(seed + 6), buildBushSprite(seed + 7)],
            flowers: [buildFlowerClusterSprite(seed + 8), buildFlowerClusterSprite(seed + 9)],
            log: [buildLogSprite(seed + 10)],
            rockSmall: [buildRockSprite(seed + 11, false), buildRockSprite(seed + 12, false)],
            rockMedium: [buildRockSprite(seed + 13, true)],
            reed: [buildReedSprite(seed + 14)],
        };
        this.shadow = buildShadowSprite();
        this.kindMeta = {};

        if (overrides.trees) {
            const t = overrides.trees;
            this.sprites.treeBig = t.images;
            this.sprites.treeSmall = t.images;
            this.kindMeta.treeBig = { scaleMul: t.scale, anchorY: t.anchorY, hasOwnShadow: t.hasOwnShadow };
            this.kindMeta.treeSmall = { scaleMul: t.scale * 0.6, anchorY: t.anchorY, hasOwnShadow: t.hasOwnShadow };

            // La hoja de árboles trae dos arbustos redondos (índices 6 y 10) y un
            // arbusto florido (índice 11): mucho mejor que dejar el "bush"/"flowers"
            // procedural (un círculo verde plano) al lado de una hierba pintada real.
            if (t.images.length >= 12) {
                this.sprites.bush = [t.images[6], t.images[10]];
                this.kindMeta.bush = { scaleMul: t.scale * 0.55, anchorY: t.anchorY, hasOwnShadow: t.hasOwnShadow };
                this.sprites.flowers = [t.images[11]];
                this.kindMeta.flowers = { scaleMul: t.scale * 0.5, anchorY: t.anchorY, hasOwnShadow: t.hasOwnShadow };
            }
        }
        if (overrides.rocks) {
            const r = overrides.rocks;
            this.sprites.rockSmall = r.images;
            this.sprites.rockMedium = r.images;
            this.kindMeta.rockSmall = { scaleMul: r.scale * 0.7, anchorY: r.anchorY, hasOwnShadow: r.hasOwnShadow };
            this.kindMeta.rockMedium = { scaleMul: r.scale * 1.15, anchorY: r.anchorY, hasOwnShadow: r.hasOwnShadow };
        }
        if (overrides.logs) {
            const l = overrides.logs;
            this.sprites.log = l.images;
            this.kindMeta.log = { scaleMul: l.scale, anchorY: l.anchorY, hasOwnShadow: l.hasOwnShadow };
        }
        if (overrides.reeds) {
            const rd = overrides.reeds;
            this.sprites.reed = rd.images;
            this.kindMeta.reed = { scaleMul: rd.scale, anchorY: rd.anchorY, hasOwnShadow: rd.hasOwnShadow };
        }
        // Vegetación nueva sin equivalente procedural: sólo aparece en el reparto de
        // scatterDecorations() cuando el override existe (get() devuelve null si no
        // hay lista, y el Renderer ya sabe saltarse un sprite nulo).
        if (overrides.grassTufts) {
            const g = overrides.grassTufts;
            this.sprites.grassTuft = g.images;
            this.kindMeta.grassTuft = { scaleMul: g.scale, anchorY: g.anchorY, hasOwnShadow: g.hasOwnShadow };
        }
        if (overrides.bamboo) {
            const b = overrides.bamboo;
            this.sprites.bamboo = [b.image];
            this.kindMeta.bamboo = { scaleMul: b.scale, anchorY: b.anchorY, hasOwnShadow: b.hasOwnShadow };
        }
        if (overrides.waterPlants) {
            const wp = overrides.waterPlants;
            this.sprites.waterPlant = wp.images;
            this.kindMeta.waterPlant = { scaleMul: wp.scale, anchorY: wp.anchorY, hasOwnShadow: wp.hasOwnShadow };
        }
        if (overrides.moss) {
            const m = overrides.moss;
            this.sprites.moss = m.images;
            this.kindMeta.moss = { scaleMul: m.scale, anchorY: m.anchorY, hasOwnShadow: m.hasOwnShadow };
        }
    }

    get(kind, variant) {
        const list = this.sprites[kind];
        return list ? list[variant % list.length] : null;
    }

    getMeta(kind) {
        return this.kindMeta[kind] ?? DEFAULT_META;
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

// `rotates`: true para objetos sin una orientación "natural" (arbustos, rocas,
// troncos, flores) — les da variedad extra. Los árboles y juncos se quedan
// derechos, como en una ilustración real.
function place(kind, variantCount, worldX, worldY, seed, rotates = false) {
    return {
        kind,
        variant: Math.floor(hash2i(worldX, worldY, seed) * variantCount),
        x: worldX,
        y: worldY,
        scale: 0.85 + hash2i(worldX, worldY, seed + 1) * 0.4,
        flip: hash2i(worldX, worldY, seed + 2) < 0.5 ? -1 : 1,
        rotation: rotates ? (hash2i(worldX, worldY, seed + 3) - 0.5) * 0.7 : 0,
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
                if (roll < 0.55) decorations.push(place('treeBig', 5, wx, wy, seed));
                else if (roll < 0.8) decorations.push(place('treeSmall', 5, wx, wy, seed + 1));
                else if (roll < 0.9) decorations.push(place('bush', 2, wx, wy, seed + 2, true));
                else if (roll < 0.95) decorations.push(place('log', 8, wx, wy, seed + 3, true));
            } else if (type === TileType.ROCK) {
                if (roll > 0.55) decorations.push(place('rockMedium', 1, wx, wy, seed + 8, true));
                else if (roll > 0.3) decorations.push(place('rockSmall', 2, wx, wy, seed + 9, true));
                else if (roll > 0.15) decorations.push(place('moss', 2, wx, wy, seed + 17, true));
            } else if (type === TileType.WATER && roll > 0.85) {
                decorations.push(place('waterPlant', 20, wx, wy, seed + 16));
            } else if (nearShore && roll > 0.45) {
                if (roll > 0.9) decorations.push(place('bamboo', 1, wx, wy, seed + 15));
                else decorations.push(place('reed', 10, wx, wy, seed + 11));
            } else if (type === TileType.GRASS) {
                if (roll > 0.985) decorations.push(place('treeSmall', 5, wx, wy, seed + 4));
                else if (roll > 0.95) decorations.push(place('bush', 2, wx, wy, seed + 5, true));
                else if (roll > 0.9) decorations.push(place('flowers', 2, wx, wy, seed + 6, true));
                else if (roll > 0.88) decorations.push(place('rockSmall', 2, wx, wy, seed + 7, true));
                else if (roll > 0.8) decorations.push(place('grassTuft', 4, wx, wy, seed + 12, true));
            } else if (type === TileType.DIRT && roll > 0.97) {
                decorations.push(place('rockSmall', 2, wx, wy, seed + 10, true));
            }
        }
    }

    decorations.sort((a, b) => a.y - b.y);
    return decorations;
}
