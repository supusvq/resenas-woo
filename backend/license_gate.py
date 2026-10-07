"""Comprobación de licencia de Reseñas Woo contra SupuHub (fase 3).

Cada llamada a /v1/import-reviews pregunta (con caché) a SupuHub si el dominio que llama tiene una
licencia activa o de prueba de «resenaswoo». Además, cada dominio solo puede usar unas pocas fichas
de Google distintas (vinculación de fichas), para que una licencia no sirva a medio mundo.

Modos (variable MRG_LICENSE_MODE):
- off: no se comprueba nada.
- log (por defecto): se comprueba y se anota lo que habría pasado, pero siempre se deja pasar.
- enforce: se bloquea lo que no tenga licencia válida.

Caché por dominio en la SQLite (tabla mrg_license_cache): válida 6 h, no válida 15 min. Si SupuHub
no responde (red, timeout, 5xx o 429) se reutiliza el último resultado VÁLIDO si tiene menos de 72 h
(«gracia»). Un 403/400 de SupuHub es un error de configuración (token o petición mal hechos): nunca
se guarda como veredicto de licencia.

Autoría: Juan Gallardo by SupuDigital (https://www.supudigital.es).
"""
import logging
import os
import sqlite3
import time
from dataclasses import dataclass
from typing import Any, Callable, Optional

from access_guard import AccessGuard, GuardBusy

log = logging.getLogger("mrg_import_service")

DEFAULT_VERIFY_URL = "https://api.supudigital.es/api/license/verify.php"
PRODUCT_CODE = "resenaswoo"

VALID_TTL = 6 * 3600
INVALID_TTL = 15 * 60
GRACE_TTL = 72 * 3600
HTTP_TIMEOUT = 8.0

MSG_SIN_LICENCIA = "Esta web no tiene una licencia activa de Reseñas Woo. Actívala en Reseñas Woo > Licencia."
MSG_NO_DISPONIBLE = "No se ha podido comprobar la licencia de Reseñas Woo. Reintenta en unos minutos."
MSG_DEMASIADAS_FICHAS = (
    "Esta licencia ya se ha usado con {n} fichas de Google distintas, el máximo permitido. "
    "Si has cambiado de ficha, contacta con el soporte de SupuDigital (https://www.supudigital.es) para liberarla."
)

MODES = ("off", "log", "enforce")


def license_mode() -> str:
    mode = os.getenv("MRG_LICENSE_MODE", "log").strip().lower() or "log"
    if mode not in MODES:
        log.warning("MRG_LICENSE_MODE=%r no es válido (off|log|enforce): se usa 'log'.", mode)
        return "log"
    return mode


@dataclass
class Decision:
    allowed: bool
    valid: Optional[bool]
    reason: str
    source: str  # 'supuhub' | 'cache' | 'grace' | 'error' | 'off'
    status_code: int = 200  # código HTTP a devolver si se bloquea
    detail: str = ""  # mensaje para el plugin si se bloquea


class LicenseDenied(Exception):
    """La petición se bloquea por licencia: la API responde con status_code y detail."""

    def __init__(self, decision: Decision) -> None:
        super().__init__(decision.detail)
        self.decision = decision


def _requests_post(url: str, json: dict, headers: dict, timeout: float) -> Any:
    import requests

    return requests.post(url, json=json, headers=headers, timeout=timeout)


class LicenseGate:
    def __init__(
        self,
        http_post: Optional[Callable[..., Any]] = None,
        clock: Callable[[], float] = time.time,
    ) -> None:
        self.mode = license_mode()
        self.verify_url = os.getenv("MRG_SUPUHUB_VERIFY_URL", DEFAULT_VERIFY_URL).strip() or DEFAULT_VERIFY_URL
        self.token = os.getenv("MRG_SUPUHUB_VERIFY_TOKEN", "").strip()
        self.max_places = int(os.getenv("MRG_MAX_PLACES_PER_DOMAIN", "3"))
        self.db_path = os.getenv("MRG_SAAS_DB_PATH", "mrg_saas.sqlite3")
        self.http_post = http_post or _requests_post
        self.clock = clock
        if self.mode != "off":
            try:
                self._ensure_schema()
            except sqlite3.OperationalError as exc:
                raise GuardBusy("Servicio ocupado, reintenta en unos segundos.") from exc

    # ------------------------------------------------------------------ licencia

    @staticmethod
    def normalize(domain: str) -> str:
        """Mismo criterio que SupuHub: minúsculas, sin www. y con los IDN en punycode."""
        d = str(domain or "").strip().lower().rstrip(".")
        if d.startswith("www."):
            d = d[4:]
        try:
            d = d.encode("idna").decode("ascii")
        except (UnicodeError, ValueError):
            pass
        return d

    def check(self, domain: str) -> Decision:
        """Veredicto de licencia para un dominio, ya aplicado el modo (en 'log' siempre deja pasar)."""
        if self.mode == "off":
            return Decision(True, None, "modo_off", "off")
        return self._apply_mode(self._verdict(domain))

    def _verdict(self, domain: str) -> Decision:
        """Lo que pasaría en modo enforce."""
        if not domain or domain.startswith("ip:"):
            return self._deny(False, "sin_dominio", "error")
        domain = self.normalize(domain)

        if not self.token:
            log.error("LICENCIA: falta MRG_SUPUHUB_VERIFY_TOKEN; no se puede comprobar %s (modo %s).", domain, self.mode)
            return self._unavailable("sin_token")

        now = self.clock()
        row = self._cache_row(domain)
        if row:
            valid, reason, checked_at = bool(row[0]), str(row[1] or ""), float(row[2] or 0)
            ttl = VALID_TTL if valid else INVALID_TTL
            if now - checked_at < ttl:
                return Decision(True, True, reason, "cache") if valid else self._deny(False, reason, "cache")

        try:
            resp = self.http_post(
                self.verify_url,
                json={"product_code": PRODUCT_CODE, "domain": domain},
                headers={"X-Service-Token": self.token, "Accept": "application/json"},
                timeout=HTTP_TIMEOUT,
            )
            status = int(getattr(resp, "status_code", 0) or 0)
        except Exception as exc:  # red, DNS, timeout…
            log.warning("LICENCIA: SupuHub no responde para %s: %s", domain, exc)
            return self._grace(domain, row, now, "red")

        if status in (400, 401, 403):
            # Configuración mal hecha (token o petición): nunca es un veredicto sobre la licencia.
            log.error("LICENCIA: SupuHub responde %s para %s. Revisa MRG_SUPUHUB_VERIFY_TOKEN y la URL.", status, domain)
            return self._unavailable(f"supuhub_{status}")
        if status == 429 or status >= 500 or status == 0:
            log.warning("LICENCIA: SupuHub responde %s para %s.", status, domain)
            return self._grace(domain, row, now, f"supuhub_{status}")
        if status != 200:
            log.error("LICENCIA: respuesta inesperada %s de SupuHub para %s.", status, domain)
            return self._unavailable(f"supuhub_{status}")

        try:
            data = resp.json()
            if not isinstance(data, dict) or not isinstance(data.get("valid"), bool):
                raise ValueError("respuesta sin 'valid'")
        except Exception as exc:
            log.warning("LICENCIA: respuesta ilegible de SupuHub para %s: %s", domain, exc)
            return self._grace(domain, row, now, "respuesta_ilegible")

        product = str(data.get("product") or PRODUCT_CODE)
        valid = bool(data["valid"]) and product == PRODUCT_CODE
        if valid:
            reason = str(data.get("status") or "active")[:60]
        else:
            reason = str(data.get("reason") or ("otro_producto" if data["valid"] else "no_valida"))[:60]
        self._cache_store(domain, valid, reason, now)
        return Decision(True, True, reason, "supuhub") if valid else self._deny(False, reason, "supuhub")

    def _grace(self, domain: str, row: Optional[tuple], now: float, why: str) -> Decision:
        last_valid_at = float(row[3] or 0) if row else 0.0
        if last_valid_at and now - last_valid_at < GRACE_TTL:
            return Decision(True, True, "gracia_" + why, "grace")
        return self._unavailable(why)

    @staticmethod
    def _deny(valid: Optional[bool], reason: str, source: str) -> Decision:
        return Decision(False, valid, reason, source, 403, MSG_SIN_LICENCIA)

    @staticmethod
    def _unavailable(reason: str) -> Decision:
        return Decision(False, None, reason, "error", 503, MSG_NO_DISPONIBLE)

    def _apply_mode(self, d: Decision) -> Decision:
        if self.mode == "log" and not d.allowed:
            return Decision(True, d.valid, d.reason, d.source, d.status_code, d.detail)
        return d

    # ------------------------------------------------------------------ fichas

    def bind_place(self, domain: str, place_key: str) -> bool:
        """Vincula la ficha al dominio si cabe (atómico). Devuelve False si supera el máximo."""
        domain = self.normalize(domain)
        now = self.clock()
        out = {"ok": False}

        def fn(conn: sqlite3.Connection) -> None:
            cur = conn.execute(
                "UPDATE mrg_license_places SET last_seen = ? WHERE domain = ? AND place_key = ?",
                (now, domain, place_key),
            )
            if cur.rowcount:
                out["ok"] = True
                return
            n = int(conn.execute("SELECT COUNT(*) FROM mrg_license_places WHERE domain = ?", (domain,)).fetchone()[0])
            if n >= self.max_places:
                return
            conn.execute(
                "INSERT INTO mrg_license_places (domain, place_key, first_seen, last_seen) VALUES (?, ?, ?, ?)",
                (domain, place_key, now, now),
            )
            out["ok"] = True

        self._atomic(fn)
        return out["ok"]

    # ------------------------------------------------------------------ entrada principal

    def authorize(self, domain: str, place_key: str, ip: str) -> Decision:
        """Licencia + límite de fichas, con registro. Lanza LicenseDenied si hay que bloquear."""
        if self.mode == "off":
            return Decision(True, None, "modo_off", "off")

        if domain and not domain.startswith("ip:"):
            domain = self.normalize(domain)
        verdict = self._verdict(domain)
        if verdict.allowed:
            # Solo se vincula la ficha si la petición pasaría también en enforce.
            if not self.bind_place(domain, place_key):
                verdict = Decision(
                    False, verdict.valid, "demasiadas_fichas", verdict.source, 403,
                    MSG_DEMASIADAS_FICHAS.format(n=self.max_places),
                )
                log.warning("LICENCIA: %s supera %s fichas (nueva: %s).", domain, self.max_places, place_key)

        decision = self._apply_mode(verdict)
        self._log(domain, place_key, ip, decision, would_block=not verdict.allowed)
        if not decision.allowed:
            raise LicenseDenied(decision)
        return decision

    def _log(self, domain: str, place_key: str, ip: str, d: Decision, would_block: bool) -> None:
        outcome = "mode={} allowed={} would_block={} valid={} source={} reason={}".format(
            self.mode, int(d.allowed), int(would_block),
            "-" if d.valid is None else int(d.valid), d.source, d.reason,
        )
        try:
            with self._connect() as conn:
                AccessGuard._insert(conn, self.clock(), "license", ip, domain or "-", place_key, outcome)
        except sqlite3.Error:
            log.warning("No se pudo anotar la decisión de licencia de %s", domain, exc_info=True)

    # ------------------------------------------------------------------ SQLite

    def _cache_row(self, domain: str) -> Optional[tuple]:
        try:
            with self._connect() as conn:
                return conn.execute(
                    "SELECT valid, reason, checked_at, last_valid_at FROM mrg_license_cache WHERE domain = ?",
                    (domain,),
                ).fetchone()
        except sqlite3.OperationalError as exc:
            raise GuardBusy("Servicio ocupado, reintenta en unos segundos.") from exc

    def _cache_store(self, domain: str, valid: bool, reason: str, now: float) -> None:
        # Un veredicto «no válida» borra la última validez: tras él no hay gracia.
        try:
            with self._connect() as conn:
                conn.execute(
                    """
                    INSERT INTO mrg_license_cache (domain, valid, reason, checked_at, last_valid_at)
                    VALUES (?, ?, ?, ?, ?)
                    ON CONFLICT(domain) DO UPDATE SET
                        valid = excluded.valid, reason = excluded.reason,
                        checked_at = excluded.checked_at, last_valid_at = excluded.last_valid_at
                    """,
                    (domain, int(valid), reason, now, now if valid else None),
                )
        except sqlite3.Error:
            log.warning("No se pudo guardar la caché de licencia de %s", domain, exc_info=True)

    def _atomic(self, fn) -> None:
        conn = self._connect()
        try:
            conn.execute("BEGIN IMMEDIATE")
            fn(conn)
            conn.execute("COMMIT")
        except sqlite3.OperationalError as exc:
            try:
                conn.execute("ROLLBACK")
            except sqlite3.Error:
                pass
            raise GuardBusy("Servicio ocupado, reintenta en unos segundos.") from exc
        except BaseException:
            try:
                conn.execute("ROLLBACK")
            except sqlite3.Error:
                pass
            raise
        finally:
            conn.close()

    def _ensure_schema(self) -> None:
        with self._connect() as conn:
            conn.execute(
                """
                CREATE TABLE IF NOT EXISTS mrg_license_cache (
                    domain TEXT PRIMARY KEY,
                    valid INTEGER NOT NULL,
                    reason TEXT NOT NULL,
                    checked_at REAL NOT NULL,
                    last_valid_at REAL
                )
                """
            )
            conn.execute(
                """
                CREATE TABLE IF NOT EXISTS mrg_license_places (
                    domain TEXT NOT NULL,
                    place_key TEXT NOT NULL,
                    first_seen REAL NOT NULL,
                    last_seen REAL NOT NULL,
                    PRIMARY KEY (domain, place_key)
                )
                """
            )
            # El registro comparte la tabla de AccessGuard (kind = 'license').
            conn.execute(
                """
                CREATE TABLE IF NOT EXISTS mrg_access_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ts REAL NOT NULL,
                    kind TEXT NOT NULL,
                    ip TEXT NOT NULL,
                    site TEXT NOT NULL,
                    place_key TEXT NOT NULL,
                    outcome TEXT NOT NULL
                )
                """
            )
            conn.execute("CREATE INDEX IF NOT EXISTS idx_access_kind_ts ON mrg_access_log (kind, ts)")

    def _connect(self) -> sqlite3.Connection:
        timeout = float(os.getenv("MRG_SQLITE_TIMEOUT", "10"))
        return sqlite3.connect(self.db_path, timeout=timeout, isolation_level=None)
