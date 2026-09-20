// Punto de entrada: conecta el lobby (DOM) con el arranque del juego (Phaser).
let phaserGame = null;

function startPhaserGame(payload) {
  phaserGame = new Phaser.Game({
    type: Phaser.AUTO,
    parent: 'phaser-container',
    backgroundColor: '#0b0e1f',
    scale: {
      mode: Phaser.Scale.RESIZE,
      width: window.innerWidth,
      height: window.innerHeight,
    },
  });
  phaserGame.scene.add('GameScene', GameScene, true, payload);
  window.phaserGame = phaserGame; // util para depuracion/tests desde consola
}

function destroyPhaserGame() {
  if (phaserGame) {
    phaserGame.destroy(true);
    phaserGame = null;
    window.phaserGame = null;
  }
}

document.addEventListener('DOMContentLoaded', () => {
  UI.initLobby();

  Network.on('game:start', (payload) => {
    UI.enterGame(payload);
    startPhaserGame(payload);
  });

  Network.on('game:over', () => {
    destroyPhaserGame();
  });
});
