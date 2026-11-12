package com.hrmediasports.studio.content

/**
 * Operaciones sobre la biblioteca y las listas. Son funciones puras: reciben un estado y devuelven
 * otro. Quitar algo de la biblioteca o de una lista solo quita la REFERENCIA: nunca toca el archivo.
 */
object ContentEditor {

    /** Añade archivos importados; un mismo archivo (misma URI) no se duplica. */
    fun addMedia(state: ContentState, items: List<MediaItem>): ContentState {
        val known = state.library.map { it.uri }.toSet()
        val fresh = items.distinctBy { it.uri }.filter { it.uri !in known }
        return state.copy(library = state.library + fresh)
    }

    /** Quita la referencia de la biblioteca y de las listas y patrocinadores que la usaban. */
    fun removeMedia(state: ContentState, mediaId: String): ContentState = state.copy(
        library = state.library.filterNot { it.id == mediaId },
        playlists = state.playlists.map { p -> p.copy(entries = p.entries.filterNot { it.mediaId == mediaId }) },
        overlays = state.overlays.filterNot { it.mediaId == mediaId },
    )

    /** Lista nueva a partir de una plantilla (vacía de contenido: el operador elige los archivos). */
    fun createFromTemplate(state: ContentState, template: Template, id: String): ContentState {
        val base = when (template) {
            Template.HalfTime -> Playlist(id, uniqueName(state, template.label), template, imageSeconds = 10, loop = true, slateText = "Volvemos en breve")
            Template.Timeout -> Playlist(id, uniqueName(state, template.label), template, imageSeconds = 6, loop = false, slateText = "Tiempo muerto")
            Template.BackSoon -> Playlist(id, uniqueName(state, template.label), template, imageSeconds = 8, loop = false, slateText = "Volvemos en breve")
        }
        return state.copy(playlists = state.playlists + base)
    }

    private fun uniqueName(state: ContentState, base: String): String {
        val names = state.playlists.map { it.name }.toSet()
        if (base !in names) return base
        var n = 2
        while ("$base $n" in names) n++
        return "$base $n"
    }

    fun removePlaylist(state: ContentState, playlistId: String): ContentState =
        state.copy(playlists = state.playlists.filterNot { it.id == playlistId })

    private fun edit(state: ContentState, playlistId: String, f: (Playlist) -> Playlist): ContentState =
        state.copy(playlists = state.playlists.map { if (it.id == playlistId) f(it) else it })

    /** Se guarda lo que se escribe (permite espacios y borrar todo mientras se edita); [displayName] da el nombre a mostrar. */
    fun rename(state: ContentState, playlistId: String, name: String): ContentState =
        edit(state, playlistId) { it.copy(name = name.take(40)) }

    /** Nombre para mostrar: sin espacios sobrantes y con un texto de reserva si quedó vacío. */
    fun displayName(p: Playlist): String = p.name.trim().ifBlank { "Lista sin nombre" }

    /** Reemplaza los datos de archivos ya existentes (por ejemplo tras volver a validarlos). */
    fun updateMedia(state: ContentState, items: List<MediaItem>): ContentState {
        val byId = items.associateBy { it.id }
        return state.copy(library = state.library.map { byId[it.id] ?: it })
    }

    /** Añade un elemento de la biblioteca a la lista (un mismo archivo puede repetirse en la lista). */
    fun addEntry(state: ContentState, playlistId: String, mediaId: String, entryId: String): ContentState {
        if (state.media(mediaId) == null) return state
        return edit(state, playlistId) { it.copy(entries = it.entries + PlaylistEntry(entryId, mediaId)) }
    }

    fun removeEntry(state: ContentState, playlistId: String, entryId: String): ContentState =
        edit(state, playlistId) { it.copy(entries = it.entries.filterNot { e -> e.entryId == entryId }) }

    /** Mueve un elemento una posición arriba (delta = -1) o abajo (+1). Fuera de rango no hace nada. */
    fun moveEntry(state: ContentState, playlistId: String, entryId: String, delta: Int): ContentState = edit(state, playlistId) { p ->
        val from = p.entries.indexOfFirst { it.entryId == entryId }
        val to = from + delta
        if (from < 0 || to !in p.entries.indices) p
        else p.copy(entries = p.entries.toMutableList().apply { add(to, removeAt(from)) })
    }

    fun toggleScoreboard(state: ContentState, playlistId: String, entryId: String): ContentState = edit(state, playlistId) { p ->
        p.copy(entries = p.entries.map { if (it.entryId == entryId) it.copy(showScoreboard = !it.showScoreboard) else it })
    }

    fun setImageSeconds(state: ContentState, playlistId: String, seconds: Int): ContentState =
        edit(state, playlistId) { it.copy(imageSeconds = seconds.coerceIn(Playlist.MIN_IMAGE_SECONDS, Playlist.MAX_IMAGE_SECONDS)) }

    fun setLoop(state: ContentState, playlistId: String, loop: Boolean): ContentState = edit(state, playlistId) { it.copy(loop = loop) }

    fun setAutoReturn(state: ContentState, playlistId: String, on: Boolean): ContentState = edit(state, playlistId) { it.copy(autoReturn = on) }

    fun setSlateText(state: ContentState, playlistId: String, text: String): ContentState =
        edit(state, playlistId) { it.copy(slateText = text.take(60)) }

    fun addOverlay(state: ContentState, mediaId: String, kind: OverlayKind, id: String): ContentState {
        val m = state.media(mediaId) ?: return state
        if (m.type != MediaType.Image) return state          // logos y franjas son imágenes
        return state.copy(overlays = state.overlays + SponsorOverlay(id, mediaId, kind))
    }

    fun toggleOverlay(state: ContentState, overlayId: String): ContentState =
        state.copy(overlays = state.overlays.map { if (it.id == overlayId) it.copy(enabled = !it.enabled) else it })

    fun removeOverlay(state: ContentState, overlayId: String): ContentState =
        state.copy(overlays = state.overlays.filterNot { it.id == overlayId })

    fun setAudioPolicy(state: ContentState, policy: MatchAudioPolicy): ContentState = state.copy(audioPolicy = policy)

    /** Problemas a avisar ANTES de poner la lista al aire (no la bloquean: lo que falle mostrará la placa de respaldo). */
    fun issues(state: ContentState, playlist: Playlist): List<String> {
        val out = mutableListOf<String>()
        if (playlist.entries.isEmpty()) out += "La lista está vacía: se mostrará solo la placa «${playlist.slateText}»."
        playlist.entries.forEachIndexed { i, e ->
            val m = state.media(e.mediaId)
            when {
                m == null -> out += "Elemento ${i + 1}: ya no está en la biblioteca."
                m.compat == Compat.Unsupported -> out += "«${m.name}» no es compatible${m.compatNote?.let { " ($it)" } ?: ""}."
                m.compat == Compat.Missing -> out += "«${m.name}» no se encuentra."
                m.compat == Compat.Unchecked -> out += "«${m.name}» aún no se validó."
                m.type == MediaType.Video && (m.durationMs ?: 0) <= 0 -> out += "«${m.name}» no informa su duración."
            }
        }
        return out
    }

    /** Duración estimada de una pasada de la lista (sin contar elementos que fallarán). */
    fun totalDurationMs(state: ContentState, playlist: Playlist): Long = playlist.entries.sumOf { e ->
        val m = state.media(e.mediaId)
        when {
            m == null || !m.playable -> BreakPlayer.FALLBACK_MS
            m.type == MediaType.Image -> playlist.imageSeconds * 1000L
            else -> m.durationMs ?: BreakPlayer.FALLBACK_MS
        }
    }
}
