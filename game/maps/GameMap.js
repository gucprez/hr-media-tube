import { generateMap, tileIdToType } from './MapGenerator.js';
import { TILE_DEFS, TILE_SIZE } from './TileTypes.js';

// Envoltorio de "consulta" sobre los datos crudos del mapa. El resto del motor
// (cámara, render, y más adelante movimiento/IA) habla con esta clase, nunca
// con los arrays crudos directamente.
export class GameMap {
    constructor(options) {
        const data = generateMap(options);
        Object.assign(this, data);
    }

    inBounds(tx, ty) {
        return tx >= 0 && ty >= 0 && tx < this.width && ty < this.height;
    }

    getTileTypeAt(tx, ty) {
        if (!this.inBounds(tx, ty)) return null;
        return tileIdToType(this.tiles[ty * this.width + tx]);
    }

    getTileTypeAtWorld(wx, wy) {
        return this.getTileTypeAt(Math.floor(wx / TILE_SIZE), Math.floor(wy / TILE_SIZE));
    }

    isWalkable(tx, ty) {
        const type = this.getTileTypeAt(tx, ty);
        if (type === null) return false;
        return TILE_DEFS[type].walkable;
    }

    isBuildable(tx, ty) {
        const type = this.getTileTypeAt(tx, ty);
        if (type === null) return false;
        return TILE_DEFS[type].buildable;
    }

    getSpeedMultiplierAt(tx, ty) {
        const type = this.getTileTypeAt(tx, ty);
        if (type === null) return 0;
        return TILE_DEFS[type].speedMultiplier;
    }
}
