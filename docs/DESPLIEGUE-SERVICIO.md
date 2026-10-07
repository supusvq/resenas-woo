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

1. **Probar en local**: `cd backend && python tests/test_access_guard.py && python tests/test_license_gate.py`.
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

## Licencia SupuHub (fase 3)

Antes de servir la caché o llamar a Apify, `/v1/import-reviews` pregunta a SupuHub
(`POST /api/license/verify.php`, producto `resenaswoo`) si el dominio de `site_url` tiene licencia
activa o de prueba. Código: `backend/license_gate.py`. Sin `site_url` = sin licencia.

| Variable | Por defecto | Qué hace |
|---|---|---|
| `MRG_LICENSE_MODE` | `log` | `off` no comprueba; `log` comprueba y anota pero deja pasar; `enforce` bloquea |
| `MRG_SUPUHUB_VERIFY_TOKEN` | (vacío) | Token de servicio. Sin él: `log` deja pasar y avisa en el log; `enforce` bloquea todo (503) |
| `MRG_SUPUHUB_VERIFY_URL` | `https://api.supudigital.es/api/license/verify.php` | Endpoint de SupuHub |
| `MRG_MAX_PLACES_PER_DOMAIN` | 3 | Fichas de Google distintas que puede usar un dominio para siempre |

Comportamiento:

- Caché por dominio (`mrg_license_cache`): licencia válida 6 h, no válida 15 min.
- SupuHub caído (red, timeout de 8 s, 5xx o 429): se reutiliza el último resultado **válido** si tiene
  menos de 72 h (`source=grace`). Si no, es «desconocido»: en `enforce` responde 503 con `Retry-After`.
- SupuHub responde 403/400: token o petición mal configurados. No se guarda como veredicto; sale
  `LICENCIA: SupuHub responde 403…` en el log del servicio; `enforce` responde 503, `log` deja pasar.
- Sin licencia, en `enforce`: HTTP 403 con `detail` «Esta web no tiene una licencia activa de
  Reseñas Woo. Actívala en Reseñas Woo > Licencia.» (el plugin lo muestra tal cual).
- Fichas (`mrg_license_places`): solo se vinculan cuando la licencia es válida. La 4.ª ficha distinta
  de un dominio da 403 en `enforce` (en `log` se anota y no se vincula). Repetir una ficha no cuenta.
  Para liberar fichas de un cliente que ha cambiado de negocio:
  `sqlite3 "$DB" "DELETE FROM mrg_license_places WHERE domain='cliente.es';"`
- `GET /health` incluye `license_mode` (nunca el token).
- Errores internos (SQLite ocupada, fallo inesperado): en `log` nunca bloquean (se anota
  `reason=error_interno`); en `enforce` dan 503 con `Retry-After`, nunca 500.
- El token no sigue redirecciones: un 3xx de SupuHub cuenta como error de configuración. Si
  `MRG_SUPUHUB_VERIFY_URL` no empieza por `https://` no se envía nada (`reason=url_no_https`).
- Orden: cada resultado se guarda con la hora en que se envió la consulta y solo pisa la fila si es
  más nuevo. Un «no válida» borra la última validez (sin gracia) y la gracia se decide releyendo la
  fila tras el fallo. Si un «no válida» no se puede guardar en SQLite (3 intentos), el proceso lo
  recuerda en memoria: 15 min como no válida y sin gracia de positivos anteriores.
- **Un solo worker.** El servicio corre con un único worker de uvicorn (unidad systemd). Toda la
  decisión de un dominio (caché → SupuHub → guardar → decidir) va bajo un candado en memoria por
  dominio, y la respuesta final sale de releer lo guardado. **No añadir `--workers`** sin antes
  cambiar ese candado por uno entre procesos (un `BEGIN IMMEDIATE` de SQLite que abarque la
  decisión o un archivo de bloqueo); la marca de negativos sin guardar también es por proceso.

### Copiar el token al .env sin mostrarlo

El token está en `/etc/supuhub/services.conf`, sección `[verify]`, clave `resenaswoo`. Se copia de
archivo a archivo, sin imprimirlo (ni en pantalla ni en el historial):

```bash
sudo bash <<'EOF_TOKEN'
set -euo pipefail
ENV=/etc/supu/google-reviews.env
TOKEN=$(awk -F'=' '
  /^\[/ { sec = ($0 ~ /^\[verify\]/) }
  sec && $1 ~ /^[[:space:]]*resenaswoo[[:space:]]*$/ { v = substr($0, index($0, "=") + 1); gsub(/^[[:space:]"]+|[[:space:]"]+$/, "", v); print v; exit }
' /etc/supuhub/services.conf)
[ -n "$TOKEN" ] || { echo "No encuentro [verify] resenaswoo en services.conf" >&2; exit 1; }
cp -a "$ENV" "$ENV.bak-$(date +%Y%m%d-%H%M)"
sed -i '/^MRG_SUPUHUB_VERIFY_TOKEN=/d; /^MRG_LICENSE_MODE=/d' "$ENV"
printf 'MRG_SUPUHUB_VERIFY_TOKEN=%s\nMRG_LICENSE_MODE=log\n' "$TOKEN" >> "$ENV"
chmod 600 "$ENV"
unset TOKEN
echo "Token copiado ($(grep -c '^MRG_SUPUHUB_VERIFY_TOKEN=' "$ENV") línea)."
EOF_TOKEN
sudo systemctl restart google-reviews
curl -s http://127.0.0.1:8000/health   # debe decir "license_mode":"log"
```

### Ver quién quedaría bloqueado

Cada decisión se anota en `mrg_access_log` con `kind='license'` (90 días). `would_block=1` es lo que
`enforce` bloquearía:

```bash
sqlite3 "$DB" "SELECT site, outcome, COUNT(*), datetime(MAX(ts),'unixepoch')
  FROM mrg_access_log WHERE kind='license' AND outcome LIKE '%would_block=1%'
  GROUP BY site, outcome ORDER BY 4 DESC;"
```

El campo `reason` dice por qué: `no_license`/otro motivo de SupuHub (sin licencia), `sin_dominio`
(no manda `site_url`), `demasiadas_fichas`, `sin_token`, `supuhub_403` (configuración) o `red` /
`supuhub_5xx` (SupuHub caído sin gracia).

### Plan de activación

1. Desplegar con `MRG_LICENSE_MODE=log` y el token copiado.
2. Unos días mirando la consulta anterior: dar de alta en SupuHub la licencia de cada web cliente que
   aparezca con `would_block=1` y revisar los `demasiadas_fichas`.
3. Cuando ya no salgan clientes legítimos con `would_block=1`, cambiar a `MRG_LICENSE_MODE=enforce`
   en el .env y reiniciar `google-reviews`. Volver atrás = poner `log` y reiniciar.
