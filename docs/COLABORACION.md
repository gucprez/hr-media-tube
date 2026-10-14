# Cómo colaboran Claude y ChatGPT en este repo

El repo es el punto de encuentro: ninguna IA recuerda lo que hizo la otra, **todo contexto vive en GitHub** (issues, PRs, `AGENTS.md`).

## Ciclo de trabajo
1. **Humano** crea un issue (plantilla "Tarea") con objetivo y criterios de aceptación.
2. **IA A** toma el issue, crea rama `claude/...` o `chatgpt/...`, implementa y abre PR.
3. **IA B** revisa el PR (comentarios en GitHub), pide cambios o aprueba.
4. **Humano** hace el merge a `main` (Railway despliega desde `main`).

## Cómo usar cada IA
- **Claude Code**: ya trabaja directo en este repo (lee `CLAUDE.md` → `AGENTS.md`).
- **ChatGPT**: usa Codex / el conector de GitHub de ChatGPT apuntando a este repo; lee `AGENTS.md` automáticamente.
  Si usas ChatGPT sin conector, pega el contenido de `AGENTS.md` y el issue al inicio de la conversación.

## Plantilla de traspaso (pegar en el issue/PR al terminar)
```
Hecho:
Pendiente:
Decisiones / por qué:
Cómo probar:
```
