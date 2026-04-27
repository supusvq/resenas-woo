# Handoff tecnico

## Decision actual

El scraper Selenium ha quedado como fallback legado. La estrategia buena ahora es:

1. Usar Google Business Profile API cuando tengamos acceso a la ficha.
2. Preparar Apify como alternativa controlada si no hay acceso oficial.
3. Mantener Selenium solo para pruebas o emergencia.

## Estado del plugin

- El plugin WordPress sigue llamando a `http://scraper.supufactory.es`.
- Nginx en el VPS reenvia a `127.0.0.1:8000`.
- El backend del plugin corre como `google-reviews.service`.
- El endpoint estable sigue siendo `POST /v1/import-reviews`.
- El plugin guarda y muestra como maximo 6 reseñas.

## Backend nuevo

Ruta local:

```text
C:\Users\Equipo\Desktop\MODELOS\APPS\PLUGINS WORDPRESS\Reseñas Woo\resenas_woo\backend
```

Ruta VPS:

```text
/opt/supu/apps/resenas-woo/backend
```

Proveedor activo por variable:

```text
MRG_REVIEW_PROVIDER=google_business_profile
```

Valores validos:

- `google_business_profile`
- `apify`
- `selenium_legacy`
- `demo`

## Flujo SaaS por cliente

1. El WordPress cliente llama a `POST /v1/sites/register`.
2. El backend devuelve `site_token`.
3. El plugin guarda `service_site_token`.
4. El cliente pulsa "Conectar Google".
5. El backend inicia OAuth con Google usando `business.manage`.
6. Google vuelve a `/v1/google/oauth/callback`.
7. El backend guarda el refresh token ligado a ese sitio.
8. El plugin lista ubicaciones con "Cargar fichas de Google" y guarda una.
9. `/v1/import-reviews` usa el token del sitio para saber que ficha leer.

## Google Business Profile

Variables necesarias:

```text
MRG_GBP_ACCESS_TOKEN=
MRG_GBP_REFRESH_TOKEN=
MRG_GBP_CLIENT_ID=
MRG_GBP_CLIENT_SECRET=
MRG_GBP_ACCOUNT_ID=
MRG_GBP_LOCATION_ID=
MRG_GBP_PLACE_NAME=
```

Variables SaaS/OAuth:

```text
MRG_SAAS_DB_PATH=mrg_saas.sqlite3
MRG_GOOGLE_CLIENT_ID=
MRG_GOOGLE_CLIENT_SECRET=
MRG_GOOGLE_REDIRECT_URI=https://scraper.supufactory.es/v1/google/oauth/callback
```

Pendiente real:

- Crear/validar proyecto Google Cloud.
- Activar Google Business Profile API.
- Conseguir OAuth con permisos sobre la ficha.
- Guardar refresh token OAuth en el VPS para renovar access tokens.
- Guardar token de forma segura en el VPS, no en Git.
- Mejorar en siguiente fase: cifrar refresh tokens en SQLite.

## Apify

Variables preparadas:

```text
MRG_APIFY_TOKEN=
MRG_APIFY_ACTOR_ID=
MRG_APIFY_TIMEOUT=180
```

Pendiente:

- Elegir actor concreto.
- Confirmar formato de salida.
- Ajustar mapeo si el actor usa nombres de campos diferentes.

## Selenium legado

Ruta local:

```text
C:\Users\Equipo\Desktop\google-reviews-scraper-pro
```

Ruta VPS:

```text
/opt/supu/services/google-reviews-scraper
```

Variables:

```text
MRG_REVIEW_PROVIDER=selenium_legacy
MRG_UPSTREAM_API_URL=http://127.0.0.1:8001
```

Problema conocido:

- Google cambia estructura/consentimiento.
- La deteccion de tarjetas de reseñas no es estable.
- Por eso ya no debe ser el camino principal.

## Comandos utiles

```bash
sudo systemctl status google-reviews --no-pager
curl http://127.0.0.1:8000/health
curl http://127.0.0.1:8000/health/upstream
```

Si se mantiene Selenium temporalmente:

```bash
curl http://127.0.0.1:8001/
```
