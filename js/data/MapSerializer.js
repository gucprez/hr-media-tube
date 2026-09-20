import { validateMapData, createEmptyMap } from './MapData.js';

export class MapValidationError extends Error {
  constructor(errors) {
    super(errors.join(' | '));
    this.name = 'MapValidationError';
    this.errors = errors;
  }
}

export class MapSerializer {
  /** Normalizes a raw parsed map object into a complete, defaulted map data object. */
  static normalize(rawData) {
    const { fatal, errors, warnings } = validateMapData(rawData);
    if (fatal) throw new MapValidationError(errors);

    const empty = createEmptyMap();
    const normalized = {
      version: rawData.version ?? 1,
      metadata: { ...empty.metadata, ...(rawData.metadata ?? {}) },
      world: { ...empty.world, ...(rawData.world ?? {}) },
      terrain: rawData.terrain ?? [],
      water: rawData.water ?? [],
      paths: rawData.paths ?? [],
      objects: rawData.objects ?? [],
      bridges: rawData.bridges ?? [],
      buildSlots: rawData.buildSlots ?? [],
      bases: rawData.bases ?? [],
      settings: { ...empty.settings, ...(rawData.settings ?? {}) }
    };

    if (warnings.length) console.warn('[MapSerializer] Map loaded with warnings:', warnings);
    return normalized;
  }

  static download(mapObject, filename = 'map.json') {
    const json = JSON.stringify(mapObject, null, 2);
    const blob = new Blob([json], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename.endsWith('.json') ? filename : `${filename}.json`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  }

  static async loadFromFile(file) {
    const text = await file.text();
    let parsed;
    try {
      parsed = JSON.parse(text);
    } catch (err) {
      throw new MapValidationError([`Invalid JSON: ${err.message}`]);
    }
    return MapSerializer.normalize(parsed);
  }

  static async loadFromUrl(url) {
    const res = await fetch(url);
    if (!res.ok) throw new MapValidationError([`Could not fetch map at "${url}" (HTTP ${res.status}).`]);
    const parsed = await res.json();
    return MapSerializer.normalize(parsed);
  }
}
