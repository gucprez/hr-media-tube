import { Camera } from './Camera.js';
import { InputManager } from './InputManager.js';
import { GameMap } from '../maps/GameMap.js';
import { TileType, TILE_SIZE } from '../maps/TileTypes.js';
import { Renderer } from '../render/Renderer.js';
import { ParticleSystem } from '../systems/ParticleSystem.js';
import { DevHUD } from '../ui/DevHUD.js';
import { hash2i } from './utils.js';

const CAMERA_SPEED = 900; // píxeles de mundo por segundo a zoom 1
const EDGE_SCROLL_MARGIN = 24;
const EDGE_SCROLL_SPEED = 700;

export class Game {
    constructor(canvas) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.input = new InputManager(canvas);
        this.camera = new Camera(canvas.clientWidth, canvas.clientHeight);
        this.gameMap = new GameMap({ width: 96, height: 96, seed: 1337 });
        this.camera.setMapBounds(this.gameMap.pixelWidth, this.gameMap.pixelHeight);
        this.camera.x = this.gameMap.spawnPoint.x;
        this.camera.y = this.gameMap.spawnPoint.y;

        this.renderer = new Renderer(2024);
        this.particles = new ParticleSystem();
        this.hud = new DevHUD();

        this.elapsedTime = 0;
        this._lastTimestamp = 0;
        this._ambientTimer = 0;
        this._dpr = Math.min(window.devicePixelRatio || 1, 2);

        this._resize();
        window.addEventListener('resize', () => this._resize());
    }

    start() {
        this._lastTimestamp = performance.now();
        requestAnimationFrame((t) => this._loop(t));
    }

    _loop(timestamp) {
        const dt = Math.min((timestamp - this._lastTimestamp) / 1000, 0.1);
        this._lastTimestamp = timestamp;
        this.elapsedTime += dt;

        this._update(dt);
        this._render();

        this.input.endFrame();
        requestAnimationFrame((t) => this._loop(t));
    }

    _update(dt) {
        this._updateCameraControls(dt);
        this.camera.update(dt);

        const mouseWorld = this.camera.screenToWorld(this.input.mouse.x, this.input.mouse.y);
        this.input.mouse.worldX = mouseWorld.x;
        this.input.mouse.worldY = mouseWorld.y;

        this._updateAmbientParticles(dt);
        this.particles.update(dt);

        this.hud.update(dt, this.camera, this.gameMap, mouseWorld);
    }

    _updateCameraControls(dt) {
        const input = this.input;
        const speed = (CAMERA_SPEED / this.camera.zoom) * dt;

        let dx = 0;
        let dy = 0;
        if (input.isKeyDown('KeyW', 'ArrowUp')) dy -= 1;
        if (input.isKeyDown('KeyS', 'ArrowDown')) dy += 1;
        if (input.isKeyDown('KeyA', 'ArrowLeft')) dx -= 1;
        if (input.isKeyDown('KeyD', 'ArrowRight')) dx += 1;

        // Auto-scroll cuando el ratón toca el borde de la pantalla (sensación RTS clásica).
        if (input.mouse.insideCanvas && input.mouse.dragButton === -1) {
            const m = input.mouse;
            const edgeSpeed = (EDGE_SCROLL_SPEED / this.camera.zoom) * dt;
            if (m.x < EDGE_SCROLL_MARGIN) this.camera.pan(-edgeSpeed, 0);
            else if (m.x > this.canvas.clientWidth - EDGE_SCROLL_MARGIN) this.camera.pan(edgeSpeed, 0);
            if (m.y < EDGE_SCROLL_MARGIN) this.camera.pan(0, -edgeSpeed);
            else if (m.y > this.canvas.clientHeight - EDGE_SCROLL_MARGIN) this.camera.pan(0, edgeSpeed);
        }

        if (dx !== 0 || dy !== 0) {
            const len = Math.hypot(dx, dy);
            this.camera.pan((dx / len) * speed, (dy / len) * speed);
        }

        // Arrastre con el botón central del ratón.
        if (input.mouse.dragButton === 1 && (input.mouse.dragDX !== 0 || input.mouse.dragDY !== 0)) {
            this.camera.pan(-input.mouse.dragDX / this.camera.zoom, -input.mouse.dragDY / this.camera.zoom);
        }

        if (input.mouse.wheelDelta !== 0) {
            const factor = input.mouse.wheelDelta > 0 ? 0.9 : 1.1;
            this.camera.zoomAt(input.mouse.x, input.mouse.y, factor);
        }
    }

    // Genera motas de polvo flotando en toda la vista, y hojas cayendo cerca de bosques,
    // usando el sistema de partículas genérico. Mantiene la escena "viva" sin unidades.
    _updateAmbientParticles(dt) {
        this._ambientTimer -= dt;
        if (this._ambientTimer > 0) return;
        this._ambientTimer = 0.06;

        const view = this.camera.getVisibleWorldRect();

        this.particles.spawn({
            x: view.left + Math.random() * (view.right - view.left),
            y: view.top + Math.random() * (view.bottom - view.top),
            vx: (Math.random() - 0.5) * 8,
            vy: -4 - Math.random() * 6,
            gravity: 0,
            maxLife: 4 + Math.random() * 3,
            size: 1 + Math.random() * 1.5,
            endSize: 0.5,
            color: '230,225,200',
            alphaStart: 0.1 + Math.random() * 0.3,
            alphaEnd: 0,
            kind: 'dot',
        });

        const sampleX = Math.floor((view.left + Math.random() * (view.right - view.left)) / TILE_SIZE);
        const sampleY = Math.floor((view.top + Math.random() * (view.bottom - view.top)) / TILE_SIZE);
        if (this.gameMap.getTileTypeAt(sampleX, sampleY) === TileType.FOREST && hash2i(sampleX, sampleY, Math.floor(this.elapsedTime)) < 0.4) {
            this.particles.spawn({
                x: sampleX * TILE_SIZE + Math.random() * TILE_SIZE,
                y: sampleY * TILE_SIZE - 20,
                vx: (Math.random() - 0.5) * 14,
                vy: 14 + Math.random() * 10,
                gravity: 4,
                maxLife: 3 + Math.random() * 2,
                size: 2.5,
                endSize: 2,
                color: Math.random() < 0.5 ? '176,120,42' : '110,150,60',
                alphaStart: 0.7,
                alphaEnd: 0,
                rotation: Math.random() * Math.PI,
                rotationSpeed: (Math.random() - 0.5) * 3,
                kind: 'leaf',
            });
        }
    }

    _render() {
        this.renderer.render(
            this.ctx,
            this.canvas.clientWidth,
            this.canvas.clientHeight,
            this.camera,
            this.gameMap,
            this.particles,
            this.elapsedTime
        );
    }

    _resize() {
        const w = window.innerWidth;
        const h = window.innerHeight;
        this.canvas.style.width = `${w}px`;
        this.canvas.style.height = `${h}px`;
        this.canvas.width = Math.round(w * this._dpr);
        this.canvas.height = Math.round(h * this._dpr);
        this.ctx.setTransform(this._dpr, 0, 0, this._dpr, 0, 0);

        this.camera.setViewportSize(w, h);
    }
}
