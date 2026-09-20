/**
 * Canonical empty map + validation for the JSON map schema (see README / spec section 23).
 */
export function createEmptyMap(name = 'Untitled Map') {
  const now = new Date().toISOString();
  return {
    version: 1,
    metadata: {
      id: `map_${Date.now()}`,
      name,
      author: '',
      created: now,
      modified: now
    },
    world: { width: 6000, height: 4000 },
    terrain: [{ type: 'grass', x: 0, y: 0, width: 6000, height: 4000 }],
    water: [],
    paths: [],
    objects: [],
    bridges: [],
    buildSlots: [],
    bases: [],
    settings: { gridSize: 25, snapEnabled: true }
  };
}

const REQUIRED_SECTIONS = ['world', 'terrain', 'water', 'paths', 'objects', 'bridges', 'buildSlots', 'bases'];

/**
 * Validates a raw parsed map object. Missing sections are recoverable (warnings,
 * filled with defaults by MapSerializer.normalize). Duplicate/missing IDs and a
 * malformed world block are fatal - the caller must not load such a map.
 */
export function validateMapData(data) {
  const errors = [];
  const warnings = [];

  if (!data || typeof data !== 'object' || Array.isArray(data)) {
    return { fatal: true, errors: ['Map file is not a valid JSON object.'], warnings };
  }

  if (!data.world || typeof data.world.width !== 'number' || typeof data.world.height !== 'number') {
    errors.push('Missing or invalid "world" size (expected { width, height }).');
  }

  for (const key of REQUIRED_SECTIONS) {
    if (data[key] === undefined) warnings.push(`Missing "${key}" section - using empty default.`);
    else if (key !== 'world' && !Array.isArray(data[key])) errors.push(`"${key}" must be an array.`);
  }

  const seenIds = new Set();
  const collections = [
    ...(Array.isArray(data.objects) ? data.objects : []),
    ...(Array.isArray(data.bridges) ? data.bridges : []),
    ...(Array.isArray(data.bases) ? data.bases : []),
    ...(Array.isArray(data.buildSlots) ? data.buildSlots : []),
    ...(Array.isArray(data.paths) ? data.paths : [])
  ];
  for (const entry of collections) {
    if (!entry || !entry.id) {
      errors.push('An entry is missing a required "id" field.');
      continue;
    }
    if (seenIds.has(entry.id)) errors.push(`Duplicate ID found: "${entry.id}".`);
    seenIds.add(entry.id);
  }

  return { fatal: errors.length > 0, errors, warnings };
}
