"""Fondo animado generado por código (gradiente + partículas), sin depender
de ningún banco de imágenes/video externo ni claves de API."""
import colorsys
import random

import numpy as np
from PIL import Image, ImageDraw

from . import config


class AnimatedBackground:
    def __init__(self, width: int = config.WIDTH, height: int = config.HEIGHT, seed: int = 0):
        self.width = width
        self.height = height
        rng = random.Random(seed)

        # Gradiente diagonal precalculado una sola vez (factor 0..1 por pixel).
        yy, xx = np.mgrid[0:height, 0:width]
        self.gradient_factor = ((xx / width) * 0.5 + (yy / height) * 0.5).astype(np.float32)

        # Viñeta suave para dar profundidad.
        cy, cx = height / 2, width / 2
        dist = np.sqrt((xx - cx) ** 2 + (yy - cy) ** 2)
        max_dist = np.sqrt(cx**2 + cy**2)
        self.vignette = (1.0 - 0.35 * (dist / max_dist) ** 2).astype(np.float32)

        # Partículas flotantes (posición, velocidad, tamaño, fase).
        self.particles = [
            {
                "x": rng.uniform(0, width),
                "y": rng.uniform(0, height),
                "vy": rng.uniform(-25, -8),
                "r": rng.uniform(2, 5),
                "phase": rng.uniform(0, 6.28),
            }
            for _ in range(22)
        ]

    def _colors_at(self, t: float):
        hue = (0.58 + 0.05 * np.sin(t * 0.15)) % 1.0
        c1 = np.array(colorsys.hsv_to_rgb(hue, 0.55, 0.12)) * 255
        c2 = np.array(colorsys.hsv_to_rgb((hue + 0.08) % 1.0, 0.6, 0.30)) * 255
        return c1.astype(np.float32), c2.astype(np.float32)

    def frame_at(self, t: float) -> Image.Image:
        c1, c2 = self._colors_at(t)
        rgb = c1 + (c2 - c1) * self.gradient_factor[..., None]
        rgb *= self.vignette[..., None]
        rgb = np.clip(rgb, 0, 255).astype(np.uint8)
        img = Image.fromarray(rgb, mode="RGB")

        draw = ImageDraw.Draw(img, "RGBA")
        for p in self.particles:
            y = (p["y"] + p["vy"] * t) % self.height
            x = p["x"] + 12 * np.sin(t * 0.6 + p["phase"])
            alpha = int(70 + 60 * np.sin(t * 0.8 + p["phase"]))
            r = p["r"]
            draw.ellipse(
                [x - r, y - r, x + r, y + r],
                fill=(255, 255, 255, max(0, min(255, alpha))),
            )
        return img
