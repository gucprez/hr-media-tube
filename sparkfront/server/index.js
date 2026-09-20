const path = require('path');
const express = require('express');
const http = require('http');
const { Server } = require('socket.io');

const GameRoom = require('./game/GameRoom');

const app = express();
const server = http.createServer(app);
const io = new Server(server, {
  cors: { origin: '*' },
});

const PORT = process.env.PORT || 3000;

// Sirve el cliente estático (HTML/CSS/JS/assets) desde ../client
app.use(express.static(path.join(__dirname, '..', 'client')));

/** @type {Map<string, GameRoom>} */
const rooms = new Map();
// socketId -> roomCode, para saber a qué sala pertenece cada conexión
const socketRoom = new Map();

function generateRoomCode() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  let code;
  do {
    code = Array.from({ length: 6 }, () => chars[Math.floor(Math.random() * chars.length)]).join('');
  } while (rooms.has(code));
  return code;
}

function broadcastLobby(room) {
  io.to(room.code).emit('lobby:update', room.serializeLobby());
}

io.on('connection', (socket) => {
  socket.on('lobby:create', (data, callback) => {
    const code = generateRoomCode();
    const room = new GameRoom(code, io);
    const result = room.addPlayer(socket.id, data && data.name);
    if (result.error) {
      callback && callback({ error: result.error });
      return;
    }
    rooms.set(code, room);
    socketRoom.set(socket.id, code);
    socket.join(code);
    callback && callback({ ok: true, code, playerId: socket.id });
    broadcastLobby(room);
  });

  socket.on('lobby:join', (data, callback) => {
    const code = ((data && data.code) || '').toUpperCase().trim();
    const room = rooms.get(code);
    if (!room) {
      callback && callback({ error: 'Sala no encontrada.' });
      return;
    }
    const result = room.addPlayer(socket.id, data && data.name);
    if (result.error) {
      callback && callback({ error: result.error });
      return;
    }
    socketRoom.set(socket.id, code);
    socket.join(code);
    callback && callback({ ok: true, code, playerId: socket.id });
    broadcastLobby(room);
  });

  socket.on('lobby:start', () => {
    const room = getRoomOf(socket.id);
    if (!room) return;
    if (room.hostId !== socket.id) return;
    if (!room.canStart()) {
      socket.emit('error:message', { message: 'Se necesitan al menos 2 jugadores.' });
      return;
    }
    if (room.start()) {
      const payload = room.getStartPayload();
      for (const player of room.players.values()) {
        io.to(player.id).emit('game:start', { ...payload, you: player.id });
      }
    }
  });

  socket.on('game:build', (data) => {
    const room = getRoomOf(socket.id);
    if (!room || !data) return;
    const result = room.handleBuild(socket.id, data.type, Number(data.x), Number(data.y));
    if (result && result.error) {
      socket.emit('error:message', { message: result.error });
    }
  });

  socket.on('game:produce', (data) => {
    const room = getRoomOf(socket.id);
    if (!room || !data) return;
    const result = room.handleProduce(socket.id, data.buildingId, data.unitType);
    if (result && result.error) {
      socket.emit('error:message', { message: result.error });
    }
  });

  socket.on('game:command', (data) => {
    const room = getRoomOf(socket.id);
    if (!room || !data) return;
    room.handleCommand(socket.id, data.unitIds, Number(data.x), Number(data.y), data.targetId || null);
  });

  socket.on('game:chat', (data) => {
    const room = getRoomOf(socket.id);
    if (!room || !data) return;
    const message = room.handleChat(socket.id, data.text);
    if (message) io.to(room.code).emit('game:chat', message);
  });

  socket.on('disconnect', () => {
    const code = socketRoom.get(socket.id);
    socketRoom.delete(socket.id);
    if (!code) return;
    const room = rooms.get(code);
    if (!room) return;
    room.removePlayer(socket.id);
    if (room.isEmpty()) {
      room.destroy();
      rooms.delete(code);
    } else if (room.state === 'lobby') {
      broadcastLobby(room);
    }
  });

  function getRoomOf(socketId) {
    const code = socketRoom.get(socketId);
    return code ? rooms.get(code) : null;
  }
});

server.listen(PORT, () => {
  console.log(`Sparkfront escuchando en http://localhost:${PORT}`);
});
