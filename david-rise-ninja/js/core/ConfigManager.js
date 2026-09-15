// Persistencia simple en localStorage para preferencias (no progreso de campaña
// todavía: eso llegará junto con el sistema de misiones en una fase posterior).
const STORAGE_KEY = 'david-rise-ninja:config';

const DEFAULTS = {
    musicVolume: 0.6,
    sfxVolume: 0.8,
    defaultZoom: 1.0,
    graphicsQuality: 'high',
    debug: false,
};

export class ConfigManager {
    constructor() {
        this.values = { ...DEFAULTS, ...this._load() };
    }

    _load() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : {};
        } catch {
            return {};
        }
    }

    _save() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(this.values));
        } catch {
            // almacenamiento no disponible (modo privado, cuota, etc.): degradar sin romper
        }
    }

    get(key, fallback = null) {
        return key in this.values ? this.values[key] : fallback;
    }

    set(key, value) {
        this.values[key] = value;
        this._save();
    }
}
