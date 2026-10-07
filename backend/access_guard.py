import logging
import os
import sqlite3
import time
from typing import Optional
from urllib.parse import urlparse

log = logging.getLogger("mrg_import_service")


class LimitExceeded(Exception):
    """Se ha superado un límite de uso: la API responde 429."""


class GuardBusy(Exception):
    """La base de datos de control está ocupada: la API responde 503 y el cliente reintenta."""


class AccessGuard:
    """Límites de uso y registro de llamadas a /v1/import-reviews.

    El servicio no exige licencia todavía (llegará con SupuHub), así que cualquiera
    podría disparar scrapes de Apify. Mientras tanto:

    - cada petición se admite y se anota en una transacción atómica (límite por IP y hora);
    - cada scrape en vivo (lo que cuesta Apify) RESERVA su cupo antes de llamar al proveedor,
      también en una transacción atómica: por web, por IP y total diario;
    - una reserva cuenta aunque el scrape falle o el proceso se caiga (se prefiere contar de más).

    La «web» la declara el cliente (site_url) y no está verificada: por eso también se limita
    por IP. La identidad verificada llegará con la licencia de SupuHub.

    Los valores se cambian con variables de entorno sin tocar el código.
    """

    def __init__(self) -> None:
        self.db_path = os.getenv("MRG_SAAS_DB_PATH", "mrg_saas.sqlite3")
        self.req_per_ip_hour = int(os.getenv("MRG_LIMIT_REQ_PER_IP_HOUR", "30"))
        self.live_per_site_day = int(os.getenv("MRG_LIMIT_LIVE_PER_SITE_DAY", "3"))
        self.live_per_ip_day = int(os.getenv("MRG_LIMIT_LIVE_PER_IP_DAY", "6"))
        self.live_global_day = int(os.getenv("MRG_LIMIT_LIVE_GLOBAL_DAY", "40"))
        self._ensure_schema()

    @staticmethod
    def site_key(site_url: Optional[str], ip: str) -> str:
        """Web que llama: el dominio (sin puerto, credenciales ni www) o, si no lo manda, la IP."""
        host = (urlparse(str(site_url or "")).hostname or "").lower().rstrip(".")
        if host.startswith("www."):
            host = host[4:]
        return host or "ip:" + ip

    def admit(self, ip: str, site: str, place_key: str) -> None:
        """Admite la petición y la anota, o lanza LimitExceeded. Atómico."""
        now = time.time()

        def check(conn: sqlite3.Connection) -> None:
            n = self._count(conn, "kind = 'request' AND ip = ? AND ts > ?", (ip, now - 3600))
            if n >= self.req_per_ip_hour:
                raise LimitExceeded("Demasiadas peticiones desde esta IP. Prueba dentro de una hora.")
            self._insert(conn, now, "request", ip, site, place_key, "admitted")

        self._atomic(check)

    def reserve_live(self, ip: str, site: str, place_key: str) -> int:
        """Reserva cupo para un scrape en vivo antes de llamar al proveedor. Devuelve el id de la reserva."""
        now = time.time()
        since = now - 86400
        out = {}

        def check(conn: sqlite3.Connection) -> None:
            if self._count(conn, "kind = 'live' AND site = ? AND ts > ?", (site, since)) >= self.live_per_site_day:
                raise LimitExceeded("Esta web ya ha importado reseñas hoy. Prueba mañana.")
            if self._count(conn, "kind = 'live' AND ip = ? AND ts > ?", (ip, since)) >= self.live_per_ip_day:
                raise LimitExceeded("Demasiadas importaciones desde esta IP hoy. Prueba mañana.")
            if self._count(conn, "kind = 'live' AND ts > ?", (since,)) >= self.live_global_day:
                raise LimitExceeded("El servicio ha alcanzado su límite diario. Prueba mañana.")
            out["id"] = self._insert(conn, now, "live", ip, site, place_key, "reserved")

        self._atomic(check)
        return int(out["id"])

    def finish(self, row_id: int, outcome: str) -> None:
        """Anota cómo acabó un scrape. Es solo informativo: si falla, no afecta a la respuesta."""
        try:
            with self._connect() as conn:
                conn.execute("UPDATE mrg_access_log SET outcome = ? WHERE id = ?", (outcome[:190], row_id))
        except sqlite3.Error:
            log.warning("No se pudo anotar el resultado del scrape %s", row_id, exc_info=True)

    def _atomic(self, fn) -> None:
        conn = self._connect()
        try:
            conn.execute("BEGIN IMMEDIATE")
            fn(conn)
            conn.execute("COMMIT")
        except LimitExceeded:
            conn.execute("ROLLBACK")
            raise
        except sqlite3.OperationalError as exc:
            try:
                conn.execute("ROLLBACK")
            except sqlite3.Error:
                pass
            raise GuardBusy("Servicio ocupado, reintenta en unos segundos.") from exc
        finally:
            conn.close()

    @staticmethod
    def _count(conn: sqlite3.Connection, where: str, params: tuple) -> int:
        return int(conn.execute("SELECT COUNT(*) FROM mrg_access_log WHERE " + where, params).fetchone()[0] or 0)

    @staticmethod
    def _insert(conn, ts, kind, ip, site, place_key, outcome) -> int:
        cur = conn.execute(
            "INSERT INTO mrg_access_log (ts, kind, ip, site, place_key, outcome) VALUES (?, ?, ?, ?, ?, ?)",
            (ts, kind, ip[:64], site[:190], place_key[:190], outcome[:190]),
        )
        # Se guardan 90 días.
        conn.execute("DELETE FROM mrg_access_log WHERE ts < ?", (ts - 90 * 86400,))
        return int(cur.lastrowid)

    def _ensure_schema(self) -> None:
        with self._connect() as conn:
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
        # isolation_level=None: las transacciones las abre BEGIN IMMEDIATE de forma explícita.
        return sqlite3.connect(self.db_path, timeout=10, isolation_level=None)
