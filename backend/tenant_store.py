import os
import secrets
import sqlite3
from datetime import datetime, timedelta, timezone
from typing import Any, Dict, Optional


class TenantStore:
    def __init__(self) -> None:
        self.db_path = os.getenv("MRG_SAAS_DB_PATH", "mrg_saas.sqlite3")
        self._ensure_schema()

    def register_site(self, site_url: str) -> Dict[str, Any]:
        normalized_url = self._normalize_site_url(site_url)
        existing = self.get_site_by_url(normalized_url)
        if existing:
            return existing

        site_id = "site_" + secrets.token_urlsafe(18)
        site_token = secrets.token_urlsafe(32)
        now = self._now()

        with self._connect() as conn:
            conn.execute(
                """
                INSERT INTO mrg_sites (site_id, site_url, site_token, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?)
                """,
                (site_id, normalized_url, site_token, now, now),
            )

        return self.get_site_by_url(normalized_url) or {
            "site_id": site_id,
            "site_url": normalized_url,
            "site_token": site_token,
        }

    def authenticate(self, site_url: str, site_token: str) -> Optional[Dict[str, Any]]:
        normalized_url = self._normalize_site_url(site_url)
        with self._connect() as conn:
            row = conn.execute(
                "SELECT * FROM mrg_sites WHERE site_url = ? AND site_token = ?",
                (normalized_url, site_token),
            ).fetchone()
        return dict(row) if row else None

    def get_site_by_url(self, site_url: str) -> Optional[Dict[str, Any]]:
        normalized_url = self._normalize_site_url(site_url)
        with self._connect() as conn:
            row = conn.execute("SELECT * FROM mrg_sites WHERE site_url = ?", (normalized_url,)).fetchone()
        return dict(row) if row else None

    def create_oauth_state(self, site_id: str) -> str:
        state = secrets.token_urlsafe(32)
        expires_at = (datetime.now(timezone.utc) + timedelta(minutes=15)).strftime("%Y-%m-%d %H:%M:%S")

        with self._connect() as conn:
            conn.execute(
                "INSERT INTO mrg_oauth_states (state, site_id, expires_at) VALUES (?, ?, ?)",
                (state, site_id, expires_at),
            )

        return state

    def consume_oauth_state(self, state: str) -> Optional[Dict[str, Any]]:
        now = self._now()
        with self._connect() as conn:
            row = conn.execute(
                """
                SELECT s.* FROM mrg_oauth_states o
                INNER JOIN mrg_sites s ON s.site_id = o.site_id
                WHERE o.state = ? AND o.expires_at > ?
                """,
                (state, now),
            ).fetchone()
            conn.execute("DELETE FROM mrg_oauth_states WHERE state = ?", (state,))

        return dict(row) if row else None

    def update_google_tokens(self, site_id: str, refresh_token: str) -> None:
        now = self._now()
        with self._connect() as conn:
            conn.execute(
                """
                UPDATE mrg_sites
                SET google_refresh_token = ?, updated_at = ?
                WHERE site_id = ?
                """,
                (refresh_token, now, site_id),
            )

    def update_google_location(
        self,
        site_url: str,
        site_token: str,
        account_id: str,
        location_id: str,
        place_name: str,
    ) -> Dict[str, Any]:
        site = self.authenticate(site_url, site_token)
        if not site:
            raise ValueError("Site token no valido.")

        now = self._now()
        with self._connect() as conn:
            conn.execute(
                """
                UPDATE mrg_sites
                SET google_account_id = ?, google_location_id = ?, google_place_name = ?, updated_at = ?
                WHERE site_id = ?
                """,
                (account_id, location_id, place_name, now, site["site_id"]),
            )

        return self.authenticate(site_url, site_token) or site

    def _ensure_schema(self) -> None:
        with self._connect() as conn:
            conn.execute(
                """
                CREATE TABLE IF NOT EXISTS mrg_sites (
                    site_id TEXT PRIMARY KEY,
                    site_url TEXT NOT NULL UNIQUE,
                    site_token TEXT NOT NULL,
                    google_refresh_token TEXT DEFAULT '',
                    google_account_id TEXT DEFAULT '',
                    google_location_id TEXT DEFAULT '',
                    google_place_name TEXT DEFAULT '',
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )
                """
            )
            conn.execute(
                """
                CREATE TABLE IF NOT EXISTS mrg_oauth_states (
                    state TEXT PRIMARY KEY,
                    site_id TEXT NOT NULL,
                    expires_at TEXT NOT NULL
                )
                """
            )

    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.db_path)
        conn.row_factory = sqlite3.Row
        return conn

    def _normalize_site_url(self, site_url: str) -> str:
        return str(site_url).strip().rstrip("/")

    def _now(self) -> str:
        return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
