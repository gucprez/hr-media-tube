"""Generación de voz en off gratuita (Microsoft Edge TTS, sin API key)."""
import asyncio
import os
import ssl

import edge_tts

from . import config


def _patch_ssl_for_corporate_proxy() -> None:
    """Si estamos detrás de un proxy que re-termina TLS con su propia CA
    (típico de sandboxes de CI/desarrollo), edge-tts falla porque fija su
    propio contexto SSL vía certifi. En una máquina normal el archivo no
    existe y esta función no hace nada.
    """
    bundle = config.CORPORATE_CA_BUNDLE
    if bundle and os.path.exists(bundle):
        import edge_tts.communicate as _comm

        _comm._SSL_CTX = ssl.create_default_context(cafile=bundle)


_patch_ssl_for_corporate_proxy()


async def _synthesize(text: str, out_mp3_path: str, voice: str, rate: str) -> list[dict]:
    communicate = edge_tts.Communicate(text, voice, rate=rate, boundary="WordBoundary")
    word_boundaries = []
    with open(out_mp3_path, "wb") as audio_file:
        async for chunk in communicate.stream():
            if chunk["type"] == "audio":
                audio_file.write(chunk["data"])
            elif chunk["type"] == "WordBoundary":
                word_boundaries.append(
                    {
                        "text": chunk["text"],
                        # offset/duration vienen en unidades de 100ns (ticks)
                        "start": chunk["offset"] / 10_000_000,
                        "duration": chunk["duration"] / 10_000_000,
                    }
                )
    return word_boundaries


def generate_voiceover(
    text: str,
    out_mp3_path: str,
    voice: str = config.VOICE,
    rate: str = config.VOICE_RATE,
) -> list[dict]:
    """Genera el archivo de audio y devuelve el timing palabra por palabra,
    usado luego para sincronizar los subtítulos animados."""
    return asyncio.run(_synthesize(text, out_mp3_path, voice, rate))
