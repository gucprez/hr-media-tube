import re

import pytest
from fastapi.testclient import TestClient

PNG = b"\x89PNG\r\n\x1a\n" + b"0" * 50


@pytest.fixture()
def cliente(tmp_path, monkeypatch):
    monkeypatch.setenv("FARO_DATA_DIR", str(tmp_path))
    monkeypatch.setenv("FARO_ADMIN_PASSWORD", "clave-de-prueba")
    monkeypatch.setenv("FARO_INSECURE_COOKIES", "1")
    from faro import app as modulo
    modulo.db.iniciar()
    from faro import seguridad
    seguridad._intentos.clear()
    return TestClient(modulo.app, follow_redirects=False)


def entrar(c):
    r = c.post("/admin/login", data={"clave": "clave-de-prueba"})
    assert r.status_code == 303
    return csrf(c)


def csrf(c):
    return re.search(r'name="csrf" value="([0-9a-f]+)"', c.get("/admin").text).group(1)


def test_inicio_sin_contenido_inventado(cliente):
    t = cliente.get("/").text
    assert "Próximamente" in t and "Aún no hay noticias" in t and "Aún no hay mensajes" in t
    assert "Horario por confirmar" in t and "Matutino" in t and "Cultos evangelísticos" in t
    assert "tel:" not in t  # teléfono sin confirmar: no se publica
    assert "<audio" not in t and "iframe" not in t


def test_admin_requiere_login(cliente):
    assert cliente.get("/admin").headers["location"] == "/admin/login"
    assert cliente.post("/admin/noticias/nueva", data={}).status_code == 303


def test_login_incorrecto_y_limite(cliente):
    for _ in range(5):
        assert cliente.post("/admin/login", data={"clave": "mal"}).status_code == 401
    assert cliente.post("/admin/login", data={"clave": "clave-de-prueba"}).status_code == 429


def test_sin_password_configurada(cliente, monkeypatch):
    monkeypatch.delenv("FARO_ADMIN_PASSWORD")
    assert cliente.post("/admin/login", data={"clave": ""}).status_code == 503


def test_csrf_obligatorio(cliente):
    entrar(cliente)
    r = cliente.post("/admin/noticias/nueva", data={"titulo": "x", "fecha": "2026-01-01"})
    assert r.status_code == 403


def test_noticia_crear_publicar_y_borrar(cliente):
    token = entrar(cliente)
    r = cliente.post("/admin/noticias/nueva", data={
        "csrf": token, "titulo": "Campaña <b>especial</b>", "fecha": "2026-11-09", "resumen": "Resumen",
        "cuerpo": "Párrafo uno\n\nPárrafo <script>alert(1)</script>", "publicada": "1"})
    assert r.status_code == 303
    assert "Campaña &lt;b&gt;especial&lt;/b&gt;" in cliente.get("/").text
    pagina = cliente.get("/noticias/1").text
    assert "<script>alert" not in pagina and "&lt;script&gt;" in pagina and "9 de noviembre de 2026" in pagina
    cliente.post("/admin/noticias/1", data={"csrf": token, "titulo": "Borrador", "fecha": "2026-11-09"})
    assert cliente.get("/noticias/1").status_code == 404  # sin "publicada" queda oculta
    cliente.post("/admin/noticias/1/borrar", data={"csrf": token})
    assert "Borrador" not in cliente.get("/admin/noticias").text


def test_validaciones_noticia(cliente):
    token = entrar(cliente)
    r = cliente.post("/admin/noticias/nueva", data={"csrf": token, "titulo": "", "fecha": "no-es-fecha"})
    assert r.status_code == 422 and "obligatorio" in r.text and "fecha no es válida" in r.text


def test_imagen_solo_formatos_validos(cliente):
    token = entrar(cliente)
    datos = {"csrf": token, "titulo": "Con foto", "fecha": "2026-11-09", "publicada": "1"}
    malo = cliente.post("/admin/noticias/nueva", data=datos,
                        files={"imagen_archivo": ("a.png", b"<?php echo 1;", "image/png")})
    assert malo.status_code == 422 and "JPG, PNG o WebP" in malo.text
    bueno = cliente.post("/admin/noticias/nueva", data=datos, files={"imagen_archivo": ("a.png", PNG, "image/png")})
    assert bueno.status_code == 303
    ruta = re.search(r'/uploads/([0-9a-f]{24}\.png)', cliente.get("/noticias/1").text).group(0)
    assert cliente.get(ruta).status_code == 200


def test_mensaje_youtube(cliente):
    token = entrar(cliente)
    r = cliente.post("/admin/mensajes/nuevo", data={
        "csrf": token, "titulo": "Domingo", "fecha": "2026-11-09", "publicado": "1",
        "youtube": "https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=5"})
    assert r.status_code == 303
    assert "youtube-nocookie.com/embed/dQw4w9WgXcQ" in cliente.get("/mensajes").text
    malo = cliente.post("/admin/mensajes/nuevo", data={
        "csrf": token, "titulo": "X", "fecha": "2026-11-09", "youtube": "https://evil.example/x"})
    assert malo.status_code == 422


def test_horarios_y_ajustes(cliente):
    token = entrar(cliente)
    datos = {"csrf": token}
    for i in range(7):
        datos |= {f"actividad_{i}": f"Act {i}", f"descripcion_{i}": "d", f"hora_{i}": "7:00 p. m." if i == 0 else ""}
    cliente.post("/admin/horarios", data=datos)
    t = cliente.get("/").text
    assert "Act 3" in t and "7:00 p. m." in t and "Por confirmar" in t
    base = {"csrf": token, "correo": "a@b.co", "direccion": "Calle 1", "pastor_nombre": "", "quienes_somos": "Hola",
            "telefono": "", "radio_url": ""}
    assert cliente.post("/admin/ajustes", data=base | {"radio_url": "javascript:alert(1)"}).status_code == 422
    assert cliente.post("/admin/ajustes", data=base | {"telefono": "<x>"}).status_code == 422
    cliente.post("/admin/ajustes", data=base | {"telefono": "+1 809 555 0100", "radio_url": "https://radio.example/player"})
    t = cliente.get("/").text
    assert 'href="tel:+18095550100"' in t and 'src="https://radio.example/player"' in t and "Próximamente" not in t


def test_biblia(cliente):
    r = cliente.get("/biblia/juan/8")
    assert r.status_code == 200 and "Yo soy la luz del mundo" in r.text
    assert "En el principio" in cliente.get("/biblia/genesis/1").text  # "EN" -> "En"
    assert cliente.get("/biblia/juan/999").status_code == 404
    assert cliente.get("/biblia/..%2Findice/1").status_code == 404
    assert "Génesis 50" in cliente.get("/biblia/exodo/1").text  # capítulo anterior cruza de libro
    assert "Juan 8:12" in cliente.get("/biblia", params={"q": "yo soy la luz del mundo"}).text
