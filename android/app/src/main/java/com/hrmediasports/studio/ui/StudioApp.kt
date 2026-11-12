package com.hrmediasports.studio.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.lifecycle.viewmodel.initializer
import androidx.lifecycle.viewmodel.viewModelFactory
import com.hrmediasports.studio.content.FileContentStore
import com.hrmediasports.studio.content.MediaInspector
import com.hrmediasports.studio.ui.content.ContentActions
import com.hrmediasports.studio.ui.content.ContentPanel
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import com.hrmediasports.studio.protocol.LinkState
import com.hrmediasports.studio.ui.components.ConnectionStatusBar
import com.hrmediasports.studio.ui.components.DemoBanner
import com.hrmediasports.studio.ui.components.HrBrandBar
import com.hrmediasports.studio.ui.components.ManualCorrectionDialog
import com.hrmediasports.studio.ui.components.NavRail
import com.hrmediasports.studio.ui.components.OutputControls
import com.hrmediasports.studio.ui.components.StatusChip
import com.hrmediasports.studio.ui.screens.CamerasScreen
import com.hrmediasports.studio.ui.screens.GraphicsScreen
import com.hrmediasports.studio.ui.screens.ReplaysScreen
import com.hrmediasports.studio.ui.screens.SettingsScreen
import com.hrmediasports.studio.ui.screens.StudioActions
import com.hrmediasports.studio.ui.screens.StudioScreen
import com.hrmediasports.studio.ui.theme.HrColors
import com.hrmediasports.studio.ui.theme.HrDimens

@Composable
fun StudioApp(viewModel: StudioViewModel? = null) {
    val context = LocalContext.current.applicationContext
    // En la app real la biblioteca y las listas se guardan en disco; en pruebas se inyecta otro ViewModel.
    val vm: StudioViewModel = viewModel ?: viewModel(
        factory = viewModelFactory { initializer { StudioViewModel(contentStore = FileContentStore(context)) } },
    )
    StudioApp(vm, context)
}

@Composable
private fun StudioApp(viewModel: StudioViewModel, context: android.content.Context) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val scope = rememberCoroutineScope()
    // Selector del sistema: solo archivos locales elegidos por el operador (imágenes y videos).
    val picker = rememberLauncherForActivityResult(ActivityResultContracts.OpenMultipleDocuments()) { uris ->
        if (uris.isNotEmpty()) {
            scope.launch {
                val items = withContext(Dispatchers.IO) {
                    val inspector = MediaInspector(context)
                    uris.map { uri -> inspector.keepPermission(uri); inspector.inspect(uri) }   // valida ANTES de usarlos
                }
                viewModel.importMedia(items)
            }
        }
    }
    val content = ContentActions(
        onClose = viewModel::closeContentPanel,
        onTab = viewModel::setContentTab,
        onImport = { picker.launch(arrayOf("image/*", "video/*")) },
        onRemoveMedia = { id ->
            val uri = viewModel.state.value.content.media(id)?.uri
            viewModel.removeMedia(id)
            // Solo se quita la referencia y su permiso de lectura; el archivo original no se toca.
            if (uri != null && viewModel.state.value.content.library.none { it.uri == uri }) MediaInspector(context).releasePermission(uri)
        },
        onCreate = viewModel::createPlaylist,
        onEdit = viewModel::editPlaylist,
        onRemovePlaylist = viewModel::removePlaylist,
        onRename = viewModel::renamePlaylist,
        onAddEntry = viewModel::addEntry,
        onRemoveEntry = viewModel::removeEntry,
        onMoveEntry = viewModel::moveEntry,
        onToggleScoreboard = viewModel::toggleEntryScoreboard,
        onImageSeconds = viewModel::setImageSeconds,
        onLoop = viewModel::setLoop,
        onAutoReturn = viewModel::setAutoReturn,
        onSlateText = viewModel::setSlateText,
        onPrepare = { id ->
            // Antes de preparar se vuelven a validar los archivos de la lista: pueden haberse borrado o movido.
            val s = viewModel.state.value
            val media = s.content.playlist(id)?.entries?.mapNotNull { s.content.media(it.mediaId) }?.distinctBy { it.id }.orEmpty()
            scope.launch {
                val checked = withContext(Dispatchers.IO) { MediaInspector(context).let { insp -> media.map(insp::recheck) } }
                viewModel.updateMedia(checked)
                viewModel.preparePlaylist(id)
            }
        },
        onAddOverlay = viewModel::addOverlay,
        onToggleOverlay = viewModel::toggleOverlay,
        onRemoveOverlay = viewModel::removeOverlay,
        onAudioPolicy = viewModel::setAudioPolicy,
        onReturnSource = viewModel::setReturnSource,
        onBackToMatch = viewModel::backToMatch,
        onPause = viewModel::breakTogglePause,
        onNext = viewModel::breakNext,
        onStop = viewModel::breakStop,
    )
    StudioAppContent(
        content = content,
        state = state,
        actions = StudioActions(
            onSelectSource = viewModel::selectSource,
            onTakeToAir = viewModel::takeToAir,
            onSaveReplay = viewModel::onSaveReplayClick,
            onManualCorrection = viewModel::openCorrection,
            onDiscardCorrection = viewModel::discardCorrection,
            onSetMonitorView = viewModel::setMonitorView,
            onToggleReplaysTray = viewModel::toggleReplaysTray,
            onOpenPanel = viewModel::openPanel,
            onClosePanel = viewModel::closePanel,
        ),
        onNavigate = viewModel::navigate,
        onRecord = viewModel::onRecordClick,
        onStream = viewModel::onStreamClick,
        onDraftChange = viewModel::updateDraft,
        onApplyCorrection = viewModel::applyCorrection,
        onDismissCorrection = viewModel::dismissCorrection,
        onMessageShown = viewModel::consumeMessage,
    )
}

/** Versión sin ViewModel para poder previsualizarla y probarla. */
@Composable
fun StudioAppContent(
    state: StudioUiState,
    actions: StudioActions,
    onNavigate: (Destination) -> Unit,
    onRecord: () -> Unit,
    onStream: () -> Unit,
    onDraftChange: ((CorrectionDraft) -> CorrectionDraft) -> Unit,
    onApplyCorrection: () -> Unit,
    onDismissCorrection: () -> Unit,
    onMessageShown: () -> Unit,
    content: ContentActions = ContentActions(),
) {
    val snackbar = remember { SnackbarHostState() }
    LaunchedEffect(state.message) {
        state.message?.let {
            snackbar.showSnackbar(it)
            onMessageShown()
        }
    }

    Scaffold(
        containerColor = HrColors.Background,
        snackbarHost = { SnackbarHost(snackbar) },
        contentWindowInsets = WindowInsets(0),
        modifier = Modifier.fillMaxSize().background(HrColors.Background).windowInsetsPadding(WindowInsets.safeDrawing),
    ) { padding ->
        BoxWithConstraints(Modifier.fillMaxSize().padding(padding)) {
            val expanded = maxWidth >= HrDimens.ExpandedBreakpoint * LocalDensity.current.fontScale.coerceAtLeast(1f)
            // En ventanas bajas se quita la barra de estado inferior para dejar alto a los monitores;
            // el estado de la pizarra sigue visible en su panel.
            val shortWindow = maxHeight < 400.dp
            val fontScale = LocalDensity.current.fontScale
            val iconOnlyOutputs = !expanded && fontScale > 1.15f
            // Letra muy grande en ventana pequeña: riel solo con iconos y banner reducido al rótulo.
            val bigText = fontScale > 1.3f && (!expanded || shortWindow)
            Column(Modifier.fillMaxSize()) {
                HrTopBar(state, expanded, iconOnlyOutputs, onRecord, onStream)
                if (state.isDemo) DemoBanner(labelOnly = bigText)
                Row(Modifier.weight(1f)) {
                    NavRail(state.destination, onNavigate, compact = !expanded || shortWindow, iconsOnly = bigText)
                    when (state.destination) {
                        Destination.Studio -> StudioScreen(state, actions, Modifier.weight(1f), content)
                        Destination.Cameras -> CamerasScreen(state, Modifier.weight(1f))
                        Destination.Graphics -> GraphicsScreen(
                            state,
                            onPrepare = { id ->
                                actions.onSelectSource(id)
                                onNavigate(Destination.Studio)
                            },
                            modifier = Modifier.weight(1f),
                        )
                        Destination.Replays -> ReplaysScreen(state, Modifier.weight(1f))
                        Destination.Settings -> SettingsScreen(state, Modifier.weight(1f))
                    }
                }
                if (!shortWindow) {
                    ConnectionStatusBar(
                        connectedCameras = state.connectedCameras,
                        scoreboardConnected = state.scoreboard.link == LinkState.Connected,
                        versionLabel = "v0.1.0-demo",
                    )
                }
            }
        }
    }

    if (state.contentPanelOpen) ContentPanel(state, content)

    state.correctionDraft?.let { draft ->
        ManualCorrectionDialog(draft, onDraftChange, onApplyCorrection, onDismissCorrection)
    }
}

/** Barra superior del Estudio: marca común + estado demo + controles de salida. */
@Composable
private fun HrTopBar(state: StudioUiState, expanded: Boolean, iconOnly: Boolean, onRecord: () -> Unit, onStream: () -> Unit) {
    HrBrandBar(title = if (expanded) state.destination.title else null, compact = !expanded) {
        if (state.isDemo) StatusChip("DEMO", HrColors.Amber, filled = false)
        OutputControls(state.recording, state.streaming, onRecord, onStream, iconOnly)
    }
}
