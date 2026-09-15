import { Game } from './game/core/Game.js';

const canvas = document.getElementById('game-canvas');
const game = new Game(canvas);
game.start();

// Expuesto para depuración manual desde la consola del navegador.
window.__game = game;
