import { Camera } from './Camera.js';
import { InputManager } from './InputManager.js';
import { AssetManager } from './AssetManager.js';
import { AudioManager } from './AudioManager.js';
import { ConfigManager } from './ConfigManager.js';
import { Renderer } from './Renderer.js';
import { GameLoop } from './GameLoop.js';
import { World } from '../world/World.js';
import { HUD } from '../ui/HUD.js';
import { Minimap } from '../ui/Minimap.js';
import { Cursor } from '../ui/Cursor.js';
import { MainMenu } from '../ui/MainMenu.js';

const CAMERA_PAN_SPEED = 900; // px de mundo por segundo a zoom 1

// Controlador de alto nivel: posee el estado del juego (menú / jugando), la cámara,
// el mundo y toda la UI. GameLoop sólo mide tiempo; aquí es donde vive
// INPUT -> UPDATE -> WORLD UPDATE -> RENDER -> UI.
export class Game {
    // `assetOverrides` viene de ArtOverrides.resolveArtOverrides(), resuelto en
    // main.js ANTES de crear el Game: por cada pieza de arte para la que ya exista
    // un PNG/WEBP real en /assets, el mundo la usa en vez del placeholder de Canvas.
    constructor(canvas, minimapCanvas, assetOverrides = {}) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.state = 'menu';

        this.config = new ConfigManager();
        this.assets = new AssetManager();
        this.audio = new AudioManager(this.config);
        this.input = new InputManager(canvas);
        this.cursor = new Cursor(canvas);

        this.camera = new Camera(canvas.clientWidth, canvas.clientHeight);
        this.world = new World(20240101, assetOverrides);
        this._registerWorldAssets();

        const villageCenter = this.world.map.villageCenterWorld;
        this.camera.x = this.camera.targetX = villageCenter.x;
        this.camera.y = this.camera.targetY = villageCenter.y - 200;
        this.camera.zoom = this.camera.targetZoom = this.config.get('defaultZoom', 1.0);
        this.camera.setMapBounds(this.world.map.pixelWidth, this.world.map.pixelHeight);

        this.renderer = new Renderer();
        this.hud = new HUD(this.config);
        this.minimap = new Minimap(minimapCanvas, this.world);
        this.mainMenu = new MainMenu({
            config: this.config,
            audio: this.audio,
            onPlay: () => this._startPlaying(),
        });

        this._menuDriftAngle = 0;
        this._prevF3 = false;
        this._dpr = Math.min(window.devicePixelRatio || 1, 2);

        this._resize();
        window.addEventListener('resize', () => this._resize());

        this.mainMenu.show();
        document.body.classList.remove('playing');

        this.loop = new GameLoop((dt) => this._tick(dt));
    }

    // Vuelca los canvases procedurales generados por el mundo en el AssetManager con
    // claves descriptivas. El Renderer sigue leyendo directamente de world.*Art (más
    // simple mientras todo es procedural), pero el registro ya existe: el día que
    // lleguen PNG/WEBP reales basta con `assets.registerImage(key, url)` para esa
    // key concreta y nada más del motor tiene que cambiar.
    _registerWorldAssets() {
        this.assets.registerCanvas('building_dojo', this.world.villageArt.dojo);
        this.world.villageArt.houses.forEach((h, i) => this.assets.registerCanvas(`building_house_${i}`, h));
        this.assets.registerCanvas('building_watchtower', this.world.villageArt.tower);
        this.assets.registerCanvas('env_lantern', this.world.villageArt.lantern);
    }

    _startPlaying() {
        this.state = 'playing';
        document.body.classList.add('playing');
    }

    start() {
        this.loop.start();
    }

    _tick(dt) {
        this._update(dt);
        this._render();
        this.input.endFrame();
    }

    _update(dt) {
        if (this.state === 'menu') {
            this._updateMenuCameraDrift(dt);
        } else {
            this._updateGameplayCamera(dt);
        }
        this.camera.update(dt);

        const mouseWorld = this.camera.screenToWorld(this.input.mouse.x, this.input.mouse.y);
        this.world.update(dt, this.camera);

        if (this.state === 'playing') {
            this.cursor.update(this.input);
            this.hud.update(dt, this.camera, this.world.map, mouseWorld);
            this.minimap.render(this.camera);

            const f3 = this.input.isKeyDown('F3');
            if (f3 && !this._prevF3) this.hud.toggleDebug();
            this._prevF3 = f3;
        }
    }

    // Paneo lento y automático alrededor de la aldea mientras se ve el menú: es lo
    // que hace que el fondo desenfocado del menú se sienta vivo en vez de una
    // captura estática.
    _updateMenuCameraDrift(dt) {
        this._menuDriftAngle += dt * 0.05;
        const center = this.world.map.villageCenterWorld;
        const radius = 260;
        this.camera.targetX = center.x + Math.cos(this._menuDriftAngle) * radius;
        this.camera.targetY = center.y + Math.sin(this._menuDriftAngle) * radius * 0.5 - 60;
        this.camera.targetZoom = 1.05;
    }

    _updateGameplayCamera(dt) {
        const input = this.input;
        const speed = (CAMERA_PAN_SPEED / this.camera.targetZoom) * dt;

        let dx = 0;
        let dy = 0;
        if (input.isKeyDown('KeyW', 'ArrowUp')) dy -= 1;
        if (input.isKeyDown('KeyS', 'ArrowDown')) dy += 1;
        if (input.isKeyDown('KeyA', 'ArrowLeft')) dx -= 1;
        if (input.isKeyDown('KeyD', 'ArrowRight')) dx += 1;
        if (dx !== 0 || dy !== 0) {
            const len = Math.hypot(dx, dy);
            this.camera.panTarget((dx / len) * speed, (dy / len) * speed);
        }

        if (input.mouse.dragging && (input.mouse.dragDX !== 0 || input.mouse.dragDY !== 0)) {
            this.camera.panTarget(-input.mouse.dragDX / this.camera.targetZoom, -input.mouse.dragDY / this.camera.targetZoom);
        }

        if (input.mouse.wheelDelta !== 0) {
            this.camera.zoomStepAt(input.mouse.x, input.mouse.y, input.mouse.wheelDelta > 0 ? -1 : 1);
        }
    }

    _render() {
        this.renderer.render(this.ctx, this.canvas.clientWidth, this.canvas.clientHeight, this.camera, this.world);
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
