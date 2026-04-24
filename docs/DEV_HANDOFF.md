# Handoff tecnico

## Estado actual

- El plugin de WordPress ya esta separado del scraper real.
- El plugin llama a `http://scraper.supufactory.es`.
- Nginx en el VPS reenvia a `127.0.0.1:8000`.
- El backend del plugin corre como `google-reviews.service`.
- El backend esta en modo `upstream`.
- El scraper real corre en `127.0.0.1:8001`.

## Repos y rutas

- Plugin: `C:\Users\Equipo\Desktop\MODELOS\APPS\PLUGINS WORDPRESS\Reseñas Woo\resenas_woo`
- Backend del plugin en VPS: `/opt/supu/apps/resenas-woo`
- Scraper real en local: `C:\Users\Equipo\Desktop\google-reviews-scraper-pro`
- Scraper real en VPS: `/opt/supu/services/google-reviews-scraper`

## Configuracion importante

- `MRG_IMPORT_MODE=upstream`
- `MRG_UPSTREAM_API_URL=http://127.0.0.1:8001`
- Limite del plugin: `6` reseñas maximo

## Lo que ya funciona

- Consentimiento de Google.
- Navegacion a la ficha de negocio.
- URL canonica de `/reviews`.
- Servicio del backend en `8000`.
- Servicio del scraper en `8001`.

## Bloqueo actual

- El scraper ya llega a la ficha y a la URL de reseñas.
- El fallo esta en la deteccion estable del listado de reseñas.
- En los logs aparecen casos como:
  - `Direct reviews URL loaded, but no review cards were visible yet`
  - `Could not find reviews pane with any selector`

## Siguiente paso util

- Revisar el ultimo HTML de debug generado en el VPS.
- Buscar el selector real del listado de reseñas en la pagina cargada.
- Ajustar `modules/scraper.py` del repo `google-reviews-scraper-pro` en funcion de ese HTML.

## Archivos clave

- `modules/scraper.py` en `google-reviews-scraper-pro`
- `backend/service.py` en `resenas-woo`
- `backend/schemas.py` en `resenas-woo`
- `includes/Reviews/ReviewSyncService.php`
- `includes/Reviews/ReviewRepository.php`

## Comandos utiles

- `sudo systemctl status google-reviews`
- `curl http://127.0.0.1:8000/health/upstream`
- `curl http://127.0.0.1:8001/`
- Revisar `logs/debug/` en el VPS para el ultimo HTML y screenshot de error
