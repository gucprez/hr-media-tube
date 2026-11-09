"""Base de datos SQLite del sitio. La ruta sale de FARO_DATA_DIR (en Railway: un Volume)."""
import os
import sqlite3
from contextlib import contextmanager
from pathlib import Path

DIAS = ["Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado", "Domingo"]

HORARIOS_INICIALES = [
    ("Matutino", "Comienza la semana en la presencia de Dios."),
    ("Vigilia", "Una noche para orar y adorar juntos."),
    ("Estudio bíblico y discipulado para nuevos creyentes", "Para quienes dan sus primeros pasos en la fe."),
    ("Retiros y talleres de enseñanza bíblica", "Enseñanza bíblica para crecer en comunidad."),
    ("Cultos de ministerios", "Cada ministerio se reúne para servir y crecer."),
    ("Culto de niños, adolescentes y jóvenes", "Un espacio para las nuevas generaciones."),
    ("Cultos evangelísticos", "Una invitación abierta a conocer a Jesús."),
]

VERSICULOS_INICIALES = [
    ("«Yo soy la luz del mundo»", "Juan 8:12"),
    ("«Lámpara es á mis pies tu palabra, y lumbrera á mi camino.»", "Salmos 119:105"),
    ("«Dios es nuestro amparo y fortaleza, nuestro pronto auxilio en las tribulaciones.»", "Salmos 46:1"),
    ("«Venid á mí todos los que estáis trabajados y cargados, que yo os haré descansar.»", "Mateo 11:28"),
]

AJUSTES_INICIALES = {
    "correo": "farodeluzradio.com@gmail.com",
    "direccion": "C/ Principal Padre Granero, No. 10",
    "telefono": "",          # vacío = no se muestra (pendiente de confirmar)
    "radio_url": "",         # vacío = "Próximamente"; si se llena, se incrusta el reproductor
    "pastor_nombre": "",
    "quienes_somos": (
        "Iluminando vidas a través de la Palabra de Dios, llevando esperanza a cada corazón.\n\n"
        "Nos reunimos de lunes a domingo para orar, aprender la Biblia, servir y compartir el mensaje "
        "de salvación con niños, adolescentes, jóvenes y familias.\n\n"
        "Faro de Luz Radio nace como una extensión de esa misión: una señal de esperanza por internet."
    ),
}

ESQUEMA = """
CREATE TABLE IF NOT EXISTS noticias(id INTEGER PRIMARY KEY, titulo TEXT NOT NULL, fecha TEXT NOT NULL,
  resumen TEXT NOT NULL DEFAULT '', cuerpo TEXT NOT NULL DEFAULT '', imagen TEXT NOT NULL DEFAULT '',
  publicada INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS mensajes(id INTEGER PRIMARY KEY, titulo TEXT NOT NULL, fecha TEXT NOT NULL,
  youtube TEXT NOT NULL DEFAULT '', descripcion TEXT NOT NULL DEFAULT '', publicado INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS horarios(dia INTEGER PRIMARY KEY, actividad TEXT NOT NULL,
  descripcion TEXT NOT NULL DEFAULT '', hora TEXT NOT NULL DEFAULT '');
CREATE TABLE IF NOT EXISTS versiculos(id INTEGER PRIMARY KEY, texto TEXT NOT NULL, referencia TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS ajustes(clave TEXT PRIMARY KEY, valor TEXT NOT NULL DEFAULT '');
"""


def data_dir() -> Path:
    p = Path(os.environ.get("FARO_DATA_DIR") or Path(__file__).resolve().parent / "runtime")
    (p / "uploads").mkdir(parents=True, exist_ok=True)
    return p


@contextmanager
def conexion():
    c = sqlite3.connect(data_dir() / "faro.db")
    c.row_factory = sqlite3.Row
    try:
        yield c
        c.commit()
    finally:
        c.close()


def iniciar() -> None:
    with conexion() as c:
        c.executescript(ESQUEMA)
        if not c.execute("SELECT 1 FROM horarios").fetchone():
            c.executemany("INSERT INTO horarios(dia,actividad,descripcion) VALUES(?,?,?)",
                          [(i, a, d) for i, (a, d) in enumerate(HORARIOS_INICIALES)])
        if not c.execute("SELECT 1 FROM versiculos").fetchone():
            c.executemany("INSERT INTO versiculos(texto,referencia) VALUES(?,?)", VERSICULOS_INICIALES)
        c.executemany("INSERT OR IGNORE INTO ajustes(clave,valor) VALUES(?,?)", AJUSTES_INICIALES.items())


def ajustes() -> dict[str, str]:
    with conexion() as c:
        return {r["clave"]: r["valor"] for r in c.execute("SELECT clave,valor FROM ajustes")}
