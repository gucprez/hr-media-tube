import { mulberry32, makeCanvas } from '../core/utils.js';
import { Building } from '../entities/Building.js';
import { Entity } from '../entities/Entity.js';

// Construye la aldea ninja como un conjunto de entidades (Building/Entity) puramente
// visuales por ahora: el Dojo principal, casas pequeñas, torres de vigilancia, faroles,
// cercas y banderas. Cada una ya tiene nombre/tipo/posición propios para que la Fase 6
// (economía + construcción) pueda darles función real sin rehacer el arte.

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
    ctx.fillStyle = grad;
    roofPolygon(ctx, cx, topY, baseY, halfWidthBase * 0.15, halfWidthBase, flare);
    ctx.fill();
    ctx.strokeStyle = 'rgba(30,14,10,0.5)';
    ctx.lineWidth = 1.5;
    ctx.stroke();

    // Líneas de tejas.
    ctx.strokeStyle = 'rgba(0,0,0,0.15)';
    ctx.lineWidth = 1;
    for (let i = 1; i < 6; i++) {
        const t = i / 6;
        ctx.beginPath();
        ctx.moveTo(cx - halfWidthBase * (1 - t) - flare * (1 - t), baseY - (baseY - topY) * t * 0.15);
        ctx.lineTo(cx + halfWidthBase * (1 - t) + flare * (1 - t), baseY - (baseY - topY) * t * 0.15);
        ctx.stroke();
    }
}

function drawWoodWall(ctx, x, y, w, h, doorway) {
    const grad = ctx.createLinearGradient(x, 0, x + w, 0);
    grad.addColorStop(0, '#8a5a34');
    grad.addColorStop(0.5, '#a06e40');
    grad.addColorStop(1, '#8a5a34');
    ctx.fillStyle = grad;
    ctx.fillRect(x, y, w, h);

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

function buildDojoSprite(seed) {
    const w = 220;
    const h = 250;
    const c = makeCanvas(w, h);
    const ctx = c.getContext('2d');
    const cx = w / 2;

    // Cimiento de piedra.
    const foundGrad = ctx.createLinearGradient(0, h - 26, 0, h);
    foundGrad.addColorStop(0, '#9a978f');
    foundGrad.addColorStop(1, '#6f6c64');
    ctx.fillStyle = foundGrad;
    ctx.beginPath();
    ctx.ellipse(cx, h - 14, 92, 16, 0, 0, Math.PI * 2);
    ctx.fill();

    // Cuerpo de madera principal.
    drawWoodWall(ctx, cx - 78, h - 108, 156, 78, true);
    drawWindowGlow(ctx, cx - 66, h - 96, 20, 22);
    drawWindowGlow(ctx, cx + 46, h - 96, 20, 22);

    // Tejado inferior (más ancho).
    drawRoofTier(ctx, cx, h - 168, h - 100, 108, 16, '#9c3f3a', '#6e2723');

    // Segundo cuerpo (torre superior, estilo pagoda).
    drawWoodWall(ctx, cx - 34, h - 190, 68, 34, false);

    // Tejado superior (más pequeño).
    drawRoofTier(ctx, cx, h - 232, h - 182, 50, 10, '#a8483f', '#7a2f28');

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
    drawRoofTier(ctx, cx, h - 84, h - 46, 46, 8, '#5c6a7a', '#37414d');

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
    drawRoofTier(ctx, cx, h - 140, h - 112, 32, 8, '#6e7f8e', '#414d59');

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

    ctx.fillStyle = 'rgba(0,0,0,0.2)';
    ctx.beginPath();
    ctx.ellipse(w / 2, h - 2, 13, 3, 0, 0, Math.PI * 2);
    ctx.fill();

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
    constructor(seed = 8800) {
        this.dojo = buildDojoSprite(seed);
        this.houses = [buildHouseSprite(seed, 0), buildHouseSprite(seed, 1), buildHouseSprite(seed, 2)];
        this.tower = buildTowerSprite(seed);
        this.lantern = buildLanternSprite();
        this.fencePost = buildFencePostSprite();
        this.shadow = buildShadow();
    }
}

// Farol independiente (Entity, no Building: nunca tendrá función de producción, sólo
// decorativo + emite partículas de brillo cálido).
class Lantern extends Entity {
    constructor(x, y, sprite) {
        super({ x, y, width: sprite.width, height: sprite.height, zIndex: 0 });
        this.sprite = sprite;
    }

    render(ctx, camera) {
        const screen = camera.worldToScreen(this.x, this.y);
        const w = this.sprite.width * camera.zoom;
        const h = this.sprite.height * camera.zoom;
        ctx.drawImage(this.sprite, screen.x - w / 2, screen.y - h, w, h);
    }
}

class FencePost extends Entity {
    constructor(x, y, sprite) {
        super({ x, y, width: sprite.width, height: sprite.height });
        this.sprite = sprite;
    }

    render(ctx, camera) {
        const screen = camera.worldToScreen(this.x, this.y);
        const w = this.sprite.width * camera.zoom;
        const h = this.sprite.height * camera.zoom;
        ctx.drawImage(this.sprite, screen.x - w / 2, screen.y - h, w, h);
    }
}

// Construye todas las entidades de la aldea alrededor de `centerWorld` y devuelve
// { entities, lanternPositions } — las posiciones de farol las usa World.js para
// anclar las partículas de brillo cálido.
export function buildVillage(centerWorld, art, seed = 555) {
    const rng = mulberry32(seed);
    const entities = [];
    const lanternPositions = [];
    const chimneyPositions = [];

    const dojo = new Building({
        name: 'Ninja Dojo',
        buildingType: 'dojo',
        sprite: art.dojo,
        shadowSprite: art.shadow,
        x: centerWorld.x,
        y: centerWorld.y,
        width: art.dojo.width,
        height: art.dojo.height,
        spriteAnchorY: 0.98,
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
        entities.push(new Lantern(x, y, art.lantern));
        lanternPositions.push({ x, y: y - art.lantern.height * 0.75 });
    });

    // Cerca perimetral: postes espaciados a lo largo de un anillo irregular.
    const fenceRadius = 300;
    const fenceCount = 26;
    for (let i = 0; i < fenceCount; i++) {
        const angle = (i / fenceCount) * Math.PI * 2;
        const jitter = 1 + (rng() - 0.5) * 0.08;
        const x = centerWorld.x + Math.cos(angle) * fenceRadius * jitter;
        const y = centerWorld.y + Math.sin(angle) * fenceRadius * 0.5 * jitter;
        entities.push(new FencePost(x, y, art.fencePost));
    }

    entities.sort((a, b) => a.depthY - b.depthY);
    return { entities, lanternPositions, chimneyPositions };
}
