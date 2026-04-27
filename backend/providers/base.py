import hashlib
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional
from urllib.parse import unquote_plus, urlparse

import requests
from requests import RequestException

from schemas import ImportRequest, ImportResponse, ReviewItem


class BaseReviewProvider:
    name = "base"

    def health(self) -> Dict[str, Any]:
        return {"ok": True, "provider": self.name}

    def import_reviews(self, payload: ImportRequest) -> ImportResponse:
        raise NotImplementedError

    def request_json(
        self,
        method: str,
        url: str,
        *,
        headers: Optional[Dict[str, str]] = None,
        json: Optional[Dict[str, Any]] = None,
        data: Optional[Dict[str, Any]] = None,
        params: Optional[Dict[str, Any]] = None,
        timeout: int = 30,
        fallback_message: str,
    ) -> Any:
        try:
            response = requests.request(
                method=method,
                url=url,
                headers=headers,
                json=json,
                data=data,
                params=params,
                timeout=timeout,
            )
        except RequestException as exc:
            raise RuntimeError(f"{fallback_message} Error de red: {exc}") from exc

        return self.json_or_raise(response, fallback_message)

    def json_or_raise(self, response: requests.Response, fallback_message: str) -> Any:
        try:
            data = response.json()
        except ValueError as exc:
            excerpt = (response.text or "").strip().replace("\n", " ")[:220]
            if response.status_code < 200 or response.status_code >= 300:
                raise RuntimeError(
                    f"{fallback_message} HTTP {response.status_code}. "
                    f"Respuesta: {excerpt or 'sin detalle'}"
                ) from exc
            raise RuntimeError(f"{fallback_message} Respuesta no JSON.") from exc

        if response.status_code < 200 or response.status_code >= 300:
            detail = ""
            if isinstance(data, dict):
                detail = str(data.get("detail") or data.get("message") or data.get("error") or "")
            raise RuntimeError(detail or f"{fallback_message} HTTP {response.status_code}.")

        return data

    def compute_rating(self, reviews: List[ReviewItem]) -> float:
        if not reviews:
            return 0
        return round(sum(review.rating for review in reviews) / len(reviews), 1)

    def normalize_rating(self, value: Any) -> int:
        if isinstance(value, str):
            rating_map = {
                "ONE": 1,
                "TWO": 2,
                "THREE": 3,
                "FOUR": 4,
                "FIVE": 5,
                "STAR_RATING_UNSPECIFIED": 0,
            }
            value = rating_map.get(value.upper(), value)

        try:
            rating = int(round(float(value or 0)))
        except (TypeError, ValueError):
            rating = 0

        return max(1, min(5, rating))

    def normalize_date_string(self, value: str) -> str:
        raw = str(value or "").strip()
        if not raw:
            return datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")

        normalized = raw.replace("Z", "+00:00")
        try:
            parsed = datetime.fromisoformat(normalized)
            return parsed.strftime("%Y-%m-%d %H:%M:%S")
        except ValueError:
            pass

        for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%dT%H:%M:%S", "%Y-%m-%d"):
            try:
                parsed = datetime.strptime(raw, fmt)
                return parsed.strftime("%Y-%m-%d %H:%M:%S")
            except ValueError:
                continue

        return raw

    def guess_business_name(self, maps_url: str) -> str:
        parsed = urlparse(maps_url)
        path = parsed.path.strip("/")
        if "/place/" in maps_url:
            slug = maps_url.split("/place/", 1)[1].split("/", 1)[0]
        else:
            slug = path.split("/")[-1] if path else "negocio"

        cleaned = unquote_plus(slug).replace("+", " ").strip()
        return (cleaned or "Negocio")[:80]

    def build_place_id(self, prefix: str, value: str) -> str:
        digest = hashlib.md5(value.strip().lower().encode("utf-8")).hexdigest()
        return f"{prefix}_{digest}"

    def build_review_id(self, prefix: str, *parts: Any) -> str:
        digest = hashlib.md5("|".join(str(part) for part in parts).encode("utf-8")).hexdigest()
        return f"{prefix}_{digest}"

    def limit_reviews(self, payload: ImportRequest) -> int:
        return max(1, min(6, int(payload.max_reviews or 6)))
