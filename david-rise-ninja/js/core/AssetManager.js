// Registro central de "dibujables" (canvas generados o, en el futuro, imágenes reales).
// El resto del motor sólo pide `assetManager.get('tree_big_1')` y recibe algo que se
// puede pasar a `ctx.drawImage`; no le importa si detrás hay un canvas procedural o un
// PNG cargado desde /assets. El día que lleguen sprites definitivos, basta con llamar
// a `registerImage(key, url)` en vez de `registerCanvas(key, canvas)` para esa key:
// ningún otro archivo del motor necesita cambiar.
export class AssetManager {
    constructor() {
        this.drawables = new Map();
        this.pending = [];
    }

    registerCanvas(key, canvas) {
        this.drawables.set(key, canvas);
        return canvas;
    }

    // Reservado para cuando existan assets reales en /assets/**.
    registerImage(key, url) {
        const img = new Image();
        const promise = new Promise((resolve) => {
            img.onload = () => resolve(img);
            img.onerror = () => resolve(null);
        });
        img.src = url;
        this.drawables.set(key, img);
        this.pending.push(promise);
        return img;
    }

    get(key) {
        return this.drawables.get(key) || null;
    }

    has(key) {
        return this.drawables.has(key);
    }

    async waitForPending() {
        await Promise.all(this.pending);
        this.pending.length = 0;
    }
}
