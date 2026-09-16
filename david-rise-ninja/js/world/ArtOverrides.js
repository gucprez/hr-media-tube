import { tryLoadImage } from '../core/AssetManager.js';

// Manifiesto de piezas de arte "sustituibles". Cada entrada dice dónde debería vivir
// el PNG/WEBP definitivo y con qué escala dibujarlo en el mundo.
//
// Importante sobre `scale`: NO normalizamos cada imagen a un ancho/alto objetivo
// individual (eso destruiría las proporciones relativas entre variantes: un árbol
// grande y uno pequeño de la misma hoja de sprites se dibujarían del mismo tamaño).
// En su lugar aplicamos una única escala uniforme por categoría — igual que si todas
// las piezas vinieran de la misma "cámara" isométrica del ilustrador, que es
// literalmente el caso — así que las proporciones originales del arte se conservan.
//
// Mientras un archivo no exista, `resolveArtOverrides()` simplemente lo omite del
// resultado y esa pieza sigue usando el placeholder de Canvas: no hay ninguna ruta
// que se pueda romper mientras se suben los PNG poco a poco.
export const ART_MANIFEST = {
    dojo: {
        kind: 'single',
        url: 'assets/village/buildings/dojo.webp',
        scale: 0.24,
        anchorY: 0.95,
        hasOwnShadow: true,
    },
    houses: {
        kind: 'array',
        urls: [0, 1, 2, 3, 4, 5].map((i) => `assets/village/buildings/house_${i}.webp`),
        scale: 0.35,
        anchorY: 0.93,
        hasOwnShadow: true,
    },
    lanterns: {
        kind: 'array',
        urls: Array.from({ length: 10 }, (_, i) => `assets/village/props/lantern_${i}.webp`),
        scale: 0.16,
        anchorY: 0.97,
        hasOwnShadow: false,
    },
    bridge: {
        kind: 'single',
        url: 'assets/environment/bridges/bridge.webp',
        scale: 0.52,
        anchorY: 0.5,
        hasOwnShadow: true,
    },
    trees: {
        kind: 'array',
        urls: Array.from({ length: 13 }, (_, i) => `assets/environment/trees/tree_${String(i).padStart(2, '0')}.webp`),
        scale: 0.48,
        anchorY: 0.96,
        hasOwnShadow: true,
    },
    rocks: {
        kind: 'array',
        urls: Array.from({ length: 15 }, (_, i) => `assets/environment/rocks/rock_${String(i).padStart(2, '0')}.webp`),
        scale: 0.32,
        anchorY: 0.94,
        hasOwnShadow: true,
    },
    grassTexture: { kind: 'single', url: 'assets/environment/terrain/grass_texture.webp' },
    waterTexture: { kind: 'single', url: 'assets/environment/water/water_texture.webp' },
    pathTexture: { kind: 'single', url: 'assets/environment/terrain/dirt_path_texture.webp' },

    towers: {
        kind: 'array',
        urls: Array.from({ length: 5 }, (_, i) => `assets/village/buildings/tower_${i}.webp`),
        scale: 0.42,
        anchorY: 0.96,
        hasOwnShadow: true,
    },
    gate: {
        kind: 'single',
        url: 'assets/village/buildings/gate.webp',
        scale: 1.85,
        anchorY: 0.92,
        hasOwnShadow: true,
    },
    fences: {
        kind: 'array',
        urls: Array.from({ length: 10 }, (_, i) => `assets/village/props/fence_${i}.webp`),
        scale: 0.35,
        anchorY: 0.92,
        hasOwnShadow: true,
    },
    benches: {
        kind: 'array',
        urls: Array.from({ length: 4 }, (_, i) => `assets/village/props/bench_${i}.webp`),
        scale: 0.34,
        anchorY: 0.9,
        hasOwnShadow: true,
    },
    barrels: {
        kind: 'array',
        urls: Array.from({ length: 7 }, (_, i) => `assets/village/props/barrel_${i}.webp`),
        scale: 0.3,
        anchorY: 0.92,
        hasOwnShadow: true,
    },
    crates: {
        kind: 'array',
        urls: Array.from({ length: 6 }, (_, i) => `assets/village/props/crate_${i}.webp`),
        scale: 0.3,
        anchorY: 0.92,
        hasOwnShadow: true,
    },
    chests: {
        kind: 'array',
        urls: Array.from({ length: 5 }, (_, i) => `assets/village/props/chest_${i}.webp`),
        scale: 0.32,
        anchorY: 0.92,
        hasOwnShadow: true,
    },
    buckets: {
        kind: 'array',
        urls: Array.from({ length: 2 }, (_, i) => `assets/village/props/bucket_${i}.webp`),
        scale: 0.2,
        anchorY: 0.95,
        hasOwnShadow: true,
    },
    rope: {
        kind: 'single',
        url: 'assets/village/props/rope.webp',
        scale: 0.37,
        anchorY: 0.85,
        hasOwnShadow: true,
    },
    sign: {
        kind: 'single',
        url: 'assets/village/props/sign.webp',
        scale: 0.41,
        anchorY: 0.95,
        hasOwnShadow: true,
    },
    weaponRack: {
        kind: 'single',
        url: 'assets/village/props/weaponrack.webp',
        scale: 0.44,
        anchorY: 0.95,
        hasOwnShadow: true,
    },
    banner: {
        kind: 'single',
        url: 'assets/village/props/banner.webp',
        scale: 0.5,
        anchorY: 0.95,
        hasOwnShadow: true,
    },
    scarecrow: {
        kind: 'single',
        url: 'assets/village/props/scarecrow.webp',
        scale: 0.51,
        anchorY: 0.95,
        hasOwnShadow: true,
    },
    shuriken: {
        kind: 'single',
        url: 'assets/village/props/shuriken.webp',
        scale: 0.32,
        anchorY: 0.9,
        hasOwnShadow: true,
    },
    logs: {
        kind: 'array',
        urls: Array.from({ length: 8 }, (_, i) => `assets/environment/deadwood/log_${i}.webp`),
        scale: 0.26,
        anchorY: 0.85,
        hasOwnShadow: true,
    },
    reeds: {
        kind: 'array',
        urls: Array.from({ length: 10 }, (_, i) => `assets/environment/vegetation/reed_${i}.webp`),
        scale: 0.26,
        anchorY: 0.95,
        hasOwnShadow: true,
    },
    grassTufts: {
        kind: 'array',
        urls: Array.from({ length: 4 }, (_, i) => `assets/environment/vegetation/grasstuft_${i}.webp`),
        scale: 0.23,
        anchorY: 0.95,
        hasOwnShadow: true,
    },
    bamboo: {
        kind: 'single',
        url: 'assets/environment/vegetation/bamboo_0.webp',
        scale: 0.71,
        anchorY: 0.95,
        hasOwnShadow: true,
    },
    waterPlants: {
        kind: 'array',
        urls: [
            ...Array.from({ length: 10 }, (_, i) => `assets/environment/vegetation/lily_${i}.webp`),
            ...Array.from({ length: 2 }, (_, i) => `assets/environment/vegetation/iris_${i}.webp`),
            ...Array.from({ length: 8 }, (_, i) => `assets/environment/vegetation/watergrass_${i}.webp`),
        ],
        scale: 0.3,
        anchorY: 0.8,
        hasOwnShadow: true,
    },
    moss: {
        kind: 'array',
        urls: Array.from({ length: 2 }, (_, i) => `assets/environment/vegetation/moss_${i}.webp`),
        scale: 0.27,
        anchorY: 0.6,
        hasOwnShadow: true,
    },
};

async function resolveEntry(spec) {
    if (spec.kind === 'single') {
        const image = await tryLoadImage(spec.url);
        if (!image) return null;
        return { image, scale: spec.scale, anchorY: spec.anchorY, hasOwnShadow: spec.hasOwnShadow };
    }

    // 'array': todas deben existir para activar el reemplazo — una hoja de sprites a
    // medias (p.ej. 4 de 13 árboles) se queda en el placeholder hasta completarse,
    // para no mezclar arte real y procedural dentro del mismo tipo de decoración.
    const images = await Promise.all(spec.urls.map(tryLoadImage));
    if (images.some((img) => !img)) return null;
    return { images, scale: spec.scale, anchorY: spec.anchorY, hasOwnShadow: spec.hasOwnShadow };
}

export async function resolveArtOverrides(manifest = ART_MANIFEST) {
    const entries = Object.entries(manifest);
    const resolved = await Promise.all(entries.map(async ([key, spec]) => [key, await resolveEntry(spec)]));
    return Object.fromEntries(resolved.filter(([, value]) => value !== null));
}
