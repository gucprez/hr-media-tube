import json
import os
import sqlite3
from contextlib import contextmanager
from datetime import datetime

DB_PATH = os.environ.get("DB_PATH", "data/app.db")


def _ensure_dir():
    directory = os.path.dirname(DB_PATH)
    if directory:
        os.makedirs(directory, exist_ok=True)


@contextmanager
def get_conn():
    _ensure_dir()
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    try:
        yield conn
        conn.commit()
    finally:
        conn.close()


def init_db():
    with get_conn() as conn:
        conn.execute(
            """
            CREATE TABLE IF NOT EXISTS items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                section TEXT NOT NULL,
                position INTEGER NOT NULL DEFAULT 0,
                active INTEGER NOT NULL DEFAULT 1,
                data TEXT NOT NULL DEFAULT '{}',
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
            """
        )
        conn.execute(
            "CREATE INDEX IF NOT EXISTS idx_items_section ON items(section, position)"
        )
    _seed_if_empty()


def _row_to_dict(row):
    payload = json.loads(row["data"])
    payload["id"] = row["id"]
    payload["position"] = row["position"]
    payload["active"] = bool(row["active"])
    return payload


def list_items(section, active_only=False):
    with get_conn() as conn:
        if active_only:
            rows = conn.execute(
                "SELECT * FROM items WHERE section = ? AND active = 1 ORDER BY position",
                (section,),
            ).fetchall()
        else:
            rows = conn.execute(
                "SELECT * FROM items WHERE section = ? ORDER BY position",
                (section,),
            ).fetchall()
    return [_row_to_dict(r) for r in rows]


def get_item(section, item_id):
    with get_conn() as conn:
        row = conn.execute(
            "SELECT * FROM items WHERE section = ? AND id = ?", (section, item_id)
        ).fetchone()
    return _row_to_dict(row) if row else None


def get_item_by(section, key, value):
    with get_conn() as conn:
        rows = conn.execute(
            "SELECT * FROM items WHERE section = ? AND active = 1", (section,)
        ).fetchall()
    for r in rows:
        d = _row_to_dict(r)
        if d.get(key) == value:
            return d
    return None


def create_item(section, data):
    with get_conn() as conn:
        max_pos = conn.execute(
            "SELECT COALESCE(MAX(position), -1) FROM items WHERE section = ?",
            (section,),
        ).fetchone()[0]
        cur = conn.execute(
            "INSERT INTO items (section, position, active, data) VALUES (?, ?, 1, ?)",
            (section, max_pos + 1, json.dumps(data)),
        )
        return cur.lastrowid


def update_item(section, item_id, data):
    with get_conn() as conn:
        conn.execute(
            "UPDATE items SET data = ? WHERE section = ? AND id = ?",
            (json.dumps(data), section, item_id),
        )


def delete_item(section, item_id):
    with get_conn() as conn:
        conn.execute(
            "DELETE FROM items WHERE section = ? AND id = ?", (section, item_id)
        )


def toggle_active(section, item_id):
    with get_conn() as conn:
        conn.execute(
            "UPDATE items SET active = 1 - active WHERE section = ? AND id = ?",
            (section, item_id),
        )


def move_item(section, item_id, direction):
    with get_conn() as conn:
        rows = conn.execute(
            "SELECT id, position FROM items WHERE section = ? ORDER BY position",
            (section,),
        ).fetchall()
        ids = [r["id"] for r in rows]
        if item_id not in ids:
            return
        idx = ids.index(item_id)
        swap_idx = idx - 1 if direction == "up" else idx + 1
        if swap_idx < 0 or swap_idx >= len(ids):
            return
        a_id, b_id = ids[idx], ids[swap_idx]
        a_pos, b_pos = rows[idx]["position"], rows[swap_idx]["position"]
        conn.execute(
            "UPDATE items SET position = ? WHERE id = ?", (b_pos, a_id)
        )
        conn.execute(
            "UPDATE items SET position = ? WHERE id = ?", (a_pos, b_id)
        )


def _seed_if_empty():
    with get_conn() as conn:
        count = conn.execute("SELECT COUNT(*) FROM items").fetchone()[0]
    if count > 0:
        return

    destinos = [
        {
            "name": "Medellín",
            "country": "Colombia",
            "tagline": "La ciudad de la eterna primavera",
            "description": "Comuna 13, Guatapé, El Peñol, tours de café y la mejor vida nocturna paisa.",
            "image": "img/medellin.jpg",
        },
        {
            "name": "Bogotá",
            "country": "Colombia",
            "tagline": "Historia, cultura y montaña",
            "description": "Monserrate, La Candelaria, Museo del Oro y los mejores planes en la capital.",
            "image": "img/bogota.jpg",
        },
        {
            "name": "Perú",
            "country": "Perú",
            "tagline": "Machu Picchu te espera",
            "description": "Cusco, Valle Sagrado, Machu Picchu y la magia de los Andes.",
            "image": "img/peru.jpg",
        },
        {
            "name": "México",
            "country": "México",
            "tagline": "Cultura, playas y sabor",
            "description": "Chichén Itzá, Cancún, Ciudad de México y mucho más.",
            "image": "img/mexico.jpg",
        },
        {
            "name": "Estados Unidos",
            "country": "USA",
            "tagline": "Grandes ciudades, grandes experiencias",
            "description": "Nueva York, Miami, Orlando y los destinos favoritos en USA.",
            "image": "img/usa.jpg",
        },
        {
            "name": "República Dominicana",
            "country": "Puerto Plata y otras ciudades",
            "tagline": "El Caribe en su máxima expresión",
            "description": "Puerto Plata, Santo Domingo, Punta Cana, Samaná y las mejores playas del país.",
            "image": "img/rd.jpg",
        },
    ]
    cruceros = [
        {
            "name": "27 Charcos de Damajagua",
            "description": "Salta, resbala y nada en las cascadas naturales de Damajagua, a solo 20 minutos de Amber Cove.",
            "image": "img/damajagua.jpg",
        },
        {
            "name": "Teleférico y Pico Isabel de Torres",
            "description": "Sube en teleférico hasta la cima del Pico Isabel de Torres y disfruta la mejor vista panorámica de Puerto Plata.",
            "image": "img/teleferico.jpg",
        },
        {
            "name": "Fortaleza San Felipe y Malecón",
            "description": "Recorre la fortaleza colonial del siglo XVI y el histórico Malecón, a pocos minutos del puerto.",
            "image": "img/fortaleza.jpg",
        },
    ]
    ofertas = [
        {
            "title": "Oferta de temporada: Puerto Plata todo incluido",
            "discount_label": "20% OFF",
            "description": "Reserva tu excursión de playa + almuerzo antes de fin de mes y obtén 20% de descuento por persona.",
            "valid_until": "",
            "image": "img/rd.jpg",
        },
    ]
    noticias = [
        {
            "title": "Consejos para comprar tu boleto de avión más barato",
            "slug": "consejos-boleto-de-avion-barato",
            "excerpt": "Te compartimos las mejores fechas y trucos para conseguir tarifas aéreas más económicas.",
            "content": "Comprar con anticipación, viajar en temporada baja y ser flexible con las fechas son claves para conseguir mejores precios. Escríbenos por WhatsApp y te ayudamos a comparar tarifas para tu próximo viaje.",
            "category": "Vuelos",
            "published_at": datetime.now().strftime("%Y-%m-%d"),
            "image": "img/usa.jpg",
        },
    ]

    for d in destinos:
        create_item("destinos", d)
    for c in cruceros:
        create_item("cruceros", c)
    for o in ofertas:
        create_item("ofertas", o)
    for n in noticias:
        create_item("noticias", n)
