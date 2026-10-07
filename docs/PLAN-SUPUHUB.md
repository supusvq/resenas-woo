# Reseñas Woo en SupuHub: estado y siguientes pasos

Decisiones de Juan (07/10/2026): consulta servicio a servicio en SupuHub; sin licencia, la web
muestra las reseñas guardadas y envía los emails de petición, y solo importar desde Google necesita
licencia; precio 49 €/año, 1 web, 15 días de prueba; Lara con licencia interna sin caducidad.

## Fases

| Fase | Estado | Dónde |
|---|---|---|
| 0. Orden y seguridad del servicio | ✅ Producción 07/10 | `backend/access_guard.py` (límites y registro); plugin 2.12.3 con reintentos |
| 1. SupuHub responde si un dominio tiene licencia | ✅ Producción 07/10 | Repo supuhub: `POST /api/license/verify.php` (D-VERIFY), release `60b330d`, token en `/etc/supuhub/services.conf` |
| 2. Plugin 3.0.0 (licencia, actualizaciones, migración segura) | ✅ Rama `v3-supuhub`, Codex aprobado, probado en el staging de Lara | `dist/resenas-woo-3.0.0.zip`, carpeta `resenas-woo` |
| 3. El servicio comprueba la licencia | ✅ Producción 07/10 en `MRG_LICENSE_MODE=log` | `backend/license_gate.py`, token en `/etc/supu/google-reviews.env` |
| 4. Alta comercial en SupuHub | Pendiente | Kit de publicación: staging con Stripe de prueba → producción LIVE |
| 5. Migrar las webs y activar el bloqueo | Pendiente | Lara + clientes; después `MRG_LICENSE_MODE=enforce` |

## Fase 4 (siguiente)

1. Fusionar `v3-supuhub` en `main` y regenerar el ZIP (`php build-release.php`).
2. Kit de publicación (`supuhub.json`; necesita Linux o macOS: ejecutarlo en el VPS o en WSL),
   **primero en staging** con Stripe en modo de prueba: producto `resenaswoo` (modo `domain`,
   prefijo `RESENAS`, slug `resenas-woo`), plan anual de 49 € con 15 días de prueba y 1 web, y la
   versión 3.0.0.
3. Compra simulada completa en staging: pago → licencia → activar en el staging de Lara → actualizar.
4. La misma publicación en producción (LIVE), solo con el OK de Juan.
5. Sustituir `License::BUY_URL` (hoy `https://supudigital.es/resenas-woo/`, provisional) por el
   enlace de pago que devuelva el kit.

## Fase 5

- Lara: licencia manual `lifetime` en el panel de SupuHub; instalar la 3.0 en producción (con copia)
  y activarla. El desinstalador de la 2.x queda neutralizado al activar la 3.0.
- Clientes: ver quién usa el servicio
  (`kind='license' AND outcome LIKE '%would_block=1%'`, ver `DESPLIEGUE-SERVICIO.md`). Pendiente de
  Juan: lista de clientes y si se les regala la licencia o pagan.
- Cuando ninguna web legítima salga con `would_block=1`: `MRG_LICENSE_MODE=enforce` y reiniciar
  `google-reviews`. Volver atrás = `log`.

## Riesgos conocidos

- `site_url` lo declara la web: alguien podría hacerse pasar por un dominio con licencia. Se mitiga
  con el tope de 3 fichas por dominio; el arreglo completo sería un token por web firmado por SupuHub.
- El servicio corre con **un solo worker** de uvicorn: el candado por dominio de `license_gate.py` lo
  necesita. Añadir `--workers` obliga a un candado entre procesos.
- `codex exec --sandbox read-only` en Windows puede escribir archivos: revisar `git status` tras cada
  revisión.
