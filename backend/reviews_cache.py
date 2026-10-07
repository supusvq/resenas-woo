import hashlib
import json
import os
import re
import sqlite3
from datetime import datetime, timezone
from typing import Any, Dict, Optional


class ReviewsCache:
    """Cachea la ultima respuesta valida por ficha de Google.

    El scrape en vivo de Apify tarda entre 8 y 120+ segundos de forma
    impredecible: cachear el resultado hace que las sincronizaciones
    posteriores sean instantaneas y que un timeout del cliente no obligue
    a repetir el scrape (el resultado ya queda guardado).
    """

    def __init__(self) -> None:
        self.db_path = os.getenv("MRG_SAAS_DB_PATH", "mrg_saas.sqlite3")
        self._ensure_schema()

    def place_key(self, maps_url: str) -> str:
        url = str(maps_url).strip().lower()
        fid = re.search(r"!1s(0x[0-9a-f]+:0x[0-9a-f]+)", url)
        if fid:
            return "fid:" + fid.group(1)
        cid = re.search(r"[?&]cid=(\d+)", url)
        if cid:
            return "cid:" + cid.group(1)
        pid = re.search(r"place_id=([a-z0-9_\-]+)", url)
        if pid:
            return "pid:" + pid.group(1)
        return "url:" + hashlib.sha1(url.encode("utf-8")).hexdigest()

    def get(self, place_key: str, max_age_seconds: int) -> Optional[Dict[str, Any]]:
        with self._connect() as conn:
            row = conn.execute(
                "SELECT payload_json, updated_at FROM mrg_reviews_cache WHERE place_key = ?",
                (place_key,),
            ).fetchone()
        if not row:
            return None
        age = (datetime.now(timezone.utc) - self._parse(row["updated_at"])).total_seconds()
        if age > max_age_seconds:
            return None
        try:
            return json.loads(row["payload_json"])
        except (ValueError, TypeError):
            return None

    def set(self, place_key: str, payload: Dict[str, Any]) -> None:
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        with self._connect() as conn:
            conn.execute(
                """
                INSERT INTO mrg_reviews_cache (place_key, payload_json, updated_at)
                VALUES (?, ?, ?)
                ON CONFLICT(place_key) DO UPDATE SET
                    payload_json = excluded.payload_json,
                    updated_at = excluded.updated_at
                """,
                (place_key, json.dumps(payload, ensure_ascii=False), now),
            )

    def _ensure_schema(self) -> None:
        with self._connect() as conn:
            conn.execute(
                """
                CREATE TABLE IF NOT EXISTS mrg_reviews_cache (
                    place_key TEXT PRIMARY KEY,
                    payload_json TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )
                """
            )

    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.db_path)
        conn.row_factory = sqlite3.Row
        return conn

    def _parse(self, value: str) -> datetime:
        return datetime.strptime(value, "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc)
