"""Noticias / anuncios de la iglesia y la radio.

Agrega nuevas entradas al inicio de la lista.
"""

from datetime import date

NEWS = [
    {
        "title": "¡Bienvenidos al nuevo sitio de Faro de Luz Radio!",
        "date": date.today().isoformat(),
        "excerpt": (
            "Renovamos por completo nuestra página web para que sea más "
            "fácil seguir nuestra agenda semanal, conocer a la iglesia y "
            "compartir mensajes de fe con tus seres queridos. Nuestra "
            "transmisión en vivo está en preparación."
        ),
        "slug": "bienvenidos-nuevo-sitio",
    },
]
