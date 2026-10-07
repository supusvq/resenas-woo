import os
import sqlite3
import time
from typing import Optional
from urllib.parse import urlparse


class LimitExceeded(Exception):
    """Se ha superado un límite de uso: la API responde 429."""


class AccessGuard:
    """Límites de uso y registro de llamadas a /v1/import-reviews.

    El servicio no exige licencia todavía (llegará con SupuHub), así que cualquiera
    podría disparar scrapes de Apify. Estos límites acotan el gasto y el registro
    permite saber qué webs lo usan:

    - peticiones por IP y hora (con o sin caché);
    - scrapes en vivo (los que cuestan Apify) por web y día;
    - scrapes en vivo totales por día, como freno de emergencia.

    Los valores se pueden cambiar con variables de entorno sin tocar el código.
    """

    def __init__(self) -> None:
        self.db_path = os.getenv("MRG_SAAS_DB_PATH", "mrg_saas.sqlite3")
        self.req_per_ip_hour = int(os.getenv("MRG_LIMIT_REQ_PER_IP_HOUR", "30"))
        self.live_per_site_day = int(os.getenv("MRG_LIMIT_LIVE_PER_SITE_DAY", "3"))
        self.live_global_day = int(os.getenv("MRG_LIMIT_LIVE_GLOBAL_DAY", "40"))
        self._ensure_schema()

    @staticmethod
    def site_key(site_url: Optional[str], ip: str) -> str:
        """Web que llama: el dominio si lo manda el plugin; si no, la IP."""
        host = urlparse(str(site_url or "")).netloc.lower()
        if host.startswith("www."):
            host = host[4:]
        return host or "ip:" + ip

    def check_request(self, ip: str) -> None:
        if self._count("ip = ? AND ts > ?", (ip, time.time() - 3600)) >= self.req_per_ip_hour:
            raise LimitExceeded("Demasiadas peticiones desde esta IP. Prueba dentro de una hora.")

    def check_live(self, site: str) -> None:
        since = time.time() - 86400
        if self._count("site = ? AND live = 1 AND ts > ?", (site, since)) >= self.live_per_site_day:
            raise LimitExceeded("Esta web ya ha importado reseñas hoy. Prueba mañana.")
        if self._count("live = 1 AND ts > ?", (since,)) >= self.live_global_day:
            raise LimitExceeded("El servicio ha alcanzado su límite diario. Prueba mañana.")

    def log(self, ip: str, site: str, place_key: str, live: bool, outcome: str) -> None:
        with self._connect() as conn:
            conn.execute(
                "INSERT INTO mrg_access_log (ts, ip, site, place_key, live, outcome) VALUES (?, ?, ?, ?, ?, ?)",
                (time.time(), ip[:64], site[:190], place_key[:190], 1 if live else 0, outcome[:190]),
            )
            # Se guardan 90 días.
            conn.execute("DELETE FROM mrg_access_log WHERE ts < ?", (time.time() - 90 * 86400,))

    def _count(self, where: str, params: tuple) -> int:
        with self._connect() as conn:
            row = conn.execute("SELECT COUNT(*) FROM mrg_access_log WHERE " + where, params).fetchone()
        return int(row[0] or 0)

    def _ensure_schema(self) -> None:
        with self._connect() as conn:
            conn.execute(
                """
                CREATE TABLE IF NOT EXISTS mrg_access_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ts REAL NOT NULL,
                    ip TEXT NOT NULL,
                    site TEXT NOT NULL,
                    place_key TEXT NOT NULL,
                    live INTEGER NOT NULL DEFAULT 0,
                    outcome TEXT NOT NULL
                )
                """
            )
            conn.execute("CREATE INDEX IF NOT EXISTS idx_access_ip_ts ON mrg_access_log (ip, ts)")
            conn.execute("CREATE INDEX IF NOT EXISTS idx_access_site_ts ON mrg_access_log (site, ts)")

    def _connect(self) -> sqlite3.Connection:
        return sqlite3.connect(self.db_path, timeout=10)
