import { Game } from './core/Game.js';
import { resolveArtOverrides } from './world/ArtOverrides.js';

const canvas = document.getElementById('game-canvas');
const minimapCanvas = document.getElementById('minimap-canvas');

// Comprueba qué piezas de arte definitivas ya existen (ver ArtOverrides.js) antes de
// construir el mundo: así el Dojo (y cualquier otra pieza futura) aparece con el PNG
// real en cuanto se suba al repo, sin tocar nada más.
const assetOverrides = await resolveArtOverrides();

const game = new Game(canvas, minimapCanvas, assetOverrides);
game.start();

// Expuesto para depuración manual desde la consola del navegador.
window.__game = game;
