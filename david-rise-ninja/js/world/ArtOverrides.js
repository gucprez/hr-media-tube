import { tryLoadImage } from '../core/AssetManager.js';

// Manifiesto de piezas de arte "sustituibles": cada entrada nombra dónde debería
// vivir el PNG/WEBP definitivo, cuánto debe medir en el mundo (el motor calcula la
// escala a partir de eso, no importa la resolución del archivo) y si esa imagen ya
// trae su propia sombra pintada (en cuyo caso el motor NO dibuja además la sombra
// procedural, para no duplicarla).
//
// Mientras el archivo no exista, `resolveArtOverrides()` simplemente no lo incluye
// en el resultado y el mundo sigue usando el placeholder de Canvas — no hay que
// tocar nada más para que el juego siga funcionando.
export const ART_MANIFEST = {
    dojo: {
        url: 'assets/village/buildings/dojo.png',
        targetWidth: 260, // ancho deseado en píxeles de mundo (a zoom 1)
        anchorY: 0.94, // fracción del sprite, desde arriba, que toca el suelo
        hasOwnShadow: true,
    },
};

// Comprueba en paralelo qué piezas del manifiesto ya existen como imagen real y
// devuelve sólo esas, listas para usar (con la escala ya calculada).
export async function resolveArtOverrides(manifest = ART_MANIFEST) {
    const entries = Object.entries(manifest);
    const results = await Promise.all(
        entries.map(async ([key, spec]) => {
            const image = await tryLoadImage(spec.url);
            if (!image) return null;
            return [
                key,
                {
                    image,
                    scale: spec.targetWidth / image.naturalWidth,
                    anchorY: spec.anchorY,
                    hasOwnShadow: spec.hasOwnShadow,
                },
            ];
        })
    );
    return Object.fromEntries(results.filter(Boolean));
}
