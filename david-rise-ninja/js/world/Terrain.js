// Catálogo lógico de terrenos: qué es transitable/construible, y la paleta de color
// que usa TerrainPainter.js para pintarlos. Deliberadamente esta clase ya NO genera
// una textura por-tile — esa técnica es la que producía la cuadrícula visible que
// pedimos eliminar. El grid de abajo sigue existiendo (para IA/colisión/construcción
// futuras), pero el jugador nunca lo ve: lo que se pinta es un lienzo continuo (ver
// TerrainPainter.js).
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

export const TILE_DEFS = {
    [TileType.GRASS]: { walkable: true, buildable: true },
    [TileType.DIRT]: { walkable: true, buildable: true },
    [TileType.PATH]: { walkable: true, buildable: false },
    [TileType.WATER]: { walkable: false, buildable: false },
    [TileType.FOREST]: { walkable: true, buildable: false },
    [TileType.ROCK]: { walkable: true, buildable: false },
    [TileType.MOUNTAIN]: { walkable: false, buildable: false },
    [TileType.VILLAGE]: { walkable: true, buildable: false },
};

export const TILE_SIZE = 64;

// Cada entrada: color base + un par de tonos de variación (para que el "stamp"
// orgánico de cada casilla en TerrainPainter nunca pinte un color plano idéntico).
export const BIOME_PALETTE = {
    [TileType.GRASS]: { base: [74, 138, 60], variants: [[92, 158, 68], [58, 118, 50], [82, 145, 95]] },
    [TileType.DIRT]: { base: [138, 106, 69], variants: [[152, 120, 80], [118, 88, 56]] },
    [TileType.FOREST]: { base: [42, 74, 38], variants: [[52, 88, 44], [34, 60, 32]] },
    [TileType.ROCK]: { base: [131, 128, 124], variants: [[145, 142, 137], [112, 110, 106]] },
    [TileType.MOUNTAIN]: { base: [92, 100, 114], variants: [[110, 117, 130], [74, 82, 96]] },
    [TileType.VILLAGE]: { base: [194, 168, 119], variants: [[204, 180, 135], [178, 152, 104]] },
    [TileType.PATH]: { base: [184, 155, 108], variants: [[196, 168, 122], [168, 140, 96]] },
    [TileType.WATER]: { base: [42, 104, 138], variants: [[52, 118, 152], [32, 88, 120]] },
};
