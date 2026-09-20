# ⚡ Sparkfront

RTS simplificado + tower defense multijugador online, ambientado en facciones
de energía viva ("Sparks"). De 2 a 4 jugadores construyen su Núcleo Spark,
producen unidades de energía y destruyen los núcleos rivales. Pensado para
jugadores de 8 a 14 años: reglas simples, un solo recurso, controles claros.

## Concepto

- **Tema**: cada jugador controla un **Núcleo Spark** con un color de facción
  neón (cian, magenta, lima o naranja) que tiñe todas sus construcciones y
  unidades.
- **Recurso único**: Energía. Se genera sola desde el Núcleo y desde los
  Generadores de Chispas.
- **Objetivo**: destruir el Núcleo Spark de todos los rivales, o tener la
  base con más vida cuando se acabe el tiempo (15 minutos).

### Edificios

| Edificio             | Coste | Función                              | Vida |
|-----------------------|-------|---------------------------------------|------|
| Núcleo Spark          | -     | Genera energía + produce Chispas     | 1000 |
| Generador de Chispas  | 50    | +2 Energía/segundo                   | 200  |
| Forja de Unidades     | 80    | Produce Chispa, Gólem y Rayo         | 300  |
| Torre de Plasma       | 60    | Dispara sola a enemigos cercanos     | 250  |
| Barrera de Energía    | 20    | Bloquea el paso de unidades          | 150  |

### Unidades

| Unidad             | Coste | Velocidad | Daño | Vida | Nota                  |
|--------------------|-------|-----------|------|------|-----------------------|
| Chispa             | 25    | Media     | 9    | 45   | Rápida y barata       |
| Gólem de Energía   | 65    | Lenta     | 22   | 140  | Lento y muy resistente|
| Rayo               | 45    | Muy rápida| 7    | 35   | Ideal para flanquear  |

## Arquitectura

- **Cliente**: HTML5 + [Phaser 3](https://phaser.io/) + `socket.io-client`.
  Todo el renderizado son formas geométricas "cartoon" (círculos, triángulos,
  rectángulos redondeados) teñidas por facción, sin necesidad de imágenes
  externas, más partículas de luz al disparar y al destruir algo.
- **Servidor**: Node.js + Express + Socket.io. **Toda la lógica del juego es
  autoritativa**: el cliente solo envía intenciones ("quiero construir aquí",
  "quiero mover esta unidad"), el servidor valida, simula (20 ticks/seg) y
  reenvía el estado del mundo a todos los jugadores de la sala.

```
sparkfront/
├── client/
│   ├── index.html
│   ├── css/style.css
│   ├── js/
│   │   ├── main.js         # arranque: lobby DOM <-> Phaser
│   │   ├── game.js         # escena Phaser: render, input, cámara, minimapa
│   │   ├── ui.js           # DOM: lobby, HUD, construir/producir, chat
│   │   ├── network.js      # capa socket.io-client + bus de eventos
│   │   └── entities/
│   │       ├── Player.js
│   │       ├── Building.js
│   │       └── Unit.js
│   └── assets/             # (vacío; todo el arte es geometría generada)
├── server/
│   ├── index.js            # Express + Socket.io + lobby de salas
│   ├── package.json
│   └── game/
│       ├── GameRoom.js      # ciclo de partida, comandos, victoria
│       ├── Map.js           # grilla + pathfinding A*
│       ├── Building.js
│       ├── Unit.js
│       ├── Player.js
│       └── constants.js     # balance del juego (edita aquí costes/daños)
└── README.md
```

## Cómo jugar

- **Click izquierdo**: seleccionar (clic simple) o arrastrar para seleccionar
  varias unidades a la vez.
- **Click derecho** (clic simple): mover ahí, o atacar si hay un enemigo bajo
  el cursor.
- **Arrastrar con el botón derecho**: mover la cámara.
- **WASD**: mover la cámara.
- Barra inferior: botones grandes para **construir** (elige edificio, aparece
  una vista previa verde/roja según si el lugar es válido, clic para
  confirmar, ESC o click derecho para cancelar) y para **producir** unidades
  (aparece al seleccionar un Núcleo o una Forja).
- Minimapa en la esquina inferior derecha, chat en la esquina inferior
  izquierda.

## Instalación y ejecución en local

Requiere [Node.js](https://nodejs.org/) 18 o superior.

```bash
cd sparkfront/server
npm install
npm start
```

El servidor arranca en `http://localhost:3000` y **también sirve el
cliente** (no hace falta un servidor web aparte). Abre esa URL en el
navegador, uno o varios jugadores desde el mismo ordenador (en pestañas
distintas) para probar el lobby y la partida.

## Jugar entre varias casas (producción)

Para que amigos en otras redes se conecten necesitas publicar el servidor en
un hosting con acceso público. Dos opciones gratuitas sencillas:

### Opción A: Render.com

1. Sube este proyecto a un repositorio de GitHub.
2. En [Render](https://render.com/) crea un **Web Service** nuevo apuntando
   al repo.
3. Configura:
   - **Root directory**: `sparkfront/server`
   - **Build command**: `npm install`
   - **Start command**: `npm start`
4. Render asigna el puerto automáticamente vía la variable de entorno
   `PORT`, que el servidor ya lee (`server/index.js`). No hace falta tocar
   nada más.
5. Cuando termine el despliegue, comparte la URL pública (algo como
   `https://sparkfront.onrender.com`) con tus amigos: cada uno la abre en su
   navegador y ya pueden crear o unirse a una sala.

### Opción B: Railway.app

1. Sube el proyecto a GitHub.
2. En [Railway](https://railway.app/) crea un proyecto nuevo desde ese repo.
3. En la configuración del servicio, fija el **Root Directory** en
   `sparkfront/server` (o usa un `Procfile`/`railway.json` con
   `web: npm start` dentro de esa carpeta).
4. Railway también expone `PORT` automáticamente; el servidor ya está
   preparado para usarlo.
5. Comparte la URL pública generada.

> En ambas opciones, como todo el estado del juego vive en el servidor, no
> necesitas base de datos ni configuración adicional: basta con que el
> proceso Node siga corriendo.

## Notas de diseño

- El servidor es la única fuente de verdad: valida coste en energía,
  colisiones con obstáculos/edificios y que solo se pueda construir cerca de
  edificios propios (evita "invadir" la base rival desde el minuto uno).
- El movimiento usa A\* sobre una grilla de 50×50 px, así que las unidades
  esquivan cristales de energía, muros y demás edificios automáticamente.
- Si un jugador se desconecta durante la partida, su Núcleo y unidades se
  retiran del mapa y queda eliminado; la partida sigue para el resto.
