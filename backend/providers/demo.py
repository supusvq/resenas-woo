from datetime import datetime, timedelta, timezone
from typing import List

from providers.base import BaseReviewProvider
from schemas import ImportRequest, ImportResponse, ReviewItem


class DemoProvider(BaseReviewProvider):
    name = "demo"

    def import_reviews(self, payload: ImportRequest) -> ImportResponse:
        business_name = self.guess_business_name(str(payload.maps_url))
        place_id = self.build_place_id("demo", str(payload.maps_url))
        reviews = self._build_reviews(place_id, payload)

        return ImportResponse(
            success=True,
            place_id=place_id,
            place_name=business_name,
            rating=4.9,
            user_ratings_total=max(199, len(reviews)),
            review_target_url=str(payload.maps_url),
            reviews=reviews,
        )

    def _build_reviews(self, place_id: str, payload: ImportRequest) -> List[ReviewItem]:
        sample_texts = [
            "Servicio rapido y muy profesional. Todo llego perfecto.",
            "Muy buena experiencia de compra y atencion al detalle.",
            "Repetiremos sin duda. Muy recomendable.",
            "Buen trato, envio rapido y resultado excelente.",
            "Todo correcto desde el primer momento.",
            "Atencion cercana y gran calidad en el acabado.",
        ]
        reviews: List[ReviewItem] = []

        for index in range(self.limit_reviews(payload)):
            published = datetime.now(timezone.utc) - timedelta(days=(index + 1) * 21)
            reviews.append(
                ReviewItem(
                    review_id=self.build_review_id("demo_review", place_id, index),
                    author_name=f"Cliente {index + 1}",
                    author_photo="",
                    rating=5,
                    review_text=sample_texts[index % len(sample_texts)],
                    review_date=published.strftime("%Y-%m-%d %H:%M:%S"),
                    relative_time=f"Hace {(index + 1) * 2} meses",
                    is_anonymous=0,
                )
            )

        return reviews
