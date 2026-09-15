import { GameMap } from './Map.js';
import { TerrainArt, TileType, TILE_SIZE } from './Terrain.js';
import { DecorationArt, scatterDecorations } from './Decoration.js';
import { VillageArt, buildVillage } from './Village.js';
import { ParticleSystem } from '../core/ParticleSystem.js';
import { hash2i } from '../core/utils.js';

// Orquestador del mundo: junta mapa, decoración, aldea y partículas ambientales, y
// mantiene una única lista de profundidad (decoraciones + edificios) ya ordenada por Y
// para que el Renderer la recorra en un solo paso (algoritmo del pintor).
export class World {
    constructor(seed = 20240101) {
        this.map = new GameMap({ seed });
        this.terrainArt = new TerrainArt(seed + 1);
        this.decorationArt = new DecorationArt(seed + 2);
        this.decorations = scatterDecorations(this.map, seed + 3);
        this.villageArt = new VillageArt(seed + 4);

        const village = buildVillage(this.map.villageCenterWorld, this.villageArt, seed + 5);
        this.villageEntities = village.entities;
        this.lanternPositions = village.lanternPositions;
        this.chimneyPositions = village.chimneyPositions;

        this.depthList = this._mergeDepthList();

        this.particles = new ParticleSystem();
        this.elapsedTime = 0;
        this._ambientTimer = 0;
        this._smokeTimer = 0;
    }

    // Combina decoraciones (datos planos) y entidades de la aldea (con su propio
    // render()) en una sola lista ordenada por Y, cada elemento etiquetado con su tipo
    // para que el Renderer sepa cómo dibujarlo sin tener que preguntar por su clase.
    _mergeDepthList() {
        const decoItems = this.decorations.map((d) => ({ kind: 'decoration', y: d.y, data: d }));
        const entityItems = this.villageEntities.map((e) => ({ kind: 'entity', y: e.depthY, data: e }));
        return decoItems.concat(entityItems).sort((a, b) => a.y - b.y);
    }

    update(dt, camera) {
        this.elapsedTime += dt;
        this._updateAmbientParticles(dt, camera);
        this.particles.update(dt);
    }

    _updateAmbientParticles(dt, camera) {
        this._ambientTimer -= dt;
        if (this._ambientTimer <= 0) {
            this._ambientTimer = 0.08;
            const view = camera.getVisibleWorldRect();

            // Polvo ambiental disperso por toda la vista.
            this.particles.spawn({
                x: view.left + Math.random() * (view.right - view.left),
                y: view.top + Math.random() * (view.bottom - view.top),
                vx: (Math.random() - 0.5) * 6,
                vy: -3 - Math.random() * 5,
                maxLife: 4 + Math.random() * 3,
                size: 1 + Math.random() * 1.4,
                endSize: 0.4,
                color: '235,228,205',
                alphaStart: 0.08 + Math.random() * 0.18,
                alphaEnd: 0,
                kind: 'dot',
            });

            // Hojas cayendo cerca de zonas de bosque visibles.
            const sx = Math.floor((view.left + Math.random() * (view.right - view.left)) / TILE_SIZE);
            const sy = Math.floor((view.top + Math.random() * (view.bottom - view.top)) / TILE_SIZE);
            if (this.map.getTileTypeAt(sx, sy) === TileType.FOREST && hash2i(sx, sy, Math.floor(this.elapsedTime * 2)) < 0.5) {
                this.particles.spawn({
                    x: sx * TILE_SIZE + Math.random() * TILE_SIZE,
                    y: sy * TILE_SIZE - 24,
                    vx: (Math.random() - 0.5) * 12,
                    vy: 12 + Math.random() * 8,
                    gravity: 4,
                    maxLife: 3 + Math.random() * 2,
                    size: 2.4,
                    endSize: 2,
                    color: Math.random() < 0.5 ? '178,122,44' : '112,150,62',
                    alphaStart: 0.75,
                    alphaEnd: 0,
                    rotation: Math.random() * Math.PI,
                    rotationSpeed: (Math.random() - 0.5) * 3,
                    kind: 'leaf',
                });
            }

            // Luciérnagas suaves cerca de la aldea al anochecer simulado (siempre
            // encendidas por ahora: el ciclo día/noche real llega en una fase futura).
            const villageCenter = this.map.villageCenterWorld;
            if (Math.random() < 0.5) {
                const angle = Math.random() * Math.PI * 2;
                const dist = 120 + Math.random() * 260;
                this.particles.spawn({
                    x: villageCenter.x + Math.cos(angle) * dist,
                    y: villageCenter.y + Math.sin(angle) * dist * 0.6,
                    vx: (Math.random() - 0.5) * 8,
                    vy: (Math.random() - 0.5) * 8,
                    maxLife: 2.5 + Math.random() * 1.5,
                    size: 1.4,
                    endSize: 1.4,
                    color: '255,240,170',
                    alphaStart: 0,
                    alphaEnd: 0,
                    glow: true,
                    kind: 'dot',
                });
            }

            // Brillo cálido persistente de cada farol (recreado con frecuencia baja
            // para simular un parpadeo suave sin coste de un sistema de luces real).
            for (const pos of this.lanternPositions) {
                this.particles.spawn({
                    x: pos.x,
                    y: pos.y,
                    vx: 0,
                    vy: 0,
                    maxLife: 0.5,
                    size: 14,
                    endSize: 14,
                    color: '255,200,120',
                    alphaStart: 0.55,
                    alphaEnd: 0.3,
                    glow: true,
                    kind: 'dot',
                });
            }
        }

        // Humo sutil de chimenea: una pequeña voluta cada cierto tiempo.
        this._smokeTimer -= dt;
        if (this._smokeTimer <= 0 && this.chimneyPositions.length) {
            this._smokeTimer = 0.6;
            for (const pos of this.chimneyPositions) {
                this.particles.spawn({
                    x: pos.x + (Math.random() - 0.5) * 3,
                    y: pos.y,
                    vx: (Math.random() - 0.5) * 4,
                    vy: -14 - Math.random() * 6,
                    maxLife: 3.5,
                    size: 3,
                    endSize: 11,
                    color: '210,210,210',
                    alphaStart: 0.28,
                    alphaEnd: 0,
                    kind: 'dot',
                });
            }
        }
    }
}
