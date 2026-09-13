"""Renderiza el video final: fondo animado + subtítulos sincronizados +
narración, todo vía ffmpeg (sin depender de la API de moviepy)."""
import subprocess
import textwrap

import imageio_ffmpeg
from PIL import Image, ImageDraw, ImageFont
from mutagen.mp3 import MP3

from . import config
from .background import AnimatedBackground
from .captions import caption_for_time

FFMPEG = imageio_ffmpeg.get_ffmpeg_exe()

_caption_font = ImageFont.truetype(config.FONT_BOLD, config.CAPTION_FONT_SIZE)


def _draw_caption(img: Image.Image, chunk: dict, t: float) -> None:
    text = " ".join(w["text"] for w in chunk["words"]).strip()
    if not text:
        return
    lines = textwrap.wrap(text, width=config.CAPTION_MAX_CHARS_PER_LINE)
    draw = ImageDraw.Draw(img)

    line_heights = []
    for line in lines:
        bbox = draw.textbbox((0, 0), line, font=_caption_font)
        line_heights.append(bbox[3] - bbox[1])
    total_h = sum(line_heights) + (len(lines) - 1) * 14

    y = config.HEIGHT * 0.68 - total_h / 2
    for line, lh in zip(lines, line_heights):
        bbox = draw.textbbox((0, 0), line, font=_caption_font)
        w = bbox[2] - bbox[0]
        x = (config.WIDTH - w) / 2
        draw.text(
            (x, y),
            line,
            font=_caption_font,
            fill=config.CAPTION_COLOR,
            stroke_width=config.CAPTION_STROKE_WIDTH,
            stroke_fill=config.CAPTION_STROKE_COLOR,
        )
        y += lh + 14


def render_video(
    audio_path: str,
    caption_chunks: list[dict],
    out_mp4_path: str,
    seed: int = 0,
) -> None:
    audio_duration = MP3(audio_path).info.length
    n_frames = int(audio_duration * config.FPS)

    bg = AnimatedBackground(seed=seed)

    cmd = [
        FFMPEG,
        "-y",
        "-f", "rawvideo",
        "-vcodec", "rawvideo",
        "-pix_fmt", "rgb24",
        "-s", f"{config.WIDTH}x{config.HEIGHT}",
        "-r", str(config.FPS),
        "-i", "-",
        "-i", audio_path,
        "-c:v", "libx264",
        "-pix_fmt", "yuv420p",
        "-c:a", "aac",
        "-shortest",
        out_mp4_path,
    ]

    proc = subprocess.Popen(cmd, stdin=subprocess.PIPE)
    assert proc.stdin is not None
    try:
        for i in range(n_frames):
            t = i / config.FPS
            frame = bg.frame_at(t)
            chunk = caption_for_time(caption_chunks, t)
            if chunk:
                _draw_caption(frame, chunk, t)
            proc.stdin.write(frame.tobytes())
    except BrokenPipeError:
        # ffmpeg ya cerró la lectura (p.ej. por -shortest); no hay más
        # frames útiles que enviar.
        pass
    finally:
        try:
            proc.stdin.close()
        except BrokenPipeError:
            pass
        proc.wait()
    if proc.returncode != 0:
        raise RuntimeError(f"ffmpeg terminó con código {proc.returncode}")
