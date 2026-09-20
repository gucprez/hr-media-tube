/**
 * Loads and indexes the asset manifest (assets/asset-manifest.json).
 * The renderer/world never hardcode file paths or per-asset logic; they always
 * go through this manifest + the AssetManager.
 */
export class AssetManifest {
  constructor() {
    this.entries = new Map();
    this.categories = new Map();
  }

  async load(url) {
    let list = [];
    try {
      const res = await fetch(url);
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      list = await res.json();
      if (!Array.isArray(list)) throw new Error('Manifest root must be an array');
    } catch (err) {
      console.error(`[AssetManifest] Failed to load "${url}":`, err.message);
      list = [];
    }

    for (const entry of list) {
      if (!entry || !entry.id || !entry.file || !entry.category) {
        console.warn('[AssetManifest] Skipping invalid manifest entry:', entry);
        continue;
      }
      if (this.entries.has(entry.id)) {
        console.warn(`[AssetManifest] Duplicate asset id "${entry.id}" - keeping first definition.`);
        continue;
      }
      this.entries.set(entry.id, entry);
      if (!this.categories.has(entry.category)) this.categories.set(entry.category, []);
      this.categories.get(entry.category).push(entry);
    }
    return this;
  }

  get(id) {
    return this.entries.get(id) ?? null;
  }

  getByCategory(category) {
    return this.categories.get(category) ?? [];
  }

  getByCategories(categoryList) {
    return categoryList.flatMap((c) => this.getByCategory(c));
  }

  allCategories() {
    return [...this.categories.keys()];
  }

  all() {
    return [...this.entries.values()];
  }
}
