from __future__ import annotations

from .models import Incident, PublisherKind, SourceRef, Verification


def assess(
    sources: list[SourceRef], retracted: bool = False
) -> tuple[Verification, float, str]:
    """Calcula estado y confianza solo a partir de las fuentes registradas.

    Reglas deterministas (sin IA):
    - retracted (corrección manual del admin) -> RETRACTED.
    - alguna fuente oficial -> OFFICIAL.
    - >=2 publicadores distintos -> CORROBORATED (no garantiza independencia
      editorial: dos medios pueden citar la misma agencia).
    - una sola fuente de medios -> REPORTED.
    """
    publishers = {s.publisher.lower() for s in sources}
    official = sorted({s.publisher for s in sources if s.kind == PublisherKind.OFFICIAL})
    if retracted:
        return Verification.RETRACTED, 0.0, "Marcado como desmentido o corregido por un revisor."
    if official:
        extra = len(publishers) - len(official)
        conf = min(0.99, 0.9 + 0.03 * (len(official) - 1) + 0.01 * extra)
        return (
            Verification.OFFICIAL,
            round(conf, 2),
            f"Publicado por fuente oficial: {', '.join(official)}.",
        )
    if len(publishers) >= 2:
        conf = min(0.85, 0.55 + 0.1 * (len(publishers) - 1))
        return (
            Verification.CORROBORATED,
            round(conf, 2),
            f"{len(publishers)} publicadores distintos lo reportan; no hay confirmación oficial.",
        )
    return (
        Verification.REPORTED,
        0.3,
        "Una sola fuente de medios; pendiente de confirmación.",
    )


def apply(incident: Incident, retracted: bool = False) -> Incident:
    status, conf, why = assess(incident.sources, retracted)
    incident.verification, incident.confidence, incident.confidence_rationale = status, conf, why
    return incident
