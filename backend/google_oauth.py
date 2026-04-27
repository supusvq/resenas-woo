import os
from typing import Any, Dict, List
from urllib.parse import urlencode

from providers.base import BaseReviewProvider


class GoogleOAuthClient(BaseReviewProvider):
    name = "google_oauth"
    scope = "https://www.googleapis.com/auth/business.manage"

    def __init__(self) -> None:
        self.client_id = os.getenv("MRG_GOOGLE_CLIENT_ID", "").strip()
        self.client_secret = os.getenv("MRG_GOOGLE_CLIENT_SECRET", "").strip()
        self.redirect_uri = os.getenv("MRG_GOOGLE_REDIRECT_URI", "").strip()
        self.auth_url = os.getenv("MRG_GOOGLE_AUTH_URL", "https://accounts.google.com/o/oauth2/v2/auth").strip()
        self.token_url = os.getenv("MRG_GBP_TOKEN_URL", "https://oauth2.googleapis.com/token").strip()
        self.account_api_base = os.getenv(
            "MRG_GBP_ACCOUNT_API_BASE",
            "https://mybusinessaccountmanagement.googleapis.com/v1",
        ).rstrip("/")
        self.business_api_base = os.getenv(
            "MRG_GBP_BUSINESS_INFO_API_BASE",
            "https://mybusinessbusinessinformation.googleapis.com/v1",
        ).rstrip("/")

    def build_authorization_url(self, state: str) -> str:
        self._require_config(["client_id", "redirect_uri"])
        return self.auth_url + "?" + urlencode(
            {
                "client_id": self.client_id,
                "redirect_uri": self.redirect_uri,
                "response_type": "code",
                "scope": self.scope,
                "access_type": "offline",
                "prompt": "consent",
                "include_granted_scopes": "true",
                "state": state,
            }
        )

    def exchange_code(self, code: str) -> Dict[str, Any]:
        self._require_config(["client_id", "client_secret", "redirect_uri"])
        return self.request_json(
            "POST",
            self.token_url,
            headers={"Accept": "application/json"},
            data={
                "code": code,
                "client_id": self.client_id,
                "client_secret": self.client_secret,
                "redirect_uri": self.redirect_uri,
                "grant_type": "authorization_code",
            },
            timeout=20,
            fallback_message="No se pudo completar OAuth con Google.",
        )

    def refresh_access_token(self, refresh_token: str) -> str:
        self._require_config(["client_id", "client_secret"])
        data = self.request_json(
            "POST",
            self.token_url,
            headers={"Accept": "application/json"},
            data={
                "client_id": self.client_id,
                "client_secret": self.client_secret,
                "refresh_token": refresh_token,
                "grant_type": "refresh_token",
            },
            timeout=20,
            fallback_message="No se pudo refrescar el token de Google.",
        )
        access_token = str(data.get("access_token") or "").strip()
        if not access_token:
            raise RuntimeError("Google no devolvio access_token.")
        return access_token

    def list_accounts(self, access_token: str) -> List[Dict[str, Any]]:
        data = self.request_json(
            "GET",
            f"{self.account_api_base}/accounts",
            headers={"Authorization": f"Bearer {access_token}", "Accept": "application/json"},
            timeout=30,
            fallback_message="No se pudieron listar cuentas de Google Business Profile.",
        )
        accounts = data.get("accounts", []) if isinstance(data, dict) else []
        return accounts if isinstance(accounts, list) else []

    def list_locations(self, access_token: str, account_name: str) -> List[Dict[str, Any]]:
        data = self.request_json(
            "GET",
            f"{self.business_api_base}/{account_name}/locations",
            headers={"Authorization": f"Bearer {access_token}", "Accept": "application/json"},
            params={"readMask": "name,title,metadata"},
            timeout=30,
            fallback_message="No se pudieron listar ubicaciones de Google Business Profile.",
        )
        locations = data.get("locations", []) if isinstance(data, dict) else []
        return locations if isinstance(locations, list) else []

    def _require_config(self, fields: List[str]) -> None:
        missing = [f"MRG_GOOGLE_{field.upper()}" for field in fields if not getattr(self, field)]
        if missing:
            raise ValueError("Faltan variables OAuth de Google: " + ", ".join(missing))
