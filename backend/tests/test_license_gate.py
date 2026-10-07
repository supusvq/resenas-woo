"""Pruebas de la licencia SupuHub (license_gate.py) sin red: el HTTP es un stub.

Ejecutar desde backend/:  python tests/test_license_gate.py
"""
import os
import sys
import tempfile
import threading
from unittest.mock import patch

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

os.environ["MRG_SAAS_DB_PATH"] = os.path.join(tempfile.mkdtemp(), "base.sqlite3")
os.environ["MRG_REVIEW_PROVIDER"] = "demo"
os.environ.pop("MRG_SUPUHUB_VERIFY_TOKEN", None)
os.environ.pop("MRG_LICENSE_MODE", None)

import sqlite3  # noqa: E402

import license_gate  # noqa: E402
from license_gate import (  # noqa: E402
    GRACE_TTL, INVALID_TTL, MSG_SIN_LICENCIA, VALID_TTL, LicenseDenied, LicenseGate, license_mode,
)

H = 3600


class Resp:
    def __init__(self, status, data=None):
        self.status_code = status
        self._data = data

    def json(self):
        if isinstance(self._data, Exception):
            raise self._data
        return self._data


class Stub:
    """Sustituye a requests.post. `respuestas` es una lista de Resp o excepciones (la última se repite)."""

    def __init__(self, *respuestas):
        self.respuestas = list(respuestas)
        self.calls = []
        self.lock = threading.Lock()

    def __call__(self, url, json, headers, timeout):
        with self.lock:
            self.calls.append((url, json, headers, timeout))
            r = self.respuestas.pop(0) if len(self.respuestas) > 1 else self.respuestas[0]
        if isinstance(r, Exception):
            raise r
        return r


class Clock:
    def __init__(self, t=1_800_000_000.0):
        self.t = t

    def __call__(self):
        return self.t


OK = Resp(200, {"domain": "cliente.es", "valid": True, "product": "resenaswoo", "status": "active",
                "expires_at": None, "environment": "production"})
NO = Resp(200, {"domain": "cliente.es", "valid": False, "reason": "no_license"})


def entorno(mode="enforce", token="tok-secreto", **extra):
    env = {"MRG_SAAS_DB_PATH": os.path.join(tempfile.mkdtemp(), "l.sqlite3"), "MRG_LICENSE_MODE": mode}
    if token is not None:
        env["MRG_SUPUHUB_VERIFY_TOKEN"] = token
    env.update(extra)
    p = patch.dict(os.environ, env)
    p.start()
    if token is None:
        os.environ.pop("MRG_SUPUHUB_VERIFY_TOKEN", None)
    return p


def denied(fn):
    try:
        fn()
    except LicenseDenied as exc:
        return exc.decision
    raise AssertionError("debía bloquear")


def log_rows(db=None):
    conn = sqlite3.connect(db or os.environ["MRG_SAAS_DB_PATH"])
    try:
        return conn.execute("SELECT site, place_key, outcome FROM mrg_access_log WHERE kind = 'license' ORDER BY id").fetchall()
    finally:
        conn.close()


def test_modo_off():
    p = entorno("off")
    try:
        stub = Stub(NO)
        g = LicenseGate(http_post=stub)
        d = g.check("cliente.es")
        assert d.allowed and d.source == "off"
        assert g.authorize("ip:1.1.1.1", "k", "1.1.1.1").allowed
        assert stub.calls == []
    finally:
        p.stop()


def test_modo_por_defecto_y_valor_raro():
    with patch.dict(os.environ, {}, clear=False):
        os.environ.pop("MRG_LICENSE_MODE", None)
        assert license_mode() == "log"
        os.environ["MRG_LICENSE_MODE"] = "enforced"
        assert license_mode() == "log"
        os.environ["MRG_LICENSE_MODE"] = " ENFORCE "
        assert license_mode() == "enforce"


def test_peticion_a_supuhub():
    p = entorno("enforce")
    try:
        stub = Stub(OK)
        d = LicenseGate(http_post=stub).check("WWW.Cliente.es")
        assert d.allowed and d.valid and d.source == "supuhub" and d.reason == "active"
        url, body, headers, timeout = stub.calls[0]
        assert url == license_gate.DEFAULT_VERIFY_URL
        assert body == {"product_code": "resenaswoo", "domain": "cliente.es"}
        assert headers["X-Service-Token"] == "tok-secreto"
        assert timeout == 8.0
        assert LicenseGate.normalize("www.Ñandú.es") == "xn--and-6ma2c.es"
    finally:
        p.stop()


def test_enforce_bloquea_y_log_deja_pasar():
    p = entorno("enforce")
    try:
        d = LicenseGate(http_post=Stub(NO)).check("cliente.es")
        assert not d.allowed and d.valid is False and d.status_code == 403 and d.detail == MSG_SIN_LICENCIA
        assert d.reason == "no_license"
        e = denied(lambda: LicenseGate(http_post=Stub(NO)).authorize("otro.es", "k", "1.1.1.1"))
        assert e.status_code == 403
        # Sin site_url (clave ip:…) = sin licencia, sin preguntar a SupuHub.
        stub = Stub(OK)
        e = denied(lambda: LicenseGate(http_post=stub).authorize("ip:1.1.1.1", "k", "1.1.1.1"))
        assert e.status_code == 403 and stub.calls == []
    finally:
        p.stop()

    p = entorno("log")
    try:
        d = LicenseGate(http_post=Stub(NO)).authorize("cliente.es", "k", "1.1.1.1")
        assert d.allowed and d.valid is False
        rows = log_rows()
        assert "mode=log allowed=1 would_block=1 valid=0" in rows[-1][2], rows
        # Una licencia no válida no vincula fichas, ni en modo log.
        conn = sqlite3.connect(os.environ["MRG_SAAS_DB_PATH"])
        assert conn.execute("SELECT COUNT(*) FROM mrg_license_places").fetchone()[0] == 0
        conn.close()
    finally:
        p.stop()


def test_cache_valida_6h_no_valida_15min():
    p = entorno("enforce")
    try:
        clock = Clock()
        stub = Stub(OK)
        g = LicenseGate(http_post=stub, clock=clock)
        assert g.check("cliente.es").source == "supuhub"
        clock.t += VALID_TTL - 60
        assert g.check("cliente.es").source == "cache"
        assert len(stub.calls) == 1
        clock.t += 120
        assert g.check("cliente.es").source == "supuhub"
        assert len(stub.calls) == 2

        stub = Stub(NO)
        g = LicenseGate(http_post=stub, clock=clock)
        assert g.check("malo.es").source == "supuhub"
        clock.t += INVALID_TTL - 10
        d = g.check("malo.es")
        assert d.source == "cache" and not d.allowed
        clock.t += 20
        g.check("malo.es")
        assert len(stub.calls) == 2
    finally:
        p.stop()


def test_gracia_72h_si_supuhub_no_responde():
    p = entorno("enforce")
    try:
        clock = Clock()
        LicenseGate(http_post=Stub(OK), clock=clock).check("cliente.es")
        for fallo in (TimeoutError("timeout"), Resp(500), Resp(502), Resp(429), Resp(200, ValueError("html"))):
            clock.t += 1
            # Caché caducada a la fuerza: el último válido sigue dentro de las 72 h.
            g = LicenseGate(http_post=Stub(fallo), clock=Clock(clock.t + VALID_TTL))
            d = g.check("cliente.es")
            assert d.allowed and d.source == "grace" and d.valid, (fallo, d)
        # Pasadas 72 h: desconocido → enforce bloquea con 503.
        d = LicenseGate(http_post=Stub(Resp(503)), clock=Clock(clock.t + GRACE_TTL + 1)).check("cliente.es")
        assert not d.allowed and d.valid is None and d.source == "error" and d.status_code == 503
        # Sin ningún válido previo tampoco hay gracia.
        d = LicenseGate(http_post=Stub(ConnectionError("x")), clock=clock).check("nuevo.es")
        assert not d.allowed and d.status_code == 503
        # Tras un «no válida» explícito no hay gracia aunque antes fuera válida.
        LicenseGate(http_post=Stub(NO), clock=Clock(clock.t + VALID_TTL)).check("cliente.es")
        d = LicenseGate(http_post=Stub(Resp(500)), clock=Clock(clock.t + VALID_TTL + INVALID_TTL + 1)).check("cliente.es")
        assert not d.allowed and d.source == "error"
    finally:
        p.stop()


def test_403_400_no_se_cachean():
    for mode in ("enforce", "log"):
        p = entorno(mode)
        try:
            for st in (403, 400):
                stub = Stub(Resp(st, {"error": "x"}))
                g = LicenseGate(http_post=stub)
                d1, d2 = g.check(f"c{st}.es"), g.check(f"c{st}.es")
                assert len(stub.calls) == 2, "un 403/400 no debe cachearse"
                assert d1.valid is None and d1.source == "error"
                if mode == "enforce":
                    assert not d1.allowed and d1.status_code == 503
                else:
                    assert d1.allowed and d2.allowed
            # Un 403 tampoco borra un válido anterior: después vuelve a funcionar.
            clock = Clock()
            LicenseGate(http_post=Stub(OK), clock=clock).check("bien.es")
            LicenseGate(http_post=Stub(Resp(403)), clock=Clock(clock.t + VALID_TTL + 1)).check("bien.es")
            d = LicenseGate(http_post=Stub(Resp(500)), clock=Clock(clock.t + VALID_TTL + 2)).check("bien.es")
            assert d.source == "grace"
        finally:
            p.stop()


def test_sin_token():
    p = entorno("enforce", token=None)
    try:
        stub = Stub(OK)
        d = LicenseGate(http_post=stub).check("cliente.es")
        assert not d.allowed and d.reason == "sin_token" and d.status_code == 503 and stub.calls == []
    finally:
        p.stop()
    p = entorno("log", token=None)
    try:
        stub = Stub(OK)
        d = LicenseGate(http_post=stub).authorize("cliente.es", "k", "1.1.1.1")
        assert d.allowed and d.reason == "sin_token" and stub.calls == []
        assert "would_block=1" in log_rows()[-1][2]
    finally:
        p.stop()


def test_limite_de_fichas():
    p = entorno("enforce")
    try:
        stub = Stub(OK)
        g = LicenseGate(http_post=stub)
        for k in ("fid:a", "fid:b", "fid:c", "fid:a", "fid:b"):
            assert g.authorize("cliente.es", k, "1.1.1.1").allowed
        e = denied(lambda: g.authorize("www.cliente.es", "fid:d", "1.1.1.1"))
        assert e.status_code == 403 and e.reason == "demasiadas_fichas" and "3 fichas" in e.detail
        # Las ya vinculadas siguen funcionando; otro dominio tiene su propio cupo.
        assert g.authorize("cliente.es", "fid:c", "1.1.1.1").allowed
        for k in ("fid:d", "fid:e", "fid:f"):
            assert g.authorize("otro.es", k, "2.2.2.2").allowed
        conn = sqlite3.connect(os.environ["MRG_SAAS_DB_PATH"])
        assert conn.execute("SELECT COUNT(*) FROM mrg_license_places WHERE domain = 'cliente.es'").fetchone()[0] == 3
        conn.close()
    finally:
        p.stop()

    # En modo log la 4.ª pasa, se anota y NO se vincula (al pasar a enforce seguiría bloqueada).
    p = entorno("log", MRG_MAX_PLACES_PER_DOMAIN="2")
    try:
        g = LicenseGate(http_post=Stub(OK))
        g.authorize("cliente.es", "a", "1.1.1.1")
        g.authorize("cliente.es", "b", "1.1.1.1")
        d = g.authorize("cliente.es", "c", "1.1.1.1")
        assert d.allowed and d.reason == "demasiadas_fichas"
        assert "would_block=1" in log_rows()[-1][2]
        assert not g.bind_place("cliente.es", "c")
    finally:
        p.stop()


def test_fichas_en_concurrencia():
    p = entorno("enforce")
    try:
        LicenseGate(http_post=Stub(OK))  # esquema creado antes de los hilos
        ok, lock = [], threading.Lock()

        def worker(i):
            if LicenseGate(http_post=Stub(OK)).bind_place("cliente.es", f"fid:{i}"):
                with lock:
                    ok.append(i)

        threads = [threading.Thread(target=worker, args=(i,)) for i in range(20)]
        [t.start() for t in threads]
        [t.join() for t in threads]
        assert len(ok) == 3, ok
        conn = sqlite3.connect(os.environ["MRG_SAAS_DB_PATH"])
        assert conn.execute("SELECT COUNT(*) FROM mrg_license_places").fetchone()[0] == 3
        conn.close()
    finally:
        p.stop()


def test_endpoint():
    import app as app_module

    try:
        from fastapi.testclient import TestClient
    except Exception:  # sin httpx: se llama a la función directamente
        TestClient = None

    body = {"maps_url": "https://www.google.com/maps/place/negocio-lic", "site_url": "https://www.cliente.es"}
    p = entorno("enforce")
    try:
        if TestClient is None:
            from fastapi import HTTPException
            from schemas import ImportRequest

            class Req:
                headers = {"x-real-ip": "9.9.9.9"}
                client = None

            with patch.object(license_gate, "_requests_post", Stub(NO)):
                try:
                    app_module.import_reviews(ImportRequest(**body), Req())
                    raise AssertionError("debía dar 403")
                except HTTPException as exc:
                    assert exc.status_code == 403 and exc.detail == MSG_SIN_LICENCIA
            with patch.object(license_gate, "_requests_post", Stub(OK)):
                assert app_module.import_reviews(ImportRequest(**body), Req()).success
            print("  (endpoint probado sin TestClient)")
            return

        c = TestClient(app_module.app)
        h = c.get("/health").json()
        assert h["ok"] and h["license_mode"] == "enforce" and "tok-secreto" not in str(h)
        # Sin licencia: 403 aunque la ficha esté en caché.
        with patch.object(license_gate, "_requests_post", Stub(OK)):
            r = c.post("/v1/import-reviews", json=body, headers={"X-Real-IP": "9.9.9.9"})
            assert r.status_code == 200, r.text
        with patch.object(license_gate, "_requests_post", Stub(NO)):
            r = c.post("/v1/import-reviews", json={**body, "site_url": "https://pirata.es"}, headers={"X-Real-IP": "9.9.9.8"})
            assert r.status_code == 403 and r.json()["detail"] == MSG_SIN_LICENCIA, r.text
            r = c.post("/v1/import-reviews", json={"maps_url": body["maps_url"]}, headers={"X-Real-IP": "9.9.9.7"})
            assert r.status_code == 403
        with patch.object(license_gate, "_requests_post", Stub(Resp(403))):
            r = c.post("/v1/import-reviews", json={**body, "site_url": "https://nuevo.es"}, headers={"X-Real-IP": "9.9.9.6"})
            assert r.status_code == 503 and r.headers.get("retry-after"), r.text
        # Modo log: la web sin licencia sigue funcionando y queda anotada.
        os.environ["MRG_LICENSE_MODE"] = "log"
        with patch.object(license_gate, "_requests_post", Stub(NO)):
            r = c.post("/v1/import-reviews", json={**body, "site_url": "https://pirata2.es"}, headers={"X-Real-IP": "9.9.9.5"})
            assert r.status_code == 200, r.text
        assert any(s == "pirata2.es" and "would_block=1" in o for s, _, o in log_rows())
    finally:
        p.stop()


if __name__ == "__main__":
    tests = [v for k, v in list(globals().items()) if k.startswith("test_") and callable(v)]
    for t in tests:
        t()
        print("ok", t.__name__)
    print(f"OK: {len(tests)} pruebas de licencia")
