import { TILE_SIZE } from '../world/Terrain.js';
import { drawSprite } from './SpriteRenderer.js';

// Pipeline de dibujado: lienzo de terreno pre-pintado (sin cuadrícula, ver
// TerrainPainter) -> capa de profundidad (decoraciones + edificios, ya ordenada por
// Y) -> brillo animado del río -> partículas -> glow cálido de la aldea -> luz
// direccional de escena. Todo con culling por viewport.
export class Renderer {
    render(ctx, canvasW, canvasH, camera, world) {
        ctx.clearRect(0, 0, canvasW, canvasH);
        ctx.fillStyle = '#05100a';
        ctx.fillRect(0, 0, canvasW, canvasH);

        this._renderTerrain(ctx, camera, world);
        this._renderDepthList(ctx, camera, world);
        this._renderRiverShimmer(ctx, camera, world);
        world.particles.render(ctx, camera);
        this._renderVillageGlow(ctx, camera, world);
        this._renderDirectionalLight(ctx, canvasW, canvasH);
        this._renderVignette(ctx, canvasW, canvasH);
    }

    // El mapa entero ya está pintado en un único canvas continuo (TerrainPainter);
    // aquí sólo se recorta y dibuja la porción visible, igual que un motor real
    // recortaría un "world texture atlas". Nada de dibujar tile a tile cada frame.
    _renderTerrain(ctx, camera, world) {
        const big = world.terrainPainter.canvas;
        const view = camera.getVisibleWorldRect(TILE_SIZE);
        const sx = Math.max(0, view.left);
        const sy = Math.max(0, view.top);
        const sw = Math.min(big.width, view.right) - sx;
        const sh = Math.min(big.height, view.bottom) - sy;
        if (sw <= 0 || sh <= 0) return;

        const dst = camera.worldToScreen(sx, sy);
        ctx.drawImage(big, sx, sy, sw, sh, dst.x, dst.y, sw * camera.zoom, sh * camera.zoom);
    }

    _renderDepthList(ctx, camera, world) {
        const view = camera.getVisibleWorldRect(160);
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

            drawSprite(ctx, camera, sprite, deco.x, deco.y, {
                scale: deco.scale,
                rotation: deco.rotation || 0,
                flipX: deco.flip < 0,
                anchorY: 0.92,
                shadowSprite: world.decorationArt.shadow,
                shadowScale: 1.1,
            });
        }
    }

    // El agua base ya está pintada en el lienzo estático; encima se dibuja, sólo en
    // la franja visible, una fila de destellos que se desplazan con el tiempo — es
    // la parte que de verdad necesita animarse frame a frame.
    _renderRiverShimmer(ctx, camera, world) {
        const map = world.map;
        const view = camera.getVisibleWorldRect(64);
        const minX = Math.max(0, Math.floor(view.left / TILE_SIZE) - 1);
        const maxX = Math.min(map.width, Math.ceil(view.right / TILE_SIZE) + 1);
        const t = world.elapsedTime;

        ctx.save();
        ctx.lineWidth = Math.max(1, 1.6 * camera.zoom);
        for (let row = -1; row <= 1; row++) {
            ctx.strokeStyle = `rgba(220,242,255,${0.16 - Math.abs(row) * 0.05})`;
            ctx.beginPath();
            let started = false;
            for (let x = minX; x <= maxX; x += 0.5) {
                const { centerY, thickness } = map.riverProfileAt(x);
                const wobble = Math.sin(x * 0.4 + t * 1.6 + row * 2) * thickness * 0.35;
                const wy = (centerY + row * thickness * 0.5 + wobble) * TILE_SIZE;
                const screen = camera.worldToScreen(x * TILE_SIZE, wy);
                started ? ctx.lineTo(screen.x, screen.y) : (ctx.moveTo(screen.x, screen.y), (started = true));
            }
            ctx.stroke();
        }
        ctx.restore();
    }

    // Halo cálido y suave alrededor de la aldea: vende la sensación de "asentamiento
    // acogedor" sin necesitar un sistema de luces real.
    _renderVillageGlow(ctx, camera, world) {
        const center = world.map.villageCenterWorld;
        const screen = camera.worldToScreen(center.x, center.y);
        const radius = 420 * camera.zoom;
        if (!(radius > 0)) return;
        const grad = ctx.createRadialGradient(screen.x, screen.y, radius * 0.1, screen.x, screen.y, radius);
        grad.addColorStop(0, 'rgba(255,214,150,0.14)');
        grad.addColorStop(1, 'rgba(255,214,150,0)');
        ctx.fillStyle = grad;
        ctx.fillRect(screen.x - radius, screen.y - radius, radius * 2, radius * 2);
    }

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
