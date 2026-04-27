# Contrato del Servicio de Importacion

El plugin llama a un servicio remoto para importar reseñas.

## Endpoint esperado

```text
POST /v1/import-reviews
```

## Cabeceras

```http
Accept: application/json
Content-Type: application/json
```

## Body de entrada

```json
{
  "maps_url": "https://www.google.com/maps/place/...",
  "max_reviews": 6,
  "language": "es",
  "site_url": "https://tudominio.com/"
}
```

## Campos de entrada

- `maps_url`: obligatoria. URL publica de Google Maps pegada en el plugin.
- `max_reviews`: maximo 6. El backend tambien fuerza este limite.
- `language`: opcional. Idioma del sitio WordPress.
- `site_url`: opcional. URL del sitio que hace la llamada.

## Respuesta correcta esperada

Codigo HTTP `200`.

```json
{
  "success": true,
  "place_id": "gbp_xxx",
  "place_name": "Lara Andalucia",
  "rating": 4.9,
  "user_ratings_total": 199,
  "review_target_url": "https://www.google.com/maps/place/...",
  "reviews": [
    {
      "review_id": "g_123456",
      "author_name": "Cristina Moreno",
      "author_photo": "https://lh3.googleusercontent.com/...",
      "rating": 5,
      "review_text": "Desde el primer contacto...",
      "review_date": "2025-11-10 12:00:00",
      "relative_time": "Hace 5 meses",
      "is_anonymous": 0
    }
  ]
}
```

## Campos aceptados por el plugin

Nivel raiz:

- `success`: booleano.
- `place_id`: opcional. Si no llega, el plugin genera uno interno.
- `rating`: opcional.
- `user_ratings_total`: opcional.
- `review_target_url`: opcional.
- `resolved_url`: opcional.
- `maps_url`: opcional.
- `reviews`: obligatorio. Array de reseñas.

Por reseña:

- `review_id`: recomendable.
- `author_name`: recomendable.
- `author_photo`: opcional.
- `rating`: obligatorio.
- `review_text`: recomendable.
- `review_date`: recomendable.
- `relative_time`: opcional.
- `is_anonymous`: opcional.

Alternativas aceptadas por compatibilidad:

- `author` en lugar de `author_name`.
- `text` en lugar de `review_text`.
- `published_relative` en lugar de `relative_time`.

## Respuesta de error recomendada

Codigo HTTP `400`, `401`, `403`, `404`, `422`, `429` o `500`.

```json
{
  "success": false,
  "message": "No se han podido importar reseñas para esta ficha."
}
```

El plugin tambien acepta `error` o `detail` si no llega `message`.

## Proveedores del backend

- `google_business_profile`: recomendado. Requiere OAuth y permiso sobre la ficha.
- `apify`: preparado para la siguiente fase.
- `selenium_legacy`: fallback antiguo.
- `demo`: pruebas sin servicios externos.

## Notas importantes

- El servicio debe devolver JSON valido siempre.
- Si `success` es `false`, el plugin muestra el mensaje al administrador.
- Si `reviews` llega vacio, el plugin trata la respuesta como error.
- El servicio no debe devolver HTML ni redirecciones interactivas.
