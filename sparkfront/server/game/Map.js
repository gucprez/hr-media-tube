// Representa el mapa del juego: una grilla de celdas usada para detectar
// colisiones y calcular rutas (A*) esquivando obstáculos, edificios y muros.

const { MAP_WIDTH, MAP_HEIGHT, GRID_CELL, OBSTACLES } = require('./constants');

class GameMap {
  constructor() {
    this.width = MAP_WIDTH;
    this.height = MAP_HEIGHT;
    this.cellSize = GRID_CELL;
    this.cols = Math.ceil(this.width / this.cellSize);
    this.rows = Math.ceil(this.height / this.cellSize);

    // blocked[cellIndex] = número de bloqueadores en esa celda (obstáculo estático + edificios)
    this.staticBlocked = new Uint8Array(this.cols * this.rows);
    this.dynamicBlockers = new Map(); // id -> {cells: [index,...]}

    for (const rect of OBSTACLES) {
      for (const idx of this.cellsForRect(rect)) {
        this.staticBlocked[idx] = 1;
      }
    }
  }

  index(cx, cy) {
    return cy * this.cols + cx;
  }

  worldToCell(x, y) {
    return {
      cx: Math.min(this.cols - 1, Math.max(0, Math.floor(x / this.cellSize))),
      cy: Math.min(this.rows - 1, Math.max(0, Math.floor(y / this.cellSize))),
    };
  }

  cellToWorld(cx, cy) {
    return {
      x: cx * this.cellSize + this.cellSize / 2,
      y: cy * this.cellSize + this.cellSize / 2,
    };
  }

  cellsForRect(rect) {
    const cells = [];
    const left = rect.x - rect.w / 2;
    const top = rect.y - rect.h / 2;
    const right = rect.x + rect.w / 2;
    const bottom = rect.y + rect.h / 2;
    const { cx: cx0, cy: cy0 } = this.worldToCell(left, top);
    const { cx: cx1, cy: cy1 } = this.worldToCell(right, bottom);
    for (let cy = cy0; cy <= cy1; cy++) {
      for (let cx = cx0; cx <= cx1; cx++) {
        cells.push(this.index(cx, cy));
      }
    }
    return cells;
  }

  isBlocked(cx, cy) {
    if (cx < 0 || cy < 0 || cx >= this.cols || cy >= this.rows) return true;
    const idx = this.index(cx, cy);
    if (this.staticBlocked[idx]) return true;
    for (const blocker of this.dynamicBlockers.values()) {
      if (blocker.cells.includes(idx)) return true;
    }
    return false;
  }

  // Comprueba si un rectángulo (para colocar un edificio) es libre.
  isRectFree(rect) {
    for (const idx of this.cellsForRect(rect)) {
      const cx = idx % this.cols;
      const cy = Math.floor(idx / this.cols);
      if (this.isBlocked(cx, cy)) return false;
    }
    return true;
  }

  addBlocker(id, rect) {
    this.dynamicBlockers.set(id, { cells: this.cellsForRect(rect) });
  }

  removeBlocker(id) {
    this.dynamicBlockers.delete(id);
  }

  // A* simple sobre la grilla. Devuelve una lista de waypoints en coordenadas de mundo.
  findPath(startX, startY, endX, endY) {
    const start = this.worldToCell(startX, startY);
    const goal = this.worldToCell(endX, endY);

    if (start.cx === goal.cx && start.cy === goal.cy) {
      return [{ x: endX, y: endY }];
    }

    const startIdx = this.index(start.cx, start.cy);
    const goalIdx = this.index(goal.cx, goal.cy);

    const openSet = new Map(); // idx -> f
    const cameFrom = new Map();
    const gScore = new Map();
    gScore.set(startIdx, 0);
    openSet.set(startIdx, this.heuristic(start.cx, start.cy, goal.cx, goal.cy));

    const neighbors = [
      [1, 0], [-1, 0], [0, 1], [0, -1],
      [1, 1], [1, -1], [-1, 1], [-1, -1],
    ];

    let iterations = 0;
    const MAX_ITERATIONS = 4000;

    while (openSet.size > 0 && iterations < MAX_ITERATIONS) {
      iterations++;
      let currentIdx = null;
      let bestF = Infinity;
      for (const [idx, f] of openSet) {
        if (f < bestF) {
          bestF = f;
          currentIdx = idx;
        }
      }

      if (currentIdx === goalIdx) {
        return this.reconstructPath(cameFrom, currentIdx, endX, endY);
      }

      openSet.delete(currentIdx);
      const cx = currentIdx % this.cols;
      const cy = Math.floor(currentIdx / this.cols);

      for (const [dx, dy] of neighbors) {
        const nx = cx + dx;
        const ny = cy + dy;
        if (nx < 0 || ny < 0 || nx >= this.cols || ny >= this.rows) continue;
        if (this.isBlocked(nx, ny)) continue;
        // evita cortar esquinas de bloqueos diagonales
        if (dx !== 0 && dy !== 0) {
          if (this.isBlocked(cx + dx, cy) || this.isBlocked(cx, cy + dy)) continue;
        }

        const nIdx = this.index(nx, ny);
        const stepCost = (dx !== 0 && dy !== 0) ? 1.41421356 : 1;
        const tentativeG = gScore.get(currentIdx) + stepCost;

        if (tentativeG < (gScore.get(nIdx) ?? Infinity)) {
          cameFrom.set(nIdx, currentIdx);
          gScore.set(nIdx, tentativeG);
          openSet.set(nIdx, tentativeG + this.heuristic(nx, ny, goal.cx, goal.cy));
        }
      }
    }

    // No se encontró camino completo: intenta acercarse en línea recta.
    return [{ x: endX, y: endY }];
  }

  heuristic(x0, y0, x1, y1) {
    return Math.hypot(x1 - x0, y1 - y0);
  }

  reconstructPath(cameFrom, currentIdx, endX, endY) {
    const path = [];
    let idx = currentIdx;
    while (cameFrom.has(idx)) {
      const cx = idx % this.cols;
      const cy = Math.floor(idx / this.cols);
      path.unshift(this.cellToWorld(cx, cy));
      idx = cameFrom.get(idx);
    }
    if (path.length > 0) {
      path[path.length - 1] = { x: endX, y: endY };
    } else {
      path.push({ x: endX, y: endY });
    }
    return path;
  }
}

module.exports = GameMap;
