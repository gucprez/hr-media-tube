import { ValueNoise2D, mulberry32, hash2i, clamp } from '../core/utils.js';
import { TileType, TILE_DEFS, TILE_SIZE } from './Terrain.js';

const TILE_ORDER = [
    TileType.WATER,
    TileType.GRASS,
    TileType.DIRT,
    TileType.FOREST,
    TileType.ROCK,
    TileType.MOUNTAIN,
    TileType.PATH,
    TileType.VILLAGE,
];
const typeToId = (t) => TILE_ORDER.indexOf(t);
const idToType = (id) => TILE_ORDER[id];

// Interpolación Catmull-Rom a través de una lista de puntos de control: da al camino
// principal curvas suaves en vez de segmentos rectos, sin necesitar una librería de
// splines externa.
function catmullRom(points, t) {
    const n = points.length - 1;
    const segT = t * n;
    const i = clamp(Math.floor(segT), 0, n - 1);
    const localT = segT - i;

    const p0 = points[Math.max(0, i - 1)];
    const p1 = points[i];
    const p2 = points[Math.min(n, i + 1)];
    const p3 = points[Math.min(n, i + 2)];

    const t2 = localT * localT;
    const t3 = t2 * localT;

    function axis(a0, a1, a2, a3) {
        return 0.5 * (2 * a1 + (-a0 + a2) * localT + (2 * a0 - 5 * a1 + 4 * a2 - a3) * t2 + (-a0 + 3 * a1 - 3 * a2 + a3) * t3);
    }

    return { x: axis(p0.x, p1.x, p2.x, p3.x), y: axis(p0.y, p1.y, p2.y, p3.y) };
}

// Mapa "Ninja Village": genera la rejilla de terreno, el río, el camino principal
// (con sus waypoints guardados para que una fase futura pueda usarlos como ruta de
// enemigos) y la zona de aldea, todo determinista a partir de `seed`.
export class GameMap {
    constructor({ width = 110, height = 80, seed = 9001 } = {}) {
        this.width = width;
        this.height = height;
        this.tileSize = TILE_SIZE;
        this.seed = seed;
        this.tiles = new Uint8Array(width * height);

        this._buildBaseTerrain();
        this._carveRiver();
        this._buildPathControlPoints();
        this._carvePath();
        this._carveVillageZone();
        this._scatterExtraRock();

        this.pixelWidth = width * TILE_SIZE;
        this.pixelHeight = height * TILE_SIZE;
    }

    _setType(tx, ty, type) {
        if (!this.inBounds(tx, ty)) return;
        this.tiles[ty * this.width + tx] = typeToId(type);
    }

    _getType(tx, ty) {
        if (!this.inBounds(tx, ty)) return null;
        return idToType(this.tiles[ty * this.width + tx]);
    }

    inBounds(tx, ty) {
        return tx >= 0 && ty >= 0 && tx < this.width && ty < this.height;
    }

    _buildBaseTerrain() {
        const elevationNoise = new ValueNoise2D(this.seed);
        const moistureNoise = new ValueNoise2D(this.seed + 777);
        const detailNoise = new ValueNoise2D(this.seed + 333);
        const { width, height } = this;
        const borderRing = 5;

        for (let y = 0; y < height; y++) {
            for (let x = 0; x < width; x++) {
                const distToEdge = Math.min(x, y, width - 1 - x, height - 1 - y);
                let elevation = elevationNoise.fbm(x, y, 4, 0.5, 0.045);
                const moisture = moistureNoise.fbm(x, y, 3, 0.55, 0.06);
                const detail = detailNoise.fbm(x, y, 2, 0.5, 0.2);

                // Empuja la elevación hacia arriba cerca del borde: crea el anillo
                // montañoso que sirve de límite natural del mapa.
                if (distToEdge < borderRing) {
                    elevation += (borderRing - distToEdge) / borderRing + detail * 0.15;
                }

                let type;
                if (elevation > 0.55) type = TileType.MOUNTAIN;
                else if (elevation > 0.36) type = TileType.ROCK;
                else if (moisture > 0.26 && elevation > -0.2) type = TileType.FOREST;
                else if (moisture < -0.32 && detail > 0.12) type = TileType.DIRT;
                else type = TileType.GRASS;

                this._setType(x, y, type);
            }
        }
    }

    _carveRiver() {
        const { width, height } = this;
        const noise = new ValueNoise2D(this.seed + 55);
        this.riverBaseY = Math.floor(height * 0.66);

        for (let x = 0; x < width; x++) {
            const wave = Math.sin(x * 0.09) * 4 + noise.fbm(x, 0, 2, 0.5, 0.08) * 3;
            const centerY = this.riverBaseY + wave;
            const thickness = 2.4 + noise.fbm(x, 50, 2, 0.5, 0.1) * 1.6;
            for (let y = 0; y < height; y++) {
                if (Math.abs(y - centerY) < thickness) {
                    const distToEdge = Math.min(x, y, width - 1 - x, height - 1 - y);
                    if (distToEdge > 2) this._setType(x, y, TileType.WATER);
                }
            }
        }
    }

    // Puntos de control (en coordenadas de tile) del camino principal: entra por un
    // hueco en las montañas del norte, atraviesa el bosque, pasa por la aldea y
    // cruza el río antes de salir por el sur.
    _buildPathControlPoints() {
        const w = this.width;
        const h = this.height;
        this.villageCenterTile = { x: Math.round(w * 0.5), y: Math.round(h * 0.46) };

        this.pathControlPoints = [
            { x: w * 0.38, y: -4 },
            { x: w * 0.44, y: h * 0.16 },
            { x: w * 0.48, y: h * 0.3 },
            { x: this.villageCenterTile.x, y: this.villageCenterTile.y },
            { x: w * 0.56, y: h * 0.62 },
            { x: w * 0.6, y: h * 0.78 },
            { x: w * 0.63, y: h + 4 },
        ];
    }

    _carvePath() {
        const samples = 400;
        const rng = mulberry32(this.seed + 2024);
        this.pathWaypoints = [];
        this.bridgeTiles = [];

        for (let i = 0; i <= samples; i++) {
            const t = i / samples;
            const p = catmullRom(this.pathControlPoints, t);
            const tx = Math.round(p.x);
            const ty = Math.round(p.y);
            if (i % 8 === 0) {
                this.pathWaypoints.push({ x: p.x * TILE_SIZE, y: p.y * TILE_SIZE });
            }

            const widthJitter = 1.6 + hash2i(i, 0, this.seed) * 1.1;
            const radius = Math.round(widthJitter);
            for (let dx = -radius; dx <= radius; dx++) {
                for (let dy = -radius; dy <= radius; dy++) {
                    if (dx * dx + dy * dy > radius * radius + 0.5) continue;
                    const nx = tx + dx;
                    const ny = ty + dy;
                    if (!this.inBounds(nx, ny)) continue;
                    if (this._getType(nx, ny) === TileType.MOUNTAIN) continue;
                    if (this._getType(nx, ny) === TileType.WATER) {
                        this.bridgeTiles.push({ x: nx, y: ny });
                    }
                    this._setType(nx, ny, TileType.PATH);
                }
            }
        }
    }

    _carveVillageZone() {
        const { x: cx, y: cy } = this.villageCenterTile;
        const radius = 11;
        for (let dy = -radius; dy <= radius; dy++) {
            for (let dx = -radius; dx <= radius; dx++) {
                const dist = Math.hypot(dx, dy);
                if (dist > radius) continue;
                const nx = cx + dx;
                const ny = cy + dy;
                if (!this.inBounds(nx, ny)) continue;
                const current = this._getType(nx, ny);
                if (current === TileType.PATH || current === TileType.WATER || current === TileType.MOUNTAIN) continue;
                // Difumina el borde de la zona para que no sea un círculo perfecto.
                const edgeNoise = hash2i(nx, ny, this.seed + 88) * 3;
                if (dist + edgeNoise > radius) continue;
                this._setType(nx, ny, TileType.VILLAGE);
            }
        }
    }

    _scatterExtraRock() {
        const rng = mulberry32(this.seed + 61);
        for (let i = 0; i < this.tiles.length; i++) {
            if (idToType(this.tiles[i]) === TileType.GRASS && rng() < 0.004) {
                this.tiles[i] = typeToId(TileType.ROCK);
            }
        }
    }

    getTileTypeAt(tx, ty) {
        return this._getType(tx, ty);
    }

    getTileTypeAtWorld(wx, wy) {
        return this.getTileTypeAt(Math.floor(wx / TILE_SIZE), Math.floor(wy / TILE_SIZE));
    }

    isWalkable(tx, ty) {
        const type = this._getType(tx, ty);
        return type !== null && TILE_DEFS[type].walkable;
    }

    get villageCenterWorld() {
        return { x: this.villageCenterTile.x * TILE_SIZE, y: this.villageCenterTile.y * TILE_SIZE };
    }
}
