// Esqueleto de audio. Todavía no existen assets de música/SFX definitivos, así que
// cada método falla en silencio si no encuentra el archivo — el motor puede llamar
// playMusic()/playSFX() desde ya sin que nada se rompa, y en cuanto lleguen los
// archivos reales a /assets/audio sólo hay que registrarlos aquí.
export class AudioManager {
    constructor(config) {
        this.config = config;
        this.musicVolume = config.get('musicVolume', 0.6);
        this.sfxVolume = config.get('sfxVolume', 0.8);
        this.currentMusic = null;
        this.tracks = new Map();
        this.sfx = new Map();
    }

    registerMusic(key, url) {
        this.tracks.set(key, url);
    }

    registerSFX(key, url) {
        this.sfx.set(key, url);
    }

    playMusic(key, { loop = true } = {}) {
        const url = this.tracks.get(key);
        this.stopMusic();
        if (!url) return; // sin asset todavía: no-op silencioso
        const audio = new Audio(url);
        audio.loop = loop;
        audio.volume = this.musicVolume;
        audio.play().catch(() => {});
        this.currentMusic = audio;
    }

    stopMusic() {
        if (this.currentMusic) {
            this.currentMusic.pause();
            this.currentMusic = null;
        }
    }

    playSFX(key) {
        const url = this.sfx.get(key);
        if (!url) return;
        const audio = new Audio(url);
        audio.volume = this.sfxVolume;
        audio.play().catch(() => {});
    }

    setMusicVolume(v) {
        this.musicVolume = v;
        if (this.currentMusic) this.currentMusic.volume = v;
        this.config.set('musicVolume', v);
    }

    setSFXVolume(v) {
        this.sfxVolume = v;
        this.config.set('sfxVolume', v);
    }
}
