"""Configuración central del generador de videos."""
import os

# Formato vertical (YouTube Shorts / TikTok / Reels).
WIDTH = 1080
HEIGHT = 1920
FPS = 24

# Voz gratuita de Microsoft Edge TTS (sin API key). Lista completa:
# `edge-tts --list-voices`
VOICE = "es-ES-AlvaroNeural"
VOICE_RATE = "+2%"

FONT_BOLD = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
FONT_REGULAR = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"

CAPTION_FONT_SIZE = 78
CAPTION_MAX_CHARS_PER_LINE = 22
CAPTION_COLOR = (255, 255, 255)
CAPTION_HIGHLIGHT_COLOR = (255, 209, 0)
CAPTION_STROKE_COLOR = (0, 0, 0)
CAPTION_STROKE_WIDTH = 6

OUTPUT_DIR = os.path.join(os.path.dirname(__file__), "output")

# --- Compatibilidad con proxies corporativos / sandboxes de desarrollo ---
# edge-tts fija su propio contexto SSL (via certifi) e ignora las variables
# de entorno estándar (SSL_CERT_FILE, REQUESTS_CA_BUNDLE...). En redes con
# un proxy que re-termina TLS con su propia CA (habitual en entornos de CI o
# corporativos) hay que apuntarlo explícitamente a ese bundle o la conexión
# falla con "self-signed certificate in certificate chain". En una máquina
# normal esta variable no existe y edge-tts usa su comportamiento por defecto.
CORPORATE_CA_BUNDLE = os.environ.get("EDGE_TTS_CA_BUNDLE", "/root/.ccr/ca-bundle.crt")
