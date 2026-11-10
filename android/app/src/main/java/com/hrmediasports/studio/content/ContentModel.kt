package com.hrmediasports.studio.content

import kotlinx.serialization.Serializable

/**
 * Contenido para descansos, medio tiempo y tiempos muertos. Módulo independiente del reconocimiento
 * de pizarra: no comparte estado con él. Todo aquí es Kotlin puro (sin Android) para poder probarlo.
 */
@Serializable
enum class MediaType(val label: String) { Image("Imagen"), Video("Video") }

/** Resultado de validar un archivo antes de usarlo. */
@Serializable
enum class Compat(val label: String) {
    Unchecked("Sin validar"),
    Ok("Compatible"),
    Unsupported("No compatible"),
    Missing("Archivo no encontrado"),
}

/** Referencia a un archivo local elegido con el selector del sistema. El archivo original nunca se copia ni se borra. */
@Serializable
data class MediaItem(
    val id: String,
    val uri: String,
    val name: String,
    val type: MediaType,
    val durationMs: Long? = null,
    val width: Int? = null,
    val height: Int? = null,
    val mime: String? = null,
    val compat: Compat = Compat.Unchecked,
    val compatNote: String? = null,
    /** true/false si el sistema informó un decodificador por hardware para este video; null si no se sabe. */
    val hardwareDecoder: Boolean? = null,
) {
    val playable: Boolean get() = compat == Compat.Ok
}

@Serializable
data class PlaylistEntry(val entryId: String, val mediaId: String, val showScoreboard: Boolean = false)

@Serializable
enum class Template(val label: String) {
    HalfTime("Medio tiempo"),
    Timeout("Tiempo muerto"),
    BackSoon("Volvemos en breve"),
}

@Serializable
data class Playlist(
    val id: String,
    val name: String,
    val template: Template? = null,
    val entries: List<PlaylistEntry> = emptyList(),
    /** Segundos que se muestra cada imagen. */
    val imageSeconds: Int = 8,
    val loop: Boolean = false,
    /** Al terminar la lista, volver solo a la cámara. Si es false, queda la placa de espera. */
    val autoReturn: Boolean = false,
    /** Texto de la placa de espera cuando la lista termina (o está vacía). */
    val slateText: String = "Volvemos en breve",
) {
    companion object {
        const val MIN_IMAGE_SECONDS = 1
        const val MAX_IMAGE_SECONDS = 120
    }
}

@Serializable
enum class OverlayKind(val label: String) { Logo("Logo"), Strip("Franja") }

/** Logo o franja de patrocinador para dibujar sobre la cámara (requiere el motor de composición, aún inexistente). */
@Serializable
data class SponsorOverlay(val id: String, val mediaId: String, val kind: OverlayKind, val enabled: Boolean = false)

/** Qué hacer con el audio del partido mientras hay un anuncio al aire. */
@Serializable
enum class MatchAudioPolicy(val label: String) { Keep("Mantener"), Reduce("Reducir"), Mute("Silenciar") }

@Serializable
data class ContentState(
    val version: Int = 1,
    val library: List<MediaItem> = emptyList(),
    val playlists: List<Playlist> = emptyList(),
    val overlays: List<SponsorOverlay> = emptyList(),
    val audioPolicy: MatchAudioPolicy = MatchAudioPolicy.Reduce,
) {
    fun media(id: String): MediaItem? = library.firstOrNull { it.id == id }
    fun playlist(id: String?): Playlist? = playlists.firstOrNull { it.id == id }
}

/** Formatea milisegundos como m:ss. */
fun formatDuration(ms: Long): String {
    val s = (ms.coerceAtLeast(0) + 999) / 1000
    return "%d:%02d".format(java.util.Locale.ROOT, s / 60, s % 60)
}
