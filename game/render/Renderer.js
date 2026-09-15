import { TILE_SIZE } from '../maps/TileTypes.js';
import { hash2i } from '../core/utils.js';
import { TileArtFactory } from './TileArtFactory.js';

const WATER_ANIM_FPS = 6;

// Pipeline de dibujado principal: terreno -> decoraciones (ordenadas por profundidad)
// -> partículas -> overlay de iluminación ambiental. Todo con culling por viewport.
export class Renderer {
    constructor(seed) {
        this.art = new TileArtFactory(seed);
    }

    render(ctx, canvasW, canvasH, camera, gameMap, particleSystem, elapsedTime) {
        ctx.clearRect(0, 0, canvasW, canvasH);

        // Fondo fuera de los límites del mapa (niebla de guerra / vacío del mundo).
        ctx.fillStyle = '#0a0d12';
        ctx.fillRect(0, 0, canvasW, canvasH);

        this._renderTerrain(ctx, camera, gameMap, elapsedTime);
        this._renderDecorations(ctx, camera, gameMap);
        particleSystem.render(ctx, camera);
        this._renderDirectionalLight(ctx, canvasW, canvasH);
        this._renderAmbientLighting(ctx, canvasW, canvasH);
    }

    _renderTerrain(ctx, camera, gameMap, elapsedTime) {
        const view = camera.getVisibleWorldRect();
        const { width, height, tiles } = gameMap;

        const minTX = Math.max(0, Math.floor(view.left / TILE_SIZE) - 1);
        const maxTX = Math.min(width - 1, Math.ceil(view.right / TILE_SIZE) + 1);
        const minTY = Math.max(0, Math.floor(view.top / TILE_SIZE) - 1);
        const maxTY = Math.min(height - 1, Math.ceil(view.bottom / TILE_SIZE) + 1);

        const drawSize = Math.ceil(TILE_SIZE * camera.zoom) + 1;
        const waterFrame = Math.floor(elapsedTime * WATER_ANIM_FPS);

        for (let ty = minTY; ty <= maxTY; ty++) {
            for (let tx = minTX; tx <= maxTX; tx++) {
                const type = gameMap.getTileTypeAt(tx, ty);
                const variant = Math.floor(hash2i(tx, ty, 7) * 4);
                const texture = this.art.getTileTexture(type, variant, waterFrame);
                if (!texture) continue;

                const screen = camera.worldToScreen(tx * TILE_SIZE, ty * TILE_SIZE);
                ctx.drawImage(texture, Math.round(screen.x), Math.round(screen.y), drawSize, drawSize);
            }
        }
    }

    _renderDecorations(ctx, camera, gameMap) {
        const view = camera.getVisibleWorldRect(96);
        const decorations = gameMap.decorations;

        // La lista ya viene ordenada por Y de fábrica; recorrerla en orden basta
        // para lograr profundidad correcta (pseudo 2.5D / painter's algorithm).
        for (const deco of decorations) {
            if (deco.y < view.top || deco.y > view.bottom) continue;
            if (deco.x < view.left || deco.x > view.right) continue;

            const sprite = this.art.getDecorSprite(deco.kind, deco.variant);
            if (!sprite) continue;

            const screen = camera.worldToScreen(deco.x, deco.y);
            const scale = deco.scale * camera.zoom;
            const w = sprite.width * scale;
            const h = sprite.height * scale;

            const shadow = this.art.shadowSprite;
            const shadowScale = (w / shadow.width) * 1.1;
            ctx.drawImage(
                shadow,
                screen.x - (shadow.width * shadowScale) / 2,
                screen.y - shadow.height * shadowScale * 0.42,
                shadow.width * shadowScale,
                shadow.height * shadowScale * 0.55
            );

            ctx.save();
            ctx.translate(screen.x, screen.y - h + h * 0.08);
            if (deco.flip < 0) ctx.scale(-1, 1);
            ctx.drawImage(sprite, -w / 2, 0, w, h);
            ctx.restore();
        }
    }

    // Luz direccional "de sol" aplicada una única vez sobre toda la escena visible
    // (en vez de por-tile) para dar sensación de volumen sin generar costuras
    // visibles en los bordes de cada casilla del mapa.
    _renderDirectionalLight(ctx, canvasW, canvasH) {
        const grad = ctx.createLinearGradient(0, 0, canvasW * 0.6, canvasH * 0.6);
        grad.addColorStop(0, 'rgba(255,248,220,0.07)');
        grad.addColorStop(0.55, 'rgba(255,248,220,0)');
        grad.addColorStop(1, 'rgba(10,14,20,0.10)');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, canvasW, canvasH);
    }

    _renderAmbientLighting(ctx, canvasW, canvasH) {
        const grad = ctx.createRadialGradient(
            canvasW / 2,
            canvasH / 2,
            Math.min(canvasW, canvasH) * 0.25,
            canvasW / 2,
            canvasH / 2,
            Math.max(canvasW, canvasH) * 0.75
        );
        grad.addColorStop(0, 'rgba(0,0,0,0)');
        grad.addColorStop(1, 'rgba(0,0,0,0.35)');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, canvasW, canvasH);
    }
}
