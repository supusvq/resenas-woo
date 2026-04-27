import os
import time
from typing import Any, Dict, List

from providers.base import BaseReviewProvider
from schemas import ImportRequest, ImportResponse, ReviewItem


class SeleniumLegacyProvider(BaseReviewProvider):
    name = "selenium_legacy"

    def __init__(self) -> None:
        self.upstream_url = os.getenv("MRG_UPSTREAM_API_URL", "").rstrip("/")
        self.api_key = os.getenv("MRG_UPSTREAM_API_KEY", "").strip()
        self.timeout_seconds = int(os.getenv("MRG_UPSTREAM_TIMEOUT", "180"))

    def health(self) -> Dict[str, Any]:
        if not self.upstream_url:
            return {
                "ok": False,
                "provider": self.name,
                "configured": False,
                "message": "Falta MRG_UPSTREAM_API_URL.",
            }

        try:
            payload = self.request_json(
                "GET",
                self.upstream_url,
                timeout=10,
                fallback_message="No se pudo consultar la raiz del scraper Selenium legado.",
            )
            return {
                "ok": True,
                "provider": self.name,
                "configured": True,
                "upstream_url": self.upstream_url,
                "upstream_health": payload,
            }
        except RuntimeError as exc:
            return {
                "ok": False,
                "provider": self.name,
                "configured": True,
                "upstream_url": self.upstream_url,
                "message": str(exc),
            }

    def import_reviews(self, payload: ImportRequest) -> ImportResponse:
        if not self.upstream_url:
            raise ValueError("Falta la variable MRG_UPSTREAM_API_URL para usar Selenium legado.")

        headers = self._build_headers()
        job_id = self._start_job(headers, payload)
        self._wait_for_job(headers, job_id)
        place_data = self._find_place_for_job(headers, str(payload.maps_url))
        place_id = str(place_data.get("place_id", "")).strip()

        if not place_id:
            raise RuntimeError("El scraper Selenium no devolvio un place_id utilizable.")

        reviews_payload = self._fetch_reviews(headers, place_id, self.limit_reviews(payload))
        raw_reviews = reviews_payload.get("reviews", [])
        if not isinstance(raw_reviews, list) or not raw_reviews:
            raise RuntimeError("El scraper Selenium no devolvio reseñas.")

        reviews = [self._normalize_review(item, place_id) for item in raw_reviews[: self.limit_reviews(payload)]]
        place_name = str(place_data.get("place_name") or self.guess_business_name(str(payload.maps_url))).strip()

        return ImportResponse(
            success=True,
            place_id=place_id,
            place_name=place_name,
            rating=self.compute_rating(reviews),
            user_ratings_total=int(place_data.get("total_reviews") or len(reviews)),
            review_target_url=str(place_data.get("resolved_url") or payload.maps_url),
            reviews=reviews,
        )

    def _build_headers(self) -> Dict[str, str]:
        headers = {"Accept": "application/json", "Content-Type": "application/json"}
        if self.api_key:
            headers["X-API-Key"] = self.api_key
        return headers

    def _start_job(self, headers: Dict[str, str], payload: ImportRequest) -> str:
        data = self.request_json(
            "POST",
            f"{self.upstream_url}/scrape",
            headers=headers,
            json={
                "url": str(payload.maps_url),
                "max_reviews": self.limit_reviews(payload),
                "headless": True,
                "sort_by": "newest",
                "download_images": False,
                "use_s3": False,
                "max_scroll_attempts": 8,
                "scroll_idle_limit": 3,
            },
            timeout=30,
            fallback_message="No se pudo crear el trabajo en el scraper Selenium.",
        )
        job_id = str(data.get("job_id", "")).strip()
        if not job_id:
            raise RuntimeError("El scraper Selenium no devolvio job_id.")
        return job_id

    def _wait_for_job(self, headers: Dict[str, str], job_id: str) -> Dict[str, Any]:
        started_at = time.time()

        while time.time() - started_at < self.timeout_seconds:
            data = self.request_json(
                "GET",
                f"{self.upstream_url}/jobs/{job_id}",
                headers=headers,
                timeout=15,
                fallback_message="No se pudo consultar el estado del trabajo Selenium.",
            )
            status = str(data.get("status", "")).lower()

            if status in {"completed", "finished", "done"}:
                return data
            if status in {"failed", "error", "cancelled"}:
                detail = data.get("error_message") or "El trabajo Selenium termino con error."
                raise RuntimeError(str(detail))

            time.sleep(3)

        raise RuntimeError("Tiempo de espera agotado consultando el scraper Selenium.")

    def _find_place_for_job(self, headers: Dict[str, str], maps_url: str) -> Dict[str, Any]:
        data = self.request_json(
            "GET",
            f"{self.upstream_url}/places",
            headers=headers,
            params={"limit": 100},
            timeout=20,
            fallback_message="No se pudo recuperar la lista de places del scraper Selenium.",
        )

        places = data if isinstance(data, list) else data.get("places", [])
        if not isinstance(places, list):
            raise RuntimeError("El scraper Selenium devolvio un formato inesperado para places.")

        target = maps_url.strip().rstrip("/")
        for place in reversed(places):
            original_url = str(place.get("original_url", "")).strip().rstrip("/")
            resolved_url = str(place.get("resolved_url", "")).strip().rstrip("/")
            if target and (target == original_url or target == resolved_url):
                return place

        if places:
            return places[-1]

        raise RuntimeError("El scraper Selenium no devolvio ningun place para la URL indicada.")

    def _fetch_reviews(self, headers: Dict[str, str], place_id: str, max_reviews: int) -> Dict[str, Any]:
        return self.request_json(
            "GET",
            f"{self.upstream_url}/reviews/{place_id}",
            headers=headers,
            params={"limit": max_reviews, "offset": 0},
            timeout=30,
            fallback_message="No se pudieron recuperar las reseñas del scraper Selenium.",
        )

    def _normalize_review(self, item: Dict[str, Any], place_id: str) -> ReviewItem:
        review_text = item.get("review_text") or ""
        author_name = item.get("author") or "Cliente"
        review_date = item.get("review_date") or item.get("created_date") or ""
        review_id = item.get("review_id") or self.build_review_id(
            "selenium_review", place_id, author_name, review_text, review_date
        )

        return ReviewItem(
            review_id=str(review_id),
            author_name=str(author_name),
            author_photo=str(item.get("profile_picture") or ""),
            rating=self.normalize_rating(item.get("rating")),
            review_text=str(review_text),
            review_date=self.normalize_date_string(str(review_date)),
            relative_time=str(item.get("raw_date") or ""),
            is_anonymous=0 if str(author_name).strip() else 1,
        )
