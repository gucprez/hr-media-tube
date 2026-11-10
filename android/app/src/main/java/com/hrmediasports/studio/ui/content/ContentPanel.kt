package com.hrmediasports.studio.ui.content

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.ArrowDownward
import androidx.compose.material.icons.filled.ArrowUpward
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Image
import androidx.compose.material.icons.filled.Movie
import androidx.compose.material.icons.filled.Remove
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.produceState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.hrmediasports.studio.content.Compat
import com.hrmediasports.studio.content.ContentEditor
import com.hrmediasports.studio.content.MatchAudioPolicy
import com.hrmediasports.studio.content.MediaItem
import com.hrmediasports.studio.content.MediaType
import com.hrmediasports.studio.content.OverlayKind
import com.hrmediasports.studio.content.Playlist
import com.hrmediasports.studio.content.Template
import com.hrmediasports.studio.content.Thumbnails
import com.hrmediasports.studio.content.formatDuration
import com.hrmediasports.studio.ui.ContentTab
import com.hrmediasports.studio.ui.SourceKind
import com.hrmediasports.studio.ui.StudioUiState
import com.hrmediasports.studio.ui.components.PanelDialog
import com.hrmediasports.studio.ui.components.StatusChip
import com.hrmediasports.studio.ui.theme.HrColors
import com.hrmediasports.studio.ui.theme.HrDimens
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/** Acciones del módulo de contenidos; el estado vive en el ViewModel. */
data class ContentActions(
    val onClose: () -> Unit = {},
    val onTab: (ContentTab) -> Unit = {},
    val onImport: () -> Unit = {},
    val onRemoveMedia: (String) -> Unit = {},
    val onCreate: (Template) -> Unit = {},
    val onEdit: (String?) -> Unit = {},
    val onRemovePlaylist: (String) -> Unit = {},
    val onRename: (String, String) -> Unit = { _, _ -> },
    val onAddEntry: (String, String) -> Unit = { _, _ -> },
    val onRemoveEntry: (String, String) -> Unit = { _, _ -> },
    val onMoveEntry: (String, String, Int) -> Unit = { _, _, _ -> },
    val onToggleScoreboard: (String, String) -> Unit = { _, _ -> },
    val onImageSeconds: (String, Int) -> Unit = { _, _ -> },
    val onLoop: (String, Boolean) -> Unit = { _, _ -> },
    val onAutoReturn: (String, Boolean) -> Unit = { _, _ -> },
    val onSlateText: (String, String) -> Unit = { _, _ -> },
    val onPrepare: (String) -> Unit = {},
    val onAddOverlay: (String, OverlayKind) -> Unit = { _, _ -> },
    val onToggleOverlay: (String) -> Unit = {},
    val onRemoveOverlay: (String) -> Unit = {},
    val onAudioPolicy: (MatchAudioPolicy) -> Unit = {},
    val onReturnSource: (String) -> Unit = {},
    val onBackToMatch: () -> Unit = {},
    val onPause: () -> Unit = {},
    val onNext: () -> Unit = {},
    val onStop: () -> Unit = {},
)

/** Miniatura cargada fuera del hilo principal; null mientras carga o si el archivo no se puede leer. */
@Composable
fun rememberThumbnail(uri: String, isVideo: Boolean): ImageBitmap? {
    val context = LocalContext.current.applicationContext
    val bmp by produceState<ImageBitmap?>(null, uri) {
        value = withContext(Dispatchers.IO) { Thumbnails.load(context, uri, isVideo)?.asImageBitmap() }
    }
    return bmp
}

@Composable
fun MediaThumb(media: MediaItem?, size: Dp = 48.dp) {
    Box(Modifier.size(size).clip(RoundedCornerShape(6.dp)).background(HrColors.PanelRaised), contentAlignment = Alignment.Center) {
        val bmp = media?.let { rememberThumbnail(it.uri, it.type == MediaType.Video) }
        if (bmp != null) Image(bmp, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.size(size))
        else Icon(if (media?.type == MediaType.Video) Icons.Filled.Movie else Icons.Filled.Image, contentDescription = null, tint = HrColors.TextMuted)
    }
}

private fun describe(m: MediaItem): String = buildString {
    append(m.type.label)
    m.durationMs?.let { append(" · ${formatDuration(it)}") }
    if (m.width != null && m.height != null) append(" · ${m.width}×${m.height}")
    when (m.hardwareDecoder) { true -> append(" · decodificador por hardware"); false -> append(" · decodificador por software"); null -> Unit }
}

@Composable
private fun CompatChip(m: MediaItem) {
    if (m.compat != Compat.Ok) StatusChip(m.compat.label.uppercase(), HrColors.Amber, dense = true)
}

@Composable
private fun Note(text: String, color: Color = HrColors.TextMuted) = Text(text, color = color, fontSize = 13.sp)

@Composable
private fun SmallButton(text: String, onClick: () -> Unit, modifier: Modifier = Modifier, primary: Boolean = false, enabled: Boolean = true) {
    if (primary) {
        Button(
            onClick = onClick, enabled = enabled, modifier = modifier.heightIn(min = HrDimens.MinTouch),
            colors = ButtonDefaults.buttonColors(containerColor = HrColors.Orange, contentColor = Color.Black,
                disabledContainerColor = HrColors.PanelRaised, disabledContentColor = HrColors.TextMuted),
        ) { Text(text, fontWeight = FontWeight.Bold) }
    } else {
        OutlinedButton(
            onClick = onClick, enabled = enabled, modifier = modifier.heightIn(min = HrDimens.MinTouch),
            border = BorderStroke(1.dp, HrColors.Outline),
        ) { Text(text, color = if (enabled) HrColors.TextPrimary else HrColors.TextMuted) }
    }
}

/** Panel de contenidos para descansos. Se abre con el botón DESCANSO; abrirlo no cambia lo que está al aire. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun ContentPanel(state: StudioUiState, actions: ContentActions) {
    PanelDialog("CONTENIDOS PARA DESCANSOS", actions.onClose) {
        if (!state.breakEngineAvailable) {
            Note(
                "Demostración: la biblioteca y las listas son reales y se guardan, pero aún no hay motor que reproduzca video o audio. " +
                    "Nada de este panel sale en la emisión ni en la grabación.",
                HrColors.Amber,
            )
        }
        // Los avisos también se ven aquí: la barra de mensajes del Estudio queda detrás de este panel.
        state.message?.let { Note(it, HrColors.TextPrimary) }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            ContentTab.entries.forEach { tab ->
                val sel = tab == state.contentTab
                Surface(
                    modifier = Modifier.heightIn(min = HrDimens.MinTouch).selectable(selected = sel, role = Role.Tab, onClick = { actions.onTab(tab) }),
                    shape = RoundedCornerShape(8.dp),
                    color = if (sel) HrColors.Orange else Color.Transparent,
                    border = BorderStroke(1.dp, if (sel) HrColors.Orange else HrColors.Outline),
                ) {
                    Box(Modifier.padding(horizontal = 14.dp, vertical = 12.dp), contentAlignment = Alignment.Center) {
                        Text(tab.label, color = if (sel) Color.Black else HrColors.TextPrimary, fontWeight = FontWeight.Bold)
                    }
                }
            }
        }
        when (state.contentTab) {
            ContentTab.Lists -> {
                val editing = state.content.playlist(state.editingPlaylistId)
                if (editing != null) PlaylistEditor(state, editing, actions) else PlaylistList(state, actions)
            }
            ContentTab.Library -> LibraryTab(state, actions)
            ContentTab.Sponsors -> SponsorsTab(state, actions)
            ContentTab.Audio -> AudioTab(state, actions)
        }
    }
}

private fun summary(state: StudioUiState, p: Playlist): String = buildString {
    append(if (p.entries.size == 1) "1 elemento" else "${p.entries.size} elementos")
    if (p.entries.isNotEmpty()) append(" · ${formatDuration(ContentEditor.totalDurationMs(state.content, p))}")
    if (p.loop) append(" · se repite")
    if (p.autoReturn) append(" · vuelve sola a cámara")
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun PlaylistList(state: StudioUiState, actions: ContentActions) {
    Text("Nueva lista desde una plantilla", color = HrColors.TextPrimary, fontWeight = FontWeight.Bold)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Template.entries.forEach { t -> SmallButton("+ ${t.label}", { actions.onCreate(t) }) }
    }
    if (state.content.playlists.isEmpty()) Note("Aún no hay listas. Crea una con una plantilla y añade archivos de la biblioteca.")
    state.content.playlists.forEach { p ->
        val issues = ContentEditor.issues(state.content, p)
        val onAir = state.breakOnAir && state.breakState?.playlist?.id == p.id
        val prepared = state.preparedPlaylistId == p.id && state.previewSourceId == com.hrmediasports.studio.ui.SOURCE_BREAK
        Surface(shape = RoundedCornerShape(10.dp), color = HrColors.PanelRaised, modifier = Modifier.fillMaxWidth()) {
            Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(p.name, color = HrColors.TextPrimary, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
                    if (onAir) StatusChip("AL AIRE", HrColors.OnAirRed, filled = true, dense = true)
                    if (prepared && !onAir) StatusChip("PREVIA", HrColors.Orange, dense = true)
                    if (issues.isNotEmpty()) StatusChip("${issues.size} AVISO(S)", HrColors.Amber, dense = true)
                }
                Note(summary(state, p))
                issues.take(2).forEach { Note(it, HrColors.Amber) }
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    SmallButton("Preparar en Vista previa", { actions.onPrepare(p.id) }, primary = true)
                    SmallButton("Editar", { actions.onEdit(p.id) })
                }
            }
        }
    }
}

@Composable
private fun Stepper(label: String, value: String, onMinus: () -> Unit, onPlus: () -> Unit) {
    Row(Modifier.fillMaxWidth().heightIn(min = HrDimens.MinTouch), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = HrColors.TextPrimary, modifier = Modifier.weight(1f))
        IconButton(onClick = onMinus, modifier = Modifier.size(HrDimens.MinTouch)) { Icon(Icons.Filled.Remove, contentDescription = "Menos: $label", tint = HrColors.TextPrimary) }
        Text(value, color = HrColors.TextPrimary, fontWeight = FontWeight.Bold)
        IconButton(onClick = onPlus, modifier = Modifier.size(HrDimens.MinTouch)) { Icon(Icons.Filled.Add, contentDescription = "Más: $label", tint = HrColors.TextPrimary) }
    }
}

@Composable
private fun SwitchRow(label: String, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth().heightIn(min = HrDimens.MinTouch), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = HrColors.TextPrimary, modifier = Modifier.weight(1f).padding(end = 8.dp))
        Switch(checked = checked, onCheckedChange = onChange, modifier = Modifier.semantics { contentDescription = label })
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun PlaylistEditor(state: StudioUiState, p: Playlist, actions: ContentActions) {
    SmallButton("← Volver a las listas", { actions.onEdit(null) })
    OutlinedTextField(
        value = p.name, onValueChange = { actions.onRename(p.id, it) }, label = { Text("Nombre de la lista") },
        singleLine = true, modifier = Modifier.fillMaxWidth(),
    )
    Text("Elementos (${p.entries.size})", color = HrColors.TextPrimary, fontWeight = FontWeight.Bold)
    if (p.entries.isEmpty()) Note("Lista vacía: al aire mostrará solo la placa «${p.slateText}».")
    p.entries.forEachIndexed { i, e ->
        val m = state.content.media(e.mediaId)
        Surface(shape = RoundedCornerShape(10.dp), color = HrColors.PanelRaised, modifier = Modifier.fillMaxWidth()) {
            Column(Modifier.padding(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    MediaThumb(m)
                    Column(Modifier.weight(1f)) {
                        Text("${i + 1}. ${m?.name ?: "No disponible"}", color = HrColors.TextPrimary, fontWeight = FontWeight.Bold)
                        Note(m?.let { if (it.type == MediaType.Image) "Imagen · ${p.imageSeconds} s" else describe(it) } ?: "Ya no está en la biblioteca")
                    }
                    if (m != null) CompatChip(m)
                }
                FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    IconButton(onClick = { actions.onMoveEntry(p.id, e.entryId, -1) }, enabled = i > 0, modifier = Modifier.size(HrDimens.MinTouch)) {
                        Icon(Icons.Filled.ArrowUpward, contentDescription = "Subir ${m?.name ?: "elemento"}")
                    }
                    IconButton(onClick = { actions.onMoveEntry(p.id, e.entryId, +1) }, enabled = i < p.entries.lastIndex, modifier = Modifier.size(HrDimens.MinTouch)) {
                        Icon(Icons.Filled.ArrowDownward, contentDescription = "Bajar ${m?.name ?: "elemento"}")
                    }
                    SmallButton(if (e.showScoreboard) "Marcador: visible" else "Marcador: oculto", { actions.onToggleScoreboard(p.id, e.entryId) })
                    IconButton(onClick = { actions.onRemoveEntry(p.id, e.entryId) }, modifier = Modifier.size(HrDimens.MinTouch)) {
                        Icon(Icons.Filled.Close, contentDescription = "Quitar ${m?.name ?: "elemento"} de la lista (no borra el archivo)")
                    }
                }
            }
        }
    }
    Text("Añadir desde la biblioteca", color = HrColors.TextPrimary, fontWeight = FontWeight.Bold)
    if (state.content.library.isEmpty()) Note("La biblioteca está vacía. Importa imágenes y videos en la pestaña «Biblioteca».")
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        state.content.library.forEach { m -> SmallButton("+ ${m.name}", { actions.onAddEntry(p.id, m.id) }) }
    }
    Text("Ajustes de la lista", color = HrColors.TextPrimary, fontWeight = FontWeight.Bold)
    Stepper("Duración de cada imagen", "${p.imageSeconds} s", { actions.onImageSeconds(p.id, p.imageSeconds - 1) }, { actions.onImageSeconds(p.id, p.imageSeconds + 1) })
    SwitchRow("Repetir la lista al terminar", p.loop) { actions.onLoop(p.id, it) }
    SwitchRow("Al terminar, volver sola a la cámara", p.autoReturn) { actions.onAutoReturn(p.id, it) }
    OutlinedTextField(
        value = p.slateText, onValueChange = { actions.onSlateText(p.id, it) }, label = { Text("Texto de la placa de espera") },
        singleLine = true, modifier = Modifier.fillMaxWidth(),
    )
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        SmallButton("Preparar en Vista previa", { actions.onPrepare(p.id) }, primary = true)
        SmallButton("Eliminar lista", { actions.onRemovePlaylist(p.id) })
    }
    Note("Eliminar la lista o quitar elementos no borra ningún archivo.")
}

@Composable
private fun LibraryTab(state: StudioUiState, actions: ContentActions) {
    SmallButton("Importar imágenes y videos", actions.onImport, primary = true)
    Note("Se usan los archivos locales donde están: no se copian, no se descargan y nunca se borran. Quitar uno de la biblioteca solo quita la referencia.")
    if (state.content.library.isEmpty()) Note("Biblioteca vacía.")
    state.content.library.forEach { m ->
        Surface(shape = RoundedCornerShape(10.dp), color = HrColors.PanelRaised, modifier = Modifier.fillMaxWidth()) {
            Row(Modifier.padding(8.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                MediaThumb(m, 56.dp)
                Column(Modifier.weight(1f)) {
                    Text(m.name, color = HrColors.TextPrimary, fontWeight = FontWeight.Bold)
                    Note(describe(m))
                    if (m.compat != Compat.Ok) Note(m.compat.label + (m.compatNote?.let { ": $it" } ?: ""), HrColors.Amber)
                }
                SmallButton("Quitar", { actions.onRemoveMedia(m.id) })
            }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun SponsorsTab(state: StudioUiState, actions: ContentActions) {
    Note("Logos y franjas para mostrar sobre la cámara. No disponible todavía: dibujarlos sobre el video requiere el motor de composición. Aquí solo se configuran.", HrColors.Amber)
    if (state.content.overlays.isEmpty()) Note("Sin patrocinadores configurados.")
    state.content.overlays.forEach { o ->
        val m = state.content.media(o.mediaId)
        Row(Modifier.fillMaxWidth().heightIn(min = HrDimens.MinTouch), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            MediaThumb(m)
            Column(Modifier.weight(1f)) {
                Text("${o.kind.label}: ${m?.name ?: "no disponible"}", color = HrColors.TextPrimary, fontWeight = FontWeight.Bold)
                Note(if (o.enabled) "Marcado para mostrarse (sin efecto aún)" else "Oculto")
            }
            Switch(checked = o.enabled, onCheckedChange = { actions.onToggleOverlay(o.id) }, modifier = Modifier.semantics { contentDescription = "Mostrar ${m?.name ?: "patrocinador"}" })
            IconButton(onClick = { actions.onRemoveOverlay(o.id) }, modifier = Modifier.size(HrDimens.MinTouch)) { Icon(Icons.Filled.Close, contentDescription = "Quitar patrocinador") }
        }
    }
    val images = state.content.library.filter { it.type == MediaType.Image }
    Text("Añadir desde imágenes de la biblioteca", color = HrColors.TextPrimary, fontWeight = FontWeight.Bold)
    if (images.isEmpty()) Note("Importa primero una imagen en «Biblioteca».")
    images.forEach { m ->
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(m.name, color = HrColors.TextPrimary, modifier = Modifier.align(Alignment.CenterVertically))
            SmallButton("+ Logo", { actions.onAddOverlay(m.id, OverlayKind.Logo) })
            SmallButton("+ Franja", { actions.onAddOverlay(m.id, OverlayKind.Strip) })
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun AudioTab(state: StudioUiState, actions: ContentActions) {
    Text("Audio del partido durante un anuncio", color = HrColors.TextPrimary, fontWeight = FontWeight.Bold)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        MatchAudioPolicy.entries.forEach { p ->
            val sel = state.content.audioPolicy == p
            Surface(
                modifier = Modifier.heightIn(min = HrDimens.MinTouch).selectable(selected = sel, role = Role.RadioButton, onClick = { actions.onAudioPolicy(p) }),
                shape = RoundedCornerShape(8.dp), color = Color.Transparent,
                border = BorderStroke(if (sel) 2.dp else 1.dp, if (sel) HrColors.Orange else HrColors.Outline),
            ) { Box(Modifier.padding(horizontal = 14.dp, vertical = 12.dp)) { Text(p.label, color = HrColors.TextPrimary, fontWeight = if (sel) FontWeight.Bold else FontWeight.Normal) } }
        }
    }
    Note("Mezcla deseada ahora: partido ${(state.audioMix.matchGain * 100).toInt()} % · anuncio ${(state.audioMix.adGain * 100).toInt()} %")
    Note("La Vista previa nunca envía audio a la salida. Al volver al partido se restaura la mezcla anterior.")
    Note("Sin motor de audio todavía: estos valores no suenan ni se emiten; es el estado que se aplicará.", HrColors.Amber)
    Text("«Volver al partido» regresa a", color = HrColors.TextPrimary, fontWeight = FontWeight.Bold)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        state.sources.filter { it.kind == SourceKind.Camera }.forEach { s ->
            val sel = state.returnSourceId == s.id
            Surface(
                modifier = Modifier.heightIn(min = HrDimens.MinTouch).selectable(selected = sel, role = Role.RadioButton, onClick = { actions.onReturnSource(s.id) }),
                shape = RoundedCornerShape(8.dp), color = Color.Transparent,
                border = BorderStroke(if (sel) 2.dp else 1.dp, if (sel) HrColors.Orange else HrColors.Outline),
            ) { Box(Modifier.padding(horizontal = 14.dp, vertical = 12.dp)) { Text(s.label, color = HrColors.TextPrimary, fontWeight = if (sel) FontWeight.Bold else FontWeight.Normal) } }
        }
    }
}
