import { TileType, TILE_SIZE } from '../world/Terrain.js';
import { hash2i } from './utils.js';

const WATER_ANIM_FPS = 6;

// Pipeline de dibujado: terreno -> capa de profundidad (decoraciones + edificios,
// ya ordenada por Y) -> partículas -> luz direccional de escena. Todo con culling
// por viewport para no procesar lo que queda fuera de cámara.
export class Renderer {
    render(ctx, canvasW, canvasH, camera, world) {
        ctx.clearRect(0, 0, canvasW, canvasH);
        ctx.fillStyle = '#05100a';
        ctx.fillRect(0, 0, canvasW, canvasH);

        this._renderTerrain(ctx, camera, world);
        this._renderDepthList(ctx, camera, world);
        world.particles.render(ctx, camera);
        this._renderDirectionalLight(ctx, canvasW, canvasH);
        this._renderVignette(ctx, canvasW, canvasH);
    }

    _renderTerrain(ctx, camera, world) {
        const map = world.map;
        const view = camera.getVisibleWorldRect();
        const minTX = Math.max(0, Math.floor(view.left / TILE_SIZE) - 1);
        const maxTX = Math.min(map.width - 1, Math.ceil(view.right / TILE_SIZE) + 1);
        const minTY = Math.max(0, Math.floor(view.top / TILE_SIZE) - 1);
        const maxTY = Math.min(map.height - 1, Math.ceil(view.bottom / TILE_SIZE) + 1);

        const drawSize = Math.ceil(TILE_SIZE * camera.zoom) + 1;
        const waterFrame = Math.floor(world.elapsedTime * WATER_ANIM_FPS);

        for (let ty = minTY; ty <= maxTY; ty++) {
            for (let tx = minTX; tx <= maxTX; tx++) {
                const type = map.getTileTypeAt(tx, ty);
                const variant = Math.floor(hash2i(tx, ty, 7) * 4);
                const texture = world.terrainArt.getTexture(type, variant, waterFrame);
                if (!texture) continue;
                const screen = camera.worldToScreen(tx * TILE_SIZE, ty * TILE_SIZE);
                ctx.drawImage(texture, Math.round(screen.x), Math.round(screen.y), drawSize, drawSize);
            }
        }
    }

    _renderDepthList(ctx, camera, world) {
        const view = camera.getVisibleWorldRect(140);
        for (const item of world.depthList) {
            if (item.y < view.top || item.y > view.bottom) continue;

            if (item.kind === 'entity') {
                if (item.data.x < view.left || item.data.x > view.right) continue;
                item.data.render(ctx, camera);
                continue;
            }

            const deco = item.data;
            if (deco.x < view.left || deco.x > view.right) continue;
            const sprite = world.decorationArt.get(deco.kind, deco.variant);
            if (!sprite) continue;

            const screen = camera.worldToScreen(deco.x, deco.y);
            const scale = deco.scale * camera.zoom;
            const w = sprite.width * scale;
            const h = sprite.height * scale;

            const shadow = world.decorationArt.shadow;
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

    // Luz "de sol" de tarde cálida aplicada una sola vez sobre toda la escena (no por
    // tile), para dar volumen sin generar costuras entre casillas.
    _renderDirectionalLight(ctx, canvasW, canvasH) {
        const grad = ctx.createLinearGradient(0, 0, canvasW * 0.6, canvasH * 0.6);
        grad.addColorStop(0, 'rgba(255,225,170,0.10)');
        grad.addColorStop(0.55, 'rgba(255,225,170,0)');
        grad.addColorStop(1, 'rgba(20,15,25,0.14)');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, canvasW, canvasH);
    }

    _renderVignette(ctx, canvasW, canvasH) {
        const grad = ctx.createRadialGradient(
            canvasW / 2,
            canvasH / 2,
            Math.min(canvasW, canvasH) * 0.3,
            canvasW / 2,
            canvasH / 2,
            Math.max(canvasW, canvasH) * 0.78
        );
        grad.addColorStop(0, 'rgba(0,0,0,0)');
        grad.addColorStop(1, 'rgba(0,0,0,0.32)');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, canvasW, canvasH);
    }
}
