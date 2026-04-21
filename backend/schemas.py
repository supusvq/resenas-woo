from typing import List, Optional

from pydantic import BaseModel, Field, HttpUrl


class ImportRequest(BaseModel):
    maps_url: HttpUrl
    max_reviews: int = Field(default=200, ge=1, le=1000)
    language: str = Field(default="es", min_length=2, max_length=10)
    site_url: Optional[HttpUrl] = None


class ReviewItem(BaseModel):
    review_id: str
    author_name: str
    author_photo: str = ""
    rating: int = Field(ge=1, le=5)
    review_text: str = ""
    review_date: str
    relative_time: str = ""
    is_anonymous: int = 0


class ImportResponse(BaseModel):
    success: bool = True
    place_id: str
    place_name: str
    rating: float = 0
    user_ratings_total: int = 0
    review_target_url: str
    reviews: List[ReviewItem]
