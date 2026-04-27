# Backend de importacion de reseñas

Este servicio expone el endpoint que usa el plugin WordPress:

```text
POST /v1/import-reviews
```

El objetivo es dejar Selenium como fallback legado y usar proveedores mas estables:

- `google_business_profile`: API oficial de Google Business Profile. Es el camino recomendado si tenemos acceso a la ficha del negocio.
- `apify`: proveedor externo preparado para una siguiente fase.
- `selenium_legacy`: scraper antiguo en `127.0.0.1:8001`. Solo fallback.
- `demo`: reseñas falsas para probar WordPress sin servicios externos.

## Arranque local

```powershell
cd backend
python -m venv .venv
.venv\Scripts\Activate.ps1
pip install -r requirements.txt
$env:MRG_REVIEW_PROVIDER="demo"
uvicorn app:app --host 127.0.0.1 --port 8010 --reload
```

## Produccion recomendada

```bash
export MRG_REVIEW_PROVIDER=google_business_profile
export MRG_GBP_REFRESH_TOKEN="..."
export MRG_GBP_CLIENT_ID="..."
export MRG_GBP_CLIENT_SECRET="..."
export MRG_GBP_ACCOUNT_ID="..."
export MRG_GBP_LOCATION_ID="..."
export MRG_GBP_PLACE_NAME="Nombre del negocio"
uvicorn app:app --host 127.0.0.1 --port 8000
```

## Google Business Profile

Hay dos formas de usarlo:

- SaaS multi-cliente: cada WordPress se registra, conecta Google por OAuth y el backend guarda su refresh token.
- Configuracion fija por entorno: util solo para pruebas o una unica ficha.

Este modo llama a:

```text
GET /accounts/{account_id}/locations/{location_id}/reviews
```

Variables necesarias:

- `MRG_GBP_ACCESS_TOKEN`
- `MRG_GBP_REFRESH_TOKEN`
- `MRG_GBP_CLIENT_ID`
- `MRG_GBP_CLIENT_SECRET`
- `MRG_GBP_ACCOUNT_ID`
- `MRG_GBP_LOCATION_ID`
- `MRG_GBP_PLACE_NAME` opcional

Para pruebas se puede usar `MRG_GBP_ACCESS_TOKEN`, pero en produccion es mejor usar refresh token OAuth porque el access token caduca.

## Flujo SaaS multi-cliente

Registrar una web:

```text
POST /v1/sites/register
```

Body:

```json
{
  "site_url": "https://cliente.com/"
}
```

Respuesta:

```json
{
  "site_id": "site_xxx",
  "site_token": "xxx",
  "site_url": "https://cliente.com"
}
```

Iniciar OAuth:

```text
GET /v1/google/oauth/start?site_url=https://cliente.com/&site_token=xxx
```

Callback configurado en Google Cloud:

```text
https://scraper.supufactory.es/v1/google/oauth/callback
```

Listar ubicaciones conectadas:

```text
GET /v1/google/locations?site_url=https://cliente.com/&site_token=xxx
```

Seleccionar ubicacion:

```text
POST /v1/google/location
```

Body:

```json
{
  "site_url": "https://cliente.com/",
  "site_token": "xxx",
  "account_id": "accounts/123",
  "location_id": "locations/456",
  "place_name": "Nombre del negocio"
}
```

Despues, el plugin puede importar reseñas enviando `site_url` y `site_token` en `/v1/import-reviews`.

Importante: la API oficial solo sirve para fichas donde tengamos permiso en Google Business Profile. No sirve para rascar reseñas de cualquier negocio publico sin autorizacion.

## Apify

Preparado para la siguiente fase:

```bash
export MRG_REVIEW_PROVIDER=apify
export MRG_APIFY_TOKEN="..."
export MRG_APIFY_ACTOR_ID="..."
```

El backend arranca el actor, espera el resultado y normaliza campos comunes. Cuando elijamos actor concreto, ajustaremos el payload y el mapeo si hace falta.

## Selenium legado

Solo si queremos mantener el scraper antiguo:

```bash
export MRG_REVIEW_PROVIDER=selenium_legacy
export MRG_UPSTREAM_API_URL=http://127.0.0.1:8001
export MRG_UPSTREAM_TIMEOUT=180
```

## Healthchecks

```text
GET /health
GET /health/upstream
```

`/health/upstream` se mantiene por compatibilidad, pero ahora informa del proveedor activo.

## Contrato de respuesta

El plugin espera siempre JSON con:

```json
{
  "success": true,
  "place_id": "gbp_xxx",
  "place_name": "Negocio",
  "rating": 4.9,
  "user_ratings_total": 120,
  "review_target_url": "https://www.google.com/maps/place/...",
  "reviews": []
}
```

El backend limita la importacion a 6 reseñas como maximo.
