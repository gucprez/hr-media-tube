/**
 * Loads and caches asset images described by the AssetManifest. Missing/failed
 * image files never crash the app - callers get back a record with
 * loaded=false so the renderer can draw a placeholder instead.
 */
export class AssetManager {
  constructor(manifest, basePath = 'assets/') {
    this.manifest = manifest;
    this.basePath = basePath;
    this.cache = new Map(); // assetId -> { image, loaded, failed, meta }
  }

  preloadAll() {
    return Promise.all(this.manifest.all().map((entry) => this._load(entry)));
  }

  _load(entry) {
    if (this.cache.has(entry.id)) return Promise.resolve(this.cache.get(entry.id));
    const record = { image: null, loaded: false, failed: false, meta: entry };
    this.cache.set(entry.id, record);
    return new Promise((resolve) => {
      const img = new Image();
      img.onload = () => {
        record.image = img;
        record.loaded = true;
        resolve(record);
      };
      img.onerror = () => {
        record.failed = true;
        resolve(record);
      };
      img.src = this.basePath + entry.file;
    });
  }

  /** Synchronous lookup used by render/hit-test code. Triggers a lazy load if needed. */
  get(assetId) {
    const cached = this.cache.get(assetId);
    if (cached) return cached;

    const meta = this.manifest.get(assetId);
    if (!meta) {
      console.error(`[AssetManager] Unknown asset id "${assetId}".`);
      return { image: null, loaded: false, failed: true, meta: null };
    }
    this._load(meta);
    return this.cache.get(assetId);
  }
}
