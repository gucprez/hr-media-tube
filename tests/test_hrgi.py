from datetime import UTC, datetime, timedelta

import pytest
from defusedxml.common import EntitiesForbidden
from fastapi.testclient import TestClient

from hrgi.connectors import eonet, gdacs, gdelt, usgs
from hrgi.dedupe import same_event
from hrgi.models import (
    Category,
    DataClass,
    Incident,
    PublisherKind,
    SourceRef,
    Verification,
)
from hrgi.store import Store
from hrgi.verification import assess
from main import app

NOW = datetime(2026, 10, 29, 12, 0, tzinfo=UTC)


def src(publisher, kind=PublisherKind.MEDIA, ext="1"):
    return SourceRef(connector="t", publisher=publisher, kind=kind, url="https://x", external_id=ext, retrieved_at=NOW)


def inc(id="a", title="Drone attack hits city", cat=Category.MISSILE_DRONE, lat=None, lon=None, t=NOW, sources=None):
    return Incident(id=id, category=cat, title=title, lat=lat, lon=lon, occurred_at=t, published_at=t,
                    updated_at=t, sources=sources or [src("a.com", ext=id)])


def test_verification_levels():
    assert assess([src("a.com")])[0] == Verification.REPORTED
    assert assess([src("a.com"), src("b.com", ext="2")])[0] == Verification.CORROBORATED
    assert assess([src("a.com"), src("USGS", PublisherKind.OFFICIAL, "3")])[0] == Verification.OFFICIAL
    assert assess([src("USGS", PublisherKind.OFFICIAL)], retracted=True)[0] == Verification.RETRACTED


def test_same_source_twice_is_not_corroboration():
    assert assess([src("a.com", ext="1"), src("A.com", ext="2")])[0] == Verification.REPORTED


def test_dedupe_by_distance_time_and_category():
    a = inc("a", cat=Category.EARTHQUAKE, lat=10, lon=10)
    assert same_event(a, inc("b", cat=Category.EARTHQUAKE, lat=10.1, lon=10.1, t=NOW + timedelta(hours=1)))
    assert not same_event(a, inc("c", cat=Category.EARTHQUAKE, lat=20, lon=20))
    assert not same_event(a, inc("d", cat=Category.EARTHQUAKE, lat=10, lon=10, t=NOW + timedelta(days=2)))
    assert not same_event(a, inc("e", cat=Category.STORM, lat=10, lon=10))


def test_store_merges_news_into_corroborated():
    s = Store()
    s.ingest([inc("a", "Russia launches drone attack on Kyiv overnight", sources=[src("a.com", ext="a")])])
    s.ingest([inc("b", "Drone attack on Kyiv overnight launched by Russia", sources=[src("b.com", ext="b")])])
    items = s.query()
    assert len(items) == 1 and items[0].verification == Verification.CORROBORATED
    assert len(items[0].sources) == 2


def test_store_retract_and_reingest_keeps_retraction():
    s = Store()
    s.ingest([inc("a")])
    assert s.set_retracted("a", True).verification == Verification.RETRACTED
    s.ingest([inc("a2", sources=[src("z.com", ext="z")])])
    assert s.get("a").verification == Verification.RETRACTED


def test_data_class():
    assert inc().data_class(NOW) == DataClass.CURRENT
    assert inc(t=NOW - timedelta(days=5)).data_class(NOW) == DataClass.HISTORICAL


def test_usgs_parse():
    data = {"features": [{"id": "us1", "properties": {"type": "earthquake", "time": 1793244000000,
            "updated": 1793244600000, "mag": 5.1, "place": "10 km N of X", "title": "M 5.1 - X",
            "url": "https://earthquake.usgs.gov/e/us1"}, "geometry": {"coordinates": [10.0, 20.0, 5]}}]}
    [i] = usgs.parse(data, NOW)
    assert (i.lat, i.lon, i.severity) == (20.0, 10.0, "M5.1")
    assert i.occurred_at < i.published_at  # hora del hecho distinta de la publicación


def test_gdacs_parse():
    xml = b"""<rss xmlns:gdacs="http://www.gdacs.org" xmlns:geo="http://www.w3.org/2003/01/geo/wgs84_pos#"><channel><item>
    <title>Green earthquake in Indonesia</title><link>http://www.gdacs.org/report.aspx?eventid=1</link>
    <pubDate>Wed, 28 Oct 2026 22:20:23 GMT</pubDate><gdacs:fromdate>Wed, 28 Oct 2026 22:06:44 GMT</gdacs:fromdate>
    <gdacs:datemodified>Thu, 29 Oct 2026 00:11:24 GMT</gdacs:datemodified><geo:Point><geo:lat>-6.3</geo:lat>
    <geo:long>130.4</geo:long></geo:Point><gdacs:eventtype>EQ</gdacs:eventtype><gdacs:eventid>1</gdacs:eventid>
    <gdacs:alertlevel>Green</gdacs:alertlevel><gdacs:country>Indonesia</gdacs:country></item></channel></rss>"""
    [i] = gdacs.parse(xml, NOW)
    assert i.country == "Indonesia" and i.category == Category.EARTHQUAKE
    assert i.sources[0].url.startswith("https://")


def test_gdacs_rejects_xml_entities():
    with pytest.raises(EntitiesForbidden):
        gdacs.parse(b'<!DOCTYPE r [<!ENTITY x "boom">]><rss><channel><item>&x;</item></channel></rss>', NOW)


def test_eonet_parse():
    data = {"events": [{"id": "E1", "title": "Fire X", "link": "https://eonet/E1",
            "categories": [{"id": "wildfires"}], "geometry": [
                {"type": "Point", "date": "2026-10-27T00:00:00Z", "coordinates": [1, 2]},
                {"type": "Point", "date": "2026-10-28T00:00:00Z", "coordinates": [3, 4]}]}]}
    [i] = eonet.parse(data, NOW)
    assert i.category == Category.WILDFIRE and (i.lat, i.lon) == (4, 3)


def test_gdelt_never_invents_location_or_event_time():
    data = {"articles": [{"url": "https://news.example/a", "title": "Missile attack reported", "seendate":
            "20261028T101500Z", "domain": "news.example", "sourcecountry": "Russia"}]}
    [i] = gdelt.parse(data, Category.MISSILE_DRONE, NOW)
    assert i.lat is None and i.country is None and i.occurred_at is None
    assert i.verification == Verification.REPORTED


@pytest.fixture
def client(monkeypatch):
    monkeypatch.setenv("ADMIN_TOKEN", "secret")
    with TestClient(app) as c:
        c.app.state.hrgi.store.ingest([inc("a", cat=Category.EARTHQUAKE, lat=1, lon=1, sources=[src("USGS", PublisherKind.OFFICIAL)])])
        yield c


def test_api_filters(client):
    assert client.get("/api/incidents").json()["count"] == 1
    assert client.get("/api/incidents?category=storm").json()["count"] == 0
    assert client.get("/api/incidents?verification=official").json()["count"] == 1
    assert client.get("/api/incidents?data_class=historical").json()["count"] == 0
    assert client.get("/api/incidents?category=bogus").status_code == 422


def test_admin_requires_token(client):
    assert client.post("/api/admin/incidents/a/retract").status_code == 401
    assert client.post("/api/admin/incidents/a/retract", headers={"Authorization": "Bearer nope"}).status_code == 401
    r = client.post("/api/admin/incidents/a/retract", headers={"Authorization": "Bearer secret"})
    assert r.json()["verification"] == "retracted"


def test_admin_disabled_without_token(monkeypatch):
    monkeypatch.delenv("ADMIN_TOKEN", raising=False)
    with TestClient(app) as c:
        assert c.post("/api/admin/ingest", headers={"Authorization": "Bearer "}).status_code == 401


def test_rate_limit(client):
    codes = [client.get("/api/sources").status_code for _ in range(62)]
    assert codes[-1] == 429
