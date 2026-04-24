import hashlib
import logging
import os
import time
from datetime import datetime, timedelta, timezone
from typing import Any, Dict, List, Optional
from urllib.parse import urlparse

import requests
from requests import RequestException

from schemas import ImportRequest, ImportResponse, ReviewItem

log = logging.getLogger("mrg_import_service")


class ReviewImportService:
    def __init__(self) -> None:
        self.mode = os.getenv("MRG_IMPORT_MODE", "demo").strip().lower()

    def health_upstream(self) -> Dict[str, Any]:
        upstream_url = os.getenv("MRG_UPSTREAM_API_URL", "").rstrip("/")
        if self.mode != "upstream":
            return {
                "ok": True,
                "mode": self.mode,
                "upstream_configured": bool(upstream_url),
                "message": "Modo demo activo. Upstream no requerido.",
            }

        if not upstream_url:
            return {
                "ok": False,
                "mode": self.mode,
                "upstream_configured": False,
                "message": "Falta MRG_UPSTREAM_API_URL.",
            }

        try:
            payload = self._request_json(
                "GET",
                upstream_url,
                timeout=10,
                fallback_message="No se pudo consultar la raiz del scraper upstream.",
            )
            return {
                "ok": True,
                "mode": self.mode,
                "upstream_configured": True,
                "upstream_url": upstream_url,
                "upstream_health": payload,
            }
        except RuntimeError as exc:
            return {
                "ok": False,
                "mode": self.mode,
                "upstream_configured": True,
                "upstream_url": upstream_url,
                "message": str(exc),
            }

    def import_reviews(self, payload: ImportRequest) -> ImportResponse:
        if self.mode == "demo":
            return self._demo_response(payload)

        if self.mode == "upstream":
            return self._upstream_response(payload)

        raise ValueError("MRG_IMPORT_MODE debe ser 'demo' o 'upstream'.")

    def _demo_response(self, payload: ImportRequest) -> ImportResponse:
        business_name = self._guess_business_name(str(payload.maps_url))
        place_id = self._build_place_id(str(payload.maps_url))
        reviews = self._build_demo_reviews(business_name, place_id, payload.max_reviews)

        return ImportResponse(
            success=True,
            place_id=place_id,
            place_name=business_name,
            rating=4.9,
            user_ratings_total=max(199, len(reviews)),
            review_target_url=str(payload.maps_url),
            reviews=reviews,
        )

    def _upstream_response(self, payload: ImportRequest) -> ImportResponse:
        upstream_url = os.getenv("MRG_UPSTREAM_API_URL", "").rstrip("/")
        if not upstream_url:
            raise ValueError("Falta la variable MRG_UPSTREAM_API_URL para usar el modo upstream.")

        headers = self._build_headers()
        job_id = self._start_upstream_job(upstream_url, headers, payload)
        self._wait_for_job(upstream_url, headers, job_id)
        place_data = self._find_place_for_job(upstream_url, headers, str(payload.maps_url))
        place_id = str(place_data.get("place_id", "")).strip()

        if not place_id:
            raise RuntimeError("El scraper upstream no devolvio un place_id utilizable.")

        reviews_payload = self._fetch_upstream_reviews(upstream_url, headers, place_id, payload.max_reviews)
        raw_reviews = reviews_payload.get("reviews", [])
        if not isinstance(raw_reviews, list) or not raw_reviews:
            raise RuntimeError("El scraper upstream no devolvio reseÃ±as.")

        reviews = [self._normalize_upstream_review(item, place_id) for item in raw_reviews[: payload.max_reviews]]
        place_name = str(place_data.get("place_name") or self._guess_business_name(str(payload.maps_url))).strip()
        rating = self._compute_rating(reviews)

        return ImportResponse(
            success=True,
            place_id=place_id,
            place_name=place_name,
            rating=rating,
            user_ratings_total=int(place_data.get("total_reviews") or len(reviews)),
            review_target_url=str(place_data.get("resolved_url") or payload.maps_url),
            reviews=reviews,
        )

    def _build_headers(self) -> Dict[str, str]:
        headers = {
            "Accept": "application/json",
            "Content-Type": "application/json",
        }

        api_key = os.getenv("MRG_UPSTREAM_API_KEY", "").strip()
        if api_key:
            headers["X-API-Key"] = api_key

        return headers

    def _start_upstream_job(self, upstream_url: str, headers: Dict[str, str], payload: ImportRequest) -> str:
        data = self._request_json(
            "POST",
            f"{upstream_url}/scrape",
            headers=headers,
            json={
                "url": str(payload.maps_url),
                "max_reviews": payload.max_reviews,
                "headless": True,
                "sort_by": "newest",
                "download_images": False,
                "use_s3": False,
                "max_scroll_attempts": 20,
                "scroll_idle_limit": 6,
            },
            timeout=30,
            fallback_message="No se pudo crear el trabajo en el scraper upstream.",
        )
        job_id = str(data.get("job_id", "")).strip()

        if not job_id:
            raise RuntimeError("El scraper upstream no devolvio job_id.")

        return job_id

    def _wait_for_job(self, upstream_url: str, headers: Dict[str, str], job_id: str) -> Dict[str, Any]:
        timeout_seconds = int(os.getenv("MRG_UPSTREAM_TIMEOUT", "180"))
        started_at = time.time()

        while time.time() - started_at < timeout_seconds:
            data = self._request_json(
                "GET",
                f"{upstream_url}/jobs/{job_id}",
                headers=headers,
                timeout=15,
                fallback_message="No se pudo consultar el estado del trabajo upstream.",
            )
            status = str(data.get("status", "")).lower()

            if status in {"completed", "finished", "done"}:
                return data

            if status in {"failed", "error", "cancelled"}:
                detail = data.get("error_message") or "El trabajo upstream termino con error."
                raise RuntimeError(str(detail))

            time.sleep(3)

        raise RuntimeError("Tiempo de espera agotado consultando el scraper upstream.")

    def _find_place_for_job(self, upstream_url: str, headers: Dict[str, str], maps_url: str) -> Dict[str, Any]:
        data = self._request_json(
            "GET",
            f"{upstream_url}/places",
            headers=headers,
            params={"limit": 100},
            timeout=20,
            fallback_message="No se pudo recuperar la lista de places del scraper upstream.",
        )

        places = data if isinstance(data, list) else data.get("places", [])
        if not isinstance(places, list):
            raise RuntimeError("El scraper upstream devolvio un formato inesperado para places.")

        target = self._normalize_url(maps_url)
        for place in reversed(places):
            original_url = self._normalize_url(str(place.get("original_url", "")))
            resolved_url = self._normalize_url(str(place.get("resolved_url", "")))
            if target and (target == original_url or target == resolved_url):
                return place

        if places:
            return places[-1]

        raise RuntimeError("El scraper upstream no devolvio ningun place para la URL indicada.")

    def _fetch_upstream_reviews(
        self, upstream_url: str, headers: Dict[str, str], place_id: str, max_reviews: int
    ) -> Dict[str, Any]:
        return self._request_json(
            "GET",
            f"{upstream_url}/reviews/{place_id}",
            headers=headers,
            params={"limit": max_reviews, "offset": 0},
            timeout=30,
            fallback_message="No se pudieron recuperar las reseñas del scraper upstream.",
        )

    def _normalize_upstream_review(self, item: Dict[str, Any], place_id: str) -> ReviewItem:
        review_text = item.get("review_text") or ""
        author_name = item.get("author") or "Cliente"
        profile_picture = item.get("profile_picture") or ""
        review_date = item.get("review_date") or item.get("created_date") or datetime.now(timezone.utc).strftime(
            "%Y-%m-%d %H:%M:%S"
        )
        relative_time = item.get("raw_date") or ""
        review_id = item.get("review_id") or hashlib.md5(
            f"{place_id}|{author_name}|{review_text}|{review_date}".encode("utf-8")
        ).hexdigest()
        rating = int(round(float(item.get("rating") or 0)))
        rating = max(1, min(5, rating))

        return ReviewItem(
            review_id=str(review_id),
            author_name=str(author_name),
            author_photo=str(profile_picture),
            rating=rating,
            review_text=str(review_text),
            review_date=self._normalize_date_string(str(review_date)),
            relative_time=str(relative_time),
            is_anonymous=0 if str(author_name).strip() else 1,
        )

    def _request_json(
        self,
        method: str,
        url: str,
        *,
        headers: Optional[Dict[str, str]] = None,
        json: Optional[Dict[str, Any]] = None,
        params: Optional[Dict[str, Any]] = None,
        timeout: int = 30,
        fallback_message: str,
    ) -> Dict[str, Any]:
        try:
            response = requests.request(
                method=method,
                url=url,
                headers=headers,
                json=json,
                params=params,
                timeout=timeout,
            )
        except RequestException as exc:
            raise RuntimeError(f"{fallback_message} Error de red: {exc}") from exc

        return self._json_or_raise(response, fallback_message)

    def _json_or_raise(self, response: requests.Response, fallback_message: str) -> Dict[str, Any]:
        try:
            data = response.json()
        except ValueError as exc:
            excerpt = (response.text or "").strip().replace("\n", " ")[:220]
            if response.status_code < 200 or response.status_code >= 300:
                raise RuntimeError(
                    f"{fallback_message} HTTP {response.status_code}. Respuesta: {excerpt or 'sin detalle'}"
                ) from exc
            raise RuntimeError(f"{fallback_message} Respuesta no JSON.") from exc

        if response.status_code < 200 or response.status_code >= 300:
            detail = ""
            if isinstance(data, dict):
                detail = str(data.get("detail") or data.get("message") or data.get("error") or "")
            raise RuntimeError(detail or f"{fallback_message} HTTP {response.status_code}.")

        return data

    def _compute_rating(self, reviews: List[ReviewItem]) -> float:
        if not reviews:
            return 0
        return round(sum(review.rating for review in reviews) / len(reviews), 1)

    def _build_demo_reviews(self, business_name: str, place_id: str, max_reviews: int) -> List[ReviewItem]:
        sample_texts = [
            "Servicio rapido y muy profesional. Todo llego perfecto.",
            "Muy buena experiencia de compra y atencion al detalle.",
            "Repetiremos sin duda. Muy recomendable.",
            "Buen trato, envio rapido y resultado excelente.",
            "Todo correcto desde el primer momento.",
            "Atencion cercana y gran calidad en el acabado.",
            "Profesionales, puntuales y muy atentos.",
            "Muy satisfechos con el pedido y la comunicacion.",
        ]

        review_count = min(max_reviews, 6)
        reviews: List[ReviewItem] = []

        for index in range(review_count):
            published = datetime.now(timezone.utc) - timedelta(days=(index + 1) * 21)
            review_id = hashlib.md5(f"{place_id}|demo|{index}".encode("utf-8")).hexdigest()
            reviews.append(
                ReviewItem(
                    review_id=review_id,
                    author_name=f"Cliente {index + 1}",
                    author_photo="",
                    rating=5 if index < 6 else 4,
                    review_text=sample_texts[index % len(sample_texts)],
                    review_date=published.strftime("%Y-%m-%d %H:%M:%S"),
                    relative_time=f"Hace {(index + 1) * 2} meses",
                    is_anonymous=0,
                )
            )

        return reviews

    def _guess_business_name(self, maps_url: str) -> str:
        parsed = urlparse(maps_url)
        path = parsed.path.strip("/")
        if "/place/" in maps_url:
            slug = maps_url.split("/place/", 1)[1].split("/", 1)[0]
        else:
            slug = path.split("/")[-1] if path else "negocio"

        cleaned = slug.replace("+", " ").replace("%20", " ").strip()
        cleaned = cleaned or "Negocio"
        return cleaned[:80]

    def _build_place_id(self, maps_url: str) -> str:
        return "remote_" + hashlib.md5(maps_url.strip().lower().encode("utf-8")).hexdigest()

    def _normalize_url(self, value: str) -> str:
        return value.strip().rstrip("/")

    def _normalize_date_string(self, value: str) -> str:
        raw = value.strip()
        if not raw:
            return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")

        for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%dT%H:%M:%S", "%Y-%m-%d"):
            try:
                parsed = datetime.strptime(raw, fmt)
                return parsed.strftime("%Y-%m-%d %H:%M:%S")
            except ValueError:
                continue

        return raw

