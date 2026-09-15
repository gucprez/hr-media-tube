// Catálogo de terrenos. `speedMultiplier` y `walkable`/`buildable` son consumidos por
// los sistemas de movimiento y construcción que llegarán en fases posteriores; ya
// quedan definidos aquí para que el terreno "importe" desde el primer momento.
export const TileType = Object.freeze({
    GRASS: 'grass',
    DIRT: 'dirt',
    ROCK: 'rock',
    WATER: 'water',
    FOREST: 'forest',
    MOUNTAIN: 'mountain',
    ROAD: 'road',
    BUILDABLE: 'buildable',
    OBSTACLE: 'obstacle',
});

export const TILE_DEFS = {
    [TileType.GRASS]: { walkable: true, buildable: true, speedMultiplier: 1.0, variants: 4 },
    [TileType.DIRT]: { walkable: true, buildable: true, speedMultiplier: 1.05, variants: 3 },
    [TileType.ROCK]: { walkable: true, buildable: false, speedMultiplier: 0.75, variants: 3 },
    [TileType.WATER]: { walkable: false, buildable: false, speedMultiplier: 0, variants: 1 },
    [TileType.FOREST]: { walkable: true, buildable: false, speedMultiplier: 0.65, variants: 3 },
    [TileType.MOUNTAIN]: { walkable: false, buildable: false, speedMultiplier: 0, variants: 2 },
    [TileType.ROAD]: { walkable: true, buildable: false, speedMultiplier: 1.35, variants: 2 },
    [TileType.BUILDABLE]: { walkable: true, buildable: true, speedMultiplier: 1.0, variants: 3 },
    [TileType.OBSTACLE]: { walkable: false, buildable: false, speedMultiplier: 0, variants: 2 },
};

export const TILE_SIZE = 64;
