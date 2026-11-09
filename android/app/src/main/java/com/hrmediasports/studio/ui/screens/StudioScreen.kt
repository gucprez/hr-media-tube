package com.hrmediasports.studio.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.hrmediasports.studio.domain.MatchState
import com.hrmediasports.studio.ui.StudioUiState
import com.hrmediasports.studio.ui.components.PreviewPanel
import com.hrmediasports.studio.ui.components.ProgramPanel
import com.hrmediasports.studio.ui.components.ReplaysPanel
import com.hrmediasports.studio.ui.components.ScoreboardPanel
import com.hrmediasports.studio.ui.components.SourceTile
import com.hrmediasports.studio.ui.theme.HrDimens

/** Acciones que la pantalla Estudio puede disparar; el estado vive en el ViewModel. */
data class StudioActions(
    val onSelectSource: (String) -> Unit = {},
    val onTakeToAir: () -> Unit = {},
    val onSaveReplay: () -> Unit = {},
    val onManualCorrection: () -> Unit = {},
    val onDiscardCorrection: () -> Unit = {},
)

@Composable
fun StudioScreen(
    state: StudioUiState,
    actions: StudioActions,
    expanded: Boolean,
    modifier: Modifier = Modifier,
) {
    val match = state.scoreboard.match
    val scoreboard: @Composable (Modifier) -> Unit = { m ->
        ScoreboardPanel(state.scoreboard, actions.onManualCorrection, actions.onDiscardCorrection, m)
    }

    Row(modifier.fillMaxSize().padding(HrDimens.Gap), horizontalArrangement = Arrangement.spacedBy(HrDimens.Gap)) {
        Column(
            Modifier.weight(1f).fillMaxHeight().verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(HrDimens.Gap),
        ) {
            MonitorRow(state, match, actions)
            SourcesRow(state, match, actions)
            ReplaysPanel(state.replays, state.replayBufferAvailable, actions.onSaveReplay, Modifier.fillMaxWidth())
            if (!expanded) scoreboard(Modifier.fillMaxWidth())
        }
        if (expanded) {
            scoreboard(Modifier.width(300.dp).fillMaxHeight().verticalScroll(rememberScrollState()))
        }
    }
}

@Composable
private fun MonitorRow(state: StudioUiState, match: MatchState, actions: StudioActions) {
    Row(
        Modifier.fillMaxWidth().height(IntrinsicSize.Min),
        horizontalArrangement = Arrangement.spacedBy(HrDimens.Gap),
    ) {
        PreviewPanel(
            source = state.previewSource,
            match = match,
            canTakeToAir = state.canTakeToAir,
            alreadyOnAir = state.previewSourceId != null && state.previewSourceId == state.programSourceId,
            onTakeToAir = actions.onTakeToAir,
            modifier = Modifier.weight(1f).fillMaxHeight(),
        )
        ProgramPanel(state.programSource, match, Modifier.weight(1.3f).fillMaxHeight())
    }
}

@Composable
private fun SourcesRow(state: StudioUiState, match: MatchState, actions: StudioActions) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(HrDimens.Gap)) {
        state.sources.forEach { source ->
            SourceTile(
                source = source,
                match = match,
                selected = source.id == state.previewSourceId,
                onAir = source.id == state.programSourceId,
                onClick = { actions.onSelectSource(source.id) },
                modifier = Modifier.weight(1f),
            )
        }
    }
}
