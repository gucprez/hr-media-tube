from fastapi.testclient import TestClient

from main import app

client = TestClient(app)


def test_home():
    r = client.get("/")
    assert r.status_code == 200
    assert "HR Media Tube" in r.text


def test_analyze():
    r = client.post("/analyze", data={"niche": "fitness"})
    assert r.status_code == 200
    assert "fitness" in r.json()["mensaje"]
