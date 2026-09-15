// Utilidades matemáticas y de aleatoriedad determinista compartidas por todo el motor.

export function clamp(value, min, max) {
    return value < min ? min : value > max ? max : value;
}

export function lerp(a, b, t) {
    return a + (b - a) * t;
}

export function smoothstep(t) {
    return t * t * (3 - 2 * t);
}

// Suavizado independiente de framerate: aproxima un lerp exponencial usando deltaTime,
// para que la cámara (posición y zoom) se sienta fluida sin importar el FPS real.
export function damp(current, target, smoothing, dt) {
    const t = 1 - Math.pow(smoothing, dt);
    return lerp(current, target, t);
}

// PRNG determinista (mulberry32): misma semilla -> mismo mapa siempre.
export function mulberry32(seed) {
    let a = seed >>> 0;
    return function random() {
        a |= 0;
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

export function hash2i(x, y, seed = 0) {
    let h = Math.imul(x, 374761393) + Math.imul(y, 668265263) + Math.imul(seed, 2246822519);
    h = Math.imul(h ^ (h >>> 13), 1274126177);
    h ^= h >>> 16;
    return (h >>> 0) / 4294967296;
}

export class ValueNoise2D {
    constructor(seed = 1) {
        this.seed = seed;
    }

    _corner(xi, yi) {
        return hash2i(xi, yi, this.seed) * 2 - 1;
    }

    sample(x, y) {
        const xi = Math.floor(x);
        const yi = Math.floor(y);
        const tx = smoothstep(x - xi);
        const ty = smoothstep(y - yi);
        const v00 = this._corner(xi, yi);
        const v10 = this._corner(xi + 1, yi);
        const v01 = this._corner(xi, yi + 1);
        const v11 = this._corner(xi + 1, yi + 1);
        return lerp(lerp(v00, v10, tx), lerp(v01, v11, tx), ty);
    }

    fbm(x, y, octaves = 4, persistence = 0.5, scale = 1) {
        let total = 0;
        let amplitude = 1;
        let max = 0;
        let freq = scale;
        for (let i = 0; i < octaves; i++) {
            total += this.sample(x * freq, y * freq) * amplitude;
            max += amplitude;
            amplitude *= persistence;
            freq *= 2;
        }
        return total / max;
    }
}

export function makeCanvas(w, h) {
    const c = document.createElement('canvas');
    c.width = w;
    c.height = h;
    return c;
}
