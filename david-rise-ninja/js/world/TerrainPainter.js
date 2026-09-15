import { mulberry32, hash2i, makeCanvas, clamp, lerp } from '../core/utils.js';
import { TileType, TILE_SIZE, BIOME_PALETTE } from './Terrain.js';

// Pinta el mapa entero UNA sola vez en un lienzo grande y continuo, en vez de
// dibujar 8800 imágenes de tile cuadradas cada frame. Esto es lo que elimina la
// cuadrícula visible: cada casilla se pinta como una mancha orgánica (polígono
// irregular, más grande que la propia casilla) con un tono ligeramente distinto,
// así que las manchas vecinas se solapan y se funden en vez de dejar un borde recto
// cada 64px. El río y el camino se pintan aparte como cintas continuas siguiendo
// una curva, no como casillas sueltas.
//
// El resultado (`this.canvas`) es una única imagen que el Renderer simplemente
// recorta y dibuja (drawImage) según la cámara — barato en tiempo real, todo el
// coste de "pintar" se paga una sola vez al crear el mundo.
export class TerrainPainter {
    constructor(map, seed = 3300) {
        this.map = map;
        this.seed = seed;
        this.canvas = makeCanvas(map.pixelWidth, map.pixelHeight);
        this.mountainPeaks = [];
        this._paint();
    }

    _paint() {
        const ctx = this.canvas.getContext('2d');
        ctx.fillStyle = 'rgb(60,96,52)';
        ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);

        this._paintBaseBlobs(ctx);
        this._paintDetailSpeckle(ctx);
        this._paintRiver(ctx);
        this._paintPath(ctx);
        this._collectMountainPeaks();
        this._paintMountainRange(ctx);
    }

    _colorFor(type, tx, ty, extraSeed = 0) {
        const palette = BIOME_PALETTE[type];
        const roll = hash2i(tx, ty, this.seed + extraSeed);
        const pool = [palette.base, ...palette.variants];
        const [r, g, b] = pool[Math.floor(roll * pool.length)];
        // Pequeño jitter adicional de luminosidad para que ni siquiera dos casillas
        // con la misma variante se vean idénticas.
        const j = (hash2i(tx, ty, this.seed + extraSeed + 500) - 0.5) * 18;
        return `rgb(${clamp(r + j, 0, 255) | 0},${clamp(g + j, 0, 255) | 0},${clamp(b + j, 0, 255) | 0})`;
    }

    // Extruye una polilínea de puntos {x,y} con un ancho (posiblemente variable) en
    // un polígono de cinta: un borde izquierdo y otro derecho desplazados a lo largo
    // de la normal local en cada muestra. Rellenar ESE polígono una sola vez produce
    // una cinta perfectamente lisa; apilar círculos translúcidos (la técnica que
    // usábamos antes) siempre deja un borde "festoneado" visible donde cada círculo
    // termina, por muy juntos que estén.
    _ribbon(samples, halfWidths) {
        const left = [];
        const right = [];
        const n = samples.length;
        for (let i = 0; i < n; i++) {
            const prev = samples[Math.max(0, i - 1)];
            const next = samples[Math.min(n - 1, i + 1)];
            let tx = next.x - prev.x;
            let ty = next.y - prev.y;
            const len = Math.hypot(tx, ty) || 1;
            tx /= len;
            ty /= len;
            const nx = -ty;
            const ny = tx;
            const hw = halfWidths[i];
            left.push({ x: samples[i].x + nx * hw, y: samples[i].y + ny * hw });
            right.push({ x: samples[i].x - nx * hw, y: samples[i].y - ny * hw });
        }
        return { left, right };
    }

    _fillRibbon(ctx, ribbon, fillStyle, strokeStyle = null, lineWidth = 0) {
        ctx.beginPath();
        ctx.moveTo(ribbon.left[0].x, ribbon.left[0].y);
        for (let i = 1; i < ribbon.left.length; i++) ctx.lineTo(ribbon.left[i].x, ribbon.left[i].y);
        for (let i = ribbon.right.length - 1; i >= 0; i--) ctx.lineTo(ribbon.right[i].x, ribbon.right[i].y);
        ctx.closePath();
        if (fillStyle) {
            ctx.fillStyle = fillStyle;
            ctx.fill();
        }
        if (strokeStyle) {
            ctx.strokeStyle = strokeStyle;
            ctx.lineWidth = lineWidth;
            ctx.stroke();
        }
    }

    // Mancha irregular (polígono con vértices perturbados) más grande que la propia
    // casilla: al solaparse con las vecinas produce bordes de costa orgánicos en vez
    // de líneas rectas de rejilla.
    _blob(ctx, cx, cy, avgRadius, seedX, seedY, extraSeed, sides = 8) {
        ctx.beginPath();
        for (let s = 0; s < sides; s++) {
            const angle = (s / sides) * Math.PI * 2;
            const jitter = 0.72 + hash2i(seedX * 13 + s, seedY * 7 + extraSeed, this.seed) * 0.56;
            const r = avgRadius * jitter;
            const px = cx + Math.cos(angle) * r;
            const py = cy + Math.sin(angle) * r;
            s === 0 ? ctx.moveTo(px, py) : ctx.lineTo(px, py);
        }
        ctx.closePath();
        ctx.fill();
    }

    _paintBaseBlobs(ctx) {
        const map = this.map;
        ctx.globalAlpha = 0.92;
        for (let ty = 0; ty < map.height; ty++) {
            for (let tx = 0; tx < map.width; tx++) {
                const type = map.getTileTypeAt(tx, ty);
                if (type === TileType.WATER || type === TileType.PATH) continue;
                const cx = tx * TILE_SIZE + TILE_SIZE / 2 + (hash2i(tx, ty, this.seed + 9) - 0.5) * TILE_SIZE * 0.3;
                const cy = ty * TILE_SIZE + TILE_SIZE / 2 + (hash2i(tx, ty, this.seed + 10) - 0.5) * TILE_SIZE * 0.3;
                const radius = TILE_SIZE * (0.95 + hash2i(tx, ty, this.seed + 11) * 0.35);
                ctx.fillStyle = this._colorFor(type, tx, ty);
                this._blob(ctx, cx, cy, radius, tx, ty, 1, 7);
            }
        }
        ctx.globalAlpha = 1;
    }

    // Detalle fino (hierba, hojas, grietas, guijarros) esparcido en posiciones
    // continuas -no ancladas a la rejilla- para que la textura no se perciba como un
    // patrón que se repite cada 64px.
    _paintDetailSpeckle(ctx) {
        const map = this.map;
        const rng = mulberry32(this.seed + 4242);

        for (let ty = 0; ty < map.height; ty++) {
            for (let tx = 0; tx < map.width; tx++) {
                const type = map.getTileTypeAt(tx, ty);
                const baseX = tx * TILE_SIZE;
                const baseY = ty * TILE_SIZE;

                if (type === TileType.GRASS) {
                    ctx.strokeStyle = 'rgba(150,205,120,0.4)';
                    ctx.lineWidth = 1.4;
                    for (let i = 0; i < 3; i++) {
                        const x = baseX + rng() * TILE_SIZE;
                        const y = baseY + rng() * TILE_SIZE;
                        const h = 3 + rng() * 5;
                        ctx.beginPath();
                        ctx.moveTo(x, y);
                        ctx.lineTo(x + (rng() - 0.5) * 3, y - h);
                        ctx.stroke();
                    }
                } else if (type === TileType.DIRT || type === TileType.PATH || type === TileType.VILLAGE) {
                    for (let i = 0; i < 3; i++) {
                        ctx.fillStyle = `rgba(70,52,34,${0.12 + rng() * 0.15})`;
                        ctx.beginPath();
                        ctx.arc(baseX + rng() * TILE_SIZE, baseY + rng() * TILE_SIZE, 1 + rng() * 2.4, 0, Math.PI * 2);
                        ctx.fill();
                    }
                } else if (type === TileType.FOREST) {
                    for (let i = 0; i < 3; i++) {
                        ctx.fillStyle = rng() < 0.5 ? 'rgba(150,110,50,0.3)' : 'rgba(20,35,16,0.3)';
                        ctx.beginPath();
                        ctx.ellipse(
                            baseX + rng() * TILE_SIZE,
                            baseY + rng() * TILE_SIZE,
                            2 + rng() * 2,
                            1.2 + rng(),
                            rng() * Math.PI,
                            0,
                            Math.PI * 2
                        );
                        ctx.fill();
                    }
                } else if (type === TileType.ROCK) {
                    ctx.strokeStyle = 'rgba(40,40,42,0.3)';
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.moveTo(baseX + rng() * TILE_SIZE, baseY + rng() * TILE_SIZE);
                    ctx.lineTo(baseX + rng() * TILE_SIZE, baseY + rng() * TILE_SIZE);
                    ctx.stroke();
                }
            }
        }
    }

    // Río pintado como una única cinta extruida siguiendo `map.riverProfileAt`: halo
    // translúcido ancho (la orilla se funde con el terreno), cuerpo de agua sólido, y
    // una franja central algo más oscura (agua "profunda"). Tres rellenos, cero
    // festoneado.
    _paintRiver(ctx) {
        const map = this.map;
        const step = 0.4;
        const samples = [];
        const halfWidths = [];
        for (let x = -2; x <= map.width + 2; x += step) {
            const { centerY, thickness } = map.riverProfileAt(x);
            samples.push({ x: x * TILE_SIZE, y: centerY * TILE_SIZE });
            halfWidths.push(thickness * TILE_SIZE);
        }
        const [wr, wg, wb] = BIOME_PALETTE[TileType.WATER].base;

        ctx.globalAlpha = 0.4;
        this._fillRibbon(ctx, this._ribbon(samples, halfWidths.map((w) => w * 1.6)), `rgb(${wr},${wg},${wb})`);
        ctx.globalAlpha = 1;
        const body = this._ribbon(samples, halfWidths);
        this._fillRibbon(ctx, body, `rgb(${wr},${wg},${wb})`, 'rgba(15,35,45,0.28)', 4);
        this._fillRibbon(
            ctx,
            this._ribbon(samples, halfWidths.map((w) => w * 0.55)),
            `rgb(${Math.max(0, wr - 16)},${Math.max(0, wg - 14)},${Math.max(0, wb - 10)})`
        );
    }

    // Camino pintado igual que el río: cinta extruida con halo suave + cuerpo sólido
    // + una franja central más clara (el paso constante desgasta el centro),
    // siguiendo la spline Catmull-Rom re-muestreada a paso constante en mundo.
    _paintPath(ctx) {
        const map = this.map;
        const pathSamples = map.buildPathArcLengthSamples(TILE_SIZE * 0.5);
        const samples = pathSamples.map((p) => ({ x: p.x * TILE_SIZE, y: p.y * TILE_SIZE }));
        const halfWidths = pathSamples.map((p) => map.pathWidthAt(p.t) * TILE_SIZE);
        const [pr, pg, pb] = BIOME_PALETTE[TileType.PATH].base;

        ctx.globalAlpha = 0.35;
        this._fillRibbon(ctx, this._ribbon(samples, halfWidths.map((w) => w * 1.8)), `rgb(${pr},${pg},${pb})`);
        ctx.globalAlpha = 1;
        this._fillRibbon(ctx, this._ribbon(samples, halfWidths), `rgb(${pr},${pg},${pb})`);
        this._fillRibbon(
            ctx,
            this._ribbon(samples, halfWidths.map((w) => w * 0.5)),
            `rgb(${Math.min(255, pr + 16)},${Math.min(255, pg + 14)},${Math.min(255, pb + 10)})`
        );

        // Piedrecitas y marcas de desgaste a lo largo del camino.
        const rng = mulberry32(this.seed + 909);
        pathSamples.forEach((p, i) => {
            if (i % 4 !== 0) return;
            const w = map.pathWidthAt(p.t) * TILE_SIZE;
            for (let k = 0; k < 2; k++) {
                const ox = (rng() - 0.5) * w * 1.4;
                const oy = (rng() - 0.5) * w * 1.4;
                ctx.fillStyle = `rgba(120,105,80,${0.25 + rng() * 0.25})`;
                ctx.beginPath();
                ctx.ellipse(p.x * TILE_SIZE + ox, p.y * TILE_SIZE + oy, 2.5 + rng() * 2, 1.5 + rng(), rng() * Math.PI, 0, Math.PI * 2);
                ctx.fill();
            }
        });
    }

    // Sólo una fracción de las casillas de montaña se convierte en "ancla" de un
    // macizo: menos picos, pero mucho más grandes y variados, leen como una
    // cordillera ilustrada. Un pico por CADA casilla (lo que hacíamos antes) da un
    // campo de conos idénticos y muy juntos — exactamente el aspecto "triángulos
    // simples" que se pidió eliminar.
    _collectMountainPeaks() {
        const map = this.map;
        for (let ty = 0; ty < map.height; ty++) {
            for (let tx = 0; tx < map.width; tx++) {
                if (map.getTileTypeAt(tx, ty) !== TileType.MOUNTAIN) continue;
                const anchorRoll = hash2i(tx, ty, this.seed + 20);
                if (anchorRoll > 0.15) continue;

                const jx = (hash2i(tx, ty, this.seed + 21) - 0.5) * TILE_SIZE * 1.2;
                const jy = (hash2i(tx, ty, this.seed + 22) - 0.5) * TILE_SIZE * 1.2;
                const sizeRoll = hash2i(tx, ty, this.seed + 23);
                this.mountainPeaks.push({
                    x: tx * TILE_SIZE + TILE_SIZE / 2 + jx,
                    y: ty * TILE_SIZE + TILE_SIZE / 2 + jy,
                    size: TILE_SIZE * (2.4 + sizeRoll * 2.0),
                    snow: sizeRoll > 0.87,
                    haze: clamp(1 - sizeRoll, 0.15, 0.85),
                    // Un tercio son lomas redondeadas en vez de picos afilados: sin
                    // esta mezcla, toda la cordillera se lee como "un solo molde
                    // repetido", aunque cada pico tenga su propia silueta.
                    rounded: hash2i(tx, ty, this.seed + 25) < 0.35,
                    tint: (hash2i(tx, ty, this.seed + 26) - 0.5) * 20,
                    seed: hash2i(tx, ty, this.seed + 24) * 10000,
                });
            }
        }
        this.mountainPeaks.sort((a, b) => a.y - b.y);
    }

    // Cordillera ilustrada: macizos superpuestos ordenados por Y (los más "cercanos"
    // tapan a los de detrás), cada uno con 2-3 cumbres asimétricas fundidas en una
    // sola silueta (nunca un cono aislado), sombreado direccional, facetas de roca y
    // nieve suave sólo en los más altos.
    _paintMountainRange(ctx) {
        for (const peak of this.mountainPeaks) {
            this._paintPeak(ctx, peak);
        }
    }

    // Silueta de un macizo: varias "cumbres" (puntos de control a distinta altura)
    // unidas por curvas, en vez de un triángulo simétrico único.
    _massifPath(ctx, x, y, size, rng) {
        const halfBase = size * 0.68;
        const summits = 2 + Math.floor(rng() * 2);
        // Construye picos irregulares: cada "diente" tiene su propia altura y una
        // ligera curva en la base para que no sea un polígono de aristas rectas.
        ctx.beginPath();
        ctx.moveTo(x - halfBase, y);
        for (let i = 0; i < summits; i++) {
            const t0 = i / summits;
            const t1 = (i + 0.5) / summits;
            const t2 = (i + 1) / summits;
            const peakHeight = size * (0.55 + rng() * 0.45);
            const peakX = x - halfBase + t1 * halfBase * 2 + (rng() - 0.5) * size * 0.18;
            const peakY = y - peakHeight;
            const valleyX = x - halfBase + t2 * halfBase * 2;
            const valleyY = y - size * (0.15 + rng() * 0.15);
            const risingX = x - halfBase + t0 * halfBase * 2;
            ctx.quadraticCurveTo(risingX + (peakX - risingX) * 0.4, y - peakHeight * 0.55, peakX, peakY);
            if (i < summits - 1) {
                ctx.quadraticCurveTo(peakX + (valleyX - peakX) * 0.5, valleyY - size * 0.05, valleyX, valleyY);
            } else {
                ctx.quadraticCurveTo(peakX + (valleyX - peakX) * 0.5, y - size * 0.08, valleyX, y);
            }
        }
        ctx.lineTo(x + halfBase, y);
        ctx.closePath();
        return { halfBase };
    }

    // Loma redondeada (sin punta): varios lóbulos superpuestos, misma técnica que
    // el follaje de los árboles. Mezclada entre los picos afilados rompe la
    // sensación de "un solo molde repetido" que da una cordillera hecha sólo de
    // triángulos.
    _moundPath(ctx, x, y, size, rng, fillStyle, haze) {
        const lobes = 4 + Math.floor(rng() * 3);
        for (let i = 0; i < lobes; i++) {
            const t = i / (lobes - 1);
            const lx = x - size * 0.55 + t * size * 1.1;
            const ly = y - size * (0.25 + Math.sin(t * Math.PI) * 0.55 + rng() * 0.12);
            const lr = size * (0.32 + rng() * 0.16);
            ctx.fillStyle = fillStyle;
            ctx.beginPath();
            ctx.arc(lx, ly, lr, 0, Math.PI * 2);
            ctx.fill();
            if (haze > 0.3) {
                ctx.fillStyle = `rgba(158,174,196,${haze * 0.32})`;
                ctx.fill();
            }
        }
    }

    _paintPeak(ctx, peak) {
        const { x, y, size, snow, haze, rounded, tint } = peak;
        const rng = mulberry32(Math.floor(peak.seed));

        // Sombra de contacto suave en la base.
        ctx.fillStyle = 'rgba(0,0,0,0.16)';
        ctx.beginPath();
        ctx.ellipse(x, y + size * 0.1, size * 0.62, size * 0.14, 0, 0, Math.PI * 2);
        ctx.fill();

        const grad = ctx.createLinearGradient(x - size * 0.6, y - size, x + size * 0.3, y);
        const rockBase = lerp(100, 150, haze) + tint;
        grad.addColorStop(0, `rgb(${rockBase + 24},${rockBase + 26},${rockBase + 32})`);
        grad.addColorStop(0.5, `rgb(${rockBase},${rockBase + 4},${rockBase + 12})`);
        grad.addColorStop(1, `rgb(${rockBase - 28},${rockBase - 24},${rockBase - 14})`);
        ctx.fillStyle = grad;

        const rngShape = mulberry32(Math.floor(peak.seed) + 1);
        if (rounded) {
            this._moundPath(ctx, x, y, size, rngShape, grad, haze);
        } else {
            this._massifPath(ctx, x, y, size, rngShape);
            ctx.fillStyle = grad;
            ctx.fill();
            // Neblina atmosférica para los macizos "lejanos" (haze alto): los funde
            // ligeramente hacia el color del cielo en vez de dejarlos con el mismo
            // contraste que los cercanos.
            if (haze > 0.3) {
                ctx.fillStyle = `rgba(158,174,196,${haze * 0.32})`;
                ctx.fill();
            }
        }

        // Facetas de roca: un par de manchas de sombra/luz irregulares (misma
        // técnica que las rocas sueltas), no líneas rectas repetidas.
        for (let i = 0; i < 3; i++) {
            const fx = x + (rng() - 0.5) * size * 0.9;
            const fy = y - size * (0.2 + rng() * 0.55);
            const fr = size * (0.1 + rng() * 0.12);
            ctx.fillStyle = rng() < 0.5 ? 'rgba(255,255,255,0.08)' : 'rgba(10,10,14,0.14)';
            ctx.beginPath();
            const sides = 5;
            for (let s = 0; s < sides; s++) {
                const angle = (s / sides) * Math.PI * 2;
                const rr = fr * (0.7 + rng() * 0.4);
                const px = fx + Math.cos(angle) * rr;
                const py = fy + Math.sin(angle) * rr * 0.8;
                s === 0 ? ctx.moveTo(px, py) : ctx.lineTo(px, py);
            }
            ctx.closePath();
            ctx.fill();
        }

        if (snow) {
            // Nieve como un par de manchas suaves e irregulares (misma técnica que el
            // follaje), nunca un triángulo nítido tipo "gorro". Cada mancha crea su
            // propio gradiente centrado en su propia posición — reutilizar un único
            // gradiente para varias manchas las hace aparecer como círculos sólidos
            // gigantes en vez de nieve difuminada.
            for (let i = 0; i < 2; i++) {
                const bx = x + (rng() - 0.5) * size * 0.3;
                const by = y - size * (0.78 + rng() * 0.16);
                const r = size * (0.14 + rng() * 0.06);
                const g = ctx.createRadialGradient(bx, by, 0, bx, by, r);
                g.addColorStop(0, 'rgba(255,255,255,0.85)');
                g.addColorStop(1, 'rgba(255,255,255,0)');
                ctx.fillStyle = g;
                ctx.beginPath();
                ctx.arc(bx, by, r, 0, Math.PI * 2);
                ctx.fill();
            }
        }
    }
}
