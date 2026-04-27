import logging

from fastapi import FastAPI, HTTPException

from schemas import ImportRequest, ImportResponse
from service import ReviewImportService

log = logging.getLogger("mrg_import_service")

app = FastAPI(
    title="MRG Import Service",
    version="0.2.0",
    description="Backend para importar reseñas de Google hacia el plugin Reseñas Woo.",
)


@app.get("/health")
def healthcheck():
    service = ReviewImportService()
    return service.health()


@app.get("/health/upstream")
def healthcheck_upstream():
    service = ReviewImportService()
    return service.health_upstream()


@app.post("/v1/import-reviews", response_model=ImportResponse)
def import_reviews(payload: ImportRequest):
    service = ReviewImportService()

    try:
        return service.import_reviews(payload)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    except RuntimeError as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc
    except Exception as exc:
        log.exception("Error inesperado en /v1/import-reviews")
        raise HTTPException(status_code=502, detail=f"Error inesperado en backend: {exc}") from exc
