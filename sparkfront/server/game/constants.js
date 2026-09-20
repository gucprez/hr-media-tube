// Constantes compartidas por toda la lógica del servidor.
// Cambiar valores aquí ajusta el balance del juego sin tocar la lógica.

const MAP_WIDTH = 2000;
const MAP_HEIGHT = 1500;

const TICK_RATE = 20; // ticks por segundo
const TICK_MS = 1000 / TICK_RATE;

const GRID_CELL = 50; // tamaño de celda para el pathfinding (px)

const MAX_PLAYERS = 4;
const MIN_PLAYERS = 2;

const STARTING_ENERGY = 150;
const MAX_GAME_DURATION_MS = 15 * 60 * 1000; // 15 minutos, luego gana quien tenga más vida de núcleo

// Puntos de aparición, uno por esquina del mapa.
const SPAWN_POINTS = [
  { x: 150, y: 150 },
  { x: MAP_WIDTH - 150, y: 150 },
  { x: 150, y: MAP_HEIGHT - 150 },
  { x: MAP_WIDTH - 150, y: MAP_HEIGHT - 150 },
];

// Colores de facción neón: cian, magenta, lima, naranja.
const PLAYER_COLORS = [0x00e5ff, 0xff2fd6, 0xaaff00, 0xff8a00];

// Obstáculos estáticos del mapa (cristales de energía) que bloquean construcción y movimiento.
const OBSTACLES = [
  { x: 950, y: 700, w: 220, h: 220 },
  { x: 500, y: 300, w: 90, h: 90 },
  { x: 1500, y: 300, w: 90, h: 90 },
  { x: 500, y: 1200, w: 90, h: 90 },
  { x: 1500, y: 1200, w: 90, h: 90 },
  { x: 1000, y: 200, w: 120, h: 70 },
  { x: 1000, y: 1300, w: 120, h: 70 },
  { x: 300, y: 750, w: 80, h: 80 },
  { x: 1700, y: 750, w: 80, h: 80 },
];

// Distancia máxima a la que se puede construir un nuevo edificio,
// medida desde el edificio propio más cercano. Evita construir en el núcleo enemigo.
const BUILD_RADIUS = 550;

const BUILDING_TYPES = {
  core: {
    name: 'Núcleo Spark',
    cost: 0,
    hp: 1000,
    size: 120,
    energyRate: 1, // energía/segundo generada pasivamente
    canProduce: ['spark'],
    blocksMovement: true,
  },
  generator: {
    name: 'Generador de Chispas',
    cost: 50,
    hp: 200,
    size: 60,
    energyRate: 2,
    canProduce: [],
    blocksMovement: true,
  },
  forge: {
    name: 'Forja de Unidades',
    cost: 80,
    hp: 300,
    size: 70,
    energyRate: 0,
    canProduce: ['spark', 'golem', 'bolt'],
    blocksMovement: true,
  },
  plasmaTower: {
    name: 'Torre de Plasma',
    cost: 60,
    hp: 250,
    size: 50,
    energyRate: 0,
    canProduce: [],
    blocksMovement: true,
    range: 260,
    damage: 18,
    attackRate: 1, // ataques por segundo
  },
  barrier: {
    name: 'Barrera de Energía',
    cost: 20,
    hp: 150,
    size: 40,
    energyRate: 0,
    canProduce: [],
    blocksMovement: true,
  },
};

const UNIT_TYPES = {
  spark: {
    name: 'Chispa',
    cost: 25,
    speed: 110,
    damage: 9,
    hp: 45,
    range: 40,
    attackRate: 1.1,
    buildTime: 3.5,
  },
  golem: {
    name: 'Gólem de Energía',
    cost: 65,
    speed: 40,
    damage: 22,
    hp: 140,
    range: 50,
    attackRate: 0.7,
    buildTime: 8,
  },
  bolt: {
    name: 'Rayo',
    cost: 45,
    speed: 190,
    damage: 7,
    hp: 35,
    range: 35,
    attackRate: 1.6,
    buildTime: 5,
  },
};

module.exports = {
  MAP_WIDTH,
  MAP_HEIGHT,
  TICK_RATE,
  TICK_MS,
  GRID_CELL,
  MAX_PLAYERS,
  MIN_PLAYERS,
  STARTING_ENERGY,
  MAX_GAME_DURATION_MS,
  SPAWN_POINTS,
  PLAYER_COLORS,
  OBSTACLES,
  BUILD_RADIUS,
  BUILDING_TYPES,
  UNIT_TYPES,
};
