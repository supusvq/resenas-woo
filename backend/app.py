import logging

from fastapi import FastAPI, HTTPException, Query
from fastapi.responses import HTMLResponse

from google_oauth import GoogleOAuthClient
from schemas import ImportRequest, ImportResponse, SiteLocationRequest, SiteRegisterRequest, SiteRegisterResponse
from service import ReviewImportService
from tenant_store import TenantStore

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


@app.post("/v1/sites/register", response_model=SiteRegisterResponse)
def register_site(payload: SiteRegisterRequest):
    site = TenantStore().register_site(str(payload.site_url))
    return SiteRegisterResponse(
        site_id=str(site["site_id"]),
        site_token=str(site["site_token"]),
        site_url=str(site["site_url"]),
    )


@app.get("/v1/google/oauth/start")
def google_oauth_start(
    site_url: str = Query(...),
    site_token: str = Query(...),
):
    store = TenantStore()
    site = store.authenticate(site_url, site_token)
    if not site:
        raise HTTPException(status_code=403, detail="Site token no valido.")

    state = store.create_oauth_state(str(site["site_id"]))
    auth_url = GoogleOAuthClient().build_authorization_url(state)
    return {"success": True, "authorization_url": auth_url}


@app.get("/v1/google/oauth/callback", response_class=HTMLResponse)
def google_oauth_callback(code: str = Query(...), state: str = Query(...)):
    store = TenantStore()
    site = store.consume_oauth_state(state)
    if not site:
        raise HTTPException(status_code=400, detail="Estado OAuth no valido o caducado.")

    token_data = GoogleOAuthClient().exchange_code(code)
    refresh_token = str(token_data.get("refresh_token") or "").strip()
    if not refresh_token:
        raise HTTPException(
            status_code=400,
            detail="Google no devolvio refresh_token. Repite la conexion y acepta permisos.",
        )

    store.update_google_tokens(str(site["site_id"]), refresh_token)
    return HTMLResponse(
        "<h1>Google conectado</h1>"
        "<p>Ya puedes volver a WordPress y seleccionar la ficha del negocio.</p>"
    )


@app.get("/v1/google/locations")
def google_locations(site_url: str = Query(...), site_token: str = Query(...)):
    site = TenantStore().authenticate(site_url, site_token)
    if not site:
        raise HTTPException(status_code=403, detail="Site token no valido.")

    refresh_token = str(site.get("google_refresh_token") or "")
    if not refresh_token:
        raise HTTPException(status_code=400, detail="Esta web todavia no ha conectado Google.")

    oauth = GoogleOAuthClient()
    access_token = oauth.refresh_access_token(refresh_token)
    accounts = oauth.list_accounts(access_token)
    locations = []

    for account in accounts:
        account_name = str(account.get("name") or "")
        if not account_name:
            continue
        for location in oauth.list_locations(access_token, account_name):
            locations.append(
                {
                    "account_id": account_name,
                    "location_id": str(location.get("name") or ""),
                    "place_name": str(location.get("title") or ""),
                }
            )

    return {"success": True, "locations": locations}


@app.post("/v1/google/location")
def google_select_location(payload: SiteLocationRequest):
    site = TenantStore().update_google_location(
        str(payload.site_url),
        payload.site_token,
        payload.account_id,
        payload.location_id,
        payload.place_name,
    )
    return {
        "success": True,
        "site_id": site["site_id"],
        "account_id": site["google_account_id"],
        "location_id": site["google_location_id"],
        "place_name": site["google_place_name"],
    }


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
