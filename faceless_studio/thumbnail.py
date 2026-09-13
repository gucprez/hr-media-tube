"""Genera una miniatura llamativa (PIL puro, sin bancos de imágenes)."""
import colorsys
import textwrap

import numpy as np
from PIL import Image, ImageDraw, ImageFont

from . import config

THUMB_W, THUMB_H = 1080, 1920


def make_thumbnail(title: str, out_path: str, seed: int = 0) -> None:
    hue = (seed * 0.17) % 1.0
    top = np.array(colorsys.hsv_to_rgb(hue, 0.65, 0.85)) * 255
    bottom = np.array(colorsys.hsv_to_rgb((hue + 0.12) % 1.0, 0.7, 0.35)) * 255

    yy = np.linspace(0, 1, THUMB_H).reshape(-1, 1, 1)
    rgb = top + (bottom - top) * yy  # (THUMB_H, 1, 3)
    rgb = np.broadcast_to(rgb, (THUMB_H, THUMB_W, 3))
    img = Image.fromarray(np.clip(rgb, 0, 255).astype(np.uint8), "RGB")

    draw = ImageDraw.Draw(img, "RGBA")
    draw.rectangle([0, THUMB_H * 0.55, THUMB_W, THUMB_H], fill=(0, 0, 0, 140))

    font = ImageFont.truetype(config.FONT_BOLD, 96)
    lines = textwrap.wrap(title.upper(), width=14)
    line_heights = []
    for line in lines:
        bbox = draw.textbbox((0, 0), line, font=font)
        line_heights.append(bbox[3] - bbox[1])
    total_h = sum(line_heights) + (len(lines) - 1) * 20

    y = THUMB_H * 0.62
    for line, lh in zip(lines, line_heights):
        bbox = draw.textbbox((0, 0), line, font=font)
        w = bbox[2] - bbox[0]
        x = (THUMB_W - w) / 2
        draw.text(
            (x, y),
            line,
            font=font,
            fill=(255, 255, 255),
            stroke_width=8,
            stroke_fill=(0, 0, 0),
        )
        y += lh + 20

    img.save(out_path, quality=92)
