package com.hrmediasports.studio.content

import kotlinx.serialization.json.Json

/** Dónde se guardan la biblioteca y las listas (solo referencias y ajustes; nunca los archivos). */
interface ContentStore {
    fun load(): ContentState
    fun save(state: ContentState)
}

object ContentJson {
    private val json = Json { ignoreUnknownKeys = true; encodeDefaults = true }

    fun encode(state: ContentState): String = json.encodeToString(ContentState.serializer(), state)

    /** Nunca lanza: un archivo dañado o de otra versión deja la biblioteca vacía en lugar de cerrar la app. */
    fun decode(text: String?): ContentState {
        if (text.isNullOrBlank()) return ContentState()
        return try {
            json.decodeFromString(ContentState.serializer(), text)
        } catch (e: Exception) {
            ContentState()
        }
    }
}

class InMemoryContentStore(private var state: ContentState = ContentState()) : ContentStore {
    var saves = 0
        private set
    override fun load() = state
    override fun save(state: ContentState) { this.state = state; saves++ }
}
