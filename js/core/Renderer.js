/**
 * Thin canvas driver. It knows nothing about individual asset types - it clears
 * the screen, applies the camera transform, and asks World / editor overlays to
 * draw themselves in world space.
 */
export class Renderer {
  constructor(ctx, camera) {
    this.ctx = ctx;
    this.camera = camera;
  }

  clear() {
    const { ctx, camera } = this;
    ctx.save();
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.fillStyle = '#0b0d10';
    ctx.fillRect(0, 0, camera.viewportWidth, camera.viewportHeight);
    ctx.restore();
  }

  renderFrame(world, assetManager, drawWorldOverlay, drawScreenOverlay) {
    const { ctx, camera } = this;
    this.clear();

    ctx.save();
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    camera.applyTransform(ctx);

    ctx.fillStyle = '#161c14';
    ctx.fillRect(0, 0, world.width, world.height);

    world.render(ctx, assetManager, camera);
    if (drawWorldOverlay) drawWorldOverlay(ctx);
    ctx.restore();

    if (drawScreenOverlay) drawScreenOverlay(ctx);
  }
}
