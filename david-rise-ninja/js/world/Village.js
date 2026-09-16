import { mulberry32, makeCanvas } from '../core/utils.js';
import { Building } from '../entities/Building.js';
import { Entity } from '../entities/Entity.js';
import { drawSprite } from '../core/SpriteRenderer.js';

// Construye la aldea ninja como un conjunto de entidades (Building/Entity) puramente
// visuales por ahora: el Dojo principal, casas pequeñas, torres de vigilancia, faroles,
// cercas, banderas, barriles, cajas, bancos y un puente. Cada una ya tiene nombre/tipo/
// posición propios para que la Fase 6 (economía + construcción) pueda darles función
// real sin rehacer el arte.

function roofPolygon(ctx, cx, topY, baseY, halfWidthTop, halfWidthBase, flare) {
    ctx.beginPath();
    ctx.moveTo(cx - halfWidthBase - flare, baseY);
    ctx.quadraticCurveTo(cx - halfWidthBase * 0.5, topY + (baseY - topY) * 0.35, cx, topY);
    ctx.quadraticCurveTo(cx + halfWidthBase * 0.5, topY + (baseY - topY) * 0.35, cx + halfWidthBase + flare, baseY);
    ctx.quadraticCurveTo(cx + halfWidthBase * 0.6, baseY - (baseY - topY) * 0.12, cx, baseY - (baseY - topY) * 0.08);
    ctx.quadraticCurveTo(cx - halfWidthBase * 0.6, baseY - (baseY - topY) * 0.12, cx - halfWidthBase - flare, baseY);
    ctx.closePath();
}

function drawRoofTier(ctx, cx, topY, baseY, halfWidthBase, flare, colorTop, colorBottom) {
    const grad = ctx.createLinearGradient(0, topY, 0, baseY);
    grad.addColorStop(0, colorTop);
    grad.addColorStop(1, colorBottom);
    ctx.save();
    ctx.fillStyle = grad;
    roofPolygon(ctx, cx, topY, baseY, halfWidthBase * 0.15, halfWidthBase, flare);
    ctx.fill();
    ctx.clip();

    // Tejas en forma de escama (arcos superpuestos) en vez de simples líneas rectas:
    // se lee como un tejado japonés trabajado, no como un triángulo con rayas.
    ctx.strokeStyle = 'rgba(0,0,0,0.22)';
    ctx.lineWidth = 1;
    const rowH = (baseY - topY) * 0.16;
    for (let row = 0; row < 7; row++) {
        const y = baseY - row * rowH * 0.92;
        const rowWidth = halfWidthBase * (1 - row * 0.11) + flare * (1 - row * 0.11);
        const scaleR = rowH * 0.62;
        for (let sx = -rowWidth; sx <= rowWidth; sx += scaleR * 1.3) {
            ctx.beginPath();
            ctx.arc(cx + sx + (row % 2 ? scaleR * 0.65 : 0), y, scaleR, Math.PI, Math.PI * 2);
            ctx.stroke();
        }
    }
    ctx.restore();

    ctx.strokeStyle = 'rgba(30,14,10,0.55)';
    ctx.lineWidth = 1.8;
    roofPolygon(ctx, cx, topY, baseY, halfWidthBase * 0.15, halfWidthBase, flare);
    ctx.stroke();

    // Cumbrera con leve brillo para separar el tejado del cielo/fondo.
    ctx.strokeStyle = 'rgba(255,220,190,0.35)';
    ctx.lineWidth = 1.4;
    ctx.beginPath();
    ctx.moveTo(cx - halfWidthBase * 0.6, baseY - (baseY - topY) * 0.12);
    ctx.lineTo(cx, baseY - (baseY - topY) * 0.08);
    ctx.lineTo(cx + halfWidthBase * 0.6, baseY - (baseY - topY) * 0.12);
    ctx.stroke();
}

function drawWoodWall(ctx, x, y, w, h, doorway) {
    const grad = ctx.createLinearGradient(x, 0, x + w, 0);
    grad.addColorStop(0, '#7c4f2c');
    grad.addColorStop(0.5, '#a06e40');
    grad.addColorStop(1, '#7c4f2c');
    ctx.fillStyle = grad;
    ctx.fillRect(x, y, w, h);

    // Veta de madera: trazos horizontales cortos e irregulares sobre cada tabla.
    const rng = mulberry32(Math.floor(x * 13 + y * 7 + w));
    ctx.strokeStyle = 'rgba(60,35,18,0.25)';
    ctx.lineWidth = 1;
    for (let i = 0; i < Math.floor((w * h) / 220); i++) {
        const lx = x + rng() * w;
        const ly = y + rng() * h;
        ctx.beginPath();
        ctx.moveTo(lx, ly);
        ctx.lineTo(lx + 4 + rng() * 8, ly + (rng() - 0.5) * 2);
        ctx.stroke();
    }

    ctx.strokeStyle = 'rgba(50,30,15,0.35)';
    ctx.lineWidth = 1.5;
    for (let i = 1; i < 6; i++) {
        const lx = x + (w / 6) * i;
        ctx.beginPath();
        ctx.moveTo(lx, y);
        ctx.lineTo(lx, y + h);
        ctx.stroke();
    }

    // Franja roja de acento, sello distintivo del Dojo.
    ctx.fillStyle = '#8c2c2c';
    ctx.fillRect(x, y + h * 0.12, w, h * 0.1);

    // Contacto con el suelo ligeramente oscurecido (ambient occlusion básico).
    const aoGrad = ctx.createLinearGradient(0, y + h * 0.82, 0, y + h);
    aoGrad.addColorStop(0, 'rgba(0,0,0,0)');
    aoGrad.addColorStop(1, 'rgba(0,0,0,0.28)');
    ctx.fillStyle = aoGrad;
    ctx.fillRect(x, y + h * 0.82, w, h * 0.18);

    if (doorway) {
        const dw = w * 0.28;
        const dh = h * 0.62;
        const dx = x + w / 2 - dw / 2;
        const dy = y + h - dh;
        const doorGrad = ctx.createLinearGradient(0, dy, 0, dy + dh);
        doorGrad.addColorStop(0, '#241611');
        doorGrad.addColorStop(1, '#3a2417');
        ctx.fillStyle = doorGrad;
        ctx.fillRect(dx, dy, dw, dh);
        ctx.strokeStyle = '#5a3820';
        ctx.lineWidth = 2;
        ctx.strokeRect(dx, dy, dw, dh);
    }
}

function drawWindowGlow(ctx, x, y, w, h) {
    ctx.fillStyle = 'rgba(255, 214, 140, 0.85)';
    ctx.fillRect(x, y, w, h);
    ctx.strokeStyle = 'rgba(70,45,20,0.6)';
    ctx.lineWidth = 1;
    ctx.strokeRect(x, y, w, h);
}

// Cimiento de piedra hecho de bloques individuales en vez de una elipse plana.
function drawStoneFoundation(ctx, cx, groundY, halfWidth, seed) {
    const rng = mulberry32(seed);
    ctx.fillStyle = 'rgba(0,0,0,0.28)';
    ctx.beginPath();
    ctx.ellipse(cx, groundY + 6, halfWidth * 1.02, 16, 0, 0, Math.PI * 2);
    ctx.fill();

    const blocks = Math.round((halfWidth * 2) / 26);
    for (let i = 0; i < blocks; i++) {
        const bx = cx - halfWidth + (i / blocks) * halfWidth * 2;
        const bw = (halfWidth * 2) / blocks + 3;
        const bh = 14 + rng() * 6;
        const by = groundY - bh * 0.6 + rng() * 3;
        const grad = ctx.createLinearGradient(0, by, 0, by + bh);
        grad.addColorStop(0, '#a7a49b');
        grad.addColorStop(1, '#767268');
        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.roundRect(bx, by, bw, bh, 3);
        ctx.fill();
        ctx.strokeStyle = 'rgba(40,38,34,0.4)';
        ctx.lineWidth = 1;
        ctx.stroke();
    }
}

function buildDojoSprite(seed) {
    const w = 220;
    const h = 250;
    const c = makeCanvas(w, h);
    const ctx = c.getContext('2d');
    const cx = w / 2;

    drawStoneFoundation(ctx, cx, h - 14, 92, seed);

    // Cuerpo de madera principal.
    drawWoodWall(ctx, cx - 78, h - 108, 156, 78, true);
    drawWindowGlow(ctx, cx - 66, h - 96, 20, 22);
    drawWindowGlow(ctx, cx + 46, h - 96, 20, 22);

    // Tejado inferior (más ancho).
    drawRoofTier(ctx, cx, h - 168, h - 100, 108, 16, '#a8433d', '#6e2723');

    // Segundo cuerpo (torre superior, estilo pagoda).
    drawWoodWall(ctx, cx - 34, h - 190, 68, 34, false);

    // Tejado superior (más pequeño).
    drawRoofTier(ctx, cx, h - 232, h - 182, 50, 10, '#b04d43', '#7a2f28');

    // Remate / asta de bandera en la cumbrera.
    ctx.strokeStyle = '#3a2a1a';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(cx, h - 232);
    ctx.lineTo(cx, h - 250);
    ctx.stroke();
    ctx.fillStyle = '#c23b3b';
    ctx.beginPath();
    ctx.moveTo(cx, h - 250);
    ctx.lineTo(cx + 16, h - 244);
    ctx.lineTo(cx, h - 238);
    ctx.closePath();
    ctx.fill();

    // Banderas colgando de los aleros.
    for (const side of [-1, 1]) {
        const bx = cx + side * 92;
        ctx.strokeStyle = '#3a2a1a';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(bx, h - 168);
        ctx.lineTo(bx, h - 118);
        ctx.stroke();
        ctx.fillStyle = '#8c2c2c';
        ctx.beginPath();
        ctx.moveTo(bx, h - 160);
        ctx.lineTo(bx + side * 14, h - 150);
        ctx.lineTo(bx, h - 138);
        ctx.closePath();
        ctx.fill();
    }

    return c;
}

function buildHouseSprite(seed, variant) {
    const w = 96;
    const h = 96;
    const c = makeCanvas(w, h);
    const ctx = c.getContext('2d');
    const cx = w / 2;

    ctx.fillStyle = 'rgba(0,0,0,0.15)';
    ctx.beginPath();
    ctx.ellipse(cx, h - 8, 38, 8, 0, 0, Math.PI * 2);
    ctx.fill();

    drawWoodWall(ctx, cx - 32, h - 52, 64, 40, variant === 0);
    if (variant !== 0) drawWindowGlow(ctx, cx - 8, h - 42, 16, 16);
    drawRoofTier(ctx, cx, h - 84, h - 46, 46, 8, '#637282', '#37414d');

    if (variant === 1) {
        // Pequeña chimenea (uno de los edificios "humea" ligeramente; el humo lo
        // añade Village.js como partícula anclada a este punto).
        ctx.fillStyle = '#4a4a4a';
        ctx.fillRect(cx + 18, h - 78, 8, 14);
    }

    return c;
}

function buildTowerSprite(seed) {
    const w = 60;
    const h = 150;
    const c = makeCanvas(w, h);
    const ctx = c.getContext('2d');
    const cx = w / 2;

    ctx.fillStyle = 'rgba(0,0,0,0.18)';
    ctx.beginPath();
    ctx.ellipse(cx, h - 6, 20, 6, 0, 0, Math.PI * 2);
    ctx.fill();

    const postGrad = ctx.createLinearGradient(cx - 8, 0, cx + 8, 0);
    postGrad.addColorStop(0, '#5c3d24');
    postGrad.addColorStop(1, '#7a5230');
    ctx.fillStyle = postGrad;
    ctx.fillRect(cx - 7, h - 100, 14, 92);

    drawWoodWall(ctx, cx - 22, h - 118, 44, 22, false);
    drawRoofTier(ctx, cx, h - 140, h - 112, 32, 8, '#75879a', '#414d59');

    ctx.strokeStyle = 'rgba(40,25,12,0.5)';
    ctx.lineWidth = 2;
    for (let i = 0; i < 3; i++) {
        ctx.beginPath();
        ctx.moveTo(cx - 7, h - 90 + i * 26);
        ctx.lineTo(cx + 7, h - 78 + i * 26);
        ctx.stroke();
    }

    return c;
}

function buildLanternSprite() {
    const w = 20;
    const h = 40;
    const c = makeCanvas(w, h);
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#3a2a1a';
    ctx.fillRect(w / 2 - 2, h - 20, 4, 20);
    const g = ctx.createRadialGradient(w / 2, h - 28, 1, w / 2, h - 28, 10);
    g.addColorStop(0, '#ffe9a8');
    g.addColorStop(1, '#c9702f');
    ctx.fillStyle = g;
    ctx.beginPath();
    ctx.roundRect(w / 2 - 8, h - 36, 16, 16, 4);
    ctx.fill();
    ctx.strokeStyle = '#5a3818';
    ctx.lineWidth = 1.5;
    ctx.stroke();
    return c;
}

// Un segmento autocontenido (dos postes + dos travesaños) en vez de un poste
// suelto: así cada instancia ya se lee como "cerca" aunque no toque a la vecina.
function buildFencePostSprite() {
    const w = 30;
    const h = 26;
    const c = makeCanvas(w, h);
    const ctx = c.getContext('2d');

    const postGrad = ctx.createLinearGradient(0, 0, 6, 0);
    postGrad.addColorStop(0, '#5c3f26');
    postGrad.addColorStop(1, '#7a5734');
    for (const px of [3, w - 9]) {
        ctx.fillStyle = postGrad;
        ctx.fillRect(px, 4, 6, h - 8);
    }

    ctx.fillStyle = '#8a6640';
    ctx.fillRect(2, 9, w - 4, 3.5);
    ctx.fillRect(2, 16, w - 4, 3.5);
    ctx.strokeStyle = 'rgba(40,26,14,0.4)';
    ctx.lineWidth = 1;
    ctx.strokeRect(2, 9, w - 4, 3.5);
    ctx.strokeRect(2, 16, w - 4, 3.5);
    return c;
}

function buildBarrelSprite(seed) {
    const w = 24;
    const h = 28;
    const c = makeCanvas(w, h);
    const ctx = c.getContext('2d');
    const grad = ctx.createLinearGradient(0, 0, w, 0);
    grad.addColorStop(0, '#6e4a28');
    grad.addColorStop(0.5, '#9a6d3e');
    grad.addColorStop(1, '#6e4a28');
    ctx.fillStyle = grad;
    ctx.beginPath();
    ctx.roundRect(2, 2, w - 4, h - 4, 6);
    ctx.fill();
    ctx.strokeStyle = '#3a2814';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(2, h * 0.32);
    ctx.lineTo(w - 2, h * 0.32);
    ctx.moveTo(2, h * 0.7);
    ctx.lineTo(w - 2, h * 0.7);
    ctx.stroke();
    return c;
}

function buildCrateSprite(seed) {
    const w = 26;
    const h = 24;
    const c = makeCanvas(w, h);
    const ctx = c.getContext('2d');
    const grad = ctx.createLinearGradient(0, 0, 0, h);
    grad.addColorStop(0, '#a97b46');
    grad.addColorStop(1, '#7c5730');
    ctx.fillStyle = grad;
    ctx.fillRect(1, 1, w - 2, h - 2);
    ctx.strokeStyle = 'rgba(50,32,16,0.6)';
    ctx.lineWidth = 1.6;
    ctx.strokeRect(1, 1, w - 2, h - 2);
    ctx.beginPath();
    ctx.moveTo(1, 1);
    ctx.lineTo(w - 1, h - 1);
    ctx.moveTo(w - 1, 1);
    ctx.lineTo(1, h - 1);
    ctx.stroke();
    return c;
}

function buildBenchSprite(seed) {
    const w = 40;
    const h = 20;
    const c = makeCanvas(w, h);
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#5c3f26';
    ctx.fillRect(4, h - 8, 3, 8);
    ctx.fillRect(w - 7, h - 8, 3, 8);
    const grad = ctx.createLinearGradient(0, h - 12, 0, h - 4);
    grad.addColorStop(0, '#a06e40');
    grad.addColorStop(1, '#7c4f2c');
    ctx.fillStyle = grad;
    ctx.fillRect(2, h - 12, w - 4, 5);
    return c;
}

function buildBannerPostSprite(seed) {
    const w = 22;
    const h = 62;
    const c = makeCanvas(w, h);
    const ctx = c.getContext('2d');
    ctx.strokeStyle = '#3a2a1a';
    ctx.lineWidth = 3;
    ctx.beginPath();
    ctx.moveTo(w / 2, h - 4);
    ctx.lineTo(w / 2, 4);
    ctx.stroke();
    const grad = ctx.createLinearGradient(0, 6, 0, h * 0.66);
    grad.addColorStop(0, '#a8433d');
    grad.addColorStop(1, '#6e2723');
    ctx.fillStyle = grad;
    ctx.beginPath();
    ctx.moveTo(w / 2 - 9, 6);
    ctx.lineTo(w / 2 + 9, 6);
    ctx.lineTo(w / 2 + 9, h * 0.6);
    ctx.lineTo(w / 2, h * 0.5);
    ctx.lineTo(w / 2 - 9, h * 0.6);
    ctx.closePath();
    ctx.fill();
    ctx.strokeStyle = 'rgba(0,0,0,0.3)';
    ctx.lineWidth = 1;
    ctx.stroke();
    return c;
}

// Puente de madera para el cruce camino/río: tablones + barandillas laterales. Se
// coloca y rota para alinearse con la dirección del camino en ese punto.
function buildBridgeSprite(seed) {
    const w = 130;
    const h = 60;
    const c = makeCanvas(w, h);
    const ctx = c.getContext('2d');
    const rng = mulberry32(seed);

    ctx.fillStyle = 'rgba(0,0,0,0.2)';
    ctx.beginPath();
    ctx.ellipse(w / 2, h * 0.62, w * 0.46, h * 0.16, 0, 0, Math.PI * 2);
    ctx.fill();

    const deckGrad = ctx.createLinearGradient(0, h * 0.32, 0, h * 0.58);
    deckGrad.addColorStop(0, '#9a6d3e');
    deckGrad.addColorStop(1, '#6e4a28');
    ctx.fillStyle = deckGrad;
    ctx.fillRect(6, h * 0.32, w - 12, h * 0.26);

    ctx.strokeStyle = 'rgba(50,32,16,0.55)';
    ctx.lineWidth = 1.4;
    for (let x = 12; x < w - 6; x += 9) {
        ctx.beginPath();
        ctx.moveTo(x + (rng() - 0.5) * 2, h * 0.32);
        ctx.lineTo(x + (rng() - 0.5) * 2, h * 0.58);
        ctx.stroke();
    }

    // Barandillas.
    for (const railY of [h * 0.3, h * 0.6]) {
        ctx.strokeStyle = '#3a2a1a';
        ctx.lineWidth = 3;
        ctx.beginPath();
        ctx.moveTo(4, railY);
        ctx.lineTo(w - 4, railY);
        ctx.stroke();
        for (let x = 8; x < w - 4; x += 20) {
            ctx.beginPath();
            ctx.moveTo(x, railY - 7);
            ctx.lineTo(x, railY + 3);
            ctx.stroke();
        }
    }

    return c;
}

function buildShadow() {
    const size = 140;
    const c = makeCanvas(size, size * 0.5);
    const ctx = c.getContext('2d');
    const g = ctx.createRadialGradient(size / 2, size * 0.25, 2, size / 2, size * 0.25, size / 2);
    g.addColorStop(0, 'rgba(0,0,0,0.4)');
    g.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, size, size * 0.5);
    return c;
}

export class VillageArt {
    // `overrides` viene de ArtOverrides.resolveArtOverrides(): por cada clave que
    // exista ahí (p.ej. `dojo`) ya hay un PNG real cargado y se usa en vez del
    // placeholder de Canvas, sin que el resto del motor tenga que saberlo — Building
    // sigue recibiendo simplemente "un sprite" y "un spriteAnchorY".
    constructor(seed = 8800, overrides = {}) {
        this.dojo = overrides.dojo?.image ?? buildDojoSprite(seed);
        this.dojoScale = overrides.dojo?.scale ?? 1;
        this.dojoAnchorY = overrides.dojo?.anchorY ?? 0.98;
        this.dojoHasOwnShadow = overrides.dojo?.hasOwnShadow ?? false;

        this.houses = [buildHouseSprite(seed, 0), buildHouseSprite(seed, 1), buildHouseSprite(seed, 2)];
        this.tower = buildTowerSprite(seed);
        this.lantern = buildLanternSprite();
        this.fencePost = buildFencePostSprite();
        this.barrel = buildBarrelSprite(seed);
        this.crate = buildCrateSprite(seed);
        this.bench = buildBenchSprite(seed);
        this.bannerPost = buildBannerPostSprite(seed);
        this.bridge = buildBridgeSprite(seed);
        this.shadow = buildShadow();
    }
}

// Entidad genérica para props sin lógica propia (farol, cerca, barril, caja, banco,
// bandera, puente): todas dibujan a través de SpriteRenderer con sombra opcional.
class Prop extends Entity {
    constructor(x, y, sprite, { shadow = null, rotation = 0, zIndexBonus = 0, anchorY = 1 } = {}) {
        super({ x, y, width: sprite.width, height: sprite.height, zIndex: zIndexBonus });
        this.sprite = sprite;
        this.shadow = shadow;
        this.rotation = rotation;
        this.anchorY = anchorY;
    }

    render(ctx, camera) {
        drawSprite(ctx, camera, this.sprite, this.x, this.y, {
            rotation: this.rotation,
            anchorY: this.anchorY,
            shadowSprite: this.shadow,
            shadowScale: 0.5,
        });
    }
}

// Construye todas las entidades de la aldea alrededor de `centerWorld` y devuelve
// { entities, lanternPositions, chimneyPositions } — World.js usa las posiciones de
// farol/chimenea para anclar partículas de brillo/humo.
export function buildVillage(centerWorld, art, seed = 555) {
    const rng = mulberry32(seed);
    const entities = [];
    const lanternPositions = [];
    const chimneyPositions = [];

    const dojo = new Building({
        name: 'Ninja Dojo',
        buildingType: 'dojo',
        sprite: art.dojo,
        shadowSprite: art.dojoHasOwnShadow ? null : art.shadow,
        scale: art.dojoScale,
        x: centerWorld.x,
        y: centerWorld.y,
        width: art.dojo.width,
        height: art.dojo.height,
        spriteAnchorY: art.dojoAnchorY,
        zIndex: 4,
    });
    entities.push(dojo);

    const houseOffsets = [
        { dx: -180, dy: 40 },
        { dx: 170, dy: 70 },
        { dx: -130, dy: -120 },
        { dx: 190, dy: -90 },
    ];
    houseOffsets.forEach((off, i) => {
        const variantIndex = i % art.houses.length;
        const sprite = art.houses[variantIndex];
        entities.push(
            new Building({
                name: `Casa ${i + 1}`,
                buildingType: 'house',
                sprite,
                shadowSprite: art.shadow,
                x: centerWorld.x + off.dx,
                y: centerWorld.y + off.dy,
                width: sprite.width,
                height: sprite.height,
                spriteAnchorY: 0.95,
                zIndex: 2,
            })
        );
        // La casa "variante 1" tiene chimenea: aquí es donde World.js ancla el
        // humo sutil que pide el diseño ("saliendo de alguna casa").
        if (variantIndex === 1) {
            chimneyPositions.push({
                x: centerWorld.x + off.dx + 18,
                y: centerWorld.y + off.dy - sprite.height * 0.82,
            });
        }

        // Barriles/cajas apoyados junto a cada casa: hacen que la aldea se sienta
        // habitada en vez de un decorado vacío.
        const propAngle = rng() * Math.PI * 2;
        const propDist = 34 + rng() * 10;
        const px = centerWorld.x + off.dx + Math.cos(propAngle) * propDist;
        const py = centerWorld.y + off.dy + Math.sin(propAngle) * propDist * 0.6 + sprite.height * 0.3;
        entities.push(new Prop(px, py, rng() < 0.5 ? art.barrel : art.crate, { shadow: art.shadow }));
    });

    const towerOffsets = [
        { dx: -230, dy: -40 },
        { dx: 240, dy: 10 },
    ];
    towerOffsets.forEach((off, i) => {
        entities.push(
            new Building({
                name: `Torre de vigilancia ${i + 1}`,
                buildingType: 'watchtower',
                sprite: art.tower,
                shadowSprite: art.shadow,
                x: centerWorld.x + off.dx,
                y: centerWorld.y + off.dy,
                width: art.tower.width,
                height: art.tower.height,
                spriteAnchorY: 0.97,
                zIndex: 3,
            })
        );
    });

    // Faroles flanqueando la entrada del dojo y el camino interno.
    const lanternOffsets = [
        { dx: -50, dy: 60 },
        { dx: 50, dy: 60 },
        { dx: -140, dy: 130 },
        { dx: 140, dy: 130 },
    ];
    lanternOffsets.forEach((off) => {
        const x = centerWorld.x + off.dx;
        const y = centerWorld.y + off.dy;
        entities.push(new Prop(x, y, art.lantern));
        lanternPositions.push({ x, y: y - art.lantern.height * 0.75 });
    });

    // Bancos junto al camino interno, frente al dojo.
    entities.push(new Prop(centerWorld.x - 90, centerWorld.y + 95, art.bench, { shadow: art.shadow }));
    entities.push(new Prop(centerWorld.x + 90, centerWorld.y + 95, art.bench, { shadow: art.shadow, rotation: Math.PI }));

    // Estandartes flanqueando la entrada norte de la aldea.
    entities.push(new Prop(centerWorld.x - 46, centerWorld.y - 170, art.bannerPost, { shadow: art.shadow }));
    entities.push(new Prop(centerWorld.x + 46, centerWorld.y - 170, art.bannerPost, { shadow: art.shadow }));

    // Cerca perimetral: postes espaciados a lo largo de un anillo irregular.
    const fenceRadius = 300;
    const fenceCount = 26;
    for (let i = 0; i < fenceCount; i++) {
        const angle = (i / fenceCount) * Math.PI * 2;
        const jitter = 1 + (rng() - 0.5) * 0.08;
        const x = centerWorld.x + Math.cos(angle) * fenceRadius * jitter;
        const y = centerWorld.y + Math.sin(angle) * fenceRadius * 0.5 * jitter;
        entities.push(new Prop(x, y, art.fencePost));
    }

    entities.sort((a, b) => a.depthY - b.depthY);
    return { entities, lanternPositions, chimneyPositions };
}

// Puente sobre el río en el punto donde lo cruza el camino. Se calcula aparte
// (necesita el mapa, no sólo el centro de la aldea) y se añade al mundo si existe
// un cruce real.
export function buildBridge(map, art) {
    if (!map.bridgeTiles.length) return null;
    let sx = 0;
    let sy = 0;
    for (const t of map.bridgeTiles) {
        sx += t.x;
        sy += t.y;
    }
    const cx = (sx / map.bridgeTiles.length) * map.tileSize;
    const cy = (sy / map.bridgeTiles.length) * map.tileSize;

    // Orientación: tangente de la curva del camino en ese punto (muestreo fino
    // alrededor de t donde pasa más cerca del centro del puente).
    let bestT = 0.5;
    let bestDist = Infinity;
    for (let i = 0; i <= 200; i++) {
        const t = i / 200;
        const p = map.samplePath(t);
        const d = Math.hypot(p.x * map.tileSize - cx, p.y * map.tileSize - cy);
        if (d < bestDist) {
            bestDist = d;
            bestT = t;
        }
    }
    const p0 = map.samplePath(Math.max(0, bestT - 0.01));
    const p1 = map.samplePath(Math.min(1, bestT + 0.01));
    const angle = Math.atan2(p1.y - p0.y, p1.x - p0.x) + Math.PI / 2;

    return new Prop(cx, cy, art.bridge, { rotation: angle, zIndexBonus: -2, anchorY: 0.5 });
}
