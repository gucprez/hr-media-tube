// Menú principal como overlay DOM. El "fondo desenfocado del mundo ninja" que pide el
// diseño no es una imagen estática: es el propio canvas del juego, siempre renderizando
// detrás, visto a través de un `backdrop-filter: blur() brightness()` (ver ui.css). Así
// el menú hereda gratis el movimiento del agua, las partículas y la cámara en órbita
// lenta que Game.js activa mientras el estado es "menu".
export class MainMenu {
    constructor({ config, audio, onPlay }) {
        this.config = config;
        this.audio = audio;
        this.onPlay = onPlay;

        this.root = document.getElementById('main-menu');
        this.optionsPanel = document.getElementById('options-panel');
        this.menuContent = document.getElementById('menu-content');

        this._bind();
        this._spawnLeaves();
    }

    _bind() {
        document.getElementById('btn-options').addEventListener('click', () => this._showOptions());
        document.getElementById('btn-options-back').addEventListener('click', () => this._hideOptions());
        document.getElementById('btn-exit').addEventListener('click', () => this._handleExit());

        const musicSlider = document.getElementById('opt-music');
        const sfxSlider = document.getElementById('opt-sfx');
        musicSlider.value = this.config.get('musicVolume', 0.6);
        sfxSlider.value = this.config.get('sfxVolume', 0.8);
        musicSlider.addEventListener('input', (e) => this.audio.setMusicVolume(parseFloat(e.target.value)));
        sfxSlider.addEventListener('input', (e) => this.audio.setSFXVolume(parseFloat(e.target.value)));
    }

    _showOptions() {
        this.menuContent.hidden = true;
        this.optionsPanel.hidden = false;
    }

    _hideOptions() {
        this.optionsPanel.hidden = true;
        this.menuContent.hidden = false;
    }

    _handleExit() {
        // Un navegador no permite cerrar una pestaña que no abrió el propio script;
        // `window.close()` sólo funciona en ese caso concreto. Se intenta igualmente
        // y, si el navegador lo ignora, se informa al jugador en vez de fallar en
        // silencio.
        window.close();
        const note = document.getElementById('menu-exit-note');
        note.hidden = false;
    }

    _spawnLeaves() {
        const layer = document.getElementById('menu-leaves');
        const count = 14;
        for (let i = 0; i < count; i++) {
            const leaf = document.createElement('span');
            leaf.className = 'menu-leaf';
            leaf.style.left = `${Math.random() * 100}%`;
            leaf.style.animationDelay = `${Math.random() * 12}s`;
            leaf.style.animationDuration = `${10 + Math.random() * 8}s`;
            leaf.style.setProperty('--drift', `${(Math.random() - 0.5) * 160}px`);
            layer.appendChild(leaf);
        }
    }

    show() {
        this.root.hidden = false;
        document.body.classList.add('in-menu');
        if (this.onPlay) {
            document.getElementById('btn-play').onclick = () => {
                this.hide();
                this.onPlay();
            };
        }
    }

    hide() {
        this.root.hidden = true;
        document.body.classList.remove('in-menu');
    }
}
