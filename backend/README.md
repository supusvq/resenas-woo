# Backend minimo para Reseñas Woo

Este backend expone el endpoint que espera el plugin:

- `POST /v1/import-reviews`

Tiene dos modos:

- `demo`: devuelve reseñas simuladas para probar el plugin de punta a punta.
- `upstream`: delega en una instancia del scraper externo y normaliza la respuesta al contrato del plugin.

## 1. Crear entorno virtual

```powershell
cd backend
python -m venv .venv
.venv\Scripts\Activate.ps1
pip install -r requirements.txt
```

## 2. Arranque rapido en modo demo

```powershell
$env:MRG_IMPORT_MODE="demo"
uvicorn app:app --host 127.0.0.1 --port 8010 --reload
```

Luego en el plugin usa como URL del servicio:

```text
http://127.0.0.1:8010
```

## 3. Modo upstream con scraper real

Debes tener una API del scraper funcionando aparte, por ejemplo en:

```text
http://127.0.0.1:8001
```

Variables:

```powershell
$env:MRG_IMPORT_MODE="upstream"
$env:MRG_UPSTREAM_API_URL="http://127.0.0.1:8001"
$env:MRG_UPSTREAM_API_KEY=""
$env:MRG_UPSTREAM_TIMEOUT="180"
uvicorn app:app --host 127.0.0.1 --port 8010 --reload
```

## 4. Healthcheck

```text
GET /health
```

Respuesta:

```json
{
  "ok": true,
  "service": "mrg-import-service"
}
```

## 5. Endpoint principal

```text
POST /v1/import-reviews
```

Ejemplo:

```json
{
  "maps_url": "https://www.google.com/maps/place/...",
  "max_reviews": 200,
  "language": "es",
  "site_url": "https://midominio.com/"
}
```

## 6. Notas de integracion

- El plugin ya esta preparado para este endpoint.
- En `demo` puedes validar el flujo sin scraper real.
- En `upstream` este backend:
  - crea el job en `/scrape`
  - consulta `/jobs/{job_id}`
  - busca el place correspondiente
  - descarga las reseñas y las normaliza

## 7. Siguiente paso recomendado

Cuando quieras, podemos adaptar este backend al repo `google-reviews-scraper-pro` de forma mas estricta y dejarlo listo para produccion con:

- timeouts mas finos
- logs estructurados
- autenticacion
- rate limiting
- dockerizacion
