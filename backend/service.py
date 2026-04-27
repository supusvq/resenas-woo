import os
from typing import Any, Dict

from providers.apify import ApifyProvider
from providers.demo import DemoProvider
from providers.google_business_profile import GoogleBusinessProfileProvider
from providers.selenium_legacy import SeleniumLegacyProvider
from schemas import ImportRequest, ImportResponse
from tenant_store import TenantStore


class ReviewImportService:
    def __init__(self) -> None:
        provider_name = os.getenv("MRG_REVIEW_PROVIDER", "").strip().lower()
        legacy_mode = os.getenv("MRG_IMPORT_MODE", "").strip().lower()

        if not provider_name:
            provider_name = {
                "upstream": "selenium_legacy",
                "demo": "demo",
            }.get(legacy_mode, "google_business_profile")

        self.provider_name = provider_name
        self.provider = self._build_provider(provider_name)

    def health(self) -> Dict[str, Any]:
        return {
            "ok": True,
            "service": "mrg-import-service",
            "provider": self.provider_name,
        }

    def health_upstream(self) -> Dict[str, Any]:
        # Backwards compatible endpoint: now reports the active provider.
        return self.provider.health()

    def import_reviews(self, payload: ImportRequest) -> ImportResponse:
        tenant_provider = self._build_tenant_provider(payload)
        if tenant_provider:
            return tenant_provider.import_reviews(payload)

        return self.provider.import_reviews(payload)

    def _build_tenant_provider(self, payload: ImportRequest):
        if self.provider_name != "google_business_profile":
            return None

        if not payload.site_url or not payload.site_token:
            return None

        site = TenantStore().authenticate(str(payload.site_url), str(payload.site_token))
        if not site:
            raise ValueError("Site token no valido para esta web.")

        if not site.get("google_refresh_token"):
            raise ValueError("Esta web todavia no ha conectado Google Business Profile.")

        if not site.get("google_account_id") or not site.get("google_location_id"):
            raise ValueError("Esta web todavia no ha seleccionado una ubicacion de Google.")

        return GoogleBusinessProfileProvider(
            refresh_token=str(site.get("google_refresh_token") or ""),
            account_id=str(site.get("google_account_id") or ""),
            location_id=str(site.get("google_location_id") or ""),
            place_name=str(site.get("google_place_name") or ""),
        )

    def _build_provider(self, provider_name: str):
        if provider_name == "google_business_profile":
            return GoogleBusinessProfileProvider()

        if provider_name == "apify":
            return ApifyProvider()

        if provider_name in {"selenium_legacy", "upstream"}:
            return SeleniumLegacyProvider()

        if provider_name == "demo":
            return DemoProvider()

        raise ValueError(
            "MRG_REVIEW_PROVIDER debe ser 'google_business_profile', 'apify', "
            "'selenium_legacy' o 'demo'."
        )
