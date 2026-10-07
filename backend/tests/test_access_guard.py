"""Pruebas de los límites de /v1/import-reviews con el proveedor demo (sin red ni Apify).

Ejecutar desde backend/:  python tests/test_access_guard.py
"""
import os
import sys
import tempfile
import threading

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

os.environ["MRG_SAAS_DB_PATH"] = os.path.join(tempfile.mkdtemp(), "t.sqlite3")
os.environ["MRG_REVIEW_PROVIDER"] = "demo"
os.environ["MRG_LIMIT_LIVE_PER_SITE_DAY"] = "2"
os.environ["MRG_LIMIT_LIVE_PER_IP_DAY"] = "3"
os.environ["MRG_LIMIT_LIVE_GLOBAL_DAY"] = "4"
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


def test_site_key():
    k = AccessGuard.site_key
    assert k("https://www.Cliente-A.es/tienda", "1.1.1.1") == "cliente-a.es"
    assert k("https://user:pw@cliente-a.es:8443/x", "1.1.1.1") == "cliente-a.es"
    assert k(None, "1.1.1.1") == "ip:1.1.1.1"


def test_live_limits():
    svc = ReviewImportService()
    # Dos scrapes en vivo de la misma web: bien. La misma ficha otra vez sale de caché y no gasta cupo.
    svc.import_reviews(req(1), ip="1.1.1.1")
    svc.import_reviews(req(2), ip="1.1.1.1")
    svc.import_reviews(req(1), ip="1.1.1.1")
    expect_limit(lambda: svc.import_reviews(req(3), ip="1.1.1.1"), "por web")
    # Cambiar el site_url no basta: la misma IP tiene su propio tope diario (3).
    svc.import_reviews(req(4, "https://otra.es"), ip="1.1.1.1")
    expect_limit(lambda: svc.import_reviews(req(5, "https://otra-mas.es"), ip="1.1.1.1"), "por IP")
    # Otra IP y otra web, hasta el tope global (4).
    svc.import_reviews(req(6, "https://cliente-b.es"), ip="2.2.2.2")
    expect_limit(lambda: svc.import_reviews(req(7, "https://cliente-c.es"), ip="3.3.3.3"), "global")


def test_no_bloquear_a_otra_web():
    os.environ["MRG_SAAS_DB_PATH"] = os.path.join(tempfile.mkdtemp(), "d.sqlite3")
    g = AccessGuard()
    # Un tercero declara el dominio de la víctima y agota «su» cupo…
    g.reserve_live("6.6.6.6", "victima.es", "a")
    g.reserve_live("6.6.6.6", "victima.es", "b")
    expect_limit(lambda: g.reserve_live("6.6.6.6", "victima.es", "c"), "atacante")
    # …pero la víctima, desde su IP, sigue pudiendo importar.
    g.reserve_live("5.5.5.5", "victima.es", "d")


def test_admit_per_ip_hour():
    g = AccessGuard()
    for _ in range(5):
        g.admit("7.7.7.7", "x", "k")
    expect_limit(lambda: g.admit("7.7.7.7", "x", "k"), "peticiones por IP y hora")
    g.admit("8.8.8.8", "x", "k")


def test_concurrent_reservations():
    # Base nueva: 20 hilos (IPs distintas) reservan a la vez; nunca más que el tope global (4).
    os.environ["MRG_SAAS_DB_PATH"] = os.path.join(tempfile.mkdtemp(), "c.sqlite3")
    g = AccessGuard()
    ok, lock = [], threading.Lock()

    def worker(i):
        try:
            AccessGuard().reserve_live(f"10.0.0.{i}", "concurrente.es", f"k{i}")
            with lock:
                ok.append(i)
        except LimitExceeded:
            pass

    threads = [threading.Thread(target=worker, args=(i,)) for i in range(20)]
    [t.start() for t in threads]
    [t.join() for t in threads]
    assert len(ok) == 4, ok
    del g


if __name__ == "__main__":
    test_site_key()
    test_live_limits()
    test_admit_per_ip_hour()
    test_no_bloquear_a_otra_web()
    test_concurrent_reservations()
    print("OK: dominio normalizado, límites por web/IP/global, por IP y hora, y reservas atómicas en concurrencia")
