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

// PRNG determinista (mulberry32). Con la misma semilla siempre genera la misma secuencia,
// lo que permite regenerar el mismo mapa/misión de forma reproducible.
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

// Hash entero rápido para asignar variantes visuales estables a cada celda (misma
// celda siempre pinta la misma variante, sin necesidad de guardar nada en memoria).
export function hash2i(x, y, seed = 0) {
    let h = Math.imul(x, 374761393) + Math.imul(y, 668265263) + Math.imul(seed, 2246822519);
    h = Math.imul(h ^ (h >>> 13), 1274126177);
    h ^= h >>> 16;
    return (h >>> 0) / 4294967296;
}

// Generador de ruido de valor 2D suavizado (value noise), suficiente para repartir
// biomas de forma orgánica sin depender de librerías externas de Perlin/Simplex.
export class ValueNoise2D {
    constructor(seed = 1) {
        this.seed = seed;
    }

    _cornerValue(xi, yi) {
        return hash2i(xi, yi, this.seed) * 2 - 1;
    }

    sample(x, y) {
        const xi = Math.floor(x);
        const yi = Math.floor(y);
        const tx = smoothstep(x - xi);
        const ty = smoothstep(y - yi);

        const v00 = this._cornerValue(xi, yi);
        const v10 = this._cornerValue(xi + 1, yi);
        const v01 = this._cornerValue(xi, yi + 1);
        const v11 = this._cornerValue(xi + 1, yi + 1);

        const top = lerp(v00, v10, tx);
        const bottom = lerp(v01, v11, tx);
        return lerp(top, bottom, ty);
    }

    // Fractal Brownian Motion: suma varias octavas de ruido para lograr detalle
    // a distintas escalas (masas continentales grandes + variación fina).
    fbm(x, y, octaves = 4, persistence = 0.5, scale = 1) {
        let total = 0;
        let amplitude = 1;
        let maxAmplitude = 0;
        let frequency = scale;
        for (let i = 0; i < octaves; i++) {
            total += this.sample(x * frequency, y * frequency) * amplitude;
            maxAmplitude += amplitude;
            amplitude *= persistence;
            frequency *= 2;
        }
        return total / maxAmplitude;
    }
}

export function distance(ax, ay, bx, by) {
    const dx = bx - ax;
    const dy = by - ay;
    return Math.sqrt(dx * dx + dy * dy);
}
