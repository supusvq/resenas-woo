import os
import time
from typing import Any, Dict, List, Optional
from urllib.parse import quote

from providers.base import BaseReviewProvider
from schemas import ImportRequest, ImportResponse, ReviewItem


class ApifyProvider(BaseReviewProvider):
    name = "apify"
    photo_keys = (
        "authorImage",
        "authorPhoto",
        "authorPhotoUrl",
        "authorPicture",
        "authorProfilePhoto",
        "profilePicture",
        "profilePhoto",
        "profilePhotoUrl",
        "reviewerPhoto",
        "reviewerPhotoUrl",
        "reviewerImage",
        "reviewerImageUrl",
        "reviewerProfilePhotoUrl",
        "userPhoto",
        "userPhotoUrl",
        "userImage",
        "imageUrl",
        "avatar",
        "avatarUrl",
    )

    def __init__(self) -> None:
        self.api_base = os.getenv("MRG_APIFY_API_BASE", "https://api.apify.com/v2").rstrip("/")
        self.token = os.getenv("MRG_APIFY_TOKEN", "").strip()
        self.actor_id = os.getenv("MRG_APIFY_ACTOR_ID", "").strip()
        self.timeout_seconds = int(os.getenv("MRG_APIFY_TIMEOUT", "180"))

    def health(self) -> Dict[str, Any]:
        missing = self._missing_config()
        return {
            "ok": not missing,
            "provider": self.name,
            "configured": not missing,
            "missing": missing,
            "message": "Apify listo." if not missing else "Faltan credenciales de Apify.",
        }

    def import_reviews(self, payload: ImportRequest) -> ImportResponse:
        missing = self._missing_config()
        if missing:
            raise ValueError("Faltan variables para Apify: " + ", ".join(missing))

        run = self._start_run(payload)
        run_id = str(run.get("id") or run.get("data", {}).get("id") or "").strip()
        if not run_id:
            raise RuntimeError("Apify no devolvio run_id.")

        self._wait_for_run(run_id)
        items = self._fetch_dataset_items(run_id)
        normalized_reviews = [self._normalize_review(item, payload) for item in items]
        reviews_with_text = [review for review in normalized_reviews if review.review_text.strip()]
        reviews = (reviews_with_text or normalized_reviews)[: self.limit_reviews(payload)]

        if not reviews:
            raise RuntimeError("Apify no devolvio reseñas con un formato compatible.")

        return ImportResponse(
            success=True,
            place_id=self.build_place_id("apify", str(payload.maps_url)),
            place_name=self.guess_business_name(str(payload.maps_url)),
            rating=self.compute_rating(reviews),
            user_ratings_total=len(reviews),
            review_target_url=str(payload.maps_url),
            reviews=reviews,
        )

    def _start_run(self, payload: ImportRequest) -> Dict[str, Any]:
        return self.request_json(
            "POST",
            f"{self.api_base}/acts/{self._actor_path()}/runs",
            params={"token": self.token},
            json={
                "startUrls": [{"url": str(payload.maps_url)}],
                "maxReviews": max(20, self.limit_reviews(payload) * 4),
                "reviewsSort": "newest",
                "reviewsOrigin": "google",
                "personalData": True,
                "language": payload.language,
            },
            timeout=30,
            fallback_message="No se pudo iniciar el actor de Apify.",
        )

    def _wait_for_run(self, run_id: str) -> Dict[str, Any]:
        started_at = time.time()
        while time.time() - started_at < self.timeout_seconds:
            data = self.request_json(
                "GET",
                f"{self.api_base}/actor-runs/{run_id}",
                params={"token": self.token},
                timeout=20,
                fallback_message="No se pudo consultar el estado de Apify.",
            )
            run_data = data.get("data", data) if isinstance(data, dict) else {}
            status = str(run_data.get("status", "")).upper()

            if status == "SUCCEEDED":
                return run_data
            if status in {"FAILED", "ABORTED", "TIMED-OUT"}:
                raise RuntimeError(f"Apify termino con estado {status}.")

            time.sleep(3)

        raise RuntimeError("Tiempo de espera agotado esperando a Apify.")

    def _fetch_dataset_items(self, run_id: str) -> List[Dict[str, Any]]:
        data = self.request_json(
            "GET",
            f"{self.api_base}/actor-runs/{run_id}/dataset/items",
            params={"token": self.token, "clean": "true"},
            timeout=30,
            fallback_message="No se pudo leer el dataset de Apify.",
        )
        if not isinstance(data, list):
            raise RuntimeError("Apify devolvio un dataset con formato inesperado.")
        return data

    def _normalize_review(self, item: Dict[str, Any], payload: ImportRequest) -> ReviewItem:
        author_name = item.get("authorName") or item.get("author") or item.get("name") or "Cliente"
        review_text = item.get("reviewText") or item.get("text") or item.get("comment") or ""
        review_date = item.get("publishedAtDate") or item.get("date") or item.get("reviewDate") or ""
        rating = item.get("stars") or item.get("rating") or item.get("score") or 5
        review_id = item.get("reviewId") or item.get("review_id") or item.get("id") or self.build_review_id(
            "apify_review", str(payload.maps_url), author_name, review_text, review_date
        )

        return ReviewItem(
            review_id=str(review_id),
            author_name=str(author_name),
            author_photo=self._extract_author_photo(item),
            rating=self.normalize_rating(rating),
            review_text=str(review_text),
            review_date=self.normalize_date_string(str(review_date)),
            relative_time=str(item.get("relativeTime") or item.get("publishedAt") or ""),
            is_anonymous=0 if str(author_name).strip() else 1,
        )

    def _extract_author_photo(self, item: Dict[str, Any]) -> str:
        photo = self._find_photo_url(item)
        return photo or ""

    def _find_photo_url(self, value: Any) -> Optional[str]:
        if isinstance(value, dict):
            for key in self.photo_keys:
                photo = self._normalize_photo_url(value.get(key))
                if photo:
                    return photo

            for nested_key in ("author", "reviewer", "user", "person", "profile"):
                photo = self._find_photo_url(value.get(nested_key))
                if photo:
                    return photo

        return None

    def _normalize_photo_url(self, value: Any) -> Optional[str]:
        if not value:
            return None

        url = str(value).strip()
        if url.startswith("//"):
            url = "https:" + url

        if url.startswith(("http://", "https://")):
            return url

        return None

    def _missing_config(self) -> List[str]:
        missing = []
        if not self.token:
            missing.append("MRG_APIFY_TOKEN")
        if not self.actor_id:
            missing.append("MRG_APIFY_ACTOR_ID")
        return missing

    def _actor_path(self) -> str:
        # Apify API expects named actors as "username~actor-name".
        # The console shows them as "username/actor-name", so accept both.
        return quote(self.actor_id.replace("/", "~"), safe="")
