"""Pruebas de los límites de /v1/import-reviews con el proveedor demo (sin red ni Apify).

Ejecutar desde backend/:  python -m pytest -q tests  (o  python tests/test_access_guard.py)
"""
import os
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

os.environ["MRG_SAAS_DB_PATH"] = os.path.join(tempfile.mkdtemp(), "t.sqlite3")
os.environ["MRG_REVIEW_PROVIDER"] = "demo"
os.environ["MRG_LIMIT_LIVE_PER_SITE_DAY"] = "2"
os.environ["MRG_LIMIT_LIVE_GLOBAL_DAY"] = "3"
os.environ["MRG_LIMIT_REQ_PER_IP_HOUR"] = "5"

from access_guard import AccessGuard, LimitExceeded  # noqa: E402
from schemas import ImportRequest  # noqa: E402
from service import ReviewImportService  # noqa: E402


def req(n, site="https://www.cliente-a.es"):
    return ImportRequest(maps_url=f"https://www.google.com/maps/place/negocio-{n}", site_url=site)


def expect_limit(fn, msg):
    try:
        fn()
    except LimitExceeded:
        return
    raise AssertionError("debía saltar el límite: " + msg)


def test_all():
    svc = ReviewImportService()
    g = AccessGuard()
    assert g.site_key("https://www.Cliente-A.es/tienda", "1.1.1.1") == "cliente-a.es"
    assert g.site_key(None, "1.1.1.1") == "ip:1.1.1.1"

    # Dos scrapes en vivo de la misma web: bien. La misma ficha otra vez: sale de caché y no cuenta.
    svc.import_reviews(req(1), ip="1.1.1.1")
    svc.import_reviews(req(2), ip="1.1.1.1")
    svc.import_reviews(req(1), ip="1.1.1.1")
    # Tercer scrape en vivo de esa web: límite por web y día.
    expect_limit(lambda: svc.import_reviews(req(3), ip="1.1.1.1"), "por web")
    # Otra web puede, hasta el tope global (3).
    svc.import_reviews(req(4, "https://cliente-b.es"), ip="2.2.2.2")
    expect_limit(lambda: svc.import_reviews(req(5, "https://cliente-c.es"), ip="3.3.3.3"), "global")
    # Peticiones por IP y hora: 1.1.1.1 lleva 3 registradas; con 5 se corta.
    g.log("1.1.1.1", "x", "k", False, "cache")
    g.log("1.1.1.1", "x", "k", False, "cache")
    expect_limit(lambda: g.check_request("1.1.1.1"), "por IP")
    g.check_request("9.9.9.9")
    print("OK: límites por web, globales y por IP; la caché no gasta cupo")


if __name__ == "__main__":
    test_all()
