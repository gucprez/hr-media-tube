# Protocolo de revisión cruzada

Objetivo: que **Claude y ChatGPT revisen todo** lo que se hace, encuentren errores, los compartan y los resuelvan.

## Regla de oro
Quien escribe el código **no** lo revisa solo. Si lo escribió Claude, lo revisa ChatGPT (y viceversa). El humano decide el merge.

## Ciclo
1. **PR abierto** → CI corre automático (`ruff` + `pytest`). Si falla, el autor lo arregla antes de pedir revisión.
2. **Revisor (la otra IA)** lee el diff completo y busca:
   - Bugs y casos borde (entradas vacías, errores no manejados)
   - Seguridad (inyección, secretos, validación de entradas)
   - Rendimiento y dependencias innecesarias
   - Falta de tests para lo nuevo
3. **Cada hallazgo** se comenta en el PR en la línea exacta con este formato:
   `[severidad] problema → solución sugerida`
   Ejemplo: `[alto] /analyze no valida niche vacío → devolver 422 si está vacío`
4. **Hallazgos fuera del PR** (errores que ya existían) → issue con plantilla "Error / hallazgo de revisión".
5. **Autor** responde cada comentario: lo arregla (nuevo commit) o explica por qué no.
6. **Revisor** confirma la solución y resuelve el hilo.
7. Cuando CI está en verde y no quedan hilos abiertos → el humano mergea.

## Revisión periódica del repo completo
Cada cierto tiempo, pide a una IA: "Revisa todo el repo, busca errores y abre un issue por cada uno". Luego la otra IA confirma cada issue (¿es real?) y propone o implementa la solución en un PR.

## Prompt para pedir una revisión (copiar y pegar)
> Revisa el PR #N de gucprez/hr-media-tube siguiendo docs/REVISION.md. Busca bugs, problemas de seguridad y falta de tests. Comenta cada hallazgo en el PR con formato `[severidad] problema → solución`.
