import { ValueNoise2D, mulberry32, hash2i, clamp } from '../core/utils.js';
import { TileType, TILE_SIZE } from './TileTypes.js';

const TILE_ORDER = [
    TileType.WATER,
    TileType.GRASS,
    TileType.DIRT,
    TileType.ROCK,
    TileType.FOREST,
    TileType.MOUNTAIN,
    TileType.ROAD,
    TileType.BUILDABLE,
    TileType.OBSTACLE,
];

export function tileIdToType(id) {
    return TILE_ORDER[id];
}

function typeToId(type) {
    return TILE_ORDER.indexOf(type);
}

// Genera un mapa completo: rejilla de terreno + capa de decoraciones (árboles, rocas,
// arbustos, juncos) ya posicionadas en coordenadas de mundo. Todo es determinista a
// partir de `seed`, para poder reconstruir la misma misión más adelante.
export function generateMap({ width = 96, height = 96, seed = 1337 } = {}) {
    const elevationNoise = new ValueNoise2D(seed);
    const moistureNoise = new ValueNoise2D(seed + 9999);
    const detailNoise = new ValueNoise2D(seed + 555);
    const rng = mulberry32(seed + 42);

    const tiles = new Uint8Array(width * height);
    const centerX = Math.floor(width / 2);
    const centerY = Math.floor(height / 2);

    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            const elevation = elevationNoise.fbm(x, y, 4, 0.5, 0.045);
            const moisture = moistureNoise.fbm(x, y, 3, 0.55, 0.06);
            const detail = detailNoise.fbm(x, y, 2, 0.5, 0.18);

            let type;
            if (elevation < -0.42) {
                type = TileType.WATER;
            } else if (elevation > 0.5) {
                type = TileType.MOUNTAIN;
            } else if (elevation > 0.32) {
                type = TileType.ROCK;
            } else if (moisture > 0.28 && elevation > -0.15) {
                type = TileType.FOREST;
            } else if (moisture < -0.35 && detail > 0.15) {
                type = TileType.DIRT;
            } else {
                type = TileType.GRASS;
            }

            // Zona de construcción despejada alrededor del punto de partida del jugador.
            const distToCenter = Math.hypot(x - centerX, y - centerY);
            if (distToCenter < 7.5) {
                type = TileType.BUILDABLE;
            } else if (distToCenter < 9 && (type === TileType.WATER || type === TileType.MOUNTAIN)) {
                type = TileType.GRASS;
            }

            tiles[y * width + x] = typeToId(type);
        }
    }

    carveRoad(tiles, width, height, centerX, centerY, rng);
    scatterObstacles(tiles, width, height, rng);

    const decorations = scatterDecorations(tiles, width, height, seed, rng);

    return {
        width,
        height,
        tileSize: TILE_SIZE,
        tiles,
        decorations,
        spawnPoint: { x: centerX * TILE_SIZE + TILE_SIZE / 2, y: centerY * TILE_SIZE + TILE_SIZE / 2 },
        pixelWidth: width * TILE_SIZE,
        pixelHeight: height * TILE_SIZE,
    };
}

// Traza un camino serpenteante desde el centro hacia un borde del mapa mediante un
// paseo aleatorio sesgado, evitando agua y montaña.
function carveRoad(tiles, width, height, startX, startY, rng) {
    let x = startX;
    let y = startY + 8;
    const targetY = height - 3;
    let guard = 0;

    while (y < targetY && guard < 4000) {
        guard++;
        const idx = y * width + x;
        const currentType = tileIdToType(tiles[idx]);
        if (currentType !== TileType.WATER && currentType !== TileType.MOUNTAIN) {
            tiles[idx] = typeToId(TileType.ROAD);
            if (x + 1 < width) tiles[idx + 1] = typeToId(TileType.ROAD);
        }

        const roll = rng();
        if (roll < 0.15 && x > 2) x -= 1;
        else if (roll < 0.3 && x < width - 3) x += 1;
        else y += 1;

        x = clamp(x, 1, width - 2);
        y = clamp(y, 1, height - 2);
    }
}

function scatterObstacles(tiles, width, height, rng) {
    const total = tiles.length;
    for (let i = 0; i < total; i++) {
        const type = tileIdToType(tiles[i]);
        if (type === TileType.GRASS && rng() < 0.006) {
            tiles[i] = typeToId(TileType.OBSTACLE);
        }
    }
}

function scatterDecorations(tiles, width, height, seed, rng) {
    const decorations = [];

    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            const type = tileIdToType(tiles[y * width + x]);
            const worldX = x * TILE_SIZE;
            const worldY = y * TILE_SIZE;
            const localSeed = hash2i(x, y, seed);

            if (type === TileType.FOREST) {
                const treeCount = 1 + Math.floor(hash2i(x, y, seed + 1) * 2.4);
                for (let i = 0; i < treeCount; i++) {
                    decorations.push(makeDecoration('tree', worldX, worldY, seed + i, rng));
                }
            } else if (type === TileType.GRASS && localSeed < 0.05) {
                decorations.push(makeDecoration('bush', worldX, worldY, seed + 2, rng));
            } else if (type === TileType.GRASS && localSeed > 0.965) {
                decorations.push(makeDecoration('rock', worldX, worldY, seed + 3, rng));
            } else if (type === TileType.ROCK && localSeed > 0.7) {
                decorations.push(makeDecoration('rock', worldX, worldY, seed + 4, rng));
            } else if (
                type !== TileType.WATER &&
                type !== TileType.MOUNTAIN &&
                isNearWater(tiles, width, height, x, y) &&
                localSeed > 0.6
            ) {
                decorations.push(makeDecoration('reed', worldX, worldY, seed + 5, rng));
            }
        }
    }

    decorations.sort((a, b) => a.y - b.y);
    return decorations;
}

function isNearWater(tiles, width, height, x, y) {
    for (let dy = -1; dy <= 1; dy++) {
        for (let dx = -1; dx <= 1; dx++) {
            const nx = x + dx;
            const ny = y + dy;
            if (nx < 0 || ny < 0 || nx >= width || ny >= height) continue;
            if (tileIdToType(tiles[ny * width + nx]) === TileType.WATER) return true;
        }
    }
    return false;
}

function makeDecoration(kind, tileWorldX, tileWorldY, variantSeed, rng) {
    return {
        kind,
        variant: Math.floor(rng() * 3),
        x: tileWorldX + rng() * TILE_SIZE,
        y: tileWorldY + rng() * TILE_SIZE,
        scale: 0.85 + rng() * 0.4,
        flip: rng() < 0.5 ? -1 : 1,
        tintShift: rng() * 0.15 - 0.075,
    };
}
