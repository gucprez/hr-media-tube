"""Genera faro/data/biblia/*.json a partir de SpaRV.json (Reina-Valera 1909, dominio público).

Fuente: https://github.com/scrollmapper/bible_databases (formats/json/SpaRV.json, licencia MIT).
Uso: python faro/tools/construir_biblia.py ruta/SpaRV.json
"""
import json
import sys
from pathlib import Path

NOMBRES = [
    ("genesis", "Génesis"), ("exodo", "Éxodo"), ("levitico", "Levítico"), ("numeros", "Números"),
    ("deuteronomio", "Deuteronomio"), ("josue", "Josué"), ("jueces", "Jueces"), ("rut", "Rut"),
    ("1-samuel", "1 Samuel"), ("2-samuel", "2 Samuel"), ("1-reyes", "1 Reyes"), ("2-reyes", "2 Reyes"),
    ("1-cronicas", "1 Crónicas"), ("2-cronicas", "2 Crónicas"), ("esdras", "Esdras"),
    ("nehemias", "Nehemías"), ("ester", "Ester"), ("job", "Job"), ("salmos", "Salmos"),
    ("proverbios", "Proverbios"), ("eclesiastes", "Eclesiastés"), ("cantares", "Cantares"),
    ("isaias", "Isaías"), ("jeremias", "Jeremías"), ("lamentaciones", "Lamentaciones"),
    ("ezequiel", "Ezequiel"), ("daniel", "Daniel"), ("oseas", "Oseas"), ("joel", "Joel"),
    ("amos", "Amós"), ("abdias", "Abdías"), ("jonas", "Jonás"), ("miqueas", "Miqueas"),
    ("nahum", "Nahúm"), ("habacuc", "Habacuc"), ("sofonias", "Sofonías"), ("hageo", "Hageo"),
    ("zacarias", "Zacarías"), ("malaquias", "Malaquías"),
    ("mateo", "Mateo"), ("marcos", "Marcos"), ("lucas", "Lucas"), ("juan", "Juan"),
    ("hechos", "Hechos"), ("romanos", "Romanos"), ("1-corintios", "1 Corintios"),
    ("2-corintios", "2 Corintios"), ("galatas", "Gálatas"), ("efesios", "Efesios"),
    ("filipenses", "Filipenses"), ("colosenses", "Colosenses"), ("1-tesalonicenses", "1 Tesalonicenses"),
    ("2-tesalonicenses", "2 Tesalonicenses"), ("1-timoteo", "1 Timoteo"), ("2-timoteo", "2 Timoteo"),
    ("tito", "Tito"), ("filemon", "Filemón"), ("hebreos", "Hebreos"), ("santiago", "Santiago"),
    ("1-pedro", "1 Pedro"), ("2-pedro", "2 Pedro"), ("1-juan", "1 Juan"), ("2-juan", "2 Juan"),
    ("3-juan", "3 Juan"), ("judas", "Judas"), ("apocalipsis", "Apocalipsis"),
]


def main(origen: str) -> None:
    datos = json.loads(Path(origen).read_text(encoding="utf-8"))
    libros = datos["books"]
    assert len(libros) == 66 == len(NOMBRES)
    destino = Path(__file__).resolve().parent.parent / "data" / "biblia"
    destino.mkdir(parents=True, exist_ok=True)
    indice = []
    for n, (libro, (slug, nombre)) in enumerate(zip(libros, NOMBRES), start=1):
        caps = [[v["text"].strip() for v in c["verses"]] for c in libro["chapters"]]
        (destino / f"{slug}.json").write_text(
            json.dumps({"slug": slug, "nombre": nombre, "capitulos": caps}, ensure_ascii=False, separators=(",", ":")),
            encoding="utf-8")
        indice.append({"slug": slug, "nombre": nombre, "capitulos": len(caps), "testamento": "AT" if n <= 39 else "NT"})
    (destino / "indice.json").write_text(json.dumps(indice, ensure_ascii=False, indent=1), encoding="utf-8")


if __name__ == "__main__":
    main(sys.argv[1])
