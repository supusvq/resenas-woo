"""Comprobación de licencia de Reseñas Woo contra SupuHub (fase 3).

Cada llamada a /v1/import-reviews pregunta (con caché) a SupuHub si el dominio que llama tiene una
licencia activa o de prueba de «resenaswoo». Además, cada dominio solo puede usar unas pocas fichas
de Google distintas (vinculación de fichas), para que una licencia no sirva a medio mundo.

Modos (variable MRG_LICENSE_MODE):
- off: no se comprueba nada.
- log (por defecto): se comprueba y se anota lo que habría pasado, pero NUNCA se bloquea, ni siquiera
  si falla la propia comprobación (SQLite ocupada, error inesperado…): se anota «error_interno».
- enforce: se bloquea lo que no tenga licencia válida. Un fallo interno da 503 con Retry-After.

Caché por dominio en la SQLite (tabla mrg_license_cache): válida 6 h, no válida 15 min. Si SupuHub
no responde (red, timeout, 5xx o 429) se reutiliza el último resultado VÁLIDO si tiene menos de 72 h
(«gracia»). Un 3xx/400/401/403 de SupuHub es un error de configuración: nunca se guarda como veredicto.

Orden y concurrencia:
- checked_at es la hora en que se ENVIÓ la consulta. Un resultado solo sobrescribe la fila si su
  checked_at es >= el guardado: una respuesta lenta no pisa un veredicto más nuevo.
- Un «no válida» borra last_valid_at en la misma escritura: tras él no hay gracia.
- La gracia se decide releyendo la fila DESPUÉS del fallo de SupuHub, no con la copia de antes.
- Si un «no válida» no se puede guardar (tras varios intentos), queda una marca en memoria del
  proceso: el dominio se trata como no válido durante 15 min y no recibe gracia de ningún positivo
  anterior a la marca. La marca se borra cuando se consigue guardar un resultado posterior.
  Limitación: la marca es por proceso (el servicio corre con un solo worker de uvicorn).

Autoría: Juan Gallardo by SupuDigital (https://www.supudigital.es).
"""
import logging
import os
import sqlite3
import threading
import time
from dataclasses import dataclass
from typing import Any, Callable, Dict, Optional

from access_guard import AccessGuard, GuardBusy

log = logging.getLogger("mrg_import_service")

DEFAULT_VERIFY_URL = "https://api.supudigital.es/api/license/verify.php"
PRODUCT_CODE = "resenaswoo"

VALID_TTL = 6 * 3600
INVALID_TTL = 15 * 60
GRACE_TTL = 72 * 3600
HTTP_TIMEOUT = 8.0
WRITE_RETRIES = 3

MSG_SIN_LICENCIA = "Esta web no tiene una licencia activa de Reseñas Woo. Actívala en Reseñas Woo > Licencia."
MSG_NO_DISPONIBLE = "No se ha podido comprobar la licencia de Reseñas Woo. Reintenta en unos minutos."
MSG_DEMASIADAS_FICHAS = (
    "Esta licencia ya se ha usado con {n} fichas de Google distintas, el máximo permitido. "
    "Si has cambiado de ficha, contacta con el soporte de SupuDigital (https://www.supudigital.es) para liberarla."
)

MODES = ("off", "log", "enforce")

# Negativos que no se pudieron guardar en SQLite: dominio -> checked_at del negativo.
_NEGATIVOS_PENDIENTES: Dict[str, float] = {}
_NEGATIVOS_LOCK = threading.Lock()


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


def _requests_post(url: str, json: dict, headers: dict, timeout: float, allow_redirects: bool = False) -> Any:
    import requests

    # Sin seguir redirecciones: el token no debe viajar a otro sitio.
    return requests.post(url, json=json, headers=headers, timeout=timeout, allow_redirects=allow_redirects)


class LicenseGate:
    def __init__(
        self,
        http_post: Optional[Callable[..., Any]] = None,
        clock: Callable[[], float] = time.time,
    ) -> None:
        # El constructor no toca la base de datos ni puede fallar: los errores se gestionan según el modo.
        self.mode = license_mode()
        self.verify_url = os.getenv("MRG_SUPUHUB_VERIFY_URL", DEFAULT_VERIFY_URL).strip() or DEFAULT_VERIFY_URL
        self.token = os.getenv("MRG_SUPUHUB_VERIFY_TOKEN", "").strip()
        try:
            self.max_places = max(1, int(os.getenv("MRG_MAX_PLACES_PER_DOMAIN", "3")))
        except ValueError:
            log.warning("MRG_MAX_PLACES_PER_DOMAIN no es un número: se usa 3.")
            self.max_places = 3
        self.db_path = os.getenv("MRG_SAAS_DB_PATH", "mrg_saas.sqlite3")
        self.http_post = http_post or _requests_post
        self.clock = clock
        self._schema_ok = False

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
        try:
            return self._apply_mode(self._verdict(domain))
        except Exception:
            log.exception("LICENCIA: error interno comprobando %s (modo %s)", domain, self.mode)
            return self._apply_mode(self._unavailable("error_interno"))

    def _verdict(self, domain: str) -> Decision:
        """Lo que pasaría en modo enforce. Puede lanzar errores de SQLite (los gestiona quien llama)."""
        if not domain or domain.startswith("ip:"):
            return self._deny(False, "sin_dominio", "error")
        domain = self.normalize(domain)

        if not self.token:
            log.error("LICENCIA: falta MRG_SUPUHUB_VERIFY_TOKEN; no se puede comprobar %s (modo %s).", domain, self.mode)
            return self._unavailable("sin_token")
        if not self.verify_url.lower().startswith("https://"):
            log.error("LICENCIA: MRG_SUPUHUB_VERIFY_URL debe empezar por https://; no se envía el token.")
            return self._unavailable("url_no_https")

        self._ensure_schema()
        now = self.clock()

        neg = self._negativo_pendiente(domain)
        if neg is not None and now - neg < INVALID_TTL:
            return self._deny(False, "no_valida_sin_guardar", "cache")

        row = self._cache_row(domain)
        if row:
            valid, reason, checked_at = bool(row[0]), str(row[1] or ""), float(row[2] or 0)
            usable = neg is None or checked_at > neg
            ttl = VALID_TTL if valid else INVALID_TTL
            if usable and now - checked_at < ttl:
                return Decision(True, True, reason, "cache") if valid else self._deny(False, reason, "cache")

        sent_at = self.clock()
        try:
            resp = self.http_post(
                self.verify_url,
                json={"product_code": PRODUCT_CODE, "domain": domain},
                headers={"X-Service-Token": self.token, "Accept": "application/json"},
                timeout=HTTP_TIMEOUT,
                allow_redirects=False,
            )
            status = int(getattr(resp, "status_code", 0) or 0)
        except Exception as exc:  # red, DNS, timeout…
            log.warning("LICENCIA: SupuHub no responde para %s: %s", domain, exc)
            return self._grace(domain, "red")

        if status in (400, 401, 403) or 300 <= status < 400:
            # Configuración mal hecha (token, URL o petición): nunca es un veredicto sobre la licencia.
            log.error("LICENCIA: SupuHub responde %s para %s. Revisa MRG_SUPUHUB_VERIFY_TOKEN y la URL.", status, domain)
            return self._unavailable(f"supuhub_{status}")
        if status == 429 or status >= 500 or status == 0:
            log.warning("LICENCIA: SupuHub responde %s para %s.", status, domain)
            return self._grace(domain, f"supuhub_{status}")
        if status != 200:
            log.error("LICENCIA: respuesta inesperada %s de SupuHub para %s.", status, domain)
            return self._unavailable(f"supuhub_{status}")

        try:
            data = resp.json()
            if not isinstance(data, dict) or not isinstance(data.get("valid"), bool):
                raise ValueError("respuesta sin 'valid'")
        except Exception as exc:
            log.warning("LICENCIA: respuesta ilegible de SupuHub para %s: %s", domain, exc)
            return self._grace(domain, "respuesta_ilegible")

        product = str(data.get("product") or PRODUCT_CODE)
        valid = bool(data["valid"]) and product == PRODUCT_CODE
        if valid:
            reason = str(data.get("status") or "active")[:60]
        else:
            reason = str(data.get("reason") or ("otro_producto" if data["valid"] else "no_valida"))[:60]
        self._cache_store(domain, valid, reason, sent_at)
        return Decision(True, True, reason, "supuhub") if valid else self._deny(False, reason, "supuhub")

    def _grace(self, domain: str, why: str) -> Decision:
        # Se relee la fila AHORA: otra petición puede haber guardado un negativo mientras esperábamos.
        row = self._cache_row(domain)
        if row and int(row[0] or 0) == 1 and row[3]:
            last_valid_at = float(row[3])
            neg = self._negativo_pendiente(domain)
            if (neg is None or last_valid_at > neg) and self.clock() - last_valid_at < GRACE_TTL:
                return Decision(True, True, "gracia_" + why, "grace")
        return self._unavailable(why)

    @staticmethod
    def _deny(valid: Optional[bool], reason: str, source: str) -> Decision:
        return Decision(False, valid, reason, source, 403, MSG_SIN_LICENCIA)

    @staticmethod
    def _unavailable(reason: str) -> Decision:
        return Decision(False, None, reason, "error", 503, MSG_NO_DISPONIBLE)

    def _apply_mode(self, d: Decision) -> Decision:
        if self.mode != "enforce" and not d.allowed:
            return Decision(True, d.valid, d.reason, d.source, d.status_code, d.detail)
        return d

    # ------------------------------------------------------------------ fichas

    def bind_place(self, domain: str, place_key: str) -> bool:
        """Vincula la ficha al dominio si cabe (atómico). Devuelve False si supera el máximo."""
        self._ensure_schema()
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
        """Licencia + límite de fichas, con registro. Lanza LicenseDenied si hay que bloquear.

        En 'off' y 'log' nunca lanza nada. En 'enforce' un error interno es un 503, nunca un 500.
        """
        if self.mode == "off":
            return Decision(True, None, "modo_off", "off")

        try:
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
        except Exception:
            log.exception("LICENCIA: error interno con %s (modo %s)", domain, self.mode)
            verdict = self._unavailable("error_interno")

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
            self._ensure_schema()
            conn = self._connect()
            try:
                AccessGuard._insert(conn, self.clock(), "license", ip, domain or "-", place_key, outcome)
            finally:
                conn.close()
        except Exception:
            log.warning("No se pudo anotar la decisión de licencia de %s: %s", domain, outcome, exc_info=True)

    # ------------------------------------------------------------------ negativos sin guardar

    @staticmethod
    def _negativo_pendiente(domain: str) -> Optional[float]:
        with _NEGATIVOS_LOCK:
            return _NEGATIVOS_PENDIENTES.get(domain)

    @staticmethod
    def _marcar_negativo(domain: str, checked_at: float) -> None:
        with _NEGATIVOS_LOCK:
            _NEGATIVOS_PENDIENTES[domain] = max(checked_at, _NEGATIVOS_PENDIENTES.get(domain, checked_at))

    @staticmethod
    def _limpiar_negativo(domain: str, checked_at: float) -> None:
        with _NEGATIVOS_LOCK:
            neg = _NEGATIVOS_PENDIENTES.get(domain)
            if neg is not None and checked_at >= neg:
                del _NEGATIVOS_PENDIENTES[domain]

    # ------------------------------------------------------------------ SQLite

    def _cache_row(self, domain: str) -> Optional[tuple]:
        conn = self._connect()
        try:
            return conn.execute(
                "SELECT valid, reason, checked_at, last_valid_at FROM mrg_license_cache WHERE domain = ?",
                (domain,),
            ).fetchone()
        finally:
            conn.close()

    def _cache_store(self, domain: str, valid: bool, reason: str, checked_at: float) -> None:
        """Guarda el resultado solo si no hay otro más nuevo. Un negativo borra last_valid_at.

        Si un negativo no se puede guardar tras varios intentos, se marca en memoria (ver cabecera).
        """
        if not valid:
            # Antes de escribir: aunque la escritura falle, este proceso ya no da gracia.
            self._marcar_negativo(domain, checked_at)

        def fn(conn: sqlite3.Connection) -> None:
            conn.execute(
                """
                INSERT INTO mrg_license_cache (domain, valid, reason, checked_at, last_valid_at)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT(domain) DO UPDATE SET
                    valid = excluded.valid, reason = excluded.reason,
                    checked_at = excluded.checked_at, last_valid_at = excluded.last_valid_at
                WHERE excluded.checked_at >= mrg_license_cache.checked_at
                """,
                (domain, int(valid), reason, checked_at, checked_at if valid else None),
            )

        for intento in range(WRITE_RETRIES):
            try:
                self._atomic(fn)
                self._limpiar_negativo(domain, checked_at)
                return
            except (GuardBusy, sqlite3.Error):
                if intento + 1 < WRITE_RETRIES:
                    time.sleep(0.05 * (intento + 1))
        log.error(
            "LICENCIA: no se pudo guardar el resultado (%s) de %s; %s",
            "válida" if valid else "NO válida", domain,
            "queda marcado como no válido en memoria" if not valid else "se ignora",
        )

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
        if self._schema_ok:
            return
        conn = self._connect()
        try:
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
        finally:
            conn.close()
        self._schema_ok = True

    def _connect(self) -> sqlite3.Connection:
        timeout = float(os.getenv("MRG_SQLITE_TIMEOUT", "10"))
        return sqlite3.connect(self.db_path, timeout=timeout, isolation_level=None)
