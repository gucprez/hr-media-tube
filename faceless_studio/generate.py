"""CLI: genera video + miniatura + metadata para uno o todos los guiones.

Uso:
    python -m faceless_studio.generate            # genera todos los guiones
    python -m faceless_studio.generate 01_pulpo_corazones   # uno solo
"""
import argparse
import os
import sys

from . import config
from .captions import build_caption_chunks
from .render import render_video
from .scripts_data import SCRIPTS
from .thumbnail import make_thumbnail
from .tts import generate_voiceover


def generate_one(entry: dict, seed: int) -> str:
    out_dir = os.path.join(config.OUTPUT_DIR, entry["id"])
    os.makedirs(out_dir, exist_ok=True)

    audio_path = os.path.join(out_dir, "voiceover.mp3")
    video_path = os.path.join(out_dir, "video.mp4")
    thumb_path = os.path.join(out_dir, "thumbnail.jpg")
    meta_path = os.path.join(out_dir, "metadata.txt")

    print(f"[{entry['id']}] generando voz...")
    word_boundaries = generate_voiceover(entry["script"], audio_path)

    print(f"[{entry['id']}] generando video ({len(word_boundaries)} palabras)...")
    caption_chunks = build_caption_chunks(word_boundaries)
    render_video(audio_path, caption_chunks, video_path, seed=seed)

    print(f"[{entry['id']}] generando miniatura...")
    make_thumbnail(entry["title"], thumb_path, seed=seed)

    with open(meta_path, "w", encoding="utf-8") as f:
        f.write(f"TITULO:\n{entry['title']}\n\n")
        f.write(f"DESCRIPCION:\n{entry['description']}\n\n")
        f.write(f"TAGS:\n{', '.join(entry['tags'])}\n")

    print(f"[{entry['id']}] listo -> {out_dir}")
    return out_dir


def main() -> None:
    parser = argparse.ArgumentParser(description="Genera videos faceless de curiosidades.")
    parser.add_argument(
        "script_id",
        nargs="?",
        help="id de un guion puntual (ver faceless_studio/scripts_data.py). "
        "Si se omite, genera todos.",
    )
    args = parser.parse_args()

    os.makedirs(config.OUTPUT_DIR, exist_ok=True)

    if args.script_id:
        matches = [s for s in SCRIPTS if s["id"] == args.script_id]
        if not matches:
            print(f"No existe el guion '{args.script_id}'.", file=sys.stderr)
            sys.exit(1)
        targets = matches
    else:
        targets = SCRIPTS

    for i, entry in enumerate(targets):
        generate_one(entry, seed=i)


if __name__ == "__main__":
    main()
