// Utilidad de dibujado compartida por toda entidad/decoración con sprite: centraliza
// escala, rotación, flip, alpha y sombra proyectada en un solo sitio. Hoy los
// "sprites" son canvases procedurales; el día que sean PNG/WEBP/spritesheets reales,
// esta es la única función que necesitaría (si acaso) tocarse — Building, Decoration
// y el resto del mundo ya dibujan a través de ella, nunca con drawImage directo.
export function drawSprite(ctx, camera, image, worldX, worldY, opts = {}) {
    if (!image) return;
    const {
        scale = 1,
        rotation = 0,
        flipX = false,
        flipY = false,
        alpha = 1,
        anchorX = 0.5,
        anchorY = 1,
        shadowSprite = null,
        shadowScale = 1,
    } = opts;

    const screen = camera.worldToScreen(worldX, worldY);
    const w = image.width * scale * camera.zoom;
    const h = image.height * scale * camera.zoom;

    if (shadowSprite) {
        const sw = w * 0.95 * shadowScale;
        const sh = shadowSprite.height * (sw / shadowSprite.width);
        ctx.drawImage(shadowSprite, screen.x - sw / 2, screen.y - sh * 0.4, sw, sh);
    }

    ctx.save();
    ctx.globalAlpha = alpha;
    ctx.translate(screen.x, screen.y);
    if (rotation) ctx.rotate(rotation);
    ctx.scale(flipX ? -1 : 1, flipY ? -1 : 1);
    ctx.drawImage(image, -w * anchorX, -h * anchorY, w, h);
    ctx.restore();
}
