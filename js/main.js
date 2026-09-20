import { AssetManifest } from './data/AssetManifest.js';
import { AssetManager } from './core/AssetManager.js';
import { Camera } from './core/Camera.js';
import { Input } from './core/Input.js';
import { Renderer } from './core/Renderer.js';
import { World } from './world/World.js';
import { Editor } from './editor/Editor.js';
import { MapSerializer, MapValidationError } from './data/MapSerializer.js';
import { createEmptyMap } from './data/MapData.js';

const DEMO_MAP_URL = 'maps/test/editor_test.json';

function queryDom() {
  const byId = (id) => document.getElementById(id);
  return {
    inspectorContent: byId('inspector-content'),
    toolButtons: Array.from(document.querySelectorAll('#tool-buttons .tool-btn')),
    assetPalette: byId('asset-palette'),
    terrainControls: byId('terrain-controls'),
    terrainTypeSelect: byId('terrain-type-select'),
    waterControls: byId('water-controls'),
    waterTypeSelect: byId('water-type-select'),
    pathControls: byId('path-controls'),
    btnNewPath: byId('btn-new-path'),
    btnNew: byId('btn-new'),
    btnOpen: byId('btn-open'),
    fileInput: byId('file-open'),
    btnSave: byId('btn-save'),
    btnSaveAs: byId('btn-save-as'),
    btnUndo: byId('btn-undo'),
    btnRedo: byId('btn-redo'),
    gridToggle: byId('grid-toggle'),
    snapToggle: byId('snap-toggle'),
    gridSizeInput: byId('grid-size-input'),
    btnZoomIn: byId('btn-zoom-in'),
    btnZoomOut: byId('btn-zoom-out'),
    btnDebug: byId('btn-debug-toggle'),
    debugOverlay: byId('debug-overlay'),
    statusBar: byId('status-bar')
  };
}

async function bootstrap() {
  const canvas = document.getElementById('world-canvas');
  const ctx = canvas.getContext('2d');
  const viewport = document.getElementById('viewport');

  const manifest = await new AssetManifest().load('assets/asset-manifest.json');
  const assetManager = new AssetManager(manifest, 'assets/');
  assetManager.preloadAll();

  let mapData;
  try {
    mapData = await MapSerializer.loadFromUrl(DEMO_MAP_URL);
  } catch (err) {
    const reason = err instanceof MapValidationError ? err.errors.join('; ') : err.message;
    console.warn(`[main] Falling back to an empty map (could not load ${DEMO_MAP_URL}): ${reason}`);
    mapData = createEmptyMap('New Battlefield');
  }

  const world = new World(mapData);

  const camera = new Camera({
    worldWidth: world.width,
    worldHeight: world.height,
    viewportWidth: viewport.clientWidth || 1280,
    viewportHeight: viewport.clientHeight || 720
  });

  function resize() {
    const rect = viewport.getBoundingClientRect();
    canvas.width = rect.width;
    canvas.height = rect.height;
    camera.resize(rect.width, rect.height);
  }
  resize();
  window.addEventListener('resize', resize);

  const input = new Input(canvas);
  const renderer = new Renderer(ctx, camera);
  const dom = queryDom();

  const editor = new Editor({ canvas, ctx, camera, input, renderer, world, assetManager, manifest, dom });
  editor.start();

  window.__editor = editor;
}

bootstrap().catch((err) => {
  console.error('[main] Fatal error during startup:', err);
  const viewport = document.getElementById('viewport');
  if (viewport) {
    viewport.innerHTML = `<div class="fatal-error">Failed to start the World Builder.<br>${err.message}<br><small>Check the browser console for details.</small></div>`;
  }
});
