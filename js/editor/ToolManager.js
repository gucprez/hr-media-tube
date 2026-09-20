export const TOOLS = Object.freeze({
  SELECT: 'select',
  MOVE: 'move',
  TERRAIN: 'terrain',
  PATH: 'path',
  TREE: 'tree',
  ROCK: 'rock',
  BUSH: 'bush',
  GRASS: 'grass',
  FENCE: 'fence',
  BARRICADE: 'barricade',
  BRIDGE: 'bridge',
  WATER: 'water',
  PLAYER_BASE: 'playerBase',
  ENEMY_BASE: 'enemyBase',
  BUILD_SLOT: 'buildSlot',
  DELETE: 'delete'
});

/** Maps an asset-placement tool to the manifest categories its palette should show. */
export const TOOL_ASSET_CATEGORIES = {
  [TOOLS.TREE]: ['trees'],
  [TOOLS.ROCK]: ['rocks', 'decoration'],
  [TOOLS.BUSH]: ['vegetation'],
  [TOOLS.GRASS]: ['vegetation'],
  [TOOLS.FENCE]: ['fences'],
  [TOOLS.BARRICADE]: ['barricades'],
  [TOOLS.BRIDGE]: ['bridges']
};

/** type + layer to use when the tool places a generic WorldObject. */
export const TOOL_OBJECT_META = {
  [TOOLS.TREE]: { type: 'tree', layer: 'objects' },
  [TOOLS.ROCK]: { type: 'rock', layer: 'objects' },
  [TOOLS.BUSH]: { type: 'bush', layer: 'decoration' },
  [TOOLS.GRASS]: { type: 'grass', layer: 'decoration' },
  [TOOLS.FENCE]: { type: 'fence', layer: 'objects' },
  [TOOLS.BARRICADE]: { type: 'barricade', layer: 'objects' }
};

export class ToolManager {
  constructor(onToolChange) {
    this.current = TOOLS.SELECT;
    this.activeAsset = null;
    this.onToolChange = onToolChange;
  }

  setTool(tool) {
    this.current = tool;
    this.activeAsset = null;
    this.onToolChange?.(tool);
  }

  setActiveAsset(assetId) {
    this.activeAsset = assetId;
  }

  isAssetPlacementTool() {
    return Object.prototype.hasOwnProperty.call(TOOL_ASSET_CATEGORIES, this.current);
  }
}
