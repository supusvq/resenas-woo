# Contrato del Servicio de Importacion

El plugin llama a un servicio remoto para importar reseñas a partir de una URL de Google Maps.

## Endpoint esperado

`POST /v1/import-reviews`

## Cabeceras

```http
Accept: application/json
Content-Type: application/json
```

## Body de entrada

```json
{
  "maps_url": "https://www.google.com/maps/place/...",
  "max_reviews": 200,
  "language": "es",
  "site_url": "https://tudominio.com/"
}
```

## Campos de entrada

- `maps_url`: obligatoria. URL publica de Google Maps pegada en el plugin.
- `max_reviews`: obligatoria. Limite maximo solicitado por el plugin.
- `language`: opcional pero recomendable. Idioma del sitio WordPress.
- `site_url`: opcional. URL del sitio que hace la llamada.

## Respuesta correcta esperada

Codigo HTTP `200` o `201`.

```json
{
  "success": true,
  "place_id": "remote_lara_andalucia",
  "place_name": "Lara Andalucia",
  "rating": 4.9,
  "user_ratings_total": 199,
  "review_target_url": "https://g.page/r/xxxx/review",
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

### Nivel raiz

- `success`: booleano.
- `place_id`: opcional. Si no llega, el plugin genera uno interno.
- `rating`: opcional.
- `user_ratings_total`: opcional.
- `review_target_url`: opcional. Se usara en el boton de dejar reseña de los emails.
- `resolved_url`: opcional. Alternativa a `review_target_url`.
- `maps_url`: opcional. Alternativa a `review_target_url`.
- `reviews`: obligatorio. Array de reseñas.

### Por reseña

- `review_id`: recomendable.
- `author_name`: recomendable.
- `author_photo`: opcional.
- `rating`: obligatorio.
- `review_text`: recomendable.
- `review_date`: recomendable.
- `relative_time`: opcional.
- `is_anonymous`: opcional.

Tambien se aceptan estas alternativas:

- `author` en lugar de `author_name`
- `text` en lugar de `review_text`
- `published_relative` en lugar de `relative_time`

## Respuesta de error recomendada

Codigo HTTP `400`, `401`, `403`, `404`, `422`, `429` o `500`.

```json
{
  "success": false,
  "message": "No se han podido extraer reseñas para esta URL."
}
```

El plugin tambien acepta `error` si no llega `message`.

## Notas importantes

- El plugin espera JSON valido siempre.
- Si `success` es `false`, el plugin mostrara el mensaje al administrador.
- Si `reviews` llega vacio, el plugin tratara la respuesta como error.
- El servicio no debe devolver HTML ni redirecciones interactivas.
