# Guía para agentes de IA (Claude, ChatGPT/Codex, etc.)

Este archivo es la **fuente única de reglas** para cualquier IA que trabaje en este repo.
`CLAUDE.md` apunta aquí. Si cambias una regla, cámbiala solo en este archivo.

## Proyecto
HR Media Tube: app FastAPI desplegada en Railway.
- `main.py` – app y rutas
- `requirements.txt` – dependencias
- `Procfile.txt` – comando de arranque (`uvicorn main:app`)

## Comandos
```bash
pip install -r requirements.txt
uvicorn main:app --reload      # desarrollo local
```

## Flujo de trabajo (obligatorio)
1. Cada tarea parte de un **issue**. Antes de empezar, asígnate el issue o comenta "Tomo esto (Claude)" / "Tomo esto (ChatGPT)".
2. **Nunca** hagas push directo a `main`. Trabaja en una rama propia y abre un Pull Request.
3. Nombres de rama: `claude/<tema>` o `chatgpt/<tema>`.
4. Un PR = un cambio pequeño y enfocado. Describe qué cambió y cómo probarlo.
5. **La otra IA (o el humano) revisa el PR** antes de mergear. Quien escribió el código no lo mergea solo.
6. Antes de empezar: `git pull origin main` para no trabajar sobre código viejo.
7. No toques archivos que otro PR abierto esté modificando; revisa los PRs abiertos primero.

## Convenciones
- Commits en español o inglés, imperativo y corto: `Agrega endpoint /analyze`.
- Python con tipos donde sea razonable; sin dependencias nuevas sin añadirlas a `requirements.txt`.
- Nunca subas secretos (`.env`, tokens, API keys). Usa variables de entorno de Railway.

## Traspaso entre IAs
Al terminar una sesión, deja el estado en el issue o PR: qué quedó hecho, qué falta, y decisiones tomadas.
Ver `docs/COLABORACION.md`.
