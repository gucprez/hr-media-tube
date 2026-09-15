import { Game } from './core/Game.js';

const canvas = document.getElementById('game-canvas');
const minimapCanvas = document.getElementById('minimap-canvas');

const game = new Game(canvas, minimapCanvas);
game.start();

// Expuesto para depuración manual desde la consola del navegador.
window.__game = game;
