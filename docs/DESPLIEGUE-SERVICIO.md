# Despliegue del servicio de importación (scraper.supufactory.es)

Servicio FastAPI que usa el plugin para importar reseñas. Código: `backend/` de este repo.

## Dónde corre

| Qué | Valor |
|---|---|
| Servidor | VPS `supu@178.104.246.253` (compartido con SupuHub y otras apps: no tocar nginx ni MySQL de otros) |
| Código | `/opt/supu/apps/resenas-woo/backend` (clon de este repo) |
| Python | `/opt/supu/services/google-reviews/venv` |
| Servicio | `google-reviews` (systemd, usuario `supu`, uvicorn en `127.0.0.1:8000`) |
| Configuración y secretos | `/etc/supu/google-reviews.env` (solo root). **Nunca** copiar su contenido al chat ni al repo |
| nginx | `scraper.supufactory.es` → `127.0.0.1:8000`, fija `X-Real-IP` |
| Proveedor activo | `MRG_REVIEW_PROVIDER=apify` (`GET /health` lo dice) |

## Pasos

1. **Probar en local**: `cd backend && python tests/test_access_guard.py`.
2. **Revisión de Codex** del diff (solo lectura) hasta `VEREDICTO: APROBADO`.
3. **Copia de seguridad** en el VPS:
   ```bash
   cd /opt/supu/apps/resenas-woo && tar czf ~/backups/resenas-backend-$(date +%Y%m%d-%H%M).tgz backend
   ```
4. **Subir solo los archivos cambiados** a `/opt/supu/apps/resenas-woo/backend/` (scp).
5. **Reiniciar y comprobar**:
   ```bash
   sudo systemctl restart google-reviews
   sudo systemctl status google-reviews --no-pager | head -5
   curl -s http://127.0.0.1:8000/health
   curl -s https://scraper.supufactory.es/health
   ```
6. **Prueba real**: botón «Sincronizar» del plugin en una web (sale de la caché si la ficha se importó hace menos de 6 días: no gasta Apify).
7. **Volver atrás** si algo falla: descomprimir la copia del paso 3 sobre `backend/` y reiniciar.

## Límites de uso (variables opcionales en el .env)

| Variable | Por defecto | Qué limita |
|---|---|---|
| `MRG_LIMIT_REQ_PER_IP_HOUR` | 30 | Peticiones por IP y hora (con o sin caché) |
| `MRG_LIMIT_LIVE_PER_SITE_DAY` | 3 | Scrapes en vivo por web **e IP** y 24 h |
| `MRG_LIMIT_LIVE_PER_IP_DAY` | 6 | Scrapes en vivo por IP y 24 h |
| `MRG_LIMIT_LIVE_GLOBAL_DAY` | 40 | Scrapes en vivo totales en 24 h (freno de gasto de Apify) |

Registro de llamadas: tabla `mrg_access_log` en la SQLite de `MRG_SAAS_DB_PATH` (90 días).
Ver qué webs usan el servicio:

```bash
sqlite3 "$DB" "SELECT site, ip, COUNT(*), datetime(MAX(ts),'unixepoch') FROM mrg_access_log GROUP BY site, ip ORDER BY 4 DESC;"
```
