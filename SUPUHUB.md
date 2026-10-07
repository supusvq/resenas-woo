# SupuHub · Reseñas Woo

```yaml
supuhub:
  product_code: resenaswoo
  license_mode: domain
  license_policy: pro_features
  trial_days: 15
  wp_slug: resenas-woo
  wp_plugin_file: resenas-woo/mis-resenas-de-google.php
  update_uri: https://api.supudigital.es/products/resenaswoo

  free_features:
    - mostrar_resenas_guardadas
    - emails_peticion_resena
    - administracion_y_datos

  pro_features:
    - importar_resenas_google   # botón manual + tarea semanal y reintentos
    - automatic_updates          # lo decide SupuHub (package solo con licencia válida)

  expired_behavior:
    existing_functionality: allowed
    pro_features: blocked
    updates: blocked
    administration: allowed
    customer_data_access: allowed
```

| Función | Sin licencia | Prueba | Activa | Caducada |
|---|---|---|---|---|
| Mostrar reseñas guardadas | Sí | Sí | Sí | Sí |
| Emails de petición de reseña | Sí | Sí | Sí | Sí |
| Importar de Google (manual y semanal) | No | Sí | Sí | No |
| Actualizaciones | No | Sí | Sí | No |
| Acceso a datos y ajustes | Sí | Sí | Sí | Sí |

Revalidación cada 12 h (reintento 1 h tras fallo de red), 72 h de gracia si SupuHub no responde.
Código: `includes/License/`. Pruebas: `php tests/license.php` y `php tests/migration.php`.
Empaquetar: `php build-release.php` → `dist/resenas-woo-<versión>.zip`. Manifiesto: `supuhub.json`.
