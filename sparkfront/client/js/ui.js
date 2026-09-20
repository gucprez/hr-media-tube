// Toda la manipulación del DOM vive aquí. game.js (Phaser) nunca toca el DOM
// directamente; se comunica con esta capa a través de window.GameEvents.
const UI = (() => {
  const MATCH_DURATION_MS = 15 * 60 * 1000; // debe coincidir con MAX_GAME_DURATION_MS del servidor

  const el = (id) => document.getElementById(id);

  const screens = {
    lobby: el('lobby-screen'),
    waiting: el('waiting-screen'),
    game: el('game-screen'),
    gameover: el('gameover-screen'),
  };

  let buildingTypes = {};
  let unitTypes = {};
  let latestEnergy = 0;
  let currentBuildingSelection = null; // {id, type}

  function showScreen(name) {
    Object.values(screens).forEach((s) => s.classList.add('hidden'));
    screens[name].classList.remove('hidden');
  }

  function showToast(message) {
    const toast = document.createElement('div');
    toast.textContent = message;
    Object.assign(toast.style, {
      position: 'fixed',
      top: '16px',
      left: '50%',
      transform: 'translateX(-50%)',
      background: 'rgba(255,47,214,0.92)',
      color: '#0b0e1f',
      fontWeight: 'bold',
      padding: '10px 18px',
      borderRadius: '12px',
      zIndex: 999,
      boxShadow: '0 0 20px rgba(255,47,214,0.6)',
    });
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2200);
  }

  function formatTime(ms) {
    const total = Math.max(0, Math.floor(ms / 1000));
    const m = Math.floor(total / 60);
    const s = total % 60;
    return `${m}:${s.toString().padStart(2, '0')}`;
  }

  // ---------- Lobby ----------

  function initLobby() {
    el('create-room-btn').addEventListener('click', async () => {
      const name = el('name-input').value.trim();
      try {
        const res = await Network.createRoom(name);
        onJoinedLobby(res.code);
      } catch (err) {
        el('lobby-error').textContent = err;
      }
    });

    el('join-room-btn').addEventListener('click', async () => {
      const name = el('name-input').value.trim();
      const code = el('code-input').value.trim();
      try {
        const res = await Network.joinRoom(code, name);
        onJoinedLobby(res.code);
      } catch (err) {
        el('lobby-error').textContent = err;
      }
    });

    el('start-game-btn').addEventListener('click', () => Network.startGame());
    el('back-to-lobby-btn').addEventListener('click', () => window.location.reload());

    Network.on('lobby:update', renderLobby);
    Network.on('error:message', ({ message }) => showToast(message));
    Network.on('game:over', showGameOver);
  }

  function onJoinedLobby(code) {
    el('room-code-label').textContent = code;
    showScreen('waiting');
  }

  function renderLobby(state) {
    const list = el('player-list');
    list.innerHTML = '';
    state.players.forEach((p) => {
      const li = document.createElement('li');
      const dot = document.createElement('span');
      dot.className = 'player-color-dot';
      dot.style.background = ClientPlayer.hex(p.color);
      dot.style.color = ClientPlayer.hex(p.color);
      li.appendChild(dot);
      li.appendChild(document.createTextNode(p.name + (p.isHost ? ' (anfitrión)' : '')));
      list.appendChild(li);
    });

    const isHost = state.hostId === Network.socket.id;
    const canStart = state.players.length >= 2;
    el('start-game-btn').classList.toggle('hidden', !isHost);
    el('start-game-btn').disabled = !canStart;
    el('waiting-hint').textContent = isHost
      ? (canStart ? 'Listo para empezar.' : 'Esperando al menos 2 jugadores...')
      : 'Esperando a que el anfitrión inicie la partida...';
  }

  // ---------- Partida ----------

  function enterGame(payload) {
    buildingTypes = payload.buildingTypes;
    unitTypes = payload.unitTypes;
    showScreen('game');
    buildBuildBar();
    updateSelectionInfo({ kind: 'none' });

    Network.on('game:state', (s) => {
      const me = s.players.find((p) => p.id === Network.socket.id);
      if (me) {
        latestEnergy = me.energy;
        el('energy-value').textContent = latestEnergy;
        refreshBuildAffordability();
      }
      el('timer-value').textContent = formatTime(MATCH_DURATION_MS - s.time);
    });

    Network.on('game:chat', (msg) => {
      const log = el('chat-log');
      const line = document.createElement('div');
      line.textContent = `${msg.name}: ${msg.text}`;
      log.appendChild(line);
      while (log.children.length > 30) log.removeChild(log.firstChild);
      log.scrollTop = log.scrollHeight;
    });

    el('chat-input').addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && e.target.value.trim()) {
        Network.chat(e.target.value.trim());
        e.target.value = '';
      }
    });

    GameEvents.on('selection-changed', updateSelectionInfo);
    GameEvents.on('build-mode-changed', syncBuildButtonHighlight);
  }

  function buildBuildBar() {
    const container = el('build-buttons');
    container.innerHTML = '';
    Object.entries(buildingTypes).forEach(([type, def]) => {
      if (type === 'core') return;
      const btn = document.createElement('button');
      btn.className = 'action-btn';
      btn.dataset.type = type;
      btn.innerHTML = `<b>${def.name}</b><small>⚡ ${def.cost}</small>`;
      btn.addEventListener('click', () => {
        if (latestEnergy < def.cost) {
          showToast('Energía insuficiente.');
          return;
        }
        if (btn.classList.contains('selected')) {
          GameEvents.emit('build-select-cancel');
        } else {
          GameEvents.emit('build-select', type);
        }
      });
      container.appendChild(btn);
    });
  }

  function syncBuildButtonHighlight(activeType) {
    document.querySelectorAll('#build-buttons .action-btn').forEach((btn) => {
      btn.classList.toggle('selected', btn.dataset.type === activeType);
    });
  }

  function refreshBuildAffordability() {
    document.querySelectorAll('#build-buttons .action-btn').forEach((btn) => {
      const def = buildingTypes[btn.dataset.type];
      btn.disabled = latestEnergy < def.cost;
    });
    document.querySelectorAll('#produce-buttons .action-btn').forEach((btn) => {
      const def = unitTypes[btn.dataset.type];
      btn.disabled = latestEnergy < def.cost;
    });
  }

  function updateSelectionInfo(sel) {
    const info = el('selection-info');
    const produceContainer = el('produce-buttons');
    produceContainer.innerHTML = '';
    currentBuildingSelection = null;

    if (sel.kind === 'units') {
      info.textContent = `${sel.count} unidad(es) seleccionada(s)`;
    } else if (sel.kind === 'building') {
      const def = buildingTypes[sel.building.type];
      info.textContent = `${def.name} seleccionado`;
      currentBuildingSelection = sel.building;
      (def.canProduce || []).forEach((unitType) => {
        const udef = unitTypes[unitType];
        const btn = document.createElement('button');
        btn.className = 'action-btn';
        btn.dataset.type = unitType;
        btn.innerHTML = `<b>${udef.name}</b><small>⚡ ${udef.cost}</small>`;
        btn.addEventListener('click', () => {
          if (latestEnergy < udef.cost) {
            showToast('Energía insuficiente.');
            return;
          }
          Network.produce(currentBuildingSelection.id, unitType);
        });
        produceContainer.appendChild(btn);
      });
    } else {
      info.textContent = '';
    }
    refreshBuildAffordability();
  }

  // ---------- Fin de partida ----------

  function showGameOver(payload) {
    showScreen('gameover');
    const title = el('gameover-title');
    const subtitle = el('gameover-subtitle');
    if (!payload.winnerId) {
      title.textContent = '¡Empate!';
      subtitle.textContent = 'Nadie quedó en pie.';
    } else if (payload.winnerId === Network.socket.id) {
      title.textContent = '¡Victoria!';
      subtitle.textContent = 'Tu Núcleo Spark brilla más que nunca.';
    } else {
      title.textContent = 'Derrota';
      subtitle.textContent = `${payload.winnerName} conquistó el campo.`;
    }
  }

  return { initLobby, enterGame, showScreen, showToast };
})();
