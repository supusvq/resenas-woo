import os
from typing import Any, Dict, List

from providers.base import BaseReviewProvider
from schemas import ImportRequest, ImportResponse, ReviewItem


class GoogleBusinessProfileProvider(BaseReviewProvider):
    name = "google_business_profile"

    def __init__(self) -> None:
        self.api_base = os.getenv("MRG_GBP_API_BASE", "https://mybusiness.googleapis.com/v4").rstrip("/")
        self.token_url = os.getenv("MRG_GBP_TOKEN_URL", "https://oauth2.googleapis.com/token").strip()
        self.access_token = os.getenv("MRG_GBP_ACCESS_TOKEN", "").strip()
        self.refresh_token = os.getenv("MRG_GBP_REFRESH_TOKEN", "").strip()
        self.client_id = os.getenv("MRG_GBP_CLIENT_ID", "").strip()
        self.client_secret = os.getenv("MRG_GBP_CLIENT_SECRET", "").strip()
        self.account_id = os.getenv("MRG_GBP_ACCOUNT_ID", "").strip()
        self.location_id = os.getenv("MRG_GBP_LOCATION_ID", "").strip()
        self.place_name = os.getenv("MRG_GBP_PLACE_NAME", "").strip()

    def health(self) -> Dict[str, Any]:
        missing = self._missing_config()
        return {
            "ok": not missing,
            "provider": self.name,
            "configured": not missing,
            "missing": missing,
            "message": "Google Business Profile listo." if not missing else "Faltan credenciales de Google.",
        }

    def import_reviews(self, payload: ImportRequest) -> ImportResponse:
        missing = self._missing_config()
        if missing:
            raise ValueError(
                "Faltan variables para Google Business Profile: " + ", ".join(missing)
            )

        access_token = self._get_access_token()
        data = self.request_json(
            "GET",
            f"{self.api_base}/{self._account_path()}/{self._location_path()}/reviews",
            headers={"Authorization": f"Bearer {access_token}", "Accept": "application/json"},
            params={"pageSize": self.limit_reviews(payload), "orderBy": "updateTime desc"},
            timeout=30,
            fallback_message="No se pudieron leer reseñas desde Google Business Profile.",
        )

        raw_reviews = data.get("reviews", []) if isinstance(data, dict) else []
        if not isinstance(raw_reviews, list) or not raw_reviews:
            raise RuntimeError("Google Business Profile no devolvio reseñas para esta ubicacion.")

        reviews = [self._normalize_review(item) for item in raw_reviews[: self.limit_reviews(payload)]]
        place_id = self.build_place_id("gbp", f"{self._account_path()}/{self._location_path()}")
        place_name = self.place_name or self.guess_business_name(str(payload.maps_url))

        return ImportResponse(
            success=True,
            place_id=place_id,
            place_name=place_name,
            rating=float(data.get("averageRating") or self.compute_rating(reviews)),
            user_ratings_total=int(data.get("totalReviewCount") or len(reviews)),
            review_target_url=str(payload.maps_url),
            reviews=reviews,
        )

    def _normalize_review(self, item: Dict[str, Any]) -> ReviewItem:
        reviewer = item.get("reviewer") if isinstance(item.get("reviewer"), dict) else {}
        author_name = reviewer.get("displayName") or reviewer.get("name") or "Cliente"
        review_text = item.get("comment") or ""
        review_date = item.get("updateTime") or item.get("createTime") or ""
        review_id = item.get("reviewId") or item.get("name") or self.build_review_id(
            "gbp_review", self.location_id, author_name, review_text, review_date
        )

        return ReviewItem(
            review_id=str(review_id),
            author_name=str(author_name),
            author_photo=str(reviewer.get("profilePhotoUrl") or ""),
            rating=self.normalize_rating(item.get("starRating")),
            review_text=str(review_text),
            review_date=self.normalize_date_string(str(review_date)),
            relative_time="",
            is_anonymous=0 if str(author_name).strip() else 1,
        )

    def _get_access_token(self) -> str:
        if self.access_token:
            return self.access_token

        token_data = self.request_json(
            "POST",
            self.token_url,
            headers={"Accept": "application/json"},
            data={
                "client_id": self.client_id,
                "client_secret": self.client_secret,
                "refresh_token": self.refresh_token,
                "grant_type": "refresh_token",
            },
            timeout=20,
            fallback_message="No se pudo refrescar el token de Google.",
        )
        access_token = str(token_data.get("access_token") or "").strip()
        if not access_token:
            raise RuntimeError("Google no devolvio access_token al refrescar credenciales.")
        return access_token

    def _account_path(self) -> str:
        return self.account_id if self.account_id.startswith("accounts/") else f"accounts/{self.account_id}"

    def _location_path(self) -> str:
        return self.location_id if self.location_id.startswith("locations/") else f"locations/{self.location_id}"

    def _missing_config(self) -> List[str]:
        missing = []
        has_static_token = bool(self.access_token)
        has_refresh_config = bool(self.refresh_token and self.client_id and self.client_secret)

        if not has_static_token and not has_refresh_config:
            missing.append("MRG_GBP_ACCESS_TOKEN o refresh OAuth completo")
        if not self.account_id:
            missing.append("MRG_GBP_ACCOUNT_ID")
        if not self.location_id:
            missing.append("MRG_GBP_LOCATION_ID")
        return missing
