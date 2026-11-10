package com.hrmediasports.studio.content

/** Lo que se muestra en un momento dado mientras un descanso está al aire. */
sealed interface Showing {
    val title: String

    /** Un elemento de la lista. */
    data class Item(val index: Int, val entry: PlaylistEntry, val media: MediaItem) : Showing {
        override val title get() = media.name
    }

    /** Placa de respaldo: el archivo de esta posición falló. La lista sigue sola. */
    data class Fallback(val index: Int, val mediaName: String, val reason: String) : Showing {
        override val title get() = "Placa de respaldo"
    }

    /** Placa de espera: la lista terminó, estaba vacía o el operador la detuvo. */
    data class Hold(val text: String) : Showing {
        override val title get() = "Placa de espera"
    }
}

data class BreakState(
    val playlist: Playlist,
    val showing: Showing,
    val elapsedMs: Long = 0,
    /** Duración de lo que se muestra; null = indefinida (placa de espera). */
    val durationMs: Long? = null,
    val paused: Boolean = false,
) {
    val remainingMs: Long? get() = durationMs?.let { (it - elapsedMs).coerceAtLeast(0) }
}

sealed interface PlayerEvent {
    /** Aviso para el operador (no bloquea). */
    data class Notice(val text: String) : PlayerEvent

    /** La lista terminó y pidió volver sola a la cámara. */
    data object AutoReturn : PlayerEvent
}

data class Step(val state: BreakState, val events: List<PlayerEvent> = emptyList())

/**
 * Secuenciador de una lista de descanso. Es lógica pura y determinista: decide QUÉ debe estar al
 * aire y cuánto, pero no reproduce nada. En la entrega 1 lo mueve un reloj (demostración); en la
 * entrega 2 lo moverá el motor audiovisual con sus propios eventos ([itemFailed], fin de video).
 */
class BreakPlayer(private val library: (String) -> MediaItem?) {

    /** Poner al aire: SIEMPRE empieza desde el comienzo de la lista. */
    fun start(playlist: Playlist): Step = enter(playlist, 0, emptyList())

    /** Avanza el tiempo. En pausa no avanza. */
    fun tick(state: BreakState, dtMs: Long): Step {
        if (state.paused || state.durationMs == null) return Step(state)
        val elapsed = state.elapsedMs + dtMs
        return if (elapsed < state.durationMs) Step(state.copy(elapsedMs = elapsed)) else advance(state, emptyList())
    }

    fun togglePause(state: BreakState): Step = Step(state.copy(paused = !state.paused))

    /** Siguiente elemento (desde una placa de espera vuelve a empezar la lista). */
    fun next(state: BreakState): Step = when (state.showing) {
        is Showing.Hold -> if (state.playlist.entries.isEmpty()) Step(state) else enter(state.playlist, 0, emptyList())
        else -> advance(state.copy(paused = false), emptyList(), manual = true)
    }

    /** Detener: deja la placa de espera al aire (no vuelve a cámara; eso es «Volver al partido»). */
    fun stop(state: BreakState): Step = Step(hold(state.playlist))

    /** El motor (o la validación) informa que el elemento actual no pudo mostrarse. */
    fun itemFailed(state: BreakState, reason: String): Step {
        val s = state.showing as? Showing.Item ?: return Step(state)
        return Step(
            state.copy(showing = Showing.Fallback(s.index, s.media.name, reason), elapsedMs = 0, durationMs = FALLBACK_MS, paused = false),
            listOf(PlayerEvent.Notice("«${s.media.name}» falló: $reason. Se muestra la placa de respaldo y la lista continúa.")),
        )
    }

    /** Nombre de lo que viene después, para mostrarlo al operador. */
    fun nextTitle(state: BreakState): String {
        val p = state.playlist
        val i = when (val s = state.showing) {
            is Showing.Item -> s.index
            is Showing.Fallback -> s.index
            is Showing.Hold -> return if (p.entries.isEmpty()) "—" else "Inicio de la lista (con «Siguiente»)"
        }
        val n = i + 1
        return when {
            n < p.entries.size -> library(p.entries[n].mediaId)?.name ?: "Elemento ${n + 1} (no disponible)"
            p.loop -> library(p.entries[0].mediaId)?.name ?: "Elemento 1 (no disponible)"
            p.autoReturn -> "Volver a la cámara (automático)"
            else -> "Placa de espera"
        }
    }

    private fun advance(state: BreakState, events: List<PlayerEvent>, manual: Boolean = false): Step {
        val p = state.playlist
        val i = when (val s = state.showing) {
            is Showing.Item -> s.index
            is Showing.Fallback -> s.index
            is Showing.Hold -> return Step(state, events)
        }
        val n = i + 1
        return when {
            n < p.entries.size -> enter(p, n, events)
            p.loop -> enter(p, 0, events)
            // «Siguiente» manual en el último elemento no dispara el retorno automático: deja la placa.
            p.autoReturn && !manual -> Step(hold(p), events + PlayerEvent.AutoReturn)
            else -> Step(hold(p), events)
        }
    }

    private fun enter(p: Playlist, index: Int, events: List<PlayerEvent>): Step {
        if (p.entries.isEmpty()) return Step(hold(p), events)
        val entry = p.entries[index]
        val media = library(entry.mediaId)
        val problem = when {
            media == null -> "ya no está en la biblioteca"
            media.compat == Compat.Missing -> "no se encuentra el archivo"
            media.compat == Compat.Unsupported -> media.compatNote ?: "formato no compatible"
            media.compat == Compat.Unchecked -> "no se pudo validar"
            media.type == MediaType.Video && (media.durationMs ?: 0) <= 0 -> "no informa su duración"
            else -> null
        }
        if (media == null || problem != null) {
            val name = media?.name ?: "Elemento ${index + 1}"
            return Step(
                BreakState(p, Showing.Fallback(index, name, problem ?: "no disponible"), durationMs = FALLBACK_MS),
                events + PlayerEvent.Notice("«$name» no se puede mostrar: $problem. Se muestra la placa de respaldo y la lista continúa."),
            )
        }
        val duration = if (media.type == MediaType.Image) p.imageSeconds * 1000L else media.durationMs!!
        return Step(BreakState(p, Showing.Item(index, entry, media), durationMs = duration), events)
    }

    private fun hold(p: Playlist) = BreakState(p, Showing.Hold(p.slateText.ifBlank { "Volvemos en breve" }))

    companion object {
        /** Tiempo que se muestra la placa de respaldo antes de seguir con la lista. */
        const val FALLBACK_MS = 5_000L
    }
}

/** Mezcla de audio hacia la salida. En la entrega 1 es solo el estado deseado: no hay motor que lo aplique. */
data class AudioMix(val matchGain: Float = 1f, val adGain: Float = 0f)

object AudioRules {
    const val REDUCED_GAIN = 0.2f

    /** Mezcla mientras un anuncio está AL AIRE. La Vista previa nunca aporta audio a la salida. */
    fun duringBreak(previous: AudioMix, policy: MatchAudioPolicy): AudioMix = when (policy) {
        MatchAudioPolicy.Keep -> previous.copy(adGain = 1f)
        MatchAudioPolicy.Reduce -> previous.copy(matchGain = minOf(previous.matchGain, REDUCED_GAIN), adGain = 1f)
        MatchAudioPolicy.Mute -> previous.copy(matchGain = 0f, adGain = 1f)
    }
}
